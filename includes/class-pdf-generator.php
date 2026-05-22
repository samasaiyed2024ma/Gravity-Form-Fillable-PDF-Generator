<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates a filled PDF using pre-stored field coordinates from the DB.
 *
 * Strategy:
 *  1. Use Ghostscript to rasterise each page of the original PDF to a PNG image.
 *  2. Use TCPDF to build a new PDF: each page gets its PNG as a full-page background.
 *  3. Overlay field values at their stored coordinates on top.
 *
 * This preserves the full original PDF layout/artwork with no FPDI compression issues.
 */
class GFFPDF_PDF_Generator {

	/** Ghostscript binary path — auto-detected on first use. */
	private static ?string $gs_bin = null;

	/* -----------------------------------------------------------------------
	 * Public API
	 * -------------------------------------------------------------------- */

	public function generate( string $template_path, array $field_values, array $options = [] ) {
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'template_not_found', esc_html__( 'PDF template not found.', 'gf-fillable-pdf' ) );
		}

		$field_values = $this->normalise_values( $field_values );

		// Load stored field coordinates from DB
		$extractor  = new GFFPDF_PDF_Field_Extractor();
		$field_rows = $extractor->get_fields_for_template( $template_path );

		if ( empty( $field_rows ) ) {
			return new WP_Error( 'no_field_data', 'No field coordinate data found. Please re-upload the PDF template in the feed settings.' );
		}

		// Build map: field_name → field_row
		$coord_map = [];
		foreach ( $field_rows as $row ) {
			$name              = is_object( $row ) ? $row->field_name : $row['field_name'];
			$coord_map[ $name ] = $row;
		}

		// Rasterise PDF pages to PNG images via Ghostscript
		$page_images = $this->rasterise_pages( $template_path );
		if ( is_wp_error( $page_images ) ) {
			return $page_images;
		}

		// Build output PDF: PNG backgrounds + overlaid field values
		$result = $this->build_pdf( $page_images, $field_values, $coord_map );

		// Clean up temp PNG files
		foreach ( $page_images as $img ) {
			@unlink( $img['path'] );
		}

		return $result;
	}

	/* -----------------------------------------------------------------------
	 * Step 1: rasterise PDF pages to PNG via Ghostscript
	 * -------------------------------------------------------------------- */

	/**
	 * Convert every page of the PDF to a PNG file.
	 *
	 * Returns array of:
	 *   [ 'path' => '/tmp/...png', 'width_mm' => float, 'height_mm' => float, 'page' => int ]
	 *
	 * @return array|WP_Error
	 */
	private function rasterise_pages( string $pdf_path ) {
		$gs = $this->find_ghostscript();
		if ( ! $gs ) {
			return new WP_Error( 'gs_not_found', 'Ghostscript not found on this server.' );
		}

		$temp_dir    = sys_get_temp_dir();
		$base_name   = 'gffpdf_' . uniqid( '', true );
		$output_pattern = $temp_dir . '/' . $base_name . '_%04d.png';

		// 150 DPI is enough for readable text while keeping file sizes small
		$dpi = 150;

		$cmd = sprintf(
			'%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r%d -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile=%s %s 2>&1',
			escapeshellarg( $gs ),
			$dpi,
			escapeshellarg( $output_pattern ),
			escapeshellarg( $pdf_path )
		);

		$output = [];
		$exit   = -1;
		exec( $cmd, $output, $exit );

		if ( $exit !== 0 ) {
			return new WP_Error( 'gs_failed', 'Ghostscript error: ' . implode( "\n", $output ) );
		}

		// Collect generated PNG files (GS numbers them _0001.png, _0002.png …)
		$images = [];
		$page   = 1;
		while ( true ) {
			$img_path = $temp_dir . '/' . $base_name . '_' . sprintf( '%04d', $page ) . '.png';
			if ( ! file_exists( $img_path ) ) break;

			// Get image dimensions in pixels, convert to mm at the render DPI
			$size     = getimagesize( $img_path );
			$width_mm  = $size ? ( $size[0] / $dpi * 25.4 ) : 215.9;
			$height_mm = $size ? ( $size[1] / $dpi * 25.4 ) : 279.4;

			$images[] = [
				'path'      => $img_path,
				'width_mm'  => $width_mm,
				'height_mm' => $height_mm,
				'page'      => $page,
				'dpi'       => $dpi,
			];
			$page++;
		}

		if ( empty( $images ) ) {
			return new WP_Error( 'gs_no_output', 'Ghostscript produced no PNG files.' );
		}

		return $images;
	}

	/* -----------------------------------------------------------------------
	 * Step 2: build output PDF with PNG backgrounds + field overlays
	 * -------------------------------------------------------------------- */

	private function build_pdf( array $page_images, array $values, array $coord_map ) {
		try {
			$pdf = new TCPDF( 'P', 'mm', 'LETTER', true, 'UTF-8', false );
			$pdf->setPrintHeader( false );
			$pdf->setPrintFooter( false );
			$pdf->SetAutoPageBreak( false );
			$pdf->SetMargins( 0, 0, 0 );

			foreach ( $page_images as $img_info ) {
				$page_num  = $img_info['page'];
				$width_mm  = $img_info['width_mm'];
				$height_mm = $img_info['height_mm'];

				$orientation = ( $width_mm > $height_mm ) ? 'L' : 'P';
				$pdf->AddPage( $orientation, [ $width_mm, $height_mm ] );

				// Draw the rasterised page as the full-page background
				$pdf->Image( $img_info['path'], 0, 0, $width_mm, $height_mm, 'PNG' );

				// Overlay field values on this page
				foreach ( $values as $field_name => $field_value ) {
					if ( $field_value === '' ) continue;
					if ( ! isset( $coord_map[ $field_name ] ) ) continue;

					$row      = $coord_map[ $field_name ];
					$row_page = (int)( is_object( $row ) ? $row->page_number : $row['page_number'] );
					if ( $row_page !== $page_num ) continue;

					$this->draw_field( $pdf, $row, (string) $field_value );
				}
			}

			$output = $pdf->Output( '', 'S' );

			if ( empty( $output ) ) {
				return new WP_Error( 'tcpdf_empty', 'TCPDF produced no output.' );
			}

			return $output;

		} catch ( \Exception $e ) {
			return new WP_Error( 'tcpdf_exception', $e->getMessage() );
		}
	}

	/* -----------------------------------------------------------------------
	 * Draw a single field value at its stored coordinates
	 *
	 * PDF coords:   Y=0 at bottom, increases upward.
	 * TCPDF coords: Y=0 at top,    increases downward.
	 *
	 * The stored page_height is in PDF points (from the MediaBox).
	 * The PNG background was rendered at `dpi` dots/inch.
	 * We convert field coords from PDF points → mm using 0.352778 pt/mm.
	 * -------------------------------------------------------------------- */

	private function draw_field( $pdf, $row, string $value ): void {
		$pt = 0.352778; // 1 PDF point → mm

		$x1          = (float)( is_object( $row ) ? $row->rect_x1     : $row['rect_x1'] );
		$y1          = (float)( is_object( $row ) ? $row->rect_y1     : $row['rect_y1'] );
		$x2          = (float)( is_object( $row ) ? $row->rect_x2     : $row['rect_x2'] );
		$y2          = (float)( is_object( $row ) ? $row->rect_y2     : $row['rect_y2'] );
		$page_height = (float)( is_object( $row ) ? $row->page_height : $row['page_height'] );
		$field_type  = (string)( is_object( $row ) ? $row->field_type : $row['field_type'] );

		$left      = min( $x1, $x2 ) * $pt;
		$right     = max( $x1, $x2 ) * $pt;
		$bottom_pt = min( $y1, $y2 );
		$top_pt    = max( $y1, $y2 );
		$page_h_mm = $page_height * $pt;

		$field_w      = $right - $left;
		$field_h      = ( $top_pt - $bottom_pt ) * $pt;
		$tcpdf_y      = $page_h_mm - ( $top_pt * $pt );
		$font_size_pt = max( 6.0, min( 12.0, ( $top_pt - $bottom_pt ) * 0.65 ) );

		if ( $field_type === 'checkbox' ) {
			$v = strtolower( $value );
			if ( $v === 'yes' || $v === '1' || $v === 'on' || $v === 'true' ) {
				$pdf->SetFont( 'zapfdingbats', '', $font_size_pt );
				$pdf->SetTextColor( 0, 0, 0 );
				$pdf->SetXY( $left, $tcpdf_y );
				$pdf->Cell( $field_w, $field_h, '4', 0, 0, 'C' );
			}
			return;
		}

		$pdf->SetFont( 'helvetica', '', $font_size_pt );
		$pdf->SetTextColor( 0, 0, 0 );

		$text_h_mm       = $font_size_pt * $pt;
		$tcpdf_y_centred = $tcpdf_y + ( $field_h / 2 ) - ( $text_h_mm / 2 );

		$pdf->SetXY( $left + 1, $tcpdf_y_centred );
		$pdf->Cell( $field_w - 2, $text_h_mm, $value, 0, 0, 'L', false, '', 1 );
	}

	/* -----------------------------------------------------------------------
	 * Ghostscript binary detection
	 * -------------------------------------------------------------------- */

	private function find_ghostscript(): ?string {
		if ( self::$gs_bin !== null ) {
			return self::$gs_bin ?: null;
		}

		$candidates = [ 'ghostscript', 'gs', '/usr/bin/ghostscript', '/usr/bin/gs', '/usr/local/bin/gs' ];

		foreach ( $candidates as $bin ) {
			$out  = [];
			$exit = -1;
			exec( escapeshellarg( $bin ) . ' --version 2>&1', $out, $exit );
			if ( $exit === 0 ) {
				self::$gs_bin = $bin;
				return $bin;
			}
		}

		self::$gs_bin = '';
		return null;
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