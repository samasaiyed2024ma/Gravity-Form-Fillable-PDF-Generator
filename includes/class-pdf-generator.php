<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Produces the filled PDF for an entry.
 *
 * Works with every kind of fillable PDF: AcroForm forms (also ones whose
 * form dictionary is missing/empty, and ones locked with an empty-password
 * encryption) and XFA-only forms.
 *
 * The template's own AcroForm fields are filled in place (see
 * GFFPDF_AcroForm_Filler), so the generated PDF is still a live, editable
 * form: every field keeps its name and type and can be changed in Acrobat,
 * a browser's PDF viewer, etc. The template is never rasterised or redrawn.
 *
 * It is pure PHP — no pdftk, Ghostscript, qpdf, Imagick or shell access is
 * needed, so the plugin works unchanged on any host it can be installed on.
 */
class GFFPDF_PDF_Generator {

	/**
	 * @param string $template_path Absolute path to the fillable PDF template.
	 * @param array  $field_values  PDF field name => value.
	 * @param array  $options       Feed settings (font_family, font_size, font_color …).
	 * @return string|WP_Error      Raw PDF bytes on success.
	 */
	public function generate( string $template_path, array $field_values, array $options = [] ) {
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'template_not_found', esc_html__( 'PDF template not found.', 'gf-fillable-pdf-generator' ) );
		}

		$template = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		if ( $template === false || $template === '' ) {
			return new WP_Error( 'template_unreadable', esc_html__( 'PDF template could not be read.', 'gf-fillable-pdf-generator' ) );
		}

		$global = GFFPDF_Settings::get_settings();
		$fill_options = [
			// Feed-level overrides
			'font_family' => $options['font_family'] ?? '',
			'font_size'   => $options['font_size']   ?? '',
			'font_color'  => $options['font_color']  ?? '',
			// RTL toggle (feed "Reverse Text" or global "RTL Support"); null = auto-detect
			'rtl'         => array_key_exists( 'reverse_text', $options ) ? (bool) $options['reverse_text'] : null,
			// Global defaults
			'default_font_family' => $global['default_font_family'] ?? '',
			'default_font_size'   => $global['default_font_size']   ?? '',
			'default_font_color'  => $global['default_font_color']  ?? '',
			// Choice labels / positions per PDF field, used to match radio & checkbox states
			'value_aliases'       => ( isset( $options['value_aliases'] ) && is_array( $options['value_aliases'] ) ) ? $options['value_aliases'] : [],
		];

		$values = $this->normalise_values( $field_values );
		try {
			try {
				$filler = new GFFPDF_AcroForm_Filler();
				$pdf    = $filler->fill( $template, $values, $fill_options );
			} catch ( GFFPDF_XFA_Only_Exception $e ) {
				// XFA form with no AcroForm fields: fill its XML datasets instead.
				$xfa = new GFFPDF_XFA_Filler();
				$pdf = $xfa->fill( $template, $values, $fill_options );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'acroform_fill_failed', $e->getMessage() );
		}

		if ( $pdf === '' ) {
			return new WP_Error( 'pdf_empty', esc_html__( 'PDF generation produced no output.', 'gf-fillable-pdf-generator' ) );
		}

		return $pdf;
	}

	/* -----------------------------------------------------------------------
	 * Value normalisation
	 * -------------------------------------------------------------------- */

	private function normalise_values( array $values ): array {
		$out = [];
		foreach ( $values as $key => $value ) {
			if ( is_bool( $value ) )                    { $out[ $key ] = $value ? 'Yes' : 'Off'; }
			elseif ( is_array( $value ) )               { $out[ $key ] = implode( ', ', array_filter( $value ) ); }
			elseif ( $value === null || $value === '' ) { $out[ $key ] = ''; }
			else                                        { $out[ $key ] = trim( (string) $value ); }
		}
		return $out;
	}

	public static function normalise_checkbox( $gf_value ): string {
		return ( empty( $gf_value ) || $gf_value === '0' || strtolower( (string) $gf_value ) === 'off' )
			? 'Off' : 'Yes';
	}
}