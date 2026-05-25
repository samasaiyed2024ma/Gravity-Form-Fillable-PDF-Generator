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
	 *
	 * @param array $entry  Gravity Forms entry
	 * @param array $form   Gravity Forms form
	 */
	public function generate_pdf( array $entry, array $form ): void {
		$form_id = absint( $form['id'] );
		$feeds   = GFFPDF_Feed_Settings::get_active_feeds_by_form( $form_id );

		if ( empty( $feeds ) ) {
			return;
		}

		foreach ( $feeds as $feed ) {
			$this->process_feed( $feed, $entry, $form );
		}
	}

	/**
	 * Process a single feed for a given entry.
	 */
	public function process_feed( object $feed, array $entry, array $form ): ?int {
		$feed_id       = (int) $feed->id;
		$template_path = $feed->template_path;
		$settings      = json_decode( $feed->settings, true ) ?? [];
		$mappings      = json_decode( $feed->mappings, true ) ?? [];

		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			GFFPDF_Logger::error( 'Template file missing', [ 'feed_id' => $feed_id, 'path' => $template_path ] );
			return null;
		}

		if ( empty( $mappings ) ) {
			GFFPDF_Logger::warn( 'No field mappings configured — skipping PDF generation.', [ 'feed_id' => $feed_id ] );
			return null;
		}

		// Conditional logic check
		if( !$this->passes_conditional_logic( $settings, $entry, $form )){
			GFFPDF_Logger::info( 
				'Feed skipped due to conditional logic', 
				[
					'feed_id' => $feed_id,
					'entry_id' => $entry['id'],
				]);
				return null;
		}

		// Filter out any unmapped entries (value is empty string or "0")
		// so we don't write blank values into the PDF for intentionally skipped fields.
		$mappings = array_filter( $mappings, function( $gf_field_id ) {
			return $gf_field_id !== '' && $gf_field_id !== '0' && $gf_field_id !== 0;
		} );

		// Build field values from entry
		$field_values = $this->build_field_values( $mappings, $entry, $form );

		// Generate PDF bytes
		$generator = new GFFPDF_PDF_Generator();
		$pdf_bytes = $generator->generate( $template_path, $field_values, $settings );

		if ( is_wp_error( $pdf_bytes ) ) {
			GFFPDF_Logger::error( 'PDF generation failed', [
				'feed_id' => $feed_id,
				'entry_id' => $entry['id'],
				'error'   => $pdf_bytes->get_error_message(),
			] );
			return null;
		}

		// Resolve filename
		$global_settings  = GFFPDF_Settings::get_settings();
		$pattern          = $settings['filename_pattern'] ?? $global_settings['filename_pattern'];
		$filename         = GFFPDF_Helpers::resolve_filename( $pattern, $entry, $form );
		$filename         = GFFPDF_Helpers::ensure_pdf_extension( $filename );

		// Save to disk — default to TRUE so PDFs are saved unless explicitly disabled.
		$save = $global_settings['save_pdfs'] ?? true;
		if ( $save === false || $save === 0 || $save === '0' ) {
			return null;
		}

		$path = GFFPDF_File_Handler::save_generated( $pdf_bytes, $filename );
		if ( is_wp_error( $path ) ) {
			GFFPDF_Logger::error( 'PDF save failed', [ 'feed_id' => $feed_id, 'entry' => $entry['id'] ] );
			return null;
		}

		// Store record in DB
		$record_id = self::save_pdf_record( $entry['id'], $form['id'], $feed_id, $path );

		// Attach to selected notifications (oe all id legacy attach_to_email is set)
		$selected_notifications = $settings['notification_ids'] ?? [];
		if( ! empty( $selected_notifications ) && is_array( $selected_notifications ) ){
			$this->attach_to_selected_notifications( $path, $form, $entry, $selected_notifications );
		}elseif ( ! empty( $settings['attach_to_email'] ) ){
			// Attach to all notifications
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
			$expected = (string) ( $rule['vaue'] ?? '' );
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
			if( !in_array( $notification['id'] ?? '', $notification, true ) ) return $notification;

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
		// Supports both GET (plain link) and POST (AJAX button)
		$entry_id = absint( $_REQUEST['entry_id'] ?? 0 );
		$nonce    = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );

		if ( ! $entry_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid entry ID.', 'gf-fillable-pdf' ) ] );
		}

		if ( ! GFFPDF_Security::verify_nonce( $nonce, 'gffpdf_regenerate_' . $entry_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed.', 'gf-fillable-pdf' ) ], 403 );
		}

		if ( ! GFFPDF_Security::current_user_can() ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'gf-fillable-pdf' ) ], 403 );
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Entry not found.', 'gf-fillable-pdf' ) ] );
		}

		$form  = GFAPI::get_form( $entry['form_id'] );
		$feeds = GFFPDF_Feed_Settings::get_active_feeds_by_form( $entry['form_id'] );

		if ( empty( $feeds ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No active feeds found for this form.', 'gf-fillable-pdf' ) ] );
		}

		$generated = 0;
		foreach ( $feeds as $feed ) {
			$result = $this->process_feed( $feed, $entry, $form );
			if ( $result ) {
				$generated++;
			}
		}

		if ( $generated === 0 ) {
			wp_send_json_error( [ 'message' => esc_html__( 'PDF generation failed. Check debug log for details.', 'gf-fillable-pdf' ) ] );
		}

		wp_send_json_success( [
			'message'   => sprintf( _n( '%d PDF generated successfully.', '%d PDFs generated successfully.', $generated, 'gf-fillable-pdf' ), $generated ),
			'generated' => $generated,
			'redirect'  => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry_id ),
		] );
	}
}