<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Helpers {

	/**
	 * Resolve filename pattern tags.
	 *
	 * Supports: {entry_id}, {form_id}, {date}, {field:N}
	 */
	public static function resolve_filename( string $pattern, array $entry, array $form ): string {
		$date     = gmdate( 'Y-m-d' );
		$entry_id = isset( $entry['id'] ) ? $entry['id'] : '0';
		$form_id  = isset( $form['id'] )  ? $form['id']  : '0';

		$filename = str_replace(
			[ '{entry_id}', '{form_id}', '{date}' ],
			[ $entry_id,    $form_id,    $date    ],
			$pattern
		);

		// {field:N} replacements
		// $filename = preg_replace_callback( '/\{field:(\d+)\}/', function( $m ) use ( $entry ) {
		// 	$val = isset( $entry[ $m[1] ] ) ? $entry[ $m[1] ] : '';
		// 	return sanitize_file_name( $val );
		// }, $filename );

		// Update \d+ to [\d.]+ to support sub-field IDs like 1.3 or 1.6
$filename = preg_replace_callback( '/\{field:([\d.]+)\}/', function( $m ) use ( $entry ) {
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
	// Initialize WordPress Filesystem API
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! $wp_filesystem->exists( $dir ) || ! $wp_filesystem->is_dir( $dir ) ) {
            return false;
        }

        // Deletes the directory and all of its contents recursively
        return (bool) $wp_filesystem->delete( $dir, true );
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
	 * Resolve a value coming from a Gravity Forms field (typically a Signature
	 * or File Upload field) to an absolute, on-disk file path.
	 *
	 * Accepts:
	 *  - A full URL under this site's uploads directory (the normal case for
	 *    Signature / File Upload fields, e.g. https://site.com/wp-content/uploads/gravity_forms/...png)
	 *  - A protocol-relative URL (//site.com/...)
	 *  - An already-absolute server path
	 *
	 * Returns null if the value can't be resolved to an existing, readable file.
	 */
	public static function url_to_path( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}

		// The Gravity Forms Signature Add-On does NOT store a URL or path in
		// the entry for a signature field — just the bare generated filename
		// (e.g. "6aa77c021392c6.41726212.png"). It always writes signature
		// files to a single fixed folder — wp-content/uploads/gravity_forms/
		// signatures/ (flat, not per-form like regular file uploads) — so we
		// can build the on-disk path directly from that known convention.
		//
		// Deliberately NOT using gf_signature()->get_signature_url(): on
		// Signature Add-On 4.0+ that returns a permission-gated, token/query-
		// string URL (see GF_Signature_Image::get_url()) meant for browser
		// access checks, not a plain static file URL — running it through the
		// path matching below produced an empty match (the warning path
		// "/var/www/html/" with nothing after it) because the real filename
		// lives in the query string, which gets stripped before matching.
		if ( strpos( $value, '/' ) === false && preg_match( '#\.(png|jpe?g|gif)$#i', $value ) ) {
			$upload_dir     = wp_upload_dir();
			$signature_path = trailingslashit( $upload_dir['basedir'] ) . 'gravity_forms/signatures/' . $value;
			if ( file_exists( $signature_path ) && is_readable( $signature_path ) ) {
				return $signature_path;
			}
		}

		// Already an absolute local path.
		if ( file_exists( $value ) && ! preg_match( '#^(https?:)?//#i', $value ) ) {
			return is_readable( $value ) ? $value : null;
		}

		// Protocol-relative URL — assume same scheme as the site.
		if ( strpos( $value, '//' ) === 0 ) {
			$value = ( is_ssl() ? 'https:' : 'http:' ) . $value;
		}

		// Strip query string/fragment and decode %20 etc. BEFORE matching,
		// so a signature URL like ".../signature.png?ver=123" or one with
		// encoded spaces still lines up with the on-disk filename.
		$value = rawurldecode( strtok( $value, '?#' ) );

		$upload_dir = wp_upload_dir();
		$candidates = [];

		if ( strpos( $value, $upload_dir['baseurl'] ) === 0 ) {
			$candidates[] = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $value );
		}

		// Fall back to swapping the site's home URL for ABSPATH, in case the
		// file lives outside the standard uploads directory (rare, but seen
		// with some migrated/multisite installs).
		$home = untrailingslashit( home_url() );
		if ( strpos( $value, $home ) === 0 ) {
			$candidates[] = ABSPATH . ltrim( substr( $value, strlen( $home ) ), '/' );
		}

		// Domain-agnostic fallback: match on the path *relative to*
		// "wp-content/uploads/" wherever it appears in the value. This is
		// what makes signature resolution survive the common real-world
		// mismatches the two checks above miss entirely — a CDN/offloaded-
		// media hostname, an http vs https scheme difference between when
		// the signature was captured and now, the entry being regenerated
		// from a staging URL that differs from the current site URL, or
		// (unlike the checks above) a root-relative path with no scheme or
		// host at all, e.g. "/wp-content/uploads/gravity_forms/...png" —
		// which never matched here before since this whole method returned
		// early unless the value already started with "http(s)://".
		if ( preg_match( '#wp-content/uploads/(.+)$#i', $value, $m ) ) {
			$candidates[] = trailingslashit( $upload_dir['basedir'] ) . ltrim( $m[1], '/' );
		}

		foreach ( $candidates as $path ) {
			if ( $path && file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
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