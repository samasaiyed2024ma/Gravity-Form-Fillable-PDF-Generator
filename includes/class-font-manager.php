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
	 * Variable fonts (those containing an 'fvar' OpenType table) are rejected
	 * up-front because TCPDF's addTTFfont() cannot parse them and will produce
	 * a broken font definition that causes PDF generation to fail silently.
	 * Users must supply a static (non-variable) TTF/OTF instead.
	 *
	 * @param array  $file   $_FILES entry
	 * @param string $label  Human-readable display name
	 * @return string|WP_Error  TCPDF font family key on success
	 */
	public static function upload_font( array $file, string $label ): string|WP_Error {
		$allowed = [ 'ttf', 'otf' ];
		$ext     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'invalid_type', __( 'Only .ttf and .otf font files are accepted.', 'gf-fillable-pdf-generator' ) );
		}

		// Reject variable fonts before doing anything else.
		// Variable fonts embed an 'fvar' table that TCPDF cannot handle,
		// causing silent conversion failure and broken PDF output.
		if ( self::is_variable_font( $file['tmp_name'] ) ) {
			return new WP_Error(
				'variable_font',
				__( 'Variable fonts are not supported. Please upload a static (non-variable) version of this font. Variable fonts contain multiple weight/style axes in a single file — look for a "static" sub-folder inside the font ZIP, or download a specific weight (e.g. Regular, Bold) from the font provider.', 'gf-fillable-pdf-generator' )
			);
		}

		$dir = self::get_font_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Derive a safe TCPDF-compatible family key (lowercase, no spaces)
		$family = strtolower( sanitize_key( pathinfo( $file['name'], PATHINFO_FILENAME ) ) );
		if ( ! $family ) {
			return new WP_Error( 'invalid_name', __( 'Could not derive a font name from the file.', 'gf-fillable-pdf-generator' ) );
		}

		// Copy the raw font file
		$dest = $dir . $family . '.' . $ext;
		
		// Initialize the WordPress filesystem API
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Use the abstraction layer instead of native PHP move_uploaded_file()
        if ( ! $wp_filesystem->move( $file['tmp_name'], $dest, true ) ) {
			return new WP_Error( 'upload_failed', __( 'Could not save font file.', 'gf-fillable-pdf-generator' ) );
		}

		// Convert to TCPDF font definition using TCPDF's built-in converter.
		//
		// IMPORTANT: addTTFfont() derives its own font name from the filename
		// using a different algorithm than we do (it strips hyphens, commas and
		// also collapses "bold"→"b", "italic"/"oblique"→"i", "regular"→"").
		// We must register the family under the name TCPDF actually created,
		// not under our sanitize_key() version, otherwise SetFont() cannot find
		// the definition file and PDF generation fails silently.
		if ( class_exists( 'TCPDF_FONTS' ) ) {
			$tcpdf_font_path = TCPDF_FONTS::addTTFfont( $dest, 'TrueTypeUnicode', '', 96 );
			if ( $tcpdf_font_path ) {
				// Extract the real family name from the generated .php filename
				// (e.g. /path/to/tcpdf/fonts/rubik.php → "rubik")
				$tcpdf_family = strtolower( pathinfo( $tcpdf_font_path, PATHINFO_FILENAME ) );
				if ( ! $tcpdf_family ) {
					$tcpdf_family = $family; // should never happen, but be safe
				}

				$fonts                = self::get_custom_fonts();
				$fonts[ $tcpdf_family ] = $label ?: $tcpdf_family;
				update_option( 'gffpdf_custom_fonts', $fonts );
				return $tcpdf_family; // caller gets the key TCPDF can actually use
			}

			// Conversion failed — remove the copied file and surface a clear error
			// rather than registering a broken family that would cause generation failures.
			wp_delete_file( $dest );
			return new WP_Error(
				'conversion_failed',
				__( 'The font file could not be converted for use with the PDF engine. Make sure it is a valid, non-corrupted TrueType or OpenType font and try again.', 'gf-fillable-pdf-generator' )
			);
		}

		// TCPDF_FONTS class not available — register the family key so TCPDF can
		// try to locate the file directly at render time.
		$fonts            = self::get_custom_fonts();
		$fonts[ $family ] = $label ?: $family;
		update_option( 'gffpdf_custom_fonts', $fonts );
		return $family;
	}

	/**
	 * Detect whether a font file is a variable font by looking for the 'fvar'
	 * OpenType table in its table directory.
	 *
	 * OpenType/TrueType binary layout (Offset Table, 12 bytes):
	 *   4 bytes  sfVersion   (0x00010000 for TTF, 'OTTO' for CFF/OTF)
	 *   2 bytes  numTables
	 *   2 bytes  searchRange
	 *   2 bytes  entrySelector
	 *   2 bytes  rangeShift
	 * Followed by numTables × 16-byte Table Records:
	 *   4 bytes  tag   (ASCII table name, e.g. 'fvar', 'glyf', 'cmap')
	 *   4 bytes  checkSum
	 *   4 bytes  offset
	 *   4 bytes  length
	 *
	 * We read the table tags only; if 'fvar' is present the font is variable.
	 *
	 * @param string $path  Absolute path to the font file (the PHP tmp_name is fine).
	 * @return bool  True when the font is a variable font, false otherwise.
	 */
	private static function is_variable_font( string $path ): bool {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fread, WordPress.WP.AlternativeFunctions.file_system_read_fclose -- Custom binary parsing requires byte-level pointer streaming rather than loading the whole file into memory.
        $fp = @fopen( $path, 'rb' );
        if ( ! $fp ) {
            return false;
        }

        // Read the 12-byte Offset Table
        $header = fread( $fp, 12 );
        if ( strlen( $header ) < 12 ) {
            fclose( $fp );
            return false;
        }

        $num_tables = unpack( 'n', substr( $header, 4, 2 ) )[1];

        // Sanity check — legitimate fonts have between 1 and ~30 tables
        if ( $num_tables === 0 || $num_tables > 256 ) {
            fclose( $fp );
            return false;
        }

        // Scan table records (16 bytes each) looking for the 'fvar' tag
        for ( $i = 0; $i < $num_tables; $i++ ) {
            $record = fread( $fp, 16 );
            if ( strlen( $record ) < 16 ) {
                break;
            }
            if ( substr( $record, 0, 4 ) === 'fvar' ) {
                fclose( $fp );
                return true;
            }
        }

        fclose( $fp );
        // phpcs:enable
        
        return false;
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
				wp_delete_file( $file );
			}
		}
		return true;
	}
}