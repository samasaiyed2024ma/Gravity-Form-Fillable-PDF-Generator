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
			return new WP_Error( 'template_not_found', esc_html__( 'PDF template not found.', 'gf-fillable-pdf-generator' ) );
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
		$result = $this->build_pdf( $page_images, $field_values, $coord_map, $options );

		// Clean up temp PNG files
		foreach ( $page_images as $img ) {
			wp_delete_file( $img['path'] );
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

	private function build_pdf( array $page_images, array $values, array $coord_map, array $options = []) {
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

					$this->draw_field( $pdf, $row, (string) $field_value, $options );
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

	private function draw_field( $pdf, $row, string $value, array $options = [] ): void {
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

		// Draw as an image whenever the value actually resolves to one — not
		// only when the PDF template's own AcroForm field happens to be typed
		// as Signature (/Sig). Many real-world templates use a plain Text
		// field as the visual "sign here" box, so relying on the template's
		// field type alone misses those entirely and prints the raw image
		// value as text instead. Checking the value itself covers both a
		// signature URL (the standard Signature field / Add-On behaviour)
		// and a base64 data URI (some canvas-based signature fields submit
		// the image inline rather than uploading a file) — either way, if we
		// treated it as text it would print a very long string that overflows
		// its box and visually smears into whatever is printed next to it.
		if ( $field_type === 'signature' || $this->looks_like_image_value( $value ) ) {
			$this->draw_signature( $pdf, $left, $tcpdf_y, $field_w, $field_h, $value );
			return;
		}

		$font_options = $this->resolve_font_options( $options, $font_size_pt );

		// ── RTL / bidirectional text handling ───────────────────────────────
		// Detect RTL characters (Arabic or Hebrew Unicode blocks) directly in
		// the value itself and handle them automatically — this used to also
		// require the feed's "Reverse Text" checkbox (or the global "RTL
		// Support" toggle) to be turned on, which meant that with neither
		// enabled, Hebrew/Arabic text stayed on a Latin-only core font and
		// rendered as "?" characters (that font simply has no glyphs for
		// those code points) even though the value was correct. Whether a
		// font can *display* a character at all isn't an optional style
		// choice, so this no longer waits for a manual toggle — it just
		// checks the text itself. Purely Latin/English values are completely
		// unaffected either way.
		$is_rtl_text = $this->contains_rtl_characters( $value );

		// The site's chosen/default font (e.g. 'helvetica', 'times', 'courier')
		// is a core PDF font with only Latin/WinAnsi glyphs — it has no Arabic
		// or Hebrew characters at all, so TCPDF renders missing glyphs as
		// hollow boxes ("tofu") instead of the actual letters. When the value
		// actually needs RTL rendering, swap to a bundled font that has the
		// needed glyph coverage, unless the site has already picked a custom
		// uploaded font (which the admin presumably chose because it supports
		// their script).
		$family = $font_options['family'];
		if ( $is_rtl_text && ! $this->font_supports_rtl( $family ) ) {
			$family = $this->rtl_fallback_font( $value );
		}

		$pdf->SetFont( $family, '', $font_options['size'] );
		$pdf->SetTextColor( $font_options['r'], $font_options['g'], $font_options['b'] );

		// Wrap across multiple lines whenever the value actually needs it —
		// contains a line break, or is simply too long to fit the field's
		// width on one line at its configured font size — rather than only
		// for GF's "textarea"/Paragraph field type. That check turned out to
		// be unreliable: $field_type here comes from the PDF TEMPLATE's own
		// AcroForm field (almost always a plain "text" field, even when the
		// GF field mapped to it is a Paragraph), not from Gravity Forms, so
		// it never actually matched a real paragraph value. Checking the
		// value itself works regardless of what kind of box the template
		// used for it.
		$avail_w      = max( 0.0, $field_w - 2 );
		$needs_wrap   = $avail_w > 0 && ( strpos( $value, "\n" ) !== false || $pdf->GetStringWidth( $value ) > $avail_w );
		if ( $field_type === 'textarea' || $needs_wrap ) {
			$this->draw_multiline_text( $pdf, $left, $tcpdf_y, $field_w, $field_h, $value, $family, $font_options, $is_rtl_text );
			return;
		}

		// Auto-shrink (never enlarge) the font just enough for the value to
		// fit on one line within the field's own width. Without this, a
		// value longer than its box (a long name, a full address, etc.)
		// doesn't wrap or get clipped — Cell() just draws past the box's
		// right edge and visually overlaps/smears into whatever the next
		// field prints on the same line.
		$fit_size  = $font_options['size'];
		if ( $avail_w > 0 ) {
			while ( $fit_size > 5.0 && $pdf->GetStringWidth( $value ) > $avail_w ) {
				$fit_size -= 0.5;
				$pdf->SetFont( $family, '', $fit_size );
			}
		}

		$text_h_mm       = $font_size_pt * $pt;
		$tcpdf_y_centred = $tcpdf_y + ( $field_h / 2 ) - ( $text_h_mm / 2 );

		if ( $is_rtl_text ) {
			// Turn on TCPDF's Unicode bidi reordering
			$pdf->setRTL( true );
			// TCPDF's own RTL math (getCellCode()) treats the X you hand to
			// SetXY() as the cell's TRAILING edge — i.e. its right edge —
			// rather than its left edge, the opposite of what SetXY() means
			// in normal LTR mode. Anchoring at $left (the box's actual left
			// edge), like the LTR branch below does, right-aligns the text
			// against that left-edge point instead of the box's real right
			// edge, which is exactly what pulled RTL values toward the
			// center/left of the field instead of hugging its right side.
			// Anchoring at the box's right edge instead fixes that.
			//
			// SetXY()'s 3rd arg ($rtloff) matters too: with RTL on, SetXY()
			// by default ALSO transforms whatever X you pass into
			// ($this->w - $x) — a page-width-relative "logical" coordinate
			// meant for RTL page layout — rather than using it as a direct
			// physical position. That silently scrambles our mm coordinate
			// into something unrelated to the field's real location. Passing
			// true here keeps the X we computed as an actual physical
			// coordinate; setRTL(true) above still keeps bidi text shaping
			// active independently of this flag.
			$pdf->SetXY( $left + $field_w - 1, $tcpdf_y_centred, true );
			// 'R' alignment + bidi=true: TCPDF places RTL runs from right,
			// LTR sub-runs (numbers, Latin words) automatically from left.
			$pdf->Cell( $field_w - 2, $text_h_mm, $value, 0, 0, 'R', false, '', 1 );
			$pdf->setRTL( false ); // restore LTR for subsequent fields
		} else {
			$pdf->SetXY( $left + 1, $tcpdf_y_centred );
			$pdf->Cell( $field_w - 2, $text_h_mm, $value, 0, 0, 'L', false, '', 1 );
		}
	}

	/**
	 * Draw a Paragraph field's value wrapped across multiple lines within
	 * its box, shrinking the font just enough for the text to fit the
	 * box's height (never enlarging past the feed's configured size).
	 */
	private function draw_multiline_text( $pdf, float $left, float $top_y, float $field_w, float $field_h, string $value, string $family, array $font_options, bool $is_rtl_text ): void {
		$avail_w  = max( 0.0, $field_w - 2 );
		$fit_size = $font_options['size'];

		// getStringHeight() reports how tall $value renders, wrapped to
		// $avail_w, at the currently-set font size — shrink until it fits
		// the box, same spirit as the single-line auto-shrink above but
		// checking wrapped height instead of single-line width. Floor of
		// 4pt (rather than the 5pt used for single-line fields) because a
		// paragraph value that's still too tall at 5pt is common with long
		// entries, and this is a flattened/rasterised PDF — there's no such
		// thing as a scrollable field in the output, so the font is the
		// only lever available to avoid losing text. If it's still taller
		// than the box even at the floor, MultiCell doesn't clip it — the
		// remaining lines simply print past the box's bottom edge, which is
		// visually imperfect but never silently drops any of the value.
		if ( $avail_w > 0 ) {
			while ( $fit_size > 4.0 ) {
				$pdf->SetFont( $family, '', $fit_size );
				if ( $pdf->getStringHeight( $avail_w, $value ) <= $field_h ) {
					break;
				}
				$fit_size -= 0.5;
			}
		}

		if ( $is_rtl_text ) {
			$pdf->setRTL( true );
			// Same RTL anchor-point quirk as the single-line branch in
			// draw_field(): TCPDF's MultiCell() also treats the X passed to
			// SetXY() as the box's right edge (not left) when RTL is on, so
			// the anchor has to be the field's right side, not $left.
			// Same $rtloff=true reasoning as draw_field(): without it,
			// SetXY() rewrites our physical X into a page-width-relative
			// value when RTL is on, instead of using it directly.
			$pdf->SetXY( $left + $field_w - 1, $top_y, true );
			$pdf->MultiCell( $avail_w, $field_h, $value, 0, 'R', false, 1, '', '', true, 0, false, true, $field_h, 'T' );
			$pdf->setRTL( false );
		} else {
			$pdf->SetXY( $left + 1, $top_y );
			$pdf->MultiCell( $avail_w, $field_h, $value, 0, 'L', false, 1, '', '', true, 0, false, true, $field_h, 'T' );
		}
	}

	/**
	 * Draw an actual signature image into a Signature-type PDF field.
	 *
	 * The Gravity Forms Signature field (and the core Signature Add-On) store
	 * the signature as an image file and put its URL in the entry — not the
	 * raw pixel data. Previously that URL string was passed straight into
	 * Cell() and printed as text/a link. Here we resolve the URL to the file
	 * on disk and embed the actual image, scaled to fit the field's
	 * rectangle while preserving its aspect ratio (no stretching/distortion).
	 */
	private function draw_signature( $pdf, float $x, float $y, float $w, float $h, string $value ): void {
		$value = trim( $value );
		if ( $value === '' ) {
			return;
		}

		// Some canvas-based signature fields submit the image inline as a
		// base64 data URI instead of uploading a file to the server. There's
		// no on-disk file to resolve in that case, so decode it to a
		// short-lived temp file that gets cleaned up right after embedding.
		$temp_file = null;
		if ( preg_match( '#^data:image/(png|jpe?g|gif);base64,#i', $value, $m ) ) {
			$raw = base64_decode( preg_replace( '#^data:image/\w+;base64,#i', '', $value ), true );
			if ( $raw === false || $raw === '' ) {
				GFFPDF_Logger::warn( 'Signature base64 data could not be decoded — skipping' );
				return;
			}
			$ext        = strtolower( $m[1] ) === 'jpg' ? 'jpeg' : strtolower( $m[1] );
			$temp_dir   = GFFPDF_UPLOAD_DIR . 'temp/';
			if ( ! is_dir( $temp_dir ) ) {
				wp_mkdir_p( $temp_dir );
			}
			$temp_file = $temp_dir . 'sig-' . uniqid( '', true ) . '.' . $ext;
			file_put_contents( $temp_file, $raw );
			$path = $temp_file;
		} else {
			$path = GFFPDF_Helpers::url_to_path( $value );
		}

		if ( ! $path ) {
			GFFPDF_Logger::warn( 'Signature image could not be resolved to a local file — skipping', [ 'value' => $value ] );
			return;
		}

		$size = @getimagesize( $path );
		if ( ! $size ) {
			GFFPDF_Logger::warn( 'Signature file is not a readable image — skipping', [ 'path' => $path ] );
			if ( $temp_file ) {
				wp_delete_file( $temp_file );
			}
			return;
		}

		$type_map = [ IMAGETYPE_PNG => 'PNG', IMAGETYPE_JPEG => 'JPG', IMAGETYPE_GIF => 'GIF' ];
		$type     = $type_map[ $size[2] ] ?? '';

		// Small inset so the signature doesn't touch the field's edges, and
		// TCPDF's $fitbox parameter scales the image to fit inside the given
		// box while keeping its original aspect ratio, centring it within
		// the box on both axes ('CM' = centre horizontal, middle vertical).
		$pad = 0.5; // mm
		try {
			$pdf->Image(
				$path,
				$x + $pad,
				$y + $pad,
				max( 0, $w - ( $pad * 2 ) ),
				max( 0, $h - ( $pad * 2 ) ),
				$type,
				'', '', true, 300, '', false, false, 0, 'CM', false, false
			);
		} catch ( \Exception $e ) {
			GFFPDF_Logger::warn( 'Failed to embed signature image', [ 'path' => $path, 'error' => $e->getMessage() ] );
		} finally {
			if ( $temp_file && file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
		}
	}

	/**
	 * True if $value is something that should be drawn as an image rather
	 * than printed as text: either a base64 data URI, or a URL/path that
	 * resolves to an actual file on disk.
	 */
	private function looks_like_image_value( string $value ): bool {
		$value = trim( $value );
		if ( $value === '' ) {
			return false;
		}
		if ( preg_match( '#^data:image/(png|jpe?g|gif);base64,#i', $value ) ) {
			return true;
		}
		return (bool) GFFPDF_Helpers::url_to_path( $value );
	}

	/**
	 * Built-in TCPDF core fonts have no Arabic/Hebrew glyphs at all. Any font
	 * NOT in this list is assumed to be either one of the bundled Unicode
	 * fonts (which do carry those glyphs) or a custom TTF the admin uploaded
	 * specifically to support their language — so we leave those alone and
	 * only override the small set of Latin-only core fonts.
	 */
	private function font_supports_rtl( string $family ): bool {
		$latin_only_core_fonts = [ 'helvetica', 'helveticab', 'helveticai', 'helveticabi',
			'times', 'timesb', 'timesi', 'timesbi',
			'courier', 'courierb', 'courieri', 'courierbi' ];
		return ! in_array( strtolower( $family ), $latin_only_core_fonts, true );
	}

	/**
	 * Pick a bundled TCPDF font that actually has glyphs for the script in
	 * $value. 'aealarabiya' is TCPDF's dedicated Arabic font (correct glyph
	 * joining/shaping); 'dejavusans' has solid Hebrew coverage. Both ship
	 * with TCPDF, so no extra font installation is required.
	 */
	private function rtl_fallback_font( string $value ): string {
		return $this->contains_arabic_characters( $value ) ? 'aealarabiya' : 'dejavusans';
	}

	private function contains_arabic_characters( string $text ): bool {
		return (bool) preg_match(
			'/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u',
			$text
		);
	}

	/**
	 * Return true if $text contains at least one Arabic or Hebrew code-point.
	 *
	 * Unicode ranges checked:
	 *   U+0590–U+05FF  Hebrew
	 *   U+0600–U+06FF  Arabic
	 *   U+0700–U+074F  Syriac (uses Arabic script conventions)
	 *   U+0750–U+077F  Arabic Supplement
	 *   U+08A0–U+08FF  Arabic Extended-A
	 *   U+FB1D–U+FB4F  Hebrew Presentation Forms
	 *   U+FB50–U+FDFF  Arabic Presentation Forms-A
	 *   U+FE70–U+FEFF  Arabic Presentation Forms-B
	 *
	 * This is intentionally conservative: Greek, Cyrillic, CJK, etc. are all
	 * LTR-dominant and do NOT trigger RTL mode here.
	 */
	private function contains_rtl_characters( string $text ): bool {
		// Fast bail-out: if every byte is ASCII the string cannot be RTL
		if ( mb_strlen( $text, 'UTF-8' ) === strlen( $text ) ) {
			return false;
		}

		// Match any single character from a Hebrew or Arabic Unicode block
		return (bool) preg_match(
			'/[\x{0590}-\x{05FF}' .  // Hebrew
			'\x{0600}-\x{06FF}'   .  // Arabic
			'\x{0700}-\x{074F}'   .  // Syriac
			'\x{0750}-\x{077F}'   .  // Arabic Supplement
			'\x{08A0}-\x{08FF}'   .  // Arabic Extended-A
			'\x{FB1D}-\x{FB4F}'   .  // Hebrew Presentation Forms
			'\x{FB50}-\x{FDFF}'   .  // Arabic Presentation Forms-A
			'\x{FE70}-\x{FEFF}'   .  // Arabic Presentation Forms-B
			']/u',
			$text
		);
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

	private function resolve_font_options(array $options, float $auto_size): array{
		$global = GFFPDF_Settings::get_settings();
 
		// Font family: feed -> global -> 'helvetica'
		$family = '';
		if ( ! empty( $options['font_family'] ) ) {
			$family = $options['font_family'];
		} elseif ( ! empty( $global['default_font_family'] ) ) {
			$family = $global['default_font_family'];
		}
		$family = $family ?: 'helvetica';
 
		// Validate font against known fonts (builtin + custom)
		if ( class_exists( 'GFFPDF_Font_Manager' ) ) {
			$all_fonts = GFFPDF_Font_Manager::get_all_fonts();
			if ( ! isset( $all_fonts[ $family ] ) ) {
				$family = 'helvetica';
			}
		}
 
		// Font size: feed->global->auto-calculated from field height
		$size = null;
		if ( ! empty( $options['font_size'] ) && is_numeric( $options['font_size'] ) ) {
			$size = (float) $options['font_size'];
		} elseif ( ! empty( $global['default_font_size'] ) && is_numeric( $global['default_font_size'] ) ) {
			$size = (float) $global['default_font_size'];
		}
		$size = $size ?? $auto_size;
		$size = max( 6.0, min( 72.0, $size ) );
 
		// Font color: feed → global → '#000000'
		$hex = '#000000';
		if ( ! empty( $options['font_color'] ) ) {
			$hex = $options['font_color'];
		} elseif ( ! empty( $global['default_font_color'] ) ) {
			$hex = $global['default_font_color'];
		}
 
		// Convert hex to RGB
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
 
		return compact( 'family', 'size', 'r', 'g', 'b' );
	}
}