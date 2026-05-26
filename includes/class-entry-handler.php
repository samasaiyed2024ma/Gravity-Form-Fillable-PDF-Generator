<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Listens for Gravity Forms submission events and triggers PDF generation.
 */
class GFFPDF_Entry_Handler {

	public function __construct() {
		add_action( 'gform_after_submission', [ $this, 'generate_pdf' ], 10, 2 );
		add_action( 'wp_ajax_gffpdf_regenerate', [ $this, 'ajax_regenerate' ] );
	}

	/* -----------------------------------------------------------------------
	 * Main generation trigger
	 * -------------------------------------------------------------------- */

	/**
	 * Called after each successful Gravity Forms submission.
	 */
	public function generate_pdf( array $entry, array $form ): void {
		$form_id = absint( $form['id'] );
		$feeds   = GFFPDF_Feed_Settings::get_active_feeds_by_form( $form_id );

		if ( empty( $feeds ) ) {
			return;
		}

		foreach ( $feeds as $feed ) {
			$result = $this->process_feed( $feed, $entry, $form );
			// WP_Error results are already logged inside process_feed; nothing else needed here.
			if ( is_wp_error( $result ) && $result->get_error_code() !== 'conditional_logic' ) {
				GFFPDF_Logger::error( 'Submission PDF failed', [
					'feed_id'  => $feed->id,
					'entry_id' => $entry['id'],
					'reason'   => $result->get_error_message(),
				] );
			}
		}
	}

	/**
	 * Process a single feed for a given entry.
	 *
	 * @return int|WP_Error  DB record ID on success, WP_Error describing the failure.
	 */
	public function process_feed( object $feed, array $entry, array $form ) {
		$feed_id       = (int) $feed->id;
		$template_path = $feed->template_path;
		$settings      = json_decode( $feed->settings, true ) ?? [];
		$mappings      = json_decode( $feed->mappings, true ) ?? [];

		// --- Template check ---
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			GFFPDF_Logger::error( 'Template file missing', [ 'feed_id' => $feed_id, 'path' => $template_path ] );
			return new WP_Error( 'template_missing',
				sprintf( __( 'Feed "%s": PDF template file is missing or has not been uploaded.', 'gf-fillable-pdf' ), $feed->feed_name )
			);
		}

		// --- Mappings check (filter out intentionally unmapped "0"/empty values) ---
		$active_mappings = array_filter( $mappings, function( $gf_field_id ) {
			return $gf_field_id !== '' && $gf_field_id !== '0' && $gf_field_id !== 0;
		} );

		if ( empty( $active_mappings ) ) {
			GFFPDF_Logger::warn( 'No field mappings configured', [ 'feed_id' => $feed_id ] );
			return new WP_Error( 'no_mappings',
				sprintf( __( 'Feed "%s": no field mappings configured — please map at least one PDF field to a form field.', 'gf-fillable-pdf' ), $feed->feed_name )
			);
		}

		// --- Conditional logic ---
		if ( ! $this->passes_conditional_logic( $settings, $entry, $form ) ) {
			GFFPDF_Logger::info( 'Feed skipped: conditional logic', [ 'feed_id' => $feed_id, 'entry_id' => $entry['id'] ] );
			return new WP_Error( 'conditional_logic',
				sprintf( __( 'Feed "%s": skipped — conditional logic rules not met for this entry.', 'gf-fillable-pdf' ), $feed->feed_name )
			);
		}

		// --- Build field values & generate ---
		$field_values = $this->build_field_values( $active_mappings, $entry, $form );

		$generator = new GFFPDF_PDF_Generator();
		$pdf_bytes = $generator->generate( $template_path, $field_values, $settings );

		if ( is_wp_error( $pdf_bytes ) ) {
			GFFPDF_Logger::error( 'PDF generation failed', [
				'feed_id'  => $feed_id,
				'entry_id' => $entry['id'],
				'error'    => $pdf_bytes->get_error_message(),
			] );
			return new WP_Error(
				$pdf_bytes->get_error_code(),
				sprintf( 'Feed "%s": %s', $feed->feed_name, $pdf_bytes->get_error_message() )
			);
		}

		// --- Resolve filename ---
		$global_settings = GFFPDF_Settings::get_settings();
		$pattern         = $settings['filename_pattern'] ?? $global_settings['filename_pattern'];
		$filename        = GFFPDF_Helpers::resolve_filename( $pattern, $entry, $form );
		$filename        = GFFPDF_Helpers::ensure_pdf_extension( $filename );

		// --- Save to disk ---
		$save = $global_settings['save_pdfs'] ?? true;
		if ( $save === false || $save === 0 || $save === '0' ) {
			return new WP_Error( 'save_disabled',
				sprintf( 'Feed "%s": PDF saving is disabled in global settings.', $feed->feed_name )
			);
		}

