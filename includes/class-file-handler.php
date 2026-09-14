<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_File_Handler {

	const TEMPLATES_DIR  = 'templates/';
	const GENERATED_DIR  = 'generated/';
	const TEMP_DIR       = 'temp/';

	/* -----------------------------------------------------------------------
	 * Template (uploaded PDF) management
	 * -------------------------------------------------------------------- */

	/**
	 * Handle a PDF template upload, validate, and store it.
	 *
	 * @param  array       $file  $_FILES entry
	 * @return string|WP_Error  Stored file path on success, WP_Error on failure
	 */
	public static function upload_template( array $file ) {
		// Validate
		$validation = GFFPDF_Security::validate_uploaded_pdf( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Ensure target dir exists
		$dest_dir = GFFPDF_UPLOAD_DIR . self::TEMPLATES_DIR;
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		// Build unique filename
		$original  = sanitize_file_name( $file['name'] );
		$base      = pathinfo( $original, PATHINFO_FILENAME );
		$dest_name = $base . '-' . uniqid() . '.pdf';
		$dest_path = $dest_dir . $dest_name;

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->move( $file['tmp_name'], $dest_path, true ) ) {
			return new WP_Error( 'upload_failed', esc_html__( 'Failed to move uploaded file.', 'gf-fillable-pdf-generator' ) );
		}

		GFFPDF_Logger::info( 'Template uploaded', [ 'path' => $dest_path ] );
		return $dest_path;
	}

	/**
	 * Delete a template file by path.
	 */
	public static function delete_template( string $path ): bool {
		if ( ! GFFPDF_Security::is_safe_path( $path ) ) {
			return false;
		}
		if ( file_exists( $path ) ) {
			return wp_delete_file( $path );
		}
		return false;
	}

	/**
	 * List all stored template files.
	 */
	public static function list_templates(): array {
		$dir   = GFFPDF_UPLOAD_DIR . self::TEMPLATES_DIR;
		$files = glob( $dir . '*.pdf' );
		return $files ?: [];
	}

	/* -----------------------------------------------------------------------
	 * Generated PDF management
	 * -------------------------------------------------------------------- */

	/**
	 * Save a generated PDF blob to the generated directory.
	 *
	 * @param  string $content    Raw PDF bytes
	 * @param  string $filename   Desired filename (without path)
	 * @return string|WP_Error    Full path on success
	 */
	public static function save_generated( string $content, string $filename ) {
		$dir = GFFPDF_UPLOAD_DIR . self::GENERATED_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename = GFFPDF_Helpers::ensure_pdf_extension( sanitize_file_name( $filename ) );
		$path     = $dir . $filename;

		// Avoid collisions
		$i = 1;
		while ( file_exists( $path ) ) {
			$path = $dir . pathinfo( $filename, PATHINFO_FILENAME ) . '-' . $i . '.pdf';
			$i++;
		}

		if ( file_put_contents( $path, $content ) === false ) {
			return new WP_Error( 'save_failed', esc_html__( 'Failed to save generated PDF.', 'gf-fillable-pdf-generator' ) );
		}

		GFFPDF_Logger::info( 'Generated PDF saved', [ 'path' => $path ] );
		return $path;
	}

	/**
	 * Write a generated PDF blob to a short-lived temp file, used when
	 * "Save Generated PDFs" is disabled but the PDF still needs to exist on
	 * disk momentarily (e.g. to be attached to an outgoing notification email).
	 * Callers are responsible for deleting the file once it's no longer needed.
	 */
	public static function save_temp( string $content, string $prefix = 'gffpdf-attach' ): string {
		$dir = GFFPDF_UPLOAD_DIR . self::TEMP_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$path = $dir . $prefix . '-' . uniqid( '', true ) . '.pdf';
		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * Get a public URL for a stored generated PDF.
	 */
	public static function get_pdf_url( string $path ): string {
		return GFFPDF_Helpers::path_to_url( $path );
	}

	/**
	 * Stream a generated PDF to the browser for inline viewing.
	 */
	public static function stream_pdf( string $path, string $filename = 'document.pdf' ): void {
		// Initialize WordPress Filesystem API
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

		if ( ! $wp_filesystem->exists( $path ) || ! GFFPDF_Security::is_safe_path( $path ) ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf-generator' ), 404 );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Outputting raw binary PDF stream; escaping would corrupt the file.
		echo $wp_filesystem->get_contents( $path );
		exit;
	}

	/**
	 * Force-download a generated PDF.
	 */
	public static function download_pdf( string $path, string $filename = 'document.pdf' ): void {
		// Initialize WordPress Filesystem API
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

		if ( ! $wp_filesystem->exists( $path ) || ! GFFPDF_Security::is_safe_path( $path ) ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf-generator' ), 404 );
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: must-revalidate' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Outputting raw binary PDF stream; escaping would corrupt the file.
		echo $wp_filesystem->get_contents( $path );
		exit;
	}

	/* -----------------------------------------------------------------------
	 * Temp file cleanup
	 * -------------------------------------------------------------------- */

	/**
	 * Delete all temp files older than the given number of hours.
	 */
	public static function cleanup_temp( int $hours = 24 ): void {
		$dir    = GFFPDF_UPLOAD_DIR . self::TEMP_DIR;
		$files  = glob( $dir . '*' );
		$cutoff = time() - ( $hours * HOUR_IN_SECONDS );

		if ( ! $files ) return;

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	/* -----------------------------------------------------------------------
	 * Scheduled retention cleanup for generated PDFs
	 * -------------------------------------------------------------------- */

	/**
	 * Runs daily (see GFFPDF_Loader / gffpdf_daily_cleanup). Prevents the
	 * generated/ directory from growing unbounded on high-volume sites:
	 * stray temp files are always swept, and generated PDFs older than the
	 * configured retention window have their file deleted. A retention of 0
	 * (the default) keeps every generated PDF forever, matching the plugin's
	 * previous behaviour.
	 *
	 * The DB record itself is kept (with pdf_path cleared) rather than
	 * deleted, so the entry's PDF history and "Regenerate PDF" button in
	 * Gravity Forms keep working — the entry meta box will simply show that
	 * file as no longer available and let the admin regenerate it on demand.
	 */
	public static function run_scheduled_cleanup(): void {
		// Safety net: catch any attachment-only temp files that somehow
		// survived their end-of-request cleanup (e.g. a fatal error mid-request).
		self::cleanup_temp( 24 );

		$settings = GFFPDF_Settings::get_settings();
		$days     = isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 0;

		if ( $days <= 0 ) {
			return; // Keep generated PDFs forever.
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'gffpdf_entries';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// The "pdf_path != ''" check skips rows already processed by a
		// previous run, so this doesn't keep rescanning the same old rows
		// forever once their file has been removed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch maintenance query, not user-facing.
		$old_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, pdf_path FROM {$table} WHERE generated_at < %s AND pdf_path != ''",
			$cutoff
		) );

		if ( empty( $old_rows ) ) {
			return;
		}

		$deleted = 0;
		foreach ( $old_rows as $row ) {
			if ( ! empty( $row->pdf_path ) && GFFPDF_Security::is_safe_path( $row->pdf_path ) && file_exists( $row->pdf_path ) ) {
				wp_delete_file( $row->pdf_path );
			}
			// Clear the path rather than deleting the row: keeps the entry's
			// "PDF generated on <date>" history intact and lets the existing
			// "File missing" UI + Regenerate button do their job.
			$wpdb->update( $table, [ 'pdf_path' => '' ], [ 'id' => $row->id ], [ '%s' ], [ '%d' ] );
			$deleted++;
		}

		GFFPDF_Logger::info( 'Scheduled cleanup removed old generated PDF files', [
			'count'          => $deleted,
			'retention_days' => $days,
		] );
	}
}