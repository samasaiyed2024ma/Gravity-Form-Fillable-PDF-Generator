<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Manages custom font uploads and the built-in font library.
 */
class GFFPDF_Font_Manager {

	/** Directory where custom font files are stored */
	private static string $font_dir = '';

	/** Built-in TCPDF font families (core + extended) */
	public static function get_builtin_fonts(): array {
		return [
			// Core
			'helvetica'       => 'Helvetica',
			'helveticaB'      => 'Helvetica Bold',
			'helveticaI'      => 'Helvetica Italic',
			'helveticaBI'     => 'Helvetica Bold Italic',
			'courier'         => 'Courier',
			'courierB'        => 'Courier Bold',
			'courierI'        => 'Courier Italic',
			'courierBI'       => 'Courier Bold Italic',
			'times'           => 'Times New Roman',
			'timesB'          => 'Times New Roman Bold',
			'timesI'          => 'Times New Roman Italic',
			'timesBI'         => 'Times New Roman Bold Italic',
			'symbol'          => 'Symbol',
			'zapfdingbats'    => 'ZapfDingbats',
			// Unicode / multi-language
			'dejavusans'      => 'DejaVu Sans (Unicode)',
			'dejavusansb'     => 'DejaVu Sans Bold',
			'dejavusansi'     => 'DejaVu Sans Italic',
			'dejavusansmono'  => 'DejaVu Sans Mono',
			'dejavuserif'     => 'DejaVu Serif',
			'dejavuserifb'    => 'DejaVu Serif Bold',
			'freesans'        => 'Free Sans',
			'freeserif'       => 'Free Serif',
			'freemono'        => 'Free Mono',
			'pdfa'            => 'PDF/A (Helvetica alias)',
		];
	}

	/* -----------------------------------------------------------------------
	 * Custom font storage
	 * -------------------------------------------------------------------- */

	public static function get_font_dir(): string {
		if ( ! self::$font_dir ) {
			self::$font_dir = GFFPDF_UPLOAD_DIR . 'fonts/';
		}
		return self::$font_dir;
	}

	/**
	 * Return all custom uploaded fonts as [ 'family' => 'Label', ... ]
	 */
	public static function get_custom_fonts(): array {
		$fonts = get_option( 'gffpdf_custom_fonts', [] );
		return is_array( $fonts ) ? $fonts : [];
	}

	/**
	 * Returns merged builtin + custom fonts for UI dropdowns.
	 */
	public static function get_all_fonts(): array {
		return array_merge( self::get_builtin_fonts(), self::get_custom_fonts() );
	}

	/**
	 * Upload and register a custom font (.ttf or .otf).
	 *
	 * @param array  $file   $_FILES entry
	 * @param string $label  Human-readable display name
	 * @return string|WP_Error  TCPDF font family key on success
	 */
	public static function upload_font( array $file, string $label ): string|WP_Error {
		$allowed = [ 'ttf', 'otf' ];
		$ext     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'invalid_type', __( 'Only .ttf and .otf font files are accepted.', 'gf-fillable-pdf' ) );
		}

		$dir = self::get_font_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Derive a safe TCPDF-compatible family key (lowercase, no spaces)
		$family = strtolower( sanitize_key( pathinfo( $file['name'], PATHINFO_FILENAME ) ) );
		if ( ! $family ) {
			return new WP_Error( 'invalid_name', __( 'Could not derive a font name from the file.', 'gf-fillable-pdf' ) );
		}

		// Copy the raw font file
		$dest = $dir . $family . '.' . $ext;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'upload_failed', __( 'Could not save font file.', 'gf-fillable-pdf' ) );
		}

		// Convert to TCPDF font definition using TCPDF's built-in converter
		if ( class_exists( 'TCPDF_FONTS' ) ) {
			$tcpdf_font_path = TCPDF_FONTS::addTTFfont( $dest, 'TrueTypeUnicode', '', 96 );
			if ( $tcpdf_font_path ) {
				// Register in options
				$fonts          = self::get_custom_fonts();
				$fonts[ $family ] = $label ?: $family;
				update_option( 'gffpdf_custom_fonts', $fonts );
				return $family;
			}
		}

		// Fallback: just register the family so it can be referenced directly
		$fonts          = self::get_custom_fonts();
		$fonts[ $family ] = $label ?: $family;
		update_option( 'gffpdf_custom_fonts', $fonts );
		return $family;
	}

	/**
	 * Delete a custom font by family key.
	 */
	public static function delete_font( string $family ): bool {
		$fonts = self::get_custom_fonts();
		if ( ! isset( $fonts[ $family ] ) ) {
			return false;
		}
		unset( $fonts[ $family ] );
		update_option( 'gffpdf_custom_fonts', $fonts );

		// Remove font files
		$dir = self::get_font_dir();
		foreach ( [ 'ttf', 'otf', 'php', 'z' ] as $ext ) {
			$file = $dir . $family . '.' . $ext;
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		return true;
	}
}