		$path = GFFPDF_File_Handler::save_generated( $pdf_bytes, $filename );
		if ( is_wp_error( $path ) ) {
			GFFPDF_Logger::error( 'PDF save failed', [ 'feed_id' => $feed_id, 'entry' => $entry['id'] ] );
			return new WP_Error(
				$path->get_error_code(),
				sprintf( 'Feed "%s": %s', $feed->feed_name, $path->get_error_message() )
			);
		}

		// --- Store record in DB ---
		$record_id = self::save_pdf_record( $entry['id'], $form['id'], $feed_id, $path );

		// --- Attach to notifications ---
		$selected_notifications = $settings['notification_ids'] ?? [];
		if ( ! empty( $selected_notifications ) && is_array( $selected_notifications ) ) {
			$this->attach_to_selected_notifications( $path, $form, $entry, $selected_notifications );
		} elseif ( ! empty( $settings['attach_to_email'] ) ) {
			$this->attach_to_notifications( $path, $form, $entry );
		}

		GFFPDF_Logger::info( 'PDF generated', [
			'entry_id' => $entry['id'],
			'feed_id'  => $feed_id,
			'path'     => $path,
		] );

		return $record_id;
	}


	/* -----------------------------------------------------------------------
	 * Conditional logic
	 * -------------------------------------------------------------------- */
 
	/**
	 * Returns true if the feed's conditional logic passes (or is disabled).
	 */
	private function passes_conditional_logic(array $settings, array $entry, array $form): bool{
		$cl = $settings['conditional_logic'] ?? [];

		if( empty($cl['enabled']) || empty( $cl['rules'] ) ){
			return true; // No conditional logic - always run
		}

		$logic_type = $cl['logic_type'] ?? 'all'; // 'all' = AND, 'any' = OR
		$action = $cl['action'] ?? 'show'; // 'show' = enable PDF if match
		$rules = $cl['rules'];

		$results = [];
		foreach($rules as $rule){
			$field_id = (string) ( $rule['field_id'] ?? '' );
			$operator = $rule['operator'] ?? 'is';
			$expected = (string) ( $rule['value'] ?? '' );
			$actual = (string) rgar($entry, $field_id);

			$results[] = $this->evaluate_rule($actual, $operator, $expected);
		}

		$matched = ( $logic_type === 'all' ) 
			? ! in_array(false, $results, true)
			: in_array(true, $results, true);
			
		return ($action === 'show') ? $matched : ! $matched;
	}

	private function evaluate_rule(string $actual, string $operator, string $expected): bool{
		switch( $operator ){
			case 'is':
				return $actual === $expected;
			case 'isnot':
				return $actual !== $expected;
			case 'greater_than':
				return is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected;
			case 'less_than':
				return is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected;
			case 'contains':
				return str_contains( $actual, $expected );
			case 'starts_with':
				return str_starts_with( $actual, $expected );
			case 'ends_with':
				return str_ends_with( $actual, $expected );
			default:
				return false;
		}
	}


	/* -----------------------------------------------------------------------
	 * Build field values from GF entry
	 * -------------------------------------------------------------------- */

	/**
	 * Convert GF entry + mapping config into a flat [ pdf_field => value ] array.
	 */
	private function build_field_values( array $mappings, array $entry, array $form ): array {
		$values = [];

		// Build a quick lookup of field type AND sub-field inputs by field ID.
		// Keys are stored as strings to match how rgar() keys the entry array.
		$field_types  = [];
		$field_inputs = []; // sub-field inputs e.g. Name, Address
		if ( ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$id_str               = (string) $field->id;
				$field_types[ $id_str ] = $field->type;

				// Register sub-field IDs (e.g. 1.3, 1.6) with the parent type
				if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
					foreach ( $field->inputs as $input ) {
						$field_types[ (string) $input['id'] ] = $field->type;
					}
				}
			}
		}

		foreach ( $mappings as $pdf_field => $gf_field_id ) {
			// Skip unmapped fields (saved as 0 or empty string)
			if ( $gf_field_id === '' || $gf_field_id === 0 || $gf_field_id === '0' ) {
				continue;
			}

			// Use string key — both rgar() and our lookup use string keys
			$gf_field_id = (string) $gf_field_id;
			$raw_value   = rgar( $entry, $gf_field_id );
			$field_type  = $field_types[ $gf_field_id ] ?? 'text'; // Bug fix: was (int) key

			switch ( $field_type ) {
				case 'checkbox':
					$values[ $pdf_field ] = GFFPDF_PDF_Generator::normalise_checkbox( $raw_value );
					break;

				case 'date':
					$values[ $pdf_field ] = ! empty( $raw_value )
						? date_i18n( get_option( 'date_format' ), strtotime( $raw_value ) )
						: '';
					break;

				case 'fileupload':
					// Store URL, not bytes
					$values[ $pdf_field ] = $raw_value;
					break;

				default:
					$values[ $pdf_field ] = (string) $raw_value;
			}
		}

		return $values;
	}

	/* -----------------------------------------------------------------------
	 * Email attachment
	 * -------------------------------------------------------------------- */

	private function attach_to_notifications( string $pdf_path, array $form, array $entry ): void {
		add_filter( 'gform_notification', function( $notification, $form_obj, $entry_obj ) use ( $pdf_path, $form, $entry ) {
			if ( $form_obj['id'] !== $form['id'] ) return $notification;

			if ( ! isset( $notification['attachments'] ) || ! is_array( $notification['attachments'] ) ) {
				$notification['attachments'] = [];
			}
			$notification['attachments'][] = $pdf_path;
			return $notification;
		}, 10, 3 );
	}

	/**
	 * Attach PDF only to specific notificion IDs.
	 */
	private function attach_to_selected_notifications(string $pdf_path, array $form, array $entry, array $notification_ids): void{
		add_filter('gform_notification', function($notification, $form_obj, $entry_obj) use ($pdf_path, $form, $notification_ids){
			if( $form_obj['id'] !== $form['id'] ) return $notification;
			if( !in_array( $notification['id'] ?? '', $notification_ids, true ) ) return $notification;

			if( !isset( $notification['attachments'] ) || ! is_array( $notification['attachments'] ) ){
				$notification['attachments'] = [];
			}

			$notification['attachments'][] = $pdf_path;
			return $notification;
		}, 10, 3);
	}

	/* -----------------------------------------------------------------------
	 * DB helpers (static)
	 * -------------------------------------------------------------------- */

	public static function save_pdf_record( int $entry_id, int $form_id, int $feed_id, string $pdf_path ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'gffpdf_entries',
			[
				'entry_id'     => $entry_id,
				'form_id'      => $form_id,
				'feed_id'      => $feed_id,
				'pdf_path'     => $pdf_path,
				'generated_at' => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%d', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public static function get_entry_pdfs( int $entry_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_entries WHERE entry_id = %d ORDER BY generated_at DESC",
			$entry_id
		) );
	}

	public static function get_pdf_record( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_entries WHERE id = %d",
			$id
		) );
	}

	/* -----------------------------------------------------------------------
	 * Regenerate (AJAX)
	 * -------------------------------------------------------------------- */

	public function ajax_regenerate(): void {
		// Buffer any stray output (PHP notices, plugin debug output, etc.) that
		// would prepend to the JSON response and cause jQuery to fail parsing it,
		// which manifests as "HTTP 200: Request failed" in the browser.
		ob_start();

		$entry_id = absint( $_REQUEST['entry_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );

		if ( ! $entry_id ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid entry ID.', 'gf-fillable-pdf' ) ], 400 );
			return;
		}

		if ( ! GFFPDF_Security::verify_nonce( $nonce, 'gffpdf_regenerate_' . $entry_id ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'gf-fillable-pdf' ) ], 403 );
			return;
		}

		if ( ! GFFPDF_Security::current_user_can() ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'gf-fillable-pdf' ) ], 403 );
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Entry not found.', 'gf-fillable-pdf' ) ], 400 );
			return;
		}

		$form  = GFAPI::get_form( $entry['form_id'] );
		$feeds = GFFPDF_Feed_Settings::get_active_feeds_by_form( $entry['form_id'] );

		if ( empty( $feeds ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'No active feeds found for this form. Please create and activate a feed first.', 'gf-fillable-pdf' ) ], 400 );
			return;
		}

		$generated = 0;
		$errors    = [];

		foreach ( $feeds as $feed ) {
			$result = $this->process_feed( $feed, $entry, $form );

			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'conditional_logic' ) {
					GFFPDF_Logger::info( $result->get_error_message(), [] );
				} else {
					$errors[] = $result->get_error_message();
				}
			} else {
				$generated++;
			}
		}

		if ( $generated === 0 && empty( $errors ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'All feeds were skipped — conditional logic rules were not met for this entry.', 'gf-fillable-pdf' ) ], 400 );
			return;
		}

		if ( $generated === 0 && ! empty( $errors ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => implode( "\n", $errors ) ], 400 );
			return;
		}

		$success_msg = sprintf(
			_n( '%d PDF generated successfully.', '%d PDFs generated successfully.', $generated, 'gf-fillable-pdf' ),
			$generated
		);

		if ( ! empty( $errors ) ) {
			$success_msg .= ' ' . esc_html__( 'Warning:', 'gf-fillable-pdf' ) . ' ' . implode( ' | ', $errors );
		}

		ob_end_clean();
		wp_send_json_success( [
			'message'   => $success_msg,
			'generated' => $generated,
		] );
	}
}