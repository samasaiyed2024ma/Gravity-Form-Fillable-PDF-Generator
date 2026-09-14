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

		// Public PDF download link used inside notification emails (see
		// shortcode_pdf_link()). Registered for BOTH logged-in and logged-out
		// requests: the person opening the link from their email is almost
		// always a non-admin, non-logged-in site visitor, so this must not
		// require the plugin's admin capability the way the admin-only
		// view/download actions in class-admin-menu.php do. Without the
		// "nopriv" hook, WordPress' admin-ajax.php has no matching handler
		// for a logged-out request and simply prints "0" — which is exactly
		// the "opens a page that shows 0" symptom this fixes.
		add_action( 'wp_ajax_gffpdf_public_download',        [ $this, 'ajax_public_download' ] );
		add_action( 'wp_ajax_nopriv_gffpdf_public_download',  [ $this, 'ajax_public_download' ] );

		// Shortocde: [gffpdf feed_id="1" entry_id="{entry_id}"]
		add_shortcode( 'gffpdf', [ $this, 'shortcode_pdf_link' ] );
	}

	/* -----------------------------------------------------------------------
	 * GF form settings menu integration
	 * -------------------------------------------------------------------- */

	public function add_form_settings_menu( array $menu_items ): array {
		$menu_items[] = [
			'name'  => 'gffpdf',
			'label' => __( 'Fillable PDF', 'gf-fillable-pdf-generator' ),
		];
		return $menu_items;
	}

	public function render_form_settings_page(): void {
		if ( ! GFFPDF_Security::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'gf-fillable-pdf-generator' ) );
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
				'confirm_delete'    => __( 'Are you sure you want to delete this feed?', 'gf-fillable-pdf-generator' ),
				'confirm_duplicate' => __( 'Duplicate this feed?', 'gf-fillable-pdf-generator' ),
				'saving'            => __( 'Saving...', 'gf-fillable-pdf-generator' ),
				'saved'             => __( 'Feed saved.', 'gf-fillable-pdf-generator' ),
				'error'             => __( 'An error occurred. Please try again.', 'gf-fillable-pdf-generator' ),
				'uploading'         => __( 'Uploading PDF...', 'gf-fillable-pdf-generator' ),
				'upload_success'    => __( 'PDF uploaded successfully.', 'gf-fillable-pdf-generator' ),
				'no_fields'         => __( 'No fillable fields found in this PDF.', 'gf-fillable-pdf-generator' ),
				'uploading_font'    => __( 'Uploading font...',  'gf-fillable-pdf-generator'),
				'font_uploaded'     => __( 'Font uploaded.', 'gf-fillable-pdf-generator' ),
				'confirm_del_font'  => __( 'Delete this font', 'gf-fillable-pdf-generator' ),
				'add_rule' 			=> __( '+ Add Rule', 'gf-fillable-pdf-generator' ),
				'remove_rule'       => __( 'Remove', 'gf-fillable-pdf-generator' ), 
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified via GFFPDF_Security::check_ajax(), input sanitized manually or via JSON decode.
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
		$raw_settings              = isset( $_POST['feed_settings'] ) ? wp_unslash( (array) $_POST['feed_settings'] ) : [];
		$raw_settings['save_pdfs'] = $raw_settings['save_pdfs'] ?? 1;
		$raw_settings['attach_to_email']   = ! empty( $raw_settings['attach_to_email'] ) ? 1 : 0;
		$raw_settings['conditional_logic'] = $conditional_logic;
		$raw_settings['notification_ids'] = $notification_ids;

		$data = [
			'form_id'       => absint( $_POST['form_id'] ?? 0 ),
			'feed_name'     => sanitize_text_field( wp_unslash( $_POST['feed_name'] ?? '' ) ),
			'template_path' => sanitize_text_field( wp_unslash( $_POST['template_path'] ?? '' ) ),
			'mappings'      => $mappings,
			'is_active'     => absint( $_POST['is_active'] ?? 0 ),
			'settings'      => $raw_settings,
		];

		$feed_id = absint( $_POST['feed_id'] ?? 0 );
		// phpcs:enable

		if ( $feed_id ) {
			$result = self::update_feed( $feed_id, $data );
		} else {
			$result = self::create_feed( $data );
			$feed_id = $result;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'feed_id' => $feed_id, 'message' => __( 'Feed saved successfully.', 'gf-fillable-pdf-generator' ) ] );
	}

	public function ajax_delete_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id = absint( $_POST['feed_id'] ?? 0 );

		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed ID.', 'gf-fillable-pdf-generator' ) ] );
		}

		self::delete_feed( $feed_id );
		wp_send_json_success( [ 'message' => __( 'Feed deleted.', 'gf-fillable-pdf-generator' ) ] );
	}

	public function ajax_toggle_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id   = absint( $_POST['feed_id'] ?? 0 );
		$is_active = absint( $_POST['is_active'] ?? 0 );

		if ( ! $feed_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid feed ID.', 'gf-fillable-pdf-generator' ) ] );
		}

		self::toggle_feed( $feed_id, $is_active );
		wp_send_json_success();
	}

	public function ajax_duplicate_feed(): void {
		GFFPDF_Security::check_ajax();
		$feed_id = absint( $_POST['feed_id'] ?? 0 );
		$feed    = self::get_feed( $feed_id );

		if ( ! $feed ) {
			wp_send_json_error( [ 'message' => __( 'Feed not found.', 'gf-fillable-pdf-generator' ) ] );
		}

		$new_id = self::create_feed( [
			'form_id'       => $feed->form_id,
			'feed_name'     => $feed->feed_name . ' (' . __( 'Copy', 'gf-fillable-pdf-generator' ) . ')',
			'template_path' => $feed->template_path,
			'settings'      => json_decode( $feed->settings, true ) ?? [],
			'mappings'      => json_decode( $feed->mappings, true ) ?? [],
			'is_active'     => 0,
		] );

		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
		}

		wp_send_json_success( [ 'feed_id' => $new_id, 'message' => __( 'Feed duplicated.', 'gf-fillable-pdf-generator' ) ] );
	}

	public function ajax_upload_pdf(): void {
		GFFPDF_Security::check_ajax();

		if ( empty( $_FILES['pdf_file'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'gf-fillable-pdf-generator' ) ] );
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
			wp_send_json_error( [ 'message' => __( 'Feed not found.', 'gf-fillable-pdf-generator' ) ] );
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
			wp_send_json_error( ['message' => __( 'No font file uploaded', 'gf-fillable-pdf-generator' )] );
		}

		$label = sanitize_text_field( wp_unslash( $_POST['font_label'] ?? '' ) );
		$result = GFFPDF_Font_Manager::upload_font( $_FILES['font_file'], $label );

		if( is_wp_error($result) ){
			wp_send_json_error( ['message' => $result->get_error_message()] );
		}

		wp_send_json_success( [
			'family' => $result,
			'fonts' => GFFPDF_Font_Manager::get_all_fonts(),
			'message' => __( 'Font uploaded successfully.', 'gf-fillable-pdf-generator' ),
		] );
	}

	public function ajax_delete_font(): void{
		GFFPDF_Security::check_ajax();

		$family = sanitize_key( wp_unslash( $_POST['family'] ?? '' ) );
		if( !$family ){
			wp_send_json_error( ['message' => __( 'Invalid font.', 'gf-fillable-pdf-generator' )] );
		}

		GFFPDF_Font_Manager::delete_font( $family );
		wp_send_json_success( [
			'fonts' => GFFPDF_Font_Manager::get_all_fonts(),
			'message' => __( 'Font Deleted', 'gf-fillable-pdf-generator' ),
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
	 * Public (logged-out) PDF download link — used by the [gffpdf] shortcode
	 * so it can be dropped into a Gravity Forms notification email body and
	 * still work for the recipient, who is typically not logged into WP.
	 * -------------------------------------------------------------------- */

	/**
	 * Generate a signed, session-independent token for a given PDF record.
	 * Anyone holding pdf_id + this token can download that one PDF — no
	 * login required — which is what makes the link usable from an email.
	 * It never expires on its own; deleting the generated PDF (retention
	 * cleanup, "Save Generated PDFs" disabled, manual delete) is what ends
	 * access, same as the admin download links.
	 */
	private static function public_download_token( int $pdf_id, int $entry_id ): string {
		return substr( hash_hmac( 'sha256', $pdf_id . '|' . $entry_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	public function ajax_public_download(): void {
		$pdf_id = absint( $_GET['pdf_id'] ?? 0 );
		$token  = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );

		$record = GFFPDF_Entry_Handler::get_pdf_record( $pdf_id );
		if ( ! $record ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf-generator' ), 404 );
		}

		$expected = self::public_download_token( $pdf_id, (int) $record->entry_id );
		if ( ! $token || ! hash_equals( $expected, $token ) ) {
			wp_die( esc_html__( 'This link is invalid.', 'gf-fillable-pdf-generator' ), 403 );
		}

		// The file behind this record may have been removed by the retention
		// cleanup (see GFFPDF_File_Handler::run_scheduled_cleanup()) — that's
		// intentional, it's what keeps the generated/ directory from growing
		// unbounded on high-volume forms. Rather than dead-ending the visitor
		// here, rebuild the PDF on the spot from the original entry data,
		// template, and field mappings (all of which are kept indefinitely).
		// This way the server never has to hold onto every PDF forever, but
		// the download link itself never expires.
		if ( empty( $record->pdf_path ) || ! file_exists( $record->pdf_path ) || ! GFFPDF_Security::is_safe_path( $record->pdf_path ) ) {
			$fresh = $this->regenerate_for_download( $record );
			if ( ! $fresh ) {
				wp_die( esc_html__( 'This PDF is no longer available and could not be regenerated. Please contact the site owner.', 'gf-fillable-pdf-generator' ), 404 );
			}
			$record = $fresh;
		}

		GFFPDF_File_Handler::download_pdf( $record->pdf_path, basename( $record->pdf_path ) );
	}

	/**
	 * Re-run PDF generation for a record whose file has since been deleted
	 * (retention cleanup, "Save Generated PDFs" toggled off after the fact,
	 * manual deletion, etc). Writes a brand new file + DB row rather than
	 * touching the old one, so this stays consistent with how the daily
	 * cleanup already handles missing files (clears pdf_path, keeps history).
	 *
	 * @return object|null Fresh record with a valid pdf_path, or null if the
	 *                      entry/feed/template no longer exist to rebuild from.
	 */
	private function regenerate_for_download( object $record ): ?object {
		$feed = self::get_feed( (int) $record->feed_id );
		if ( ! $feed ) {
			return null;
		}

		$entry = GFAPI::get_entry( (int) $record->entry_id );
		if ( is_wp_error( $entry ) ) {
			return null;
		}

		$form = GFAPI::get_form( (int) $record->form_id );
		if ( ! $form ) {
			return null;
		}

		$handler = new GFFPDF_Entry_Handler();
		$result  = $handler->process_feed( $feed, $entry, $form );

		if ( is_wp_error( $result ) ) {
			GFFPDF_Logger::error( 'On-demand PDF regeneration failed for public download link', [
				'pdf_id' => $record->id,
				'reason' => $result->get_error_message(),
			] );
			return null;
		}

		return GFFPDF_Entry_Handler::get_pdf_record( $result );
	}

	/**
	 * Look up an already-generated PDF record for an entry (optionally
	 * scoped to one feed), without generating anything. Used by the
	 * shortcode as its fast path before it falls back to on-demand
	 * generation in generate_pdf_for_shortcode().
	 */
	private static function find_pdf_record( int $entry_id, int $feed_id ): ?object {
		if( ! $feed_id ){
			$pdfs = GFFPDF_Entry_Handler::get_entry_pdfs( $entry_id );
			return $pdfs[0] ?? null;
		}

		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}gffpdf_entries WHERE entry_id = %d AND feed_id = %d ORDER BY generated_at DESC LIMIT 1",
				$entry_id,
				$feed_id
			)
		);
	}

	/**
	 * Generate the PDF for this entry (and, if given, this specific feed)
	 * from scratch, for use by the shortcode when no record exists yet —
	 * e.g. it's being expanded in a notification that isn't also flagged
	 * to "attach" the PDF, so nothing else has generated it before now.
	 *
	 * @return object|null Fresh PDF record, or null if there's no active
	 *                      feed/entry/form to generate from, or generation
	 *                      itself fails (already logged by process_feed()).
	 */
	private static function generate_pdf_for_shortcode( int $entry_id, int $feed_id ): ?object {
		$entry = GFAPI::get_entry( $entry_id );
		if( is_wp_error( $entry ) ){
			return null;
		}

		$form = GFAPI::get_form( $entry['form_id'] );
		if( ! $form ){
			return null;
		}

		if( $feed_id ){
			$feed = self::get_feed( $feed_id );
		}else{
			$feeds = self::get_active_feeds_by_form( (int) $form['id'] );
			$feed  = $feeds[0] ?? null;
		}

		if( ! $feed ){
			return null;
		}

		$handler = new GFFPDF_Entry_Handler();
		$result  = $handler->process_feed( $feed, $entry, $form );

		if( is_wp_error( $result ) ){
			return null;
		}

		return GFFPDF_Entry_Handler::get_pdf_record( $result );
	}

	/* -----------------------------------------------------------------------
	 * Shortcode: [gffpdf feed_id="1" entry_id="123" label="Download PDF"]
	 * -------------------------------------------------------------------- */
	public function shortcode_pdf_link(array $atts): string{
		$atts = shortcode_atts( [
			'feed_id' => 0,
			'entry_id' => 0,
			'label' => '',
			'class' => 'gffpdf-shortcode-link',
		], $atts, 'gffpdf' );

		$feed_id = absint( $atts['feed_id'] );
		$entry_id = absint( $atts['entry_id'] );
		$class = esc_attr( $atts['class'] );

		if( ! $entry_id ){
			return '';
		}

		$record = self::find_pdf_record( $entry_id, $feed_id );

		// Nothing generated yet — this is expected the first time the
		// shortcode is used in a notification that ISN'T also flagged to
		// "attach" the PDF: attaching is the only thing that otherwise
		// forces generation before gform_after_submission runs. Without
		// this fallback, the shortcode only ever worked by coincidence
		// (an attach-enabled notification, or a resend after the PDF had
		// already been generated by a prior request) rather than as a
		// standalone "give me a link" option. Generate it now instead.
		if( ! $record ){
			$record = self::generate_pdf_for_shortcode( $entry_id, $feed_id );
		}

		if( !$record || !file_exists( $record->pdf_path ) ){
			return '';
		}

		// Determine link label: use shortcode attribute if explicitly passed, otherwise use file name
        $filename = basename( $record->pdf_path );
        $label    = ! empty( $atts['label'] ) ? esc_html( $atts['label'] ) : esc_html( $filename );
		
		// A regular WP nonce (as used by the logged-in-only admin download
		// links) is tied to the browser session that created it and expires
		// within ~24 hours, so it can't be used here: this link is emailed
		// to a site visitor who is not logged into wp-admin at all, and may
		// open it days later from a different device. Instead we sign the
		// link with an HMAC token scoped to this specific PDF record, which
		// works for anyone holding the link without requiring a login or a
		// WP user session — verified in ajax_public_download().
		$token = self::public_download_token( (int) $record->id, (int) $record->entry_id );
		$download_url = add_query_arg( [
			'action' => 'gffpdf_public_download',
			'pdf_id' => $record->id,
			'token'  => $token,
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
			return new WP_Error( 'db_error', __( 'Could not save feed.', 'gf-fillable-pdf-generator' ) );
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
			
			$field_label = ! empty( $field->label ) ? $field->label : 'Field ' . $field->id;

			// 1. List Field Matrix Breakdown
			if ( $field->type === 'list' ) {
				$fields[] = [
					'id'      => (string) $field->id,
					'label'   => $field_label . ' (Combined / All Rows)',
					'type'    => $field->type,
					'choices' => [],
				];

				$columns = $field->choices ?? [];
				if ( empty( $columns ) && ! empty( $field->columns ) ) {
					$columns = $field->columns;
				}

				$max_rows = 10;
				if ( ! empty( $columns ) && is_array( $columns ) ) {
					for ( $r = 0; $r < $max_rows; $r++ ) {
						$row_num = $r + 1;
						foreach ( $columns as $c_idx => $col ) {
							$col_name = is_array( $col ) ? ( $col['text'] ?? $col['value'] ) : $col;
							$key = $field->id . '.row' . $r . '.col' . $c_idx;

							$fields[] = [
								'id'      => $key,
								'label'   => $field_label . " → Row {$row_num}: {$col_name}",
								'type'    => 'list_cell',
								'choices' => [],
							];
						}
					}
				} else {
					for ( $r = 0; $r < $max_rows; $r++ ) {
						$row_num = $r + 1;
						$key = $field->id . '.row' . $r;

						$fields[] = [
							'id'      => $key,
							'label'   => $field_label . " → Row {$row_num}",
							'type'    => 'list_cell',
							'choices' => [],
						];
					}
				}
				continue;
			}

			// 2. Radio, Checkbox, Select, & Consent Fields (With choices & sub-inputs)
			$has_choices = ! empty( $field->choices ) && is_array( $field->choices );
			$has_inputs  = ! empty( $field->inputs ) && is_array( $field->inputs );

			if ( in_array( $field->type, [ 'radio', 'checkbox', 'select', 'consent' ], true ) || $has_choices ) {
				// Main selected value
				$fields[] = [
					'id'      => (string) $field->id,
					'label'   => $field_label . ' (Selected Value)',
					'type'    => $field->type,
					'choices' => [],
				];

				// Add sub-inputs (for checkboxes where each option has its own ID like 15.1, 15.2)
				if ( $has_inputs ) {
					foreach ( $field->inputs as $input ) {
						if ( ! empty( $input['isHidden'] ) ) {
							continue;
						}
						$input_label = ! empty( $input['label'] ) ? $input['label'] : $input['id'];
						$fields[] = [
							'id'      => (string) $input['id'],
							'label'   => $field_label . ' (' . $input_label . ')',
							'type'    => $field->type,
							'choices' => [],
						];
					}
				}

				// Add choice-specific targets (e.g., 15:Yes, 15:No, 15:Disciplinary reasons?)
				if ( $has_choices ) {
					foreach ( $field->choices as $choice ) {
						$choice_val  = $choice['value'] ?? $choice['text'];
						$choice_text = $choice['text'] ?? $choice['value'];
						
						$fields[] = [
							'id'      => $field->id . ':' . $choice_val,
							'label'   => $field_label . ' → Choice: ' . $choice_text,
							'type'    => $field->type . '_choice',
							'choices' => [],
						];
					}
				}
				continue;
			}
			
			// 3. Complex/Composite Fields (Name, Address, etc.)
			if ( $has_inputs ) {
				$fields[] = [
					'id'      => (string) $field->id,
					'label'   => $field_label . ' (Full Value)',
					'type'    => $field->type,
					'choices' => [],
				];

				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['isHidden'] ) ) {
						continue;
					}
					$fields[] = [
						'id'      => (string) $input['id'],
						'label'   => $field_label . ' (' . ( $input['label'] ?? $input['id'] ) . ')',
						'type'    => $field->type,
						'choices' => [],
					];
				}
				continue;
			} 

			// 4. Standard Text / Date / Paragraph Fields
			$fields[] = [
				'id'      => (string) $field->id,
				'label'   => $field_label,
				'type'    => $field->type,
				'choices' => [],
			];
		}

		return $fields;
	}
}