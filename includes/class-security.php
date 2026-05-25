<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Security {

	const CAPABILITY  = 'gravityforms_edit_forms';
	const NONCE_ADMIN = 'gffpdf_admin_nonce';
	const NONCE_AJAX  = 'gffpdf_ajax_nonce';

	/* -----------------------------------------------------------------------
	 * Capability checks
	 * -------------------------------------------------------------------- */

	/**
	 * Returns the capability required to access this plugin.
	 *
	 * Logic:
	 *  - If Gravity Forms' role/member management is active, use the native
	 *    'gravityforms_edit_forms' capability so GF admins get access automatically.
	 *  - Otherwise fall back to 'manage_options' (standard WP Administrator),
	 *    so the plugin works out-of-the-box on any site without manual setup.
	 *
	 * Any site can override this via:
	 *   add_filter( 'gffpdf_capability', fn() => 'your_custom_cap' );
	 * 
	 * @return string
	 */
	public static function get_capability(){
		$has_gf_roles = class_exists( 'GFCommon' )
		                && method_exists( 'GFCommon', 'has_members_plugin' )
		                && GFCommon::has_members_plugin();
		$default = $has_gf_roles ? self::CAPABILITY : 'manage_options';
		return (string) apply_filters( 'gffpdf_capability', $default );
	}

	public static function current_user_can(): bool {
		return current_user_can( self::get_capability() );
	}

	public static function require_capability(): void {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gf-fillable-pdf' ), 403 );
		}
	}

	/* -----------------------------------------------------------------------
	 * Nonce helpers
	 * -------------------------------------------------------------------- */

	public static function create_nonce( string $action = self::NONCE_AJAX ): string {
		return wp_create_nonce( $action );
	}

	public static function verify_nonce( string $nonce, string $action = self::NONCE_AJAX ): bool {
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Verify nonce and capability for AJAX requests; dies on failure.
	 */
	public static function check_ajax( string $action = self::NONCE_AJAX ): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! self::verify_nonce( $nonce, $action ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'gf-fillable-pdf' ) ], 403 );
		}
		if ( ! self::current_user_can() ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'gf-fillable-pdf' ) ], 403 );
		}
	}

	/* -----------------------------------------------------------------------
	 * Sanitisation helpers
	 * -------------------------------------------------------------------- */

	public static function sanitize_mappings( array $mappings ): array {
		$clean = [];
		foreach ( $mappings as $pdf_field => $gf_field_id ) {
			// Use sanitize_text_field — NOT absint — because sub-field IDs like
			// "1.3" or "2.6" must be preserved exactly as strings. absint() would
			// truncate "1.3" to 1, breaking compound GF fields (Name, Address, etc).
			$clean[ sanitize_text_field( $pdf_field ) ] = sanitize_text_field( (string) $gf_field_id );
		}
		return $clean;
	}

	public static function sanitize_settings( array $settings ): array {
		// save_pdfs: default to TRUE when the key is absent (feed modal does not
		// send this key, so absence must mean "enabled"). Only treat it as false
		// when it is explicitly passed as a falsy value (0, "0", false, "false").
		$save_pdfs_raw = $settings['save_pdfs'] ?? null;
		$save_pdfs     = ( $save_pdfs_raw === null ) ? true : (bool) $save_pdfs_raw;

		// Sanitize notification IDs(array of integers)
		$notification_ids = [];
		if(!empty($settings['notification_ids']) && is_array($settings['notification_ids'])){
			$notification_ids = array_map('sanitize_text_field', $settings['notification_ids']);
		}

		// Sanitize conditional logic rules
		$conditional_logic = [];
		if(!empty( $settings['conditional_logic'])  && is_array($settings['conditional_logic'])){
			$cl = $settings['conditional_logic'];
			$conditional_logic = [
				'enabled' => !empty($cl['enabled']),
				'action' => in_array($cl['action'] ?? '', ['show', 'hide'], true) ? $cl['action'] : 'show',
				'logic_type' => in_array($cl['logic_type'] ?? '', ['all', 'any'], true) ? $cl['logic_type'] : 'all',
				'rules' => [],
			];

			if(!empty($cl['rules']) && is_array($cl['rules'])){
				foreach($cl['rules'] as $rule){
					if(!is_array($rule)) continue;

					$conditional_logic['rules'][] = [
						'field_id' => sanitize_text_field($rule['field_id'] ?? ''),
						'operator' => sanitize_text_field($rule['operator'] ?? 'is'),
						'value' => sanitize_text_field($rule['value'] ?? ''),
					];
				}
			}
		}

		return [
			'default_font_family' => sanitize_text_field( $settings['default_font_family'] ?? 'helvetica' ),
			'default_font_size'   => absint( $settings['default_font_size'] ?? 12 ),
			'default_font_color'  => sanitize_hex_color( $settings['default_font_color'] ?? '#000000' ) ?: '#000000',
			'filename_pattern'    => sanitize_text_field( $settings['filename_pattern'] ?? 'submission-{entry_id}-{date}' ),
			'save_pdfs'           => $save_pdfs,
			'enable_logs'         => ! empty( $settings['enable_logs'] ),
			'flatten_pdf'         => ! empty( $settings['flatten_pdf'] ),
			'rtl_support'         => ! empty( $settings['rtl_support'] ),
			'storage_path'        => sanitize_text_field( $settings['storage_path'] ?? GFFPDF_UPLOAD_DIR ),
			// Per-feed settings
			'attach_to_email'     => ! empty( $settings['attach_to_email'] ),
			'notification_ids'    => $notification_ids,
			'conditional_logic'   => $conditional_logic,
			'font_family'         => sanitize_text_field( $settings['font_family'] ?? '' ),
			'font_size'           => is_numeric( $settings['font_size'] ?? '' ) ? (float) $settings['font_size'] : '',
			'font_color'          => sanitize_hex_color( $settings['font_color'] ?? '' ),
			'reverse_text'        => ! empty( $settings['reverse_text'] ),
		];
	}

	public static function sanitize_feed( array $data ): array {
		return [
			'form_id'       => absint( $data['form_id'] ?? 0 ),
			'feed_name'     => sanitize_text_field( $data['feed_name'] ?? '' ),
			'template_path' => sanitize_text_field( $data['template_path'] ?? '' ),
			'settings'      => wp_json_encode( self::sanitize_settings( $data['settings'] ?? [] ) ),
			'mappings'      => wp_json_encode( self::sanitize_mappings( $data['mappings'] ?? [] ) ),
			'is_active'     => (int) (bool) ( $data['is_active'] ?? 1 ),
		];
	}

	/* -----------------------------------------------------------------------
	 * File security
	 * -------------------------------------------------------------------- */

	/**
	 * Check an uploaded file is an allowed PDF.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_uploaded_pdf( array $file ) {
		// Size (default 10 MB)
		$max_size = apply_filters( 'gffpdf_max_upload_size', 10 * MB_IN_BYTES );
		if ( $file['size'] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: max allowed file size */
					esc_html__( 'File exceeds maximum allowed size of %s.', 'gf-fillable-pdf' ),
					GFFPDF_Helpers::format_bytes( $max_size )
				)
			);
		}

		// Extension
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $ext !== 'pdf' ) {
			return new WP_Error( 'invalid_extension', esc_html__( 'Only PDF files are allowed.', 'gf-fillable-pdf' ) );
		}

		// MIME
		$finfo    = finfo_open( FILEINFO_MIME_TYPE );
		$mime     = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( $mime !== 'application/pdf' ) {
			return new WP_Error( 'invalid_mime', esc_html__( 'Invalid file type. Only PDF files are accepted.', 'gf-fillable-pdf' ) );
		}

		return true;
	}

	/**
	 * Ensure a generated PDF path is within the allowed storage directory.
	 */
	public static function is_safe_path( string $path ): bool {
		$real     = realpath( $path );
		$base     = realpath( GFFPDF_UPLOAD_DIR );
		if ( $real === false || $base === false ) {
			return false;
		}
		return strpos( $real, $base ) === 0;
	}
}