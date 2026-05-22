<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CRUD operations on the wp_gffpdf_feeds table plus
 * the admin UI for the per-form feed settings page.
 */
class GFFPDF_Feed_Settings {

	public function __construct() {
		// Register the per-form feed settings sub-page
		add_filter( 'gform_form_settings_menu', [ $this, 'add_form_settings_menu' ], 10, 1 );
		add_action( 'gform_form_settings_page_gffpdf', [ $this, 'render_form_settings_page' ] );

		// AJAX handlers
		add_action( 'wp_ajax_gffpdf_save_feed',      [ $this, 'ajax_save_feed' ] );
		add_action( 'wp_ajax_gffpdf_delete_feed',    [ $this, 'ajax_delete_feed' ] );
		add_action( 'wp_ajax_gffpdf_toggle_feed',    [ $this, 'ajax_toggle_feed' ] );
		add_action( 'wp_ajax_gffpdf_duplicate_feed', [ $this, 'ajax_duplicate_feed' ] );
		add_action( 'wp_ajax_gffpdf_upload_pdf',     [ $this, 'ajax_upload_pdf' ] );
		add_action( 'wp_ajax_gffpdf_get_feed',       [ $this, 'ajax_get_feed' ] );
		add_action( 'wp_ajax_gffpdf_auto_map',       [ $this, 'ajax_auto_map' ] );
	}

	/* -----------------------------------------------------------------------
	 * GF form settings menu integration
	 * -------------------------------------------------------------------- */

	public function add_form_settings_menu( array $menu_items ): array {
		$menu_items[] = [
			'name'  => 'gffpdf',
			'label' => __( 'Fillable PDF', 'gf-fillable-pdf' ),
		];
		return $menu_items;
	}

