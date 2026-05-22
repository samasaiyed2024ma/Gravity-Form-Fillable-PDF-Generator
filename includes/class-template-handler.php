<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages PDF template uploads and their associated metadata stored in
 * the wp_gffpdf_feeds table (via feed settings).
 */
class GFFPDF_Template_Handler {

	/* -----------------------------------------------------------------------
	 * Upload & extract fields
	 * -------------------------------------------------------------------- */

	/**
	 * Full upload pipeline: validate → store → extract fields.
	 *
	 * @param  array $file  Entry from $_FILES
	 * @return array|WP_Error  [ 'path' => string, 'fields' => array ]
	 */
	public function handle_upload( array $file ) {
		// 1. Store the file
		$path = GFFPDF_File_Handler::upload_template( $file );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// 2. Extract fields
		$extractor = new GFFPDF_PDF_Field_Extractor();
		$fields    = $extractor->extract( $path );

		if ( is_wp_error( $fields ) ) {
			// Clean up — no point keeping an unusable template
			GFFPDF_File_Handler::delete_template( $path );
			return $fields;
		}

		return [
			'path'   => $path,
			'fields' => $fields,
		];
	}

	/**
	 * Re-extract fields from an already-stored template.
	 *
	 * @param  string       $path  Absolute path
	 * @return array|WP_Error
	 */
	public function get_fields( string $path ) {
		$extractor = new GFFPDF_PDF_Field_Extractor();
		return $extractor->extract( $path );
	}

	/* -----------------------------------------------------------------------
	 * Template file helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Return an array of stored template files with metadata.
	 */
	public function list_templates(): array {
		$files   = GFFPDF_File_Handler::list_templates();
		$results = [];
		foreach ( $files as $file ) {
			$results[] = [
				'path'     => $file,
				'name'     => basename( $file ),
				'size'     => GFFPDF_Helpers::format_bytes( filesize( $file ) ),
				'modified' => date_i18n( get_option( 'date_format' ), filemtime( $file ) ),
				'url'      => GFFPDF_File_Handler::get_pdf_url( $file ),
			];
		}
		return $results;
	}

	/**
	 * Delete a template and update any feeds that reference it.
	 */
	public function delete_template( string $path ): bool {
		global $wpdb;

		$deleted = GFFPDF_File_Handler::delete_template( $path );

		if ( $deleted ) {
			// Nullify feeds referencing this template
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}gffpdf_feeds SET template_path = '', is_active = 0 WHERE template_path = %s",
				$path
			) );
			GFFPDF_Logger::info( 'Template deleted', [ 'path' => $path ] );
		}

		return $deleted;
	}
}