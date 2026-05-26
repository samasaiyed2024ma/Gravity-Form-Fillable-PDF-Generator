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
		add_action( 'wp_ajax_gffpdf_upload_font',    [ $this, 'ajax_upload_font' ] );
		add_action( 'wp_ajax_gffpdf_delete_font',    [ $this, 'ajax_delete_font' ] );
		add_action( 'wp_ajax_gffpdf_get_notifications', [ $this, 'ajax_get_notifications' ] );

		// Shortocde: [gffpdf feed_id="1" entry_id="{entry_id}"]
		add_shortcode( 'gffpdf', [ $this, 'shortcode_pdf_link' ] );
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
		$notifications = ! empty( $form['notifications'] ) ? array_map( function( $id, $n ){
			return [ 
				'id' => $id,  
				'name' => $n['name'] ?? $id,
			];
		}, array_keys( $form['notifications'] ), $form['notifications'] ) : [];
		$all_fonts = GFFPDF_Font_Manager::get_all_fonts();

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
			[ 'jquery', 'wp-color-picker' ],
			GFFPDF_VERSION,
			true
		);

		wp_enqueue_style( 'wp-color-picker' );

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
			'fonts'    => GFFPDF_Font_Manager::get_all_fonts(),
			'strings'  => [
				'confirm_delete'    => __( 'Are you sure you want to delete this feed?', 'gf-fillable-pdf' ),
				'confirm_duplicate' => __( 'Duplicate this feed?', 'gf-fillable-pdf' ),
				'saving'            => __( 'Saving...', 'gf-fillable-pdf' ),
				'saved'             => __( 'Feed saved.', 'gf-fillable-pdf' ),
				'error'             => __( 'An error occurred. Please try again.', 'gf-fillable-pdf' ),
				'uploading'         => __( 'Uploading PDF...', 'gf-fillable-pdf' ),
				'upload_success'    => __( 'PDF uploaded successfully.', 'gf-fillable-pdf' ),
				'no_fields'         => __( 'No fillable fields found in this PDF.', 'gf-fillable-pdf' ),
				'uploading_font'    => __( 'Uploading font...',  'gf-fillable-pdf'),
				'font_uploaded'     => __( 'Font uploaded.', 'gf-fillable-pdf' ),
				'confirm_del_font'  => __( 'Delete this font', 'gf-fillable-pdf' ),
				'add_rule' 			=> __( '+ Add Rule', 'gf-fillable-pdf' ),
				'remove_rule'       => __( 'Remove', 'gf-fillable-pdf' ), 
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

		// Conditional logic posted as JSON
		$raw_cl = $_POST['conditional_logic_json'] ?? '';
		$conditional_logic = [];
		if( is_string( $raw_cl ) && $raw_cl !== '' ){
			$decode_cl = json_decode( wp_unslash( $raw_cl ), true );
			if( is_array( $decode_cl ) ){
				$conditional_logic = $decode_cl;
			}
		}

		// Notification IDs posted as JSON
		$raw_notifications = $_POST['notification_ids_json'] ?? '';
		$notification_ids = [];
		if( is_string( $raw_notifications ) && $raw_notifications !== '' ){
			$decoded_n = json_decode( wp_unslash( $raw_notifications ), true );
			if( is_array( $decoded_n ) ){
				$notification_ids = $decoded_n;
			}
		}

		// Build feed_settings array, ensuring save_pdfs defaults to true
		$raw_settings              = $_POST['feed_settings'] ?? [];
		$raw_settings['save_pdfs'] = $raw_settings['save_pdfs'] ?? 1;
		$raw_settings['conditional_logic'] = $conditional_logic;
		$raw_settings['notification_ids'] = $notification_ids;

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
	 * Font management AJAX
	 * -------------------------------------------------------------------- */
	public function ajax_upload_font(): void{
		GFFPDF_Security::check_ajax();

		if( empty( $_FILES['font_file'] ) ){
			wp_send_json_error( ['message' => __( 'No font file uploaded', 'gf-fillable-pdf' )] );
		}

		$label = sanitize_text_field( wp_unslash( $_POST['font_label'] ?? '' ) );
		$result = GFFPDF_Font_Manager::upload_font( $_FILES['font_file'], $label );

		if( is_wp_error($result) ){
			wp_send_json_error( ['message' => $result->get_error_message()] );
		}

		wp_send_json_success( [
			'family' => $result,
			'fonts' => GFFPDF_Font_Manager::get_all_fonts(),
			'message' => __( 'Font uploaded successfully.', 'gf-fillable-pdf' ),
		] );
	}

	public function ajax_delete_font(): void{
		GFFPDF_Security::check_ajax();

		$family = sanitize_key( wp_unslash( $_POST['family'] ?? '' ) );
		if( !$family ){
			wp_send_json_error( ['message' => __( 'Invalid font.', 'gf-fillable-pdf' )] );
		}

		GFFPDF_Font_Manager::delete_font( $family );
		wp_send_json_success( [
			'fonts' => GFFPDF_Font_Manager::get_all_fonts(),
			'message' => __( 'Font Deleted', 'gf-fillable-pdf' ),
		] );
	}

	/* -----------------------------------------------------------------------
	 * Notification list AJAX
	 * -------------------------------------------------------------------- */
	public function ajax_get_notifications(): void{
		GFFPDF_Security::check_ajax();

		$form_id = absint( $_POST['form_id'] ?? 0 );
		$form = GFAPI::get_form( $form_id );

		$notifications = [];
		if( !empty($form['notifications']) && is_array( $form['notifications'] ) ){
			foreach( $form['notifications'] as $id => $n ){
				$notifications[] = [
					'id' => $id,
					'name' => $n['name'] ?? $id,
				];
			}
		}

		wp_send_json_success( $notifications );
	}

	/* -----------------------------------------------------------------------
	 * Shortcode: [gffpdf feed_id="1" entry_id="123" label="Download PDF"]
	 * -------------------------------------------------------------------- */
	public function shortcode_pdf_link(array $atts): string{
		$atts = shortcode_atts( [
			'feed_id' => 0,
			'entry_id' => 0,
			'label' => __( 'Download PDF', 'gf-fillable-pdf' ),
			'class' => 'gffpdf-shortcode-link',
		], $atts, 'gffpdf' );

		$feed_id = absint( $atts['feed_id'] );
		$entry_id = absint( $atts['entry_id'] );
		$label = esc_html( $atts['label'] );
		$class = esc_attr( $atts['class'] );

		if( ! $entry_id ){
			return '';
		}

		// If no feed_id given, find the first PDF for this entry
		if( ! $feed_id ){
			$pdfs = GFFPDF_Entry_Handler::get_entry_pdfs( $entry_id );
			if( empty($pdfs) ){
				return '';
			}
			$record = $pdfs[0];
		}else{
			global $wpdb;
			$record = $wpdb->get_row( 
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}gffpdf_entries WHERE entry_id = %d AND feed_id = %d ORDER BY generated_at DESC LIMIT 1", 
					$entry_id, 
					$feed_id
				)
			);
		}

		if( !$record || !file_exists( $record->pdf_path ) ){
			return '';
		}

		$nonce = GFFPDF_Security::create_nonce();
		$download_url = add_query_arg( [
			'action' => 'gffpdf_download_pdf',
			'pdf_id' => $record->id,
			'nonce' => $nonce,
		], admin_url( 'admin-ajax.php' ) );

		return sprintf( 
			'<a href="%s" class="%s">%s</a>',
			esc_url( $download_url ),
			$class,
			$label,
		);
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

		// Field types that carry a fixed set of choices
		$choice_types = ['select', 'radio', 'checkbox', 'multiselect', 'list'];

		$fields = [];
		foreach ( $form['fields'] as $field ) {
			// Collect choices for option-based field types
			$choices = [];
			if(in_array($field->type, $choice_types, true) && !empty($field->choices) && is_array($field->choices)){
				foreach($field->choices as $choice){
					if(isset($choice['value'])){
						$choices[] = [
							'value' => $choice['value'],
							'text' => $choice['text'] ?? $choice['value'],
						];
					}
				}
			}

			$fields[] = [
				'id'    => $field->id,
				'label' => $field->label,
				'type'  => $field->type,
				'choices' => $choices,
			];

			// Sub-fields (e.g. Name, Address)
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['label'] ) ) {
						$fields[] = [
							'id'    => $input['id'],
							'label' => $field->label . ' (' . $input['label'] . ')',
							'type'  => $field->type,
							'choice' => [], // sub-fields don't have their own choices
						];
					}
				}
			}
		}
		return $fields;
	}
}