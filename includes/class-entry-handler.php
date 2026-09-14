<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Listens for Gravity Forms submission events and triggers PDF generation.
 */
class GFFPDF_Entry_Handler {

	/**
	 * Per-request cache so a feed's PDF is only ever rendered once even
	 * though it may be needed both for a notification attachment (which
	 * Gravity Forms sends BEFORE gform_after_submission fires) and for the
	 * normal after-submission save/record step.
	 *
	 * Keyed by "{entry_id}:{feed_id}" => [
	 *     'path'      => string|null   Absolute file path (permanent or temp),
	 *     'is_temp'   => bool          True if $path lives in the temp dir and
	 *                                  must be deleted once the request ends,
	 *     'record_id' => int|null      DB record id, once persisted,
	 *     'error'     => WP_Error|null,
	 * ]
	 */
	private static array $pdf_cache = [];

	/** Temp attachment-only files created during this request, cleaned up on shutdown. */
	private static array $temp_files = [];

	public function __construct() {
		add_action( 'gform_after_submission', [ $this, 'generate_pdf' ], 10, 2 );
		add_action( 'wp_ajax_gffpdf_regenerate', [ $this, 'ajax_regenerate' ] );

		// IMPORTANT: registered unconditionally, not from inside gform_after_submission.
		// Gravity Forms sends all notification emails BEFORE gform_after_submission
		// fires (notifications are sent as part of the core submission process;
		// gform_after_submission fires "after form validation, notification, and
		// entry creation"). A filter added only once gform_after_submission runs
		// is therefore always too late to affect the emails that already went out —
		// this was the reason PDFs never showed up as attachments.
		add_filter( 'gform_notification', [ $this, 'maybe_attach_pdf_to_notification' ], 10, 3 );

		// Expand the [gffpdf] shortcode inside the outgoing email body.
		// NOTE: this must NOT be done from the 'gform_notification' filter.
		// Gravity Forms fires 'gform_notification' *before* it substitutes
		// merge tags (e.g. {entry_id}) into the message — replace_variables()
		// runs afterwards, inside send_notification(). A do_shortcode() call
		// hooked to 'gform_notification' therefore sees the literal,
		// unresolved text entry_id="{entry_id}", which absint()s to 0, so the
		// shortcode function returns '' (its "no valid entry" case) — the
		// link silently disappears instead of rendering.
		// 'gform_pre_send_email' fires immediately before wp_mail(), once the
		// subject/message have already had every merge tag substituted, which
		// is what the shortcode's entry_id="{entry_id}" attribute needs to
		// resolve to a real ID.
		add_filter( 'gform_pre_send_email', [ $this, 'process_shortcodes_in_email' ], 10, 4 );

		// Delete any attachment-only temp PDFs created during this request
		// (used when "Save Generated PDFs" is disabled) once Gravity Forms
		// has finished sending notifications and gform_after_submission has run.
		add_action( 'shutdown', [ __CLASS__, 'cleanup_temp_attachments' ] );
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
	 * Process a single feed for a given entry — generates (or reuses an
	 * already-generated) PDF, persists it if enabled, and stores a DB record.
	 *
	 * @return int|WP_Error  DB record ID on success, WP_Error describing the failure.
	 */
	public function process_feed( object $feed, array $entry, array $form, bool $force_regenerate = false ) {
		$feed_id = (int) $feed->id;
		$result  = $this->resolve_pdf( $feed, $entry, $form, $force_regenerate );

		if ( is_wp_error( $result['error'] ?? null ) ) {
			return $result['error'];
		}

		if ( $result['is_temp'] ) {
			// Global "Save Generated PDFs" is disabled: the file exists only
			// as a transient attachment copy (already sent, if configured, via
			// maybe_attach_pdf_to_notification()) and is not kept or recorded.
			return new WP_Error( 'save_disabled',
				sprintf( 'Feed "%s": PDF saving is disabled in global settings.', $feed->feed_name )
			);
		}

		GFFPDF_Logger::info( 'PDF generated', [
			'entry_id' => $entry['id'],
			'feed_id'  => $feed_id,
			'path'     => $result['path'],
		] );

		return $result['record_id'];
	}

	/**
	 * Validate a feed against an entry/form, generate its PDF if needed, and
	 * either persist it permanently or write it to a temp file — caching the
	 * outcome per (entry, feed) so this only ever runs once per request no
	 * matter how many places ask for it (notification attachment + the normal
	 * after-submission save both go through here).
	 *
	 * @return array{path:?string,is_temp:bool,record_id:?int,error:?WP_Error}
	 */
	private function resolve_pdf( object $feed, array $entry, array $form, bool $force_regenerate = false ): array {
		$feed_id  = (int) $feed->id;
		$entry_id = (int) ( $entry['id'] ?? 0 );
		$cache_key = $entry_id . ':' . $feed_id;

		if ( ! $force_regenerate && isset( self::$pdf_cache[ $cache_key ] ) ) {
			return self::$pdf_cache[ $cache_key ];
		}

		$result = $this->generate_and_store_pdf( $feed, $entry, $form, $force_regenerate );
		self::$pdf_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * @return array{path:?string,is_temp:bool,record_id:?int,error:?WP_Error}
	 */
	private function generate_and_store_pdf( object $feed, array $entry, array $form, bool $force_regenerate = false ): array {
		$empty_result = [ 'path' => null, 'is_temp' => false, 'record_id' => null, 'error' => null ];

		$feed_id  = (int) $feed->id;
		$entry_id = (int) ( $entry['id'] ?? 0 );

		// Reuse an already-generated, still-on-disk PDF for this entry+feed
		// instead of generating a fresh one. Without this, every time an
		// admin uses Gravity Forms' "Resend Notifications" action (which
		// re-fires the same notification-send code path this plugin hooks
		// into), a brand new duplicate PDF file + DB row was created for the
		// exact same submission — defeating the point of the retention
		// cleanup and quietly filling the generated/ folder back up. This
		// only short-circuits when a valid file still exists; if it was
		// already cleaned up, generation proceeds normally below. The
		// explicit "Regenerate PDF" admin action passes $force_regenerate to
		// deliberately skip this and rebuild from current entry data instead.
		if ( ! $force_regenerate ) {
			$existing = self::find_existing_pdf( $entry_id, $feed_id );
			if ( $existing ) {
				return [ 'path' => $existing->pdf_path, 'is_temp' => false, 'record_id' => (int) $existing->id, 'error' => null ];
			}
		}

		$template_path = $feed->template_path;
		$settings      = json_decode( $feed->settings, true ) ?? [];
		$mappings      = json_decode( $feed->mappings, true ) ?? [];

		// --- Template check ---
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			GFFPDF_Logger::error( 'Template file missing', [ 'feed_id' => $feed_id, 'path' => $template_path ] );
			return array_merge( $empty_result, [ 'error' => new WP_Error( 'template_missing',
				// translators: %s: The name of the feed config.
				sprintf( __( 'Feed "%s": PDF template file is missing or has not been uploaded.', 'gf-fillable-pdf-generator' ), $feed->feed_name )
			) ] );
		}

		// --- Mappings check (filter out intentionally unmapped "0"/empty values) ---
		$active_mappings = array_filter( $mappings, function( $gf_field_id ) {
			return $gf_field_id !== '' && $gf_field_id !== '0' && $gf_field_id !== 0;
		} );

		if ( empty( $active_mappings ) ) {
			GFFPDF_Logger::warn( 'No field mappings configured', [ 'feed_id' => $feed_id ] );
			return array_merge( $empty_result, [ 'error' => new WP_Error( 'no_mappings',
				// translators: %s: The name of the feed config.
				sprintf( __( 'Feed "%s": no field mappings configured — please map at least one PDF field to a form field.', 'gf-fillable-pdf-generator' ), $feed->feed_name )
			) ] );
		}

		// --- Conditional logic ---
		if ( ! $this->passes_conditional_logic( $settings, $entry, $form ) ) {
			GFFPDF_Logger::info( 'Feed skipped: conditional logic', [ 'feed_id' => $feed_id, 'entry_id' => $entry['id'] ] );
			return array_merge( $empty_result, [ 'error' => new WP_Error( 'conditional_logic',
				// translators: %s: The name of the feed config.
				sprintf( __( 'Feed "%s": skipped — conditional logic rules not met for this entry.', 'gf-fillable-pdf-generator' ), $feed->feed_name )
			) ] );
		}

		// --- Build field values & generate ---
		$field_values = $this->build_field_values( $active_mappings, $entry, $form );

		$global_settings = GFFPDF_Settings::get_settings();

		// The global "RTL Support" toggle enables RTL handling plugin-wide;
		// a feed's own "Reverse Text" checkbox can also enable it individually.
		// Either one turns the capability on — but GFFPDF_PDF_Generator only
		// ever applies right-to-left rendering to values that actually contain
		// Arabic/Hebrew characters, so plain English/Latin text is never
		// affected no matter which of these is enabled.
		$settings['reverse_text'] = ! empty( $settings['reverse_text'] ) || ! empty( $global_settings['rtl_support'] );

		$generator = new GFFPDF_PDF_Generator();
		$pdf_bytes = $generator->generate( $template_path, $field_values, $settings );

		if ( is_wp_error( $pdf_bytes ) ) {
			GFFPDF_Logger::error( 'PDF generation failed', [
				'feed_id'  => $feed_id,
				'entry_id' => $entry['id'],
				'error'    => $pdf_bytes->get_error_message(),
			] );
			return array_merge( $empty_result, [ 'error' => new WP_Error(
				$pdf_bytes->get_error_code(),
				sprintf( 'Feed "%s": %s', $feed->feed_name, $pdf_bytes->get_error_message() )
			) ] );
		}

		$save = $global_settings['save_pdfs'] ?? true;
		$save = ! ( $save === false || $save === 0 || $save === '0' );

		if ( ! $save ) {
			// Not persisting to server storage — still write the bytes to a
			// short-lived temp file so email attachment can work, but it will
			// be deleted once the request ends (see cleanup_temp_attachments()).
			$path = GFFPDF_File_Handler::save_temp( $pdf_bytes );
			self::$temp_files[] = $path;
			return [ 'path' => $path, 'is_temp' => true, 'record_id' => null, 'error' => null ];
		}

		// --- Resolve filename & save permanently ---
		$pattern  = $settings['filename_pattern'] ?? $global_settings['filename_pattern'];
		$filename = GFFPDF_Helpers::resolve_filename( $pattern, $entry, $form );
		$filename = GFFPDF_Helpers::ensure_pdf_extension( $filename );

		$path = GFFPDF_File_Handler::save_generated( $pdf_bytes, $filename );
		if ( is_wp_error( $path ) ) {
			GFFPDF_Logger::error( 'PDF save failed', [ 'feed_id' => $feed_id, 'entry' => $entry['id'] ] );
			return array_merge( $empty_result, [ 'error' => new WP_Error(
				$path->get_error_code(),
				sprintf( 'Feed "%s": %s', $feed->feed_name, $path->get_error_message() )
			) ] );
		}

		$record_id = self::save_pdf_record( (int) $entry['id'], (int) $form['id'], $feed_id, $path );

		return [ 'path' => $path, 'is_temp' => false, 'record_id' => $record_id, 'error' => null ];
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

		$field_objects = [];
		if ( ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$id_str = (string) $field->id;
				$field_objects[ $id_str ] = $field;

				if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
					foreach ( $field->inputs as $input ) {
						$field_objects[ (string) $input['id'] ] = $field;
					}
				}
			}
		}

