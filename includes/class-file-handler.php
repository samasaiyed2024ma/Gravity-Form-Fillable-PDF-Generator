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

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return new WP_Error( 'upload_failed', esc_html__( 'Failed to move uploaded file.', 'gf-fillable-pdf' ) );
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
			return unlink( $path );
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
			return new WP_Error( 'save_failed', esc_html__( 'Failed to save generated PDF.', 'gf-fillable-pdf' ) );
		}

		GFFPDF_Logger::info( 'Generated PDF saved', [ 'path' => $path ] );
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
		if ( ! file_exists( $path ) || ! GFFPDF_Security::is_safe_path( $path ) ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf' ), 404 );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		readfile( $path );
		exit;
	}

	/**
	 * Force-download a generated PDF.
	 */
	public static function download_pdf( string $path, string $filename = 'document.pdf' ): void {
		if ( ! file_exists( $path ) || ! GFFPDF_Security::is_safe_path( $path ) ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf' ), 404 );
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: must-revalidate' );
		readfile( $path );
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
				unlink( $file );
			}
		}
	}
}