	public function render_form_settings_page(): void {
		if ( ! GFFPDF_Security::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'gf-fillable-pdf' ) );
		}

		$form_id = absint( rgget( 'id' ) );
		$form    = GFAPI::get_form( $form_id );
		$feeds   = self::get_feeds_by_form( $form_id );
		$fields  = $this->get_gf_fields( $form );

		wp_enqueue_media();
		$this->enqueue_feed_assets( $form_id );

         GFFormSettings::page_header();
		include GFFPDF_PATH . 'templates/feeds/feed-settings.php';
         GFFormSettings::page_footer();
	}

	private function enqueue_feed_assets( int $form_id ): void {
		wp_enqueue_style(
			'gffpdf-feed',
			GFFPDF_URL . 'assets/css/feed.css',
			[],
			GFFPDF_VERSION
		);

		wp_enqueue_script(
			'gffpdf-feed',
			GFFPDF_URL . 'assets/js/feed.js',
			[ 'jquery' ],
			GFFPDF_VERSION,
			true
		);

		wp_enqueue_script(
			'gffpdf-mappings',
			GFFPDF_URL . 'assets/js/mappings.js',
			[ 'jquery', 'gffpdf-feed' ],
			GFFPDF_VERSION,
			true
		);

		wp_localize_script( 'gffpdf-feed', 'GFFPDF', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => GFFPDF_Security::create_nonce(),
			'form_id'  => $form_id,
			'strings'  => [
				'confirm_delete'    => __( 'Are you sure you want to delete this feed?', 'gf-fillable-pdf' ),
				'confirm_duplicate' => __( 'Duplicate this feed?', 'gf-fillable-pdf' ),
				'saving'            => __( 'Saving…', 'gf-fillable-pdf' ),
				'saved'             => __( 'Feed saved.', 'gf-fillable-pdf' ),
				'error'             => __( 'An error occurred. Please try again.', 'gf-fillable-pdf' ),
				'uploading'         => __( 'Uploading PDF…', 'gf-fillable-pdf' ),
				'upload_success'    => __( 'PDF uploaded successfully.', 'gf-fillable-pdf' ),
				'no_fields'         => __( 'No fillable fields found in this PDF.', 'gf-fillable-pdf' ),
			],
		] );
	}

	/* -----------------------------------------------------------------------
	 * AJAX handlers
	 * -------------------------------------------------------------------- */

	public function ajax_save_feed(): void {
		GFFPDF_Security::check_ajax();

		// Mappings are posted as a JSON string to avoid PHP's dot-to-underscore
		// mangling of POST keys (e.g. field id "1.3" becomes "1_3" in $_POST).
		$raw_mappings = $_POST['mappings_json'] ?? '';
		$mappings     = [];
		if ( is_string( $raw_mappings ) && $raw_mappings !== '' ) {
			$decoded = json_decode( wp_unslash( $raw_mappings ), true );
			if ( is_array( $decoded ) ) {
				$mappings = $decoded;
			}
		}

		// Build feed_settings array, ensuring save_pdfs defaults to true
		// (absent from the modal form means "enabled", not "disabled").
		$raw_settings              = $_POST['feed_settings'] ?? [];
		$raw_settings['save_pdfs'] = $raw_settings['save_pdfs'] ?? 1;

		$data = [
			'form_id'       => absint( $_POST['form_id'] ?? 0 ),
			'feed_name'     => sanitize_text_field( wp_unslash( $_POST['feed_name'] ?? '' ) ),
			'template_path' => sanitize_text_field( wp_unslash( $_POST['template_path'] ?? '' ) ),
			'mappings'      => $mappings,
			'is_active'     => absint( $_POST['is_active'] ?? 0 ), // Fix: was isset() which always returns true even when value is "0"
			'settings'      => $raw_settings,
		];

		$feed_id = absint( $_POST['feed_id'] ?? 0 );

		if ( $feed_id ) {
			$result = self::update_feed( $feed_id, $data );
		} else {
			$result = self::create_feed( $data );
			$feed_id = $result;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'feed_id' => $feed_id, 'message' => __( 'Feed saved successfully.', 'gf-fillable-pdf' ) ] );
	}

	public function ajax_delete_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id = absint( $_POST['feed_id'] ?? 0 );

		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed ID.', 'gf-fillable-pdf' ) ] );
		}

		self::delete_feed( $feed_id );
		wp_send_json_success( [ 'message' => __( 'Feed deleted.', 'gf-fillable-pdf' ) ] );
	}

	public function ajax_toggle_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id   = absint( $_POST['feed_id'] ?? 0 );
		$is_active = absint( $_POST['is_active'] ?? 0 );

		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed ID.', 'gf-fillable-pdf' ) ] );
		}

		self::toggle_feed( $feed_id, $is_active );
		wp_send_json_success();
	}

	public function ajax_duplicate_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id = absint( $_POST['feed_id'] ?? 0 );
		$feed    = self::get_feed( $feed_id );

		if ( ! $feed ) {
			wp_send_json_error( [ 'message' => __( 'Feed not found.', 'gf-fillable-pdf' ) ] );
		}

		$new_id = self::create_feed( [
			'form_id'       => $feed->form_id,
			'feed_name'     => $feed->feed_name . ' (' . __( 'Copy', 'gf-fillable-pdf' ) . ')',
			'template_path' => $feed->template_path,
			'settings'      => json_decode( $feed->settings, true ) ?? [],
			'mappings'      => json_decode( $feed->mappings, true ) ?? [],
			'is_active'     => 0,
		] );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
		}

		wp_send_json_success( [ 'feed_id' => $new_id, 'message' => __( 'Feed duplicated.', 'gf-fillable-pdf' ) ] );
	}

	public function ajax_upload_pdf(): void {
		GFFPDF_Security::check_ajax();

		if ( empty( $_FILES['pdf_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'gf-fillable-pdf' ) ] );
		}

		$handler = new GFFPDF_Template_Handler();
		$result  = $handler->handle_upload( $_FILES['pdf_file'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Extract fields with coordinates and store in DB; return simple list for UI
		$extractor    = new GFFPDF_PDF_Field_Extractor();
		$ui_fields    = $extractor->get_simple_fields_for_ui( $result['path'] );

		wp_send_json_success( [
			'path'   => $result['path'],
			'fields' => $ui_fields,
			'name'   => basename( $result['path'] ),
		] );
	}

	public function ajax_get_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id = absint( $_POST['feed_id'] ?? 0 );
		$feed    = self::get_feed( $feed_id );

		if ( ! $feed ) {
			wp_send_json_error( [ 'message' => __( 'Feed not found.', 'gf-fillable-pdf' ) ] );
		}

		// Decode JSON columns — default to empty object/array so JS never
		// receives null and crashes on property access.
		$feed->settings   = json_decode( $feed->settings,  false ) ?: new stdClass();
		$feed->mappings   = json_decode( $feed->mappings,   true  ) ?: [];
		$feed->pdf_fields = [];

		// Load stored PDF fields (with coordinates) for the mapping UI.
		if ( ! empty( $feed->template_path ) && file_exists( $feed->template_path ) ) {
			$extractor        = new GFFPDF_PDF_Field_Extractor();
			$feed->pdf_fields = $extractor->get_simple_fields_for_ui( $feed->template_path );
		}

		wp_send_json_success( $feed );
	}

	public function ajax_auto_map(): void {
		GFFPDF_Security::check_ajax();

		$form_id    = absint( $_POST['form_id'] ?? 0 );
		$pdf_fields = $_POST['pdf_fields'] ?? [];

		$form      = GFAPI::get_form( $form_id );
		$gf_fields = $this->get_gf_fields( $form );

		$mappings = [];
		foreach ( $pdf_fields as $pdf_field ) {
			$name         = sanitize_text_field( $pdf_field );
			$mappings[ $name ] = GFFPDF_Helpers::auto_map( $name, $gf_fields );
		}

		wp_send_json_success( $mappings );
	}

	/* -----------------------------------------------------------------------
	 * DB CRUD (static helpers)
	 * -------------------------------------------------------------------- */

	public static function create_feed( array $data ) {
		global $wpdb;

		$clean = GFFPDF_Security::sanitize_feed( $data );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'gffpdf_feeds',
			[
				'form_id'       => $clean['form_id'],
				'feed_name'     => $clean['feed_name'],
				'template_path' => $clean['template_path'],
				'settings'      => $clean['settings'],
				'mappings'      => $clean['mappings'],
				'is_active'     => $clean['is_active'],
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( $inserted === false ) {
			return new WP_Error( 'db_error', __( 'Could not save feed.', 'gf-fillable-pdf' ) );
		}

		GFFPDF_Logger::info( 'Feed created', [ 'id' => $wpdb->insert_id, 'form_id' => $clean['form_id'] ] );
		return $wpdb->insert_id;
	}

	public static function update_feed( int $feed_id, array $data ): bool {
		global $wpdb;

		$clean = GFFPDF_Security::sanitize_feed( $data );

		$updated = $wpdb->update(
			$wpdb->prefix . 'gffpdf_feeds',
			[
				'feed_name'     => $clean['feed_name'],
				'template_path' => $clean['template_path'],
				'settings'      => $clean['settings'],
				'mappings'      => $clean['mappings'],
				'is_active'     => $clean['is_active'],
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $feed_id ],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ],
			[ '%d' ]
		);

		GFFPDF_Logger::info( 'Feed updated', [ 'id' => $feed_id ] );
		return $updated !== false;
	}

	public static function delete_feed( int $feed_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'gffpdf_feeds',
			[ 'id' => $feed_id ],
			[ '%d' ]
		);
		GFFPDF_Logger::info( 'Feed deleted', [ 'id' => $feed_id ] );
		return $deleted !== false;
	}

	public static function toggle_feed( int $feed_id, int $is_active ): bool {
		global $wpdb;
		return $wpdb->update(
			$wpdb->prefix . 'gffpdf_feeds',
			[ 'is_active' => $is_active, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $feed_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		) !== false;
	}

	public static function get_feed( int $feed_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_feeds WHERE id = %d",
			$feed_id
		) );
	}

	public static function get_feeds_by_form( int $form_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_feeds WHERE form_id = %d ORDER BY created_at DESC",
			$form_id
		) );
	}

	public static function get_active_feeds_by_form( int $form_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_feeds WHERE form_id = %d AND is_active = 1 ORDER BY id ASC",
			$form_id
		) );
	}

	/* -----------------------------------------------------------------------
	 * GF fields helper
	 * -------------------------------------------------------------------- */

	private function get_gf_fields( array $form ): array {
		if ( empty( $form['fields'] ) ) return [];

		$fields = [];
		foreach ( $form['fields'] as $field ) {
			$fields[] = [
				'id'    => $field->id,
				'label' => $field->label,
				'type'  => $field->type,
			];

			// Sub-fields (e.g. Name, Address)
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['label'] ) ) {
						$fields[] = [
							'id'    => $input['id'],
							'label' => $field->label . ' (' . $input['label'] . ')',
							'type'  => $field->type,
						];
					}
				}
			}
		}
		return $fields;
	}
}