		foreach ( $mappings as $pdf_field => $gf_field_id ) {
			if ( $gf_field_id === '' || $gf_field_id === 0 || $gf_field_id === '0' ) {
				continue;
			}

			$gf_field_id_str = (string) $gf_field_id;

			// 1. Checkbox Choice Match Target (e.g. "4:Option A")
			if ( strpos( $gf_field_id_str, ':' ) !== false ) {
				list( $real_id, $target_choice ) = explode( ':', $gf_field_id_str, 2 );
				$submitted_val = rgar( $entry, $real_id );
				
				if ( empty( $submitted_val ) ) {
					foreach ( $entry as $k => $v ) {
						if ( strpos( (string) $k, $real_id . '.' ) === 0 && (string) $v === (string) $target_choice ) {
							$submitted_val = $v;
							break;
						}
					}
				}

				$values[ $pdf_field ] = ( (string) $submitted_val === (string) $target_choice ) ? 'Yes' : '';
				continue;
			}

			// 2. Specific List Field Cell Match (e.g. "12.row0.col1" or "12.row0")
			if ( preg_match( '/^(\d+)\.row(\d+)(?:\.col(\d+))?$/', $gf_field_id_str, $matches ) ) {
				$base_field_id = $matches[1];
				$row_index     = (int) $matches[2];
				$col_index     = isset( $matches[3] ) ? (int) $matches[3] : null;

				$list_raw  = rgar( $entry, $base_field_id );
				$list_data = maybe_unserialize( $list_raw );

				if ( is_array( $list_data ) && isset( $list_data[ $row_index ] ) ) {
					$row_data = $list_data[ $row_index ];

					if ( is_array( $row_data ) ) {
						if ( $col_index !== null ) {
							// Multi-column row cell: fetch specific column by key or index
							$col_keys = array_keys( $row_data );
							$target_key = $col_keys[ $col_index ] ?? $col_index;
							$values[ $pdf_field ] = (string) ( $row_data[ $target_key ] ?? '' );
						} else {
							// Single-column row: join values
							$values[ $pdf_field ] = implode( ' | ', array_filter( $row_data ) );
						}
					} else {
						$values[ $pdf_field ] = (string) $row_data;
					}
				} else {
					$values[ $pdf_field ] = '';
				}
				continue;
			}

			$field_obj  = $field_objects[ $gf_field_id_str ] ?? null;
			$field_type = $field_obj ? $field_obj->type : 'text';

			// 3. Parent Composite Field Handling (e.g. ID "10")
			$raw_value = rgar( $entry, $gf_field_id_str );
			
			if ( empty( $raw_value ) && $field_obj && ! empty( $field_obj->inputs ) && strpos( $gf_field_id_str, '.' ) === false ) {
				$sub_vals = [];
				foreach ( $field_obj->inputs as $input ) {
					$val = rgar( $entry, (string) $input['id'] );
					if ( ! empty( $val ) ) {
						$sub_vals[] = $val;
					}
				}
				$raw_value = implode( ' ', $sub_vals );
			}

			// 4. Format standard Field Types
			switch ( $field_type ) {
				case 'checkbox':
					$values[ $pdf_field ] = GFFPDF_PDF_Generator::normalise_checkbox( $raw_value );
					break;

				case 'list':
					$list_data = maybe_unserialize( $raw_value );
					if ( is_array( $list_data ) ) {
						$rows = [];
						foreach ( $list_data as $row ) {
							$rows[] = is_array( $row ) ? implode( ' | ', array_filter( $row ) ) : $row;
						}
						$values[ $pdf_field ] = implode( "\n", array_filter( $rows ) );
					} else {
						$values[ $pdf_field ] = (string) $raw_value;
					}
					break;

				case 'date':
					$values[ $pdf_field ] = ! empty( $raw_value )
						? date_i18n( get_option( 'date_format' ), strtotime( $raw_value ) )
						: '';
					break;

				case 'fileupload':
				case 'signature':
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

	/**
	 * Expand a manually-typed [gffpdf] shortcode inside the final, fully
	 * merge-tag-resolved email body into its actual download-link HTML.
	 *
	 * Gravity Forms never runs do_shortcode() on notification content itself
	 * — only WordPress' 'the_content' filter (post/page rendering) does that
	 * automatically. Without this, [gffpdf entry_id="{entry_id}"] pasted into
	 * a notification message is sent out verbatim as plain text. This must be
	 * hooked to 'gform_pre_send_email' rather than 'gform_notification' — see
	 * the note where it's registered in the constructor for why.
	 *
	 * @param array  $email           Keys include 'to', 'subject', 'message',
	 *                                'headers', 'attachments', etc.
	 * @param string $message_format  'html' or 'text'.
	 */
	public function process_shortcodes_in_email( $email, $message_format, $notification, $entry ) {
		if ( empty( $email['message'] ) || ! is_string( $email['message'] ) || strpos( $email['message'], '[gffpdf' ) === false ) {
			return $email;
		}

		$expanded = do_shortcode( $email['message'] );

		// Plain-text notifications would otherwise show the raw
		// <a href="...">label</a> markup verbatim; fall back to a bare URL.
		if ( 'text' === $message_format ) {
			$expanded = preg_replace( '/<a\s+href="([^"]+)"[^>]*>.*?<\/a>/i', '$1', $expanded );
		}

		$email['message'] = $expanded;
		return $email;
	}

	/**
	 * Attach generated PDFs to a Gravity Forms notification as it's being sent.
	 *
	 * Hooked unconditionally in the constructor (see the note there on why —
	 * in short, Gravity Forms sends notifications before gform_after_submission
	 * fires, so this can no longer be wired up lazily from inside that hook).
	 */
	public function maybe_attach_pdf_to_notification( $notification, $form, $entry ) {
		if ( empty( $form['id'] ) || empty( $entry['id'] ) ) {
			return $notification;
		}

		$feeds = GFFPDF_Feed_Settings::get_active_feeds_by_form( (int) $form['id'] );
		if ( empty( $feeds ) ) {
			return $notification;
		}

		$notification_id = (string) ( $notification['id'] ?? '' );

		foreach ( $feeds as $feed ) {
			$settings = json_decode( $feed->settings, true ) ?? [];
			$selected = $settings['notification_ids'] ?? [];

			// AFTER (Fixed fallback logic)
			$selected_ids = array_filter( array_map( 'strval', (array) $selected ) );

			if ( ! empty( $selected_ids ) ) {
				// Specific notifications are checked
				$wants_this_notification = in_array( (string) $notification_id, $selected_ids, true );
			} else {
				// Fallback: Attach to all notifications if attach_to_email is enabled
				$wants_this_notification = ! empty( $settings['attach_to_email'] );
			}

			if ( ! $wants_this_notification ) {
				GFFPDF_Logger::info( 'Notification attach skipped: not selected for this feed', [
					'feed_id'         => $feed->id,
					'entry_id'        => $entry['id'],
					'notification_id' => $notification_id,
					'selected'        => $selected,
				] );
				continue;
			}

			$result = $this->resolve_pdf( $feed, $entry, $form );
			if ( is_wp_error( $result['error'] ?? null ) || empty( $result['path'] ) ) {
				GFFPDF_Logger::warn( 'Notification attach skipped: PDF unavailable', [
					'feed_id'         => $feed->id,
					'entry_id'        => $entry['id'],
					'notification_id' => $notification_id,
					'error'           => is_wp_error( $result['error'] ?? null ) ? $result['error']->get_error_message() : null,
				] );
				continue; // Failure already logged inside generate_and_store_pdf().
			}

			if ( ! isset( $notification['attachments'] ) || ! is_array( $notification['attachments'] ) ) {
				$notification['attachments'] = [];
			}
			$notification['attachments'][] = $result['path'];

			GFFPDF_Logger::info( 'PDF attached to notification', [
				'feed_id'         => $feed->id,
				'entry_id'        => $entry['id'],
				'notification_id' => $notification_id,
				'path'            => $result['path'],
			] );
		}

		return $notification;
	}

	/**
	 * Delete any attachment-only temp PDFs created during this request.
	 * Runs on 'shutdown', i.e. after Gravity Forms has finished sending
	 * notifications and gform_after_submission has run, so it's always safe.
	 */
	public static function cleanup_temp_attachments(): void {
		foreach ( self::$temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::$temp_files = [];
	}

	/* -----------------------------------------------------------------------
	 * DB helpers (static)
	 * -------------------------------------------------------------------- */

	public static function save_pdf_record( int $entry_id, int $form_id, int $feed_id, string $pdf_path ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'gffpdf_entries';

		// Delete existing record for this entry and feed to prevent duplicates
		$wpdb->delete(
			$table,
			[
				'entry_id' => $entry_id,
				'feed_id'  => $feed_id,
			],
			[ '%d', '%d' ]
		);

		// Insert fresh record
		$wpdb->insert(
			$table,
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
		$table = $wpdb->prefix . 'gffpdf_entries';

		// Group by feed_id to return only 1 file per feed
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT e1.* 
			FROM {$table} e1
			INNER JOIN (
				SELECT MAX(id) as max_id 
				FROM {$table} 
				WHERE entry_id = %d 
				GROUP BY feed_id
			) e2 ON e1.id = e2.max_id
			ORDER BY e1.generated_at DESC",
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

	/**
	 * Look up the most recent PDF record for this entry+feed whose file is
	 * still actually present on disk. Used to avoid regenerating (and
	 * duplicating) a PDF that already exists — see generate_and_store_pdf().
	 */
	private static function find_existing_pdf( int $entry_id, int $feed_id ): ?object {
		if ( ! $entry_id || ! $feed_id ) {
			return null;
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_entries WHERE entry_id = %d AND feed_id = %d AND pdf_path != '' ORDER BY generated_at DESC LIMIT 1",
			$entry_id,
			$feed_id
		) );

		if ( $row && file_exists( $row->pdf_path ) && GFFPDF_Security::is_safe_path( $row->pdf_path ) ) {
			return $row;
		}

		return null;
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
			wp_send_json_error( [ 'message' => esc_html__( 'Invalid entry ID.', 'gf-fillable-pdf-generator' ) ], 400 );
			return;
		}

		if ( ! GFFPDF_Security::verify_nonce( $nonce, 'gffpdf_regenerate_' . $entry_id ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'gf-fillable-pdf-generator' ) ], 403 );
			return;
		}

		if ( ! GFFPDF_Security::current_user_can() ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'gf-fillable-pdf-generator' ) ], 403 );
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'Entry not found.', 'gf-fillable-pdf-generator' ) ], 400 );
			return;
		}

		$form  = GFAPI::get_form( $entry['form_id'] );
		$feeds = GFFPDF_Feed_Settings::get_active_feeds_by_form( $entry['form_id'] );

		if ( empty( $feeds ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => esc_html__( 'No active feeds found for this form. Please create and activate a feed first.', 'gf-fillable-pdf-generator' ) ], 400 );
			return;
		}

		$generated = 0;
		$errors    = [];

		foreach ( $feeds as $feed ) {
			// Explicit admin-triggered regeneration must always rebuild from
			// the entry's current data (e.g. after fixing a mapping or after
			// the file was manually deleted) rather than being short-circuited
			// by the "reuse existing PDF" optimisation used for automatic
			// notification sends/resends.
			$result = $this->process_feed( $feed, $entry, $form, true );

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
			wp_send_json_error( [ 'message' => esc_html__( 'All feeds were skipped — conditional logic rules were not met for this entry.', 'gf-fillable-pdf-generator' ) ], 400 );
			return;
		}

		if ( $generated === 0 && ! empty( $errors ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => implode( "\n", $errors ) ], 400 );
			return;
		}

		$success_msg = sprintf(
			// translators: %d: The number of PDFs generated.
			_n( '%d PDF generated successfully.', '%d PDFs generated successfully.', $generated, 'gf-fillable-pdf-generator' ),
			$generated
		);

		if ( ! empty( $errors ) ) {
			$success_msg .= ' ' . esc_html__( 'Warning:', 'gf-fillable-pdf-generator' ) . ' ' . implode( ' | ', $errors );
		}

		ob_end_clean();
		wp_send_json_success( [
			'message'   => $success_msg,
			'generated' => $generated,
		] );
	}
}