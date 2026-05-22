<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Helpers {

	/**
	 * Resolve filename pattern tags.
	 *
	 * Supports: {entry_id}, {form_id}, {date}, {field:N}
	 */
	public static function resolve_filename( string $pattern, array $entry, array $form ): string {
		$date     = date( 'Y-m-d' );
		$entry_id = isset( $entry['id'] ) ? $entry['id'] : '0';
		$form_id  = isset( $form['id'] )  ? $form['id']  : '0';

		$filename = str_replace(
			[ '{entry_id}', '{form_id}', '{date}' ],
			[ $entry_id,    $form_id,    $date    ],
			$pattern
		);

		// {field:N} replacements
		$filename = preg_replace_callback( '/\{field:(\d+)\}/', function( $m ) use ( $entry ) {
			$val = isset( $entry[ $m[1] ] ) ? $entry[ $m[1] ] : '';
			return sanitize_file_name( $val );
		}, $filename );

		// Sanitise the result
		$filename = sanitize_file_name( $filename );
		if ( empty( $filename ) ) {
			$filename = 'submission-' . $entry_id . '-' . $date;
		}

		return $filename;
	}

	/**
	 * Ensure a filename ends with .pdf
	 */
	public static function ensure_pdf_extension( string $filename ): string {
		if ( strtolower( substr( $filename, -4 ) ) !== '.pdf' ) {
			$filename .= '.pdf';
		}
		return $filename;
	}

	/**
	 * Recursively delete a directory.
	 */
	public static function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir( $path ) ? self::delete_directory( $path ) : unlink( $path );
		}
		return rmdir( $dir );
	}

	/**
	 * Convert an absolute file path to a URL.
	 */
	public static function path_to_url( string $path ): string {
		$upload_dir = wp_upload_dir();
		return str_replace(
			$upload_dir['basedir'],
			$upload_dir['baseurl'],
			$path
		);
	}

	/**
	 * Get a safe temp file path.
	 */
	public static function temp_path( string $suffix = '.pdf' ): string {
		return GFFPDF_UPLOAD_DIR . 'temp/' . uniqid( 'gffpdf_', true ) . $suffix;
	}

	/**
	 * Return human-readable file size.
	 */
	public static function format_bytes( int $bytes ): string {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		for ( $i = 0; $bytes >= 1024 && $i < 3; $i++ ) {
			$bytes /= 1024;
		}
		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	/**
	 * Normalise a PDF field name for comparison (lowercase, underscores).
	 */
	public static function normalise_field_name( string $name ): string {
		$name = strtolower( $name );
		$name = preg_replace( '/[\s\-]+/', '_', $name );
		$name = preg_replace( '/[^a-z0-9_]/', '', $name );
		return trim( $name, '_' );
	}

	/**
	 * Attempt fuzzy auto-mapping between a PDF field name and GF field labels.
	 * Returns the best-matching GF field ID or 0.
	 */
	public static function auto_map( string $pdf_field_name, array $gf_fields ): int {
		$normalised_pdf = self::normalise_field_name( $pdf_field_name );

		$common_map = [
			'first_name'  => [ 'first_name', 'firstname', 'first' ],
			'last_name'   => [ 'last_name', 'lastname', 'last', 'surname' ],
			'email'       => [ 'email', 'email_address', 'emailaddress' ],
			'phone'       => [ 'phone', 'telephone', 'tel', 'mobile', 'cell' ],
			'address'     => [ 'address', 'street', 'addr' ],
			'city'        => [ 'city', 'town' ],
			'state'       => [ 'state', 'province', 'region' ],
			'zip'         => [ 'zip', 'postal', 'postcode', 'zip_code' ],
			'country'     => [ 'country' ],
			'company'     => [ 'company', 'organization', 'organisation', 'employer' ],
			'name'        => [ 'name', 'full_name', 'fullname' ],
			'date'        => [ 'date', 'dob', 'birthdate', 'birth_date' ],
			'signature'   => [ 'signature', 'sig' ],
		];

		foreach ( $gf_fields as $field ) {
			$field_label = self::normalise_field_name( $field['label'] );
			$field_id    = (int) $field['id'];

			// Exact normalised match on label
			if ( $field_label === $normalised_pdf ) {
				return $field_id;
			}

			// Common synonym matching
			foreach ( $common_map as $canonical => $synonyms ) {
				if ( in_array( $normalised_pdf, $synonyms, true ) && in_array( $field_label, $synonyms, true ) ) {
					return $field_id;
				}
			}
		}

		return 0;
	}
}