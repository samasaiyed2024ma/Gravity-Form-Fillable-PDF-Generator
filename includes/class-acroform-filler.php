<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-pdf-document.php';

/**
 * Fills a PDF's real AcroForm fields in place — in pure PHP.
 *
 * The original file is left byte-for-byte intact; changes are appended as an
 * incremental update (the same thing Acrobat does on "Save"). Each filled
 * field keeps its name, type and widget, gets its /V value set, and gets a
 * freshly generated appearance stream so the value is visible in every
 * viewer (Acrobat, Chrome, Firefox, Preview, phone viewers, print). Because
 * the fields are still live, the output PDF remains fully editable.
 *
 * Nothing here shells out: no pdftk, Ghostscript, qpdf, or Imagick.
 */
/** Thrown when the PDF has an XFA form but no AcroForm fields to fill. */
class GFFPDF_XFA_Only_Exception extends RuntimeException {}

class GFFPDF_AcroForm_Filler {

	const FF_READONLY   = 1;
	const FF_MULTILINE  = 4096;     // bit 13
	const FF_RADIO      = 32768;    // bit 16
	const FF_PUSHBUTTON = 65536;    // bit 17
	const FF_COMBO      = 131072;   // bit 18
	const FF_COMB       = 16777216; // bit 25

	/** @var GFFPDF_Pdf_Document */
	private $doc;
	/** @var GFFPDF_Pdf_Dict */
	private $acro;
	private $acro_num = null;
	/** @var bool AcroForm was missing/empty and rebuilt from the page widgets. */
	private $acro_rebuilt = false;
	/** @var GFFPDF_Pdf_Ref[] Root field refs (used when the AcroForm is rebuilt). */
	private $field_roots = [];
	private $fields = [];
	private $pages = [];
	private $widget_page = [];
	private $work = [];            // objnum => working copy of a dict we are changing
	private $options = [];
	private $rtl_mode = null;
	private $need_appearances = false;
	private $std_fonts = [];       // BaseFont => Ref
	private $uni = [];             // key => font record (embedded Unicode fonts)
	private $def_cache = [];
	private $image_cache = [];
	private $font_counter = 0;
	private $log = [];
	/** @var array pdf_field => [ texts, index, count ] from the entry handler. */
	private $aliases = [];
	private $cur_alias = null;

	/* =======================================================================
	 * Public API
	 * ==================================================================== */

	/**
	 * List the fillable fields of a PDF (used by the mapping UI).
	 *
	 * @return array[] rows: field_name, field_type, page_number, rect_x1..y2, page_width, page_height
	 * @throws RuntimeException when the file cannot be parsed or has no form.
	 */

	public static function list_fields( string $pdf_bytes ): array {
		$f = new self();
		$f->load( $pdf_bytes );

		// Prefer the short partial name (what earlier versions stored) and only
		// fall back to the fully-qualified name when the short name is ambiguous.
		$count = [];
		foreach ( $f->fields as $fl ) {
			$count[ $fl['partial'] ] = ( $count[ $fl['partial'] ] ?? 0 ) + 1;
		}

		$rows = [];
		$seen = [];
		foreach ( $f->fields as $fl ) {
			if ( $fl['ft'] === 'Btn' && ( $fl['ff'] & self::FF_PUSHBUTTON ) ) continue;
			$name = ( $fl['partial'] !== '' && $count[ $fl['partial'] ] === 1 ) ? $fl['partial'] : $fl['fqn'];
			if ( $name === '' || isset( $seen[ $name ] ) ) continue;
			$seen[ $name ] = true;

			switch ( $fl['ft'] ) {
				case 'Btn': $type = ( $fl['ff'] & self::FF_RADIO ) ? 'radio' : 'checkbox'; break;
				case 'Ch':  $type = 'select'; break;
				case 'Sig': $type = 'signature'; break;
				default:    $type = 'text';
			}

			$w    = $fl['widgets'][0] ?? null;
			$rect = $w ? $f->doc->nums( $w['dict']->get( 'Rect' ) ) : [];
			if ( count( $rect ) < 4 ) $rect = [ 0, 0, 0, 0 ];
			$pi   = $w ? ( $f->widget_page[ $w['num'] ] ?? 0 ) : 0;
			$pg   = $f->pages[ $pi ] ?? null;
			$box  = $pg ? $pg['box'] : [ 0, 0, 612, 792 ];

			$rows[] = [
				'field_name'  => $name,
				'field_type'  => $type,
				'page_number' => $pi + 1,
				'rect_x1'     => min( $rect[0], $rect[2] ),
				'rect_y1'     => min( $rect[1], $rect[3] ),
				'rect_x2'     => max( $rect[0], $rect[2] ),
				'rect_y2'     => max( $rect[1], $rect[3] ),
				'page_width'  => $box[2] - $box[0],
				'page_height' => $box[3] - $box[1],
			];
		}
		return $rows;
	}

	/**
	 * Fill the form.
	 *
	 * @param string $pdf_bytes  The template PDF.
	 * @param array  $values     field name (short or fully-qualified) => string value.
	 * @param array  $options    font_family, font_size, font_color (feed level) and
	 *                           default_font_family, default_font_size, default_font_color (global).
	 * @return string            The filled, still-editable PDF.
	 * @throws RuntimeException
	 */
	public function fill( string $pdf_bytes, array $values, array $options = [] ): string {
		$this->options = $options;
		// RTL handling: true/false = the feed/global "RTL" toggle; null (option absent) = auto-detect.
		$this->rtl_mode = array_key_exists( 'rtl', $options ) && $options['rtl'] !== null ? (bool) $options['rtl'] : null;
		$this->aliases = ( isset( $options['value_aliases'] ) && is_array( $options['value_aliases'] ) ) ? $options['value_aliases'] : [];
		$this->load( $pdf_bytes );

		$by_fqn = [];
		$by_partial = [];
		foreach ( $this->fields as $i => $fl ) {
			$by_fqn[ $fl['fqn'] ][]         = $i;
			$by_partial[ $fl['partial'] ][] = $i;
		}

		$filled = 0;
		foreach ( $values as $name => $value ) {
			$name  = (string) $name;
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( $value === '' ) continue;

			$this->cur_alias = $this->aliases[ $name ] ?? null;
			$targets = $by_fqn[ $name ] ?? ( $by_partial[ $name ] ?? [] );
			if ( ! $targets ) {
				$this->note( 
					sprintf(
						/* translators: %s: Field name */
						__( "No PDF field named '%s' in the template.", 'gf-fillable-pdf-generator' ),
						$name
					) 
				);
				continue;
			}
			foreach ( $targets as $idx ) {
				try {
					if ( $this->fill_field( $this->fields[ $idx ], $value ) ) $filled++;
				} catch ( \Throwable $e ) {
					$this->note( 
						sprintf(
							/* translators: 1: Field name, 2: Error message */
							__( "Field '%1\$s' could not be filled: %2\$s", 'gf-fillable-pdf-generator' ),
							$name,
							$e->getMessage()
						)
					);
					$this->need_appearances = true;
				}
			}
		}

		$this->finish();
		return $this->doc->save();
	}

	/** Non-fatal problems collected during fill() (unknown names, unusable images …). */
	public function get_notes(): array {
		return $this->log;
	}

	/* =======================================================================
	 * Loading the form structure
	 * ==================================================================== */

	private function load( string $bytes ): void {
		$this->doc = new GFFPDF_Pdf_Document( $bytes );
		$cat = $this->doc->catalog();
		if ( ! $cat ) throw new RuntimeException( __('PDF catalog not found.', 'gf-fillable-pdf-generator') );

		$raw = $cat->get( 'AcroForm' );
		$this->acro_num = $raw instanceof GFFPDF_Pdf_Ref ? $raw->num : null;
		$acro = $this->doc->dict( $raw );
		if ( ! $acro ) {
			// Some "fillable" PDFs have field widgets on the pages but no (or a
			// broken) /AcroForm entry. Viewers still show them as fields, so
			// we rebuild the form from the widgets rather than refusing.
			$acro = new GFFPDF_Pdf_Dict();
			$this->acro_rebuilt = true;
		}
		$this->acro  = $acro;
		$this->pages = $this->doc->pages();

		$widget_refs = [];
		foreach ( $this->pages as $i => $pg ) {
			$annots = $this->doc->arr( $pg['dict']->get( 'Annots' ) );
			if ( ! $annots ) continue;
			foreach ( $annots->a as $a ) {
				if ( $a instanceof GFFPDF_Pdf_Ref ) {
					$this->widget_page[ $a->num ] = $i;
					$widget_refs[] = $a;
				}
			}
		}

		$fields = $this->doc->arr( $acro->get( 'Fields' ) );
		$roots  = ( $fields && $fields->a ) ? $fields->a : [];
		if ( ! $roots ) {
			$roots = $this->roots_from_widgets( $widget_refs );
			if ( $roots ) $this->acro_rebuilt = true;
		}
		if ( ! $roots ) {
			if ( $this->has_xfa( $acro ) ) {
				throw new GFFPDF_XFA_Only_Exception( __('This PDF is an XFA-only form (no AcroForm fields).', 'gf-fillable-pdf-generator') );
			}
			throw new RuntimeException( $raw === null
				? __('This PDF has no fillable form fields — it is not a fillable form.', 'gf-fillable-pdf-generator')
				: __('The PDF form contains no fields.', 'gf-fillable-pdf-generator') );
		}
		$this->field_roots = $roots;

		$seen = [];
		foreach ( $roots as $ref ) {
			$this->walk_field( $ref, [], '', 0, $seen );
		}
		if ( ! $this->fields ) {
			throw new RuntimeException( __('No fillable AcroForm fields found in this PDF.', 'gf-fillable-pdf-generator') );
		}
	}

	private function has_xfa( GFFPDF_Pdf_Dict $acro ): bool {
		return $acro->has( 'XFA' );
	}

	/**
	 * Find the top-most field of every form widget on the pages.
	 *
	 * @param  GFFPDF_Pdf_Ref[] $widget_refs
	 * @return GFFPDF_Pdf_Ref[]
	 */
	private function roots_from_widgets( array $widget_refs ): array {
		$roots = [];
		foreach ( $widget_refs as $ref ) {
			$d = $this->doc->dict( $ref );
			if ( ! $d || $this->doc->name( $d->get( 'Subtype' ) ) !== 'Widget' ) continue;

			$top = $ref;
			$cur = $d;
			for ( $g = 0; $g < 32; $g++ ) {
				$par = $cur->get( 'Parent' );
				$pd  = $par instanceof GFFPDF_Pdf_Ref ? $this->doc->dict( $par ) : null;
				if ( ! $pd ) break;
				$top = $par;
				$cur = $pd;
			}
			// A widget that carries neither a name nor a type anywhere is not a form field.
			$td = $this->doc->dict( $top );
			if ( ! $td || ( ! $td->has( 'T' ) && ! $td->has( 'FT' ) && ! $d->has( 'FT' ) ) ) continue;
			$roots[ $top->num ] = $top;
		}
		return array_values( $roots );
	}

	private function walk_field( $ref, array $inh, string $prefix, int $depth, array &$seen ): void {
		if ( $depth > 32 || ! $ref instanceof GFFPDF_Pdf_Ref ) return;
		if ( isset( $seen[ $ref->num ] ) ) return;
		$seen[ $ref->num ] = true;

		$d = $this->doc->dict( $ref );
		if ( ! $d ) return;

		foreach ( [ 'FT', 'Ff', 'DA', 'Q', 'MaxLen', 'Opt' ] as $k ) {
			if ( $d->has( $k ) ) $inh[ $k ] = $d->get( $k );
		}
		$t = $d->get( 'T' );
		$t = $t !== null ? $this->text_of( $this->doc->resolve( $t ) ) : null;
		$fqn = ( $t !== null && $t !== '' ) ? ( $prefix === '' ? $t : $prefix . '.' . $t ) : $prefix;

		$field_kids  = [];
		$widget_kids = [];
		$kids = $this->doc->arr( $d->get( 'Kids' ) );
		if ( $kids ) {
			foreach ( $kids->a as $k ) {
				$kd = $this->doc->dict( $k );
				if ( ! $kd ) continue;
				if ( $kd->has( 'T' ) ) $field_kids[] = $k; else $widget_kids[] = $k;
			}
		}
		if ( $field_kids ) {
			foreach ( $field_kids as $k ) $this->walk_field( $k, $inh, $fqn, $depth + 1, $seen );
			return;
		}

		$ft = $this->doc->name( $inh['FT'] ?? null );
		if ( $ft === '' ) return;

		$widgets = [];
		if ( $widget_kids ) {
			foreach ( $widget_kids as $k ) {
				if ( $k instanceof GFFPDF_Pdf_Ref ) $widgets[] = [ 'num' => $k->num, 'dict' => $this->doc->dict( $k ) ];
			}
		} else {
			$widgets[] = [ 'num' => $ref->num, 'dict' => $d ];
		}
		if ( ! $widgets ) return;

		$da = $this->doc->resolve( $inh['DA'] ?? null );
		if ( ! $da instanceof GFFPDF_Pdf_Str ) $da = $this->doc->resolve( $this->acro->get( 'DA' ) );

		$this->fields[] = [
			'fqn'     => $fqn,
			'partial' => (string) $t,
			'num'     => $ref->num,
			'dict'    => $d,
			'ft'      => $ft,
			'ff'      => (int) $this->doc->num( $inh['Ff'] ?? 0, 0 ),
			'da'      => $da instanceof GFFPDF_Pdf_Str ? $da->v : '',
			'q'       => (int) $this->doc->num( $inh['Q'] ?? $this->acro->get( 'Q' ), 0 ),
			'maxlen'  => (int) $this->doc->num( $inh['MaxLen'] ?? 0, 0 ),
			'opt'     => $this->doc->arr( $inh['Opt'] ?? null ),
			'widgets' => $widgets,
		];
	}

	/* =======================================================================
	 * Field dispatch
	 * ==================================================================== */

	private function fill_field( array $f, string $value ): bool {
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );

		if ( ( $f['ft'] === 'Tx' || $f['ft'] === 'Sig' ) && $this->looks_like_image( $value ) ) {
			return $this->fill_image( $f, $value );
		}

		switch ( $f['ft'] ) {
			case 'Tx':  return $this->fill_text( $f, $value, false );
			case 'Ch':  return $this->fill_choice( $f, $value );
			case 'Btn': return $this->fill_button( $f, $value );
		}
		return false; // Sig without an image: nothing sensible to do
	}

	private function &work( int $num, GFFPDF_Pdf_Dict $orig ): GFFPDF_Pdf_Dict {
		if ( ! isset( $this->work[ $num ] ) ) {
			$this->work[ $num ] = clone $orig;
		}
		return $this->work[ $num ];
	}

	/* =======================================================================
	 * Text fields & combo boxes
	 * ==================================================================== */

	private function fill_text( array $f, string $value, bool $is_combo, ?string $export = null ): bool {
		if ( $f['maxlen'] > 0 && ! $is_combo ) {
			$value = mb_substr( $value, 0, $f['maxlen'], 'UTF-8' );
		}

		$ff = $f['ff'];
		$multiline = (bool) ( $ff & self::FF_MULTILINE ) && ! $is_combo;
		$comb      = (bool) ( $ff & self::FF_COMB ) && $f['maxlen'] > 0 && ! $is_combo;

		// A value that contains line breaks needs a multi-line box; mark the field
		// so it keeps behaving the same way when someone edits it later.
		if ( ! $multiline && ! $comb && ! $is_combo && strpos( $value, "\n" ) !== false ) {
			$multiline = true;
			$ff       |= self::FF_MULTILINE;
		}

		$fd = &$this->work( $f['num'], $f['dict'] );
		$fd->set( 'V', $this->pdf_text( $export ?? $value ) );
		$fd->remove( 'RV' );
		if ( $ff !== $f['ff'] ) $fd->set( 'Ff', $ff );

		$style = $this->field_style( $f, $value );

		foreach ( $f['widgets'] as $w ) {
			$ref = $this->text_appearance( $f, $w, $value, $style, $multiline, $comb );
			if ( ! $ref ) { $this->need_appearances = true; continue; }
			$wd = &$this->work( $w['num'], $w['dict'] );
			$wd->set( 'AP', new GFFPDF_Pdf_Dict( [ 'N' => $ref ] ) );
		}
		return true;
	}

	private function fill_choice( array $f, string $value ): bool {
		$export  = $value;
		$display = $value;
		$index   = null;

		if ( $f['opt'] ) {
			foreach ( $f['opt']->a as $i => $o ) {
				$o = $this->doc->resolve( $o );
				if ( $o instanceof GFFPDF_Pdf_Arr && count( $o->a ) >= 2 ) {
					$e = $this->text_of( $this->doc->resolve( $o->a[0] ) );
					$d = $this->text_of( $this->doc->resolve( $o->a[1] ) );
				} else {
					$e = $d = $this->text_of( $o );
				}
				if ( strcasecmp( trim( (string) $e ), trim( $value ) ) === 0 || strcasecmp( trim( (string) $d ), trim( $value ) ) === 0 ) {
					$export = (string) $e; $display = (string) $d; $index = $i;
					break;
				}
			}
		}

		if ( ! ( $f['ff'] & self::FF_COMBO ) ) {
			// List box: store the selection; viewers draw the list themselves.
			$fd = &$this->work( $f['num'], $f['dict'] );
			$fd->set( 'V', $this->pdf_text( $export ) );
			if ( $index !== null ) $fd->set( 'I', new GFFPDF_Pdf_Arr( [ $index ] ) );
			$this->need_appearances = true;
			return true;
		}

		// For a combo box the field value is the export value, but what is drawn is the display text.
		return $this->fill_text( $f, $display, true, $export );
	}

	/* -----------------------------------------------------------------------
	 * Style: font, size, colour, alignment
	 * -------------------------------------------------------------------- */

	private function field_style( array $f, string $text ): array {
		$da    = $this->parse_da( $f['da'] );
		$opt   = $this->options;

		// Size:  feed setting → template's own size → global default → auto
		$size = null;
		if ( isset( $opt['font_size'] ) && is_numeric( $opt['font_size'] ) && $opt['font_size'] > 0 )                 $size = (float) $opt['font_size'];
		elseif ( $da['size'] > 0 )                                                                                      $size = $da['size'];
		elseif ( isset( $opt['default_font_size'] ) && is_numeric( $opt['default_font_size'] ) && $opt['default_font_size'] > 0 ) $size = (float) $opt['default_font_size'];
		if ( $size !== null ) $size = max( 4.0, min( 72.0, $size ) );

		// Colour: feed setting → template's own colour → global default
		$color = $da['color'];
		if ( ! empty( $opt['font_color'] ) && ( $c = $this->hex_color_ops( (string) $opt['font_color'] ) ) ) {
			$color = $c;
		} elseif ( $color === null && ! empty( $opt['default_font_color'] ) && ( $c = $this->hex_color_ops( (string) $opt['default_font_color'] ) ) ) {
			$color = $c;
		}
		$color = $color ?: '0 g';

		$spec = $this->select_font( $da, $text, $opt );
		return [ 'spec' => $spec, 'size' => $size, 'color' => $color, 'q' => $f['q'] ];
	}

	private function parse_da( string $da ): array {
		$out = [ 'font' => null, 'size' => 0.0, 'color' => null ];
		if ( preg_match( '/\/([^\s\/]+)\s+(-?[\d.]+)\s+Tf/', $da, $m ) ) {
			$out['font'] = $m[1];
			$out['size'] = (float) $m[2];
		}
		if ( preg_match( '/(-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+)\s+k(?![a-zA-Z])/', $da, $m ) )      $out['color'] = $m[1] . ' k';
		elseif ( preg_match( '/(-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+)\s+rg(?![a-zA-Z])/', $da, $m ) )            $out['color'] = $m[1] . ' rg';
		elseif ( preg_match( '/(?<![\d.])(-?[\d.]+)\s+g(?![a-zA-Z])/', $da, $m ) )                          $out['color'] = $m[1] . ' g';
		return $out;
	}

	private function hex_color_ops( string $hex ): ?string {
		$hex = ltrim( trim( $hex ), '#' );
		if ( strlen( $hex ) === 3 ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) return null;
		return sprintf( '%s %s %s rg',
			$this->n( hexdec( substr( $hex, 0, 2 ) ) / 255 ),
			$this->n( hexdec( substr( $hex, 2, 2 ) ) / 255 ),
			$this->n( hexdec( substr( $hex, 4, 2 ) ) / 255 )
		);
	}

	/* -----------------------------------------------------------------------
	 * Font selection
	 *
	 *  1. Plain Latin text uses the template's own font (Helv etc.) so the
	 *     result looks like the form's designer intended — unless the feed
	 *     picked a specific font.
	 *  2. Accented Latin text uses a WinAnsi-encoded standard font.
	 *  3. Anything else (Cyrillic, Greek, Hebrew, Arabic …) embeds a Unicode
	 *     TrueType font — bundled DejaVu/aeAlArabiya, or a custom font the
	 *     admin uploaded — so the characters actually render.
	 * -------------------------------------------------------------------- */

	private function select_font( array $da, string $text, array $opt ): array {
		$ascii = (bool) preg_match( '/^[\x20-\x7E\n]*$/', $text );
		$win   = $ascii || $this->is_winansi( $text );
		$rtl   = $this->contains_rtl( $text );

		$family = ! empty( $opt['font_family'] ) ? (string) $opt['font_family'] : '';

		// (1) the template's own font
		$da_info = null;
		if ( $family === '' && $da['font'] ) {
			$da_info = $this->da_font_info( $da['font'] );
			if ( $da_info && $ascii ) {
				$def = $this->tcpdf_def( $this->core_key_for_base( $da_info['base'] ) );
				if ( $def ) {
					return [ 'kind' => 'simple', 'res' => $da['font'], 'ref' => $da_info['raw'], 'cw' => $def['cw'], 'dw' => $def['dw'] ?? 600 ];
				}
			}
		}

		if ( $family === '' ) {
			$family = $da_info ? $this->core_key_for_base( $da_info['base'] )
				: ( ! empty( $opt['default_font_family'] ) ? (string) $opt['default_font_family'] : 'helvetica' );
		}

		$def     = $this->tcpdf_def( $family );
		$type    = $def['type'] ?? '';
		$is_core = $def && $type === 'core' && ! in_array( strtolower( $family ), [ 'symbol', 'zapfdingbats' ], true );
		$is_uni  = $def && $type === 'TrueTypeUnicode' && ! empty( $def['file'] ) && ! empty( $def['ctg'] );

		if ( ( $is_core || ! $is_uni ) && $win ) {
			return $this->std_font_spec( $is_core ? $def : $this->tcpdf_def( 'helvetica' ) );
		}

		// Needs a Unicode font. Prefer the admin's own choice if it really has every glyph;
		// otherwise pick whichever bundled font covers the text.
		$pick = $this->pick_unicode_family( $text, $is_uni ? $family : '' );
		if ( $pick ) {
			$pdef = $this->tcpdf_def( $pick );
			if ( $pdef ) return $this->uni_font_spec( strtolower( $pick ), $pdef );
		}
		return $this->std_font_spec( $this->tcpdf_def( 'helvetica' ) ); // absolute fallback
	}

	private function da_font_info( string $name ): ?array {
		$dr    = $this->doc->dict( $this->acro->get( 'DR' ) );
		$fonts = $dr ? $this->doc->dict( $dr->get( 'Font' ) ) : null;
		if ( ! $fonts || ! $fonts->has( $name ) ) return null;
		$raw = $fonts->get( $name );
		$fd  = $this->doc->dict( $raw );
		if ( ! $fd ) return null;
		if ( ! in_array( $this->doc->name( $fd->get( 'Subtype' ) ), [ 'Type1', 'TrueType', 'MMType1' ], true ) ) return null;
		$desc = $this->doc->dict( $fd->get( 'FontDescriptor' ) );
		if ( $desc && ( $desc->has( 'FontFile' ) || $desc->has( 'FontFile2' ) || $desc->has( 'FontFile3' ) ) ) return null; // subset: may lack glyphs
		return [ 'raw' => $raw, 'base' => $this->doc->name( $fd->get( 'BaseFont' ) ) ];
	}

	private function core_key_for_base( string $base ): string {
		$b    = strtolower( preg_replace( '/^[A-Z]{6}\+/', '', $base ) );
		$bold = (bool) preg_match( '/bold|black|heavy|demi/', $b );
		$ital = (bool) preg_match( '/italic|oblique/', $b );
		if ( strpos( $b, 'courier' ) !== false ) $fam = 'courier';
		elseif ( preg_match( '/times|georgia|garamond|palatino|serif/', $b ) && strpos( $b, 'sans' ) === false ) $fam = 'times';
		else $fam = 'helvetica';
		return $fam . ( $bold ? 'b' : '' ) . ( $ital ? 'i' : '' );
	}

	private function std_font_spec( array $def ): array {
		$base = $def['name'] ?? 'Helvetica';
		if ( ! isset( $this->std_fonts[ $base ] ) ) {
			$this->std_fonts[ $base ] = $this->doc->add( new GFFPDF_Pdf_Dict( [
				'Type'     => new GFFPDF_Pdf_Name( 'Font' ),
				'Subtype'  => new GFFPDF_Pdf_Name( 'Type1' ),
				'BaseFont' => new GFFPDF_Pdf_Name( $base ),
				'Encoding' => new GFFPDF_Pdf_Name( 'WinAnsiEncoding' ),
			] ) );
		}
		return [ 'kind' => 'simple', 'res' => 'GFPF' . ( ++$this->font_counter ), 'ref' => $this->std_fonts[ $base ], 'cw' => $def['cw'], 'dw' => $def['dw'] ?? 600 ];
	}

	private function uni_font_spec( string $key, array $def ): array {
		if ( ! isset( $this->uni[ $key ] ) ) {
			$this->uni[ $key ] = [
				'def'  => $def,
				'ref'  => new GFFPDF_Pdf_Ref( $this->doc->alloc(), 0 ), // object written in finish() only if used
				'used' => [],
			];
		}
		return [
			'kind' => 'uni', 'key' => $key, 'res' => 'GFPU' . ( ++$this->font_counter ),
			'ref'  => $this->uni[ $key ]['ref'], 'cw' => $def['cw'], 'dw' => $def['dw'] ?? 600,
		];
	}

	/** Load a TCPDF font definition (.php) from the bundled library or the custom-font folder. */
	private function tcpdf_def( string $key ): ?array {
		$key = strtolower( $key );
		if ( $key === 'pdfa' ) $key = 'helvetica';
		if ( array_key_exists( $key, $this->def_cache ) ) return $this->def_cache[ $key ];

		$dirs = [];
		if ( defined( 'GFFPDF_UPLOAD_DIR' ) ) $dirs[] = GFFPDF_UPLOAD_DIR . 'fonts/';
		if ( defined( 'K_PATH_FONTS' ) )      $dirs[] = K_PATH_FONTS;
		$dirs[] = dirname( __DIR__ ) . '/vendor/tecnickcom/tcpdf/fonts/';

		foreach ( $dirs as $dir ) {
			$file = rtrim( $dir, '/\\' ) . '/' . preg_replace( '/[^a-z0-9_\-]/', '', $key ) . '.php';
			if ( is_file( $file ) ) {
				$def = ( static function ( $__file ) { include $__file; return get_defined_vars(); } )( $file );
				if ( isset( $def['cw'] ) && is_array( $def['cw'] ) ) {
					$def['dir'] = rtrim( $dir, '/\\' ) . '/';
					return $this->def_cache[ $key ] = $def;
				}
			}
		}
		return $this->def_cache[ $key ] = null;
	}

	/* -----------------------------------------------------------------------
	 * Text shaping / measuring / emitting
	 * -------------------------------------------------------------------- */

	/** @return array [ 'codes' => int[], 'w' => width in 1/1000 em ] */
	private function shape( array $spec, string $text ): array {
		if ( $spec['kind'] === 'simple' ) {
			$bytes = $this->to_winansi( $text );
			$codes = $bytes === '' ? [] : array_values( unpack( 'C*', $bytes ) );
			$w = 0;
			foreach ( $codes as $c ) $w += $spec['cw'][ $c ] ?? $spec['dw'];
			return [ 'codes' => $codes, 'w' => $w ];
		}

		$cps = $this->visual_cps( $text );
		$w = 0;
		foreach ( $cps as $c ) $w += $spec['cw'][ $c ] ?? $spec['dw'];
		return [ 'codes' => $cps, 'w' => $w ];
	}

	private function text_width( array $spec, string $text, float $size ): float {
		return $this->shape( $spec, $text )['w'] * $size / 1000;
	}

	/** Build the "( … ) Tj" operand for a run of text, recording glyph use for embedded fonts. */
	private function emit_run( array $spec, array $shaped ): string {
		if ( $spec['kind'] === 'simple' ) {
			$b = $shaped['codes'] ? pack( 'C*', ...$shaped['codes'] ) : '';
			return '(' . preg_replace_callback( '/[\\\\()\r\n\x80-\xFF]/', static function ( $m ) { return sprintf( '\\%03o', ord( $m[0] ) ); }, $b ) . ')';
		}
		$hex = '';
		foreach ( $shaped['codes'] as $c ) {
			$c = $c > 0xFFFF ? 0xFFFD : $c;
			$hex .= sprintf( '%04X', $c );
			$this->uni[ $spec['key'] ]['used'][ $c ] = $spec['cw'][ $c ] ?? $spec['dw'];
		}
		return '<' . $hex . '>';
	}

	private function wrap( array $spec, string $text, float $size, float $avail ): array {
		$lines = [];
		foreach ( explode( "\n", $text ) as $para ) {
			if ( $para === '' ) { $lines[] = ''; continue; }
			$cur = '';
			foreach ( explode( ' ', $para ) as $word ) {
				$test = $cur === '' ? $word : $cur . ' ' . $word;
				if ( $this->text_width( $spec, $test, $size ) <= $avail ) { $cur = $test; continue; }
				if ( $cur !== '' ) { $lines[] = $cur; $cur = ''; }
				if ( $this->text_width( $spec, $word, $size ) <= $avail ) { $cur = $word; continue; }
				// Single word wider than the box: break it by character.
				foreach ( preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
					if ( $cur !== '' && $this->text_width( $spec, $cur . $ch, $size ) > $avail ) { $lines[] = $cur; $cur = ''; }
					$cur .= $ch;
				}
			}
			$lines[] = $cur;
		}
		return $lines;
	}

	/* -----------------------------------------------------------------------
	 * Appearance stream for a text-like widget
	 * -------------------------------------------------------------------- */

	private function text_appearance( array $f, array $w, string $text, array $style, bool $multiline, bool $comb ): ?GFFPDF_Pdf_Ref {
		$g = $this->widget_geometry( $w );
		if ( ! $g ) return null;

		$spec  = $style['spec'];
		$dw    = $g['dw']; $dh = $g['dh'];
		$pad   = $g['bw'] + 2;
		$avail = max( 1.0, $dw - 2 * $pad );
		$q     = $style['q'];
		if ( $q === 0 && $this->contains_rtl( $text ) ) $q = 2;

		$ops = '';
		if ( $multiline ) {
			$inner_h = max( 1.0, $dh - 2 * $pad );
			$size    = $style['size'] ?? 12.0;
			while ( true ) {
				$lines = $this->wrap( $spec, $text, $size, $avail );
				if ( count( $lines ) * $size * 1.15 <= $inner_h || $size <= 4.0 ) break;
				$size -= 0.5;
			}
			$y = $dh - $pad - $size * 0.9;
			$ops .= "BT\n/{$spec['res']} " . $this->n( $size ) . " Tf\n{$style['color']}\n";
			foreach ( $lines as $line ) {
				if ( $line !== '' ) {
					$sh  = $this->shape( $spec, $line );
					$lw  = $sh['w'] * $size / 1000;
					$x   = $q === 2 ? $pad + $avail - $lw : ( $q === 1 ? $pad + ( $avail - $lw ) / 2 : $pad );
					$ops .= '1 0 0 1 ' . $this->n( max( $pad, $x ) ) . ' ' . $this->n( $y ) . " Tm\n" . $this->emit_run( $spec, $sh ) . " Tj\n";
				}
				$y -= $size * 1.15;
			}
			$ops .= "ET\n";
		} elseif ( $comb ) {
			$n     = max( 1, $f['maxlen'] );
			$size  = $style['size'] ?? min( 12.0, max( 4.0, $dh - 2 * $pad - 2 ) );
			$cell  = ( $dw - 2 * $g['bw'] ) / $n;
			$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
			$y     = ( $dh - 0.72 * $size ) / 2;
			$ops  .= "BT\n/{$spec['res']} " . $this->n( $size ) . " Tf\n{$style['color']}\n";
			foreach ( array_slice( $chars, 0, $n ) as $i => $ch ) {
				$sh   = $this->shape( $spec, $ch );
				$cwid = $sh['w'] * $size / 1000;
				$x    = $g['bw'] + $cell * $i + ( $cell - $cwid ) / 2;
				$ops .= '1 0 0 1 ' . $this->n( $x ) . ' ' . $this->n( $y ) . " Tm\n" . $this->emit_run( $spec, $sh ) . " Tj\n";
			}
			$ops .= "ET\n";
		} else {
			$sh    = $this->shape( $spec, $text );
			$size  = $style['size'] ?? min( 12.0, max( 4.0, $dh - 2 * $pad - 2 ) );
			// Shrink (never enlarge) so the value fits on one line inside the box.
			while ( $size > 5.0 && $sh['w'] * $size / 1000 > $avail ) $size -= 0.5;
			$tw = $sh['w'] * $size / 1000;
			$x  = $q === 2 ? $pad + $avail - $tw : ( $q === 1 ? $pad + ( $avail - $tw ) / 2 : $pad );
			$y  = ( $dh - 0.72 * $size ) / 2;
			$ops .= "BT\n/{$spec['res']} " . $this->n( $size ) . " Tf\n{$style['color']}\n";
			$ops .= '1 0 0 1 ' . $this->n( max( $pad, $x ) ) . ' ' . $this->n( $y ) . " Tm\n" . $this->emit_run( $spec, $sh ) . " Tj\nET\n";
		}

		$clip = '1 1 ' . $this->n( max( 1, $dw - 2 ) ) . ' ' . $this->n( max( 1, $dh - 2 ) ) . " re W n\n";
		$body = $g['chrome'] . "/Tx BMC\nq\n" . $clip . $ops . "Q\nEMC\n";

		return $this->form_xobject( $g, $body, new GFFPDF_Pdf_Dict( [
			'Font' => new GFFPDF_Pdf_Dict( [ $spec['res'] => $spec['ref'] ] ),
		] ) );
	}

	/* =======================================================================
	 * Checkboxes & radio buttons
	 * ==================================================================== */

	private function fill_button( array $f, string $value ): bool {
		if ( $f['ff'] & self::FF_PUSHBUTTON ) return false;

		$radio   = (bool) ( $f['ff'] & self::FF_RADIO );
		$widgets = $f['widgets'];
		$count   = count( $widgets );
		$v       = trim( $value );
		$truthy  = in_array( strtolower( $v ), [ 'yes', '1', 'on', 'true', 'checked', 'x', 'y', 'selected' ], true );
		$alias   = is_array( $this->cur_alias ) ? $this->cur_alias : [];

		$norm = static function ( $x ) {
			return preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( (string) $x, 'UTF-8' ) );
		};

		// Everything the submitted choice could be called: the value itself, the GF choice
		// label/value, and the same without a "|price" suffix.
		$cands = [ $v ];
		foreach ( (array) ( $alias['texts'] ?? [] ) as $t ) {
			$t = trim( (string) $t );
			if ( $t !== '' ) $cands[] = $t;
		}
		foreach ( $cands as $c ) {
			$s = preg_replace( '/\|[\d.,\-]+$/', '', $c );
			if ( $s !== $c && $s !== '' ) $cands[] = $s;
		}
		$cands = array_values( array_unique( $cands ) );

		// The template's "on" name of each widget (e.g. Choice1, Yes, 0, 1 ...).
		$states = [];
		foreach ( $widgets as $i => $w ) $states[ $i ] = $this->on_state( $w );

		// Human-readable option labels (/Opt), one per widget, when the template has them.
		$labels = [];
		if ( $f['opt'] && count( $f['opt']->a ) === $count ) {
			foreach ( $f['opt']->a as $i => $o ) {
				$o = $this->doc->resolve( $o );
				$labels[ $i ] = $this->text_of( $o instanceof GFFPDF_Pdf_Arr && $o->a ? $this->doc->resolve( end( $o->a ) ) : $o );
			}
		}

		$on = null;

		// 1. Exact (case-insensitive) match on the widget's on-state name.
		foreach ( $cands as $c ) {
			foreach ( $states as $i => $s ) {
				if ( $s !== null && strcasecmp( (string) $s, $c ) === 0 ) { $on = $i; break 2; }
			}
		}
		// 2. Same, ignoring spaces and punctuation ("Choice 1" = "choice1").
		if ( $on === null ) {
			foreach ( $cands as $c ) {
				$nc = $norm( $c );
				if ( $nc === '' ) continue;
				foreach ( $states as $i => $s ) {
					if ( $s !== null && $norm( $s ) === $nc ) { $on = $i; break 2; }
				}
			}
		}
		// 3. Match the option labels stored in /Opt.
		if ( $on === null && $labels ) {
			foreach ( $cands as $c ) {
				$nc = $norm( $c );
				if ( $nc === '' ) continue;
				foreach ( $labels as $i => $l ) {
					if ( $norm( $l ) === $nc ) { $on = $i; break 2; }
				}
			}
		}
		// 4. Plain on/off: a checkbox, or a radio that has a single widget, ticked by yes/true/1/on.
		if ( $on === null && $truthy && ( ! $radio || $count === 1 ) ) {
			$on = 0;
		}
		// 5. Last resort for a radio group: same position, but only when the PDF group and the
		//    GF field have exactly the same number of options.
		if ( $on === null && $radio && $count > 1 && isset( $alias['index'], $alias['count'] )
			&& (int) $alias['count'] === $count && $alias['index'] >= 0 && $alias['index'] < $count ) {
			$on = (int) $alias['index'];
			$this->note( 
				sprintf(
					/* translators: 1: Radio field fully qualified name, 2: Value supplied, 3: Option position index */
					__( "Radio '%1\$s': no option name matched '%2\$s'; used option position %3\$d instead.", 'gf-fillable-pdf-generator' ),
					$f['fqn'],
					$v,
					$on + 1
				)
			);
		}

		if ( $on === null ) {
			$shown = [];
			foreach ( $states as $s ) $shown[] = $s === null ? '(none)' : (string) $s;
			$this->note( "Button '{$f['fqn']}': value '$v' matches none of its options [" . implode( ', ', $shown )
				. ( $labels ? ' | labels: ' . implode( ', ', $labels ) : '' )
				. ']. Rename the PDF export values to match the form choices.' );
		}

		// A widget with no "on" appearance in the template gets one generated.
		$needs_ap = false;
		if ( $on !== null && $states[ $on ] === null ) {
			$states[ $on ] = ( $radio && $count > 1 ) ? (string) $on : 'Yes';
			$needs_ap      = true;
		}

		$fd = &$this->work( $f['num'], $f['dict'] );
		$fd->set( 'V', new GFFPDF_Pdf_Name( $on !== null ? (string) $states[ $on ] : 'Off' ) );

		foreach ( $widgets as $i => $w ) {
			$wd = &$this->work( $w['num'], $w['dict'] );
			if ( $i === $on ) {
				if ( $needs_ap ) $this->make_check_ap( $wd, $w, $radio, (string) $states[ $i ] );
				$wd->set( 'AS', new GFFPDF_Pdf_Name( (string) $states[ $i ] ) );
			} else {
				$wd->set( 'AS', new GFFPDF_Pdf_Name( 'Off' ) );
			}
		}
		return $on !== null;
	}

	private function on_state( array $w ): ?string {
		$ap = $this->doc->dict( $w['dict']->get( 'AP' ) );
		if ( ! $ap ) return null;
		$n = $this->doc->resolve( $ap->get( 'N' ) );
		if ( ! $n instanceof GFFPDF_Pdf_Dict ) return null;
		foreach ( array_keys( $n->d ) as $k ) {
			if ( $k !== 'Off' ) return (string) $k;
		}
		return null;
	}

	private function make_check_ap( GFFPDF_Pdf_Dict $wd, array $w, bool $radio, string $state = 'Yes' ): void {
		$g = $this->widget_geometry( $w );
		if ( ! $g ) return;
		$font = $this->doc->add( new GFFPDF_Pdf_Dict( [
			'Type' => new GFFPDF_Pdf_Name( 'Font' ), 'Subtype' => new GFFPDF_Pdf_Name( 'Type1' ),
			'BaseFont' => new GFFPDF_Pdf_Name( 'ZapfDingbats' ),
		] ) );
		$size  = max( 4.0, min( $g['dw'], $g['dh'] ) * 0.8 );
		$glyph = $radio ? 'l' : '4';
		$gw    = $radio ? 0.791 : 0.846;
		$x     = ( $g['dw'] - $size * $gw ) / 2;
		$y     = ( $g['dh'] - $size * 0.7 ) / 2;
		$on    = $g['chrome'] . "q\nBT\n/ZaDb " . $this->n( $size ) . " Tf\n0 g\n" . $this->n( $x ) . ' ' . $this->n( $y ) . " Td\n($glyph) Tj\nET\nQ\n";
		$res   = new GFFPDF_Pdf_Dict( [ 'Font' => new GFFPDF_Pdf_Dict( [ 'ZaDb' => $font ] ) ] );
		$wd->set( 'AP', new GFFPDF_Pdf_Dict( [
			'N' => new GFFPDF_Pdf_Dict( [
				$state => $this->form_xobject( $g, $on, $res ),
				'Off' => $this->form_xobject( $g, $g['chrome'], new GFFPDF_Pdf_Dict() ),
			] ),
		] ) );
	}

	/* =======================================================================
	 * Images (signatures etc.)
	 * ==================================================================== */

	private function looks_like_image( string $value ): bool {
		$value = trim( $value );
		if ( $value === '' || strlen( $value ) > 20000000 ) return false;
		if ( preg_match( '#^data:image/(png|jpe?g|gif);base64,#i', $value ) ) return true;
		if ( strpos( $value, "\n" ) !== false || strlen( $value ) > 2000 ) return false;
		return class_exists( 'GFFPDF_Helpers' ) && (bool) GFFPDF_Helpers::url_to_path( $value );
	}

	private function fill_image( array $f, string $value ): bool {
		$key = md5( $value );
		if ( ! array_key_exists( $key, $this->image_cache ) ) {
			$this->image_cache[ $key ] = $this->load_image( $value );
		}
		$img = $this->image_cache[ $key ];
		if ( ! $img ) {
			$this->note( 
				sprintf(
					/* translators: %s: Field fully qualified name */
					__( "Image for field '%s' could not be read.", 'gf-fillable-pdf-generator' ),
					$f['fqn']
				)
			);
			return false;
		}

		foreach ( $f['widgets'] as $w ) {
			$g = $this->widget_geometry( $w );
			if ( ! $g ) continue;
			$pad   = 1.0;
			$bw    = max( 1.0, $g['dw'] - 2 * $pad );
			$bh    = max( 1.0, $g['dh'] - 2 * $pad );
			$scale = min( $bw / $img['w'], $bh / $img['h'] );
			$iw    = $img['w'] * $scale;
			$ih    = $img['h'] * $scale;
			$x     = ( $g['dw'] - $iw ) / 2;
			$y     = ( $g['dh'] - $ih ) / 2;
			$body  = $g['chrome'] . "q\n" . $this->n( $iw ) . ' 0 0 ' . $this->n( $ih ) . ' ' . $this->n( $x ) . ' ' . $this->n( $y ) . " cm\n/Im0 Do\nQ\n";
			$ref   = $this->form_xobject( $g, $body, new GFFPDF_Pdf_Dict( [
				'XObject' => new GFFPDF_Pdf_Dict( [ 'Im0' => $img['ref'] ] ),
			] ) );
			$wd = &$this->work( $w['num'], $w['dict'] );
			$wd->set( 'AP', new GFFPDF_Pdf_Dict( [ 'N' => $ref ] ) );
		}
		return true;
	}

	private function load_image( string $value ): ?array {
		$value = trim( $value );
		$raw   = null;
		if ( preg_match( '#^data:image/\w+;base64,#i', $value ) ) {
			$raw = base64_decode( preg_replace( '#^data:image/\w+;base64,#i', '', $value ), true );
		} elseif ( class_exists( 'GFFPDF_Helpers' ) ) {
			$path = GFFPDF_Helpers::url_to_path( $value );
			if ( $path && is_readable( $path ) && filesize( $path ) < 15000000 ) $raw = file_get_contents( $path );
		}
		if ( ! is_string( $raw ) || $raw === '' ) return null;

		$info = @getimagesizefromstring( $raw );
		if ( ! $info ) return null;

		// JPEG: embed as-is (no GD needed).
		if ( $info[2] === IMAGETYPE_JPEG && in_array( $info['channels'] ?? 3, [ 1, 3 ], true ) && ( $info['bits'] ?? 8 ) === 8 ) {
			$cs  = ( $info['channels'] ?? 3 ) === 1 ? 'DeviceGray' : 'DeviceRGB';
			$ref = $this->doc->add( new GFFPDF_Pdf_Stream( new GFFPDF_Pdf_Dict( [
				'Type' => new GFFPDF_Pdf_Name( 'XObject' ), 'Subtype' => new GFFPDF_Pdf_Name( 'Image' ),
				'Width' => $info[0], 'Height' => $info[1], 'ColorSpace' => new GFFPDF_Pdf_Name( $cs ),
				'BitsPerComponent' => 8, 'Filter' => new GFFPDF_Pdf_Name( 'DCTDecode' ),
			] ), $raw ) );
			return [ 'ref' => $ref, 'w' => $info[0], 'h' => $info[1] ];
		}

		// PNG/GIF/other: decode to raw RGB (+ alpha), keep transparency as a soft mask.
		// GD is used when present; PNGs also decode with the built-in pure-PHP reader,
		// so signature images never depend on an optional PHP extension.
		$dec = null;
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$dec = $this->decode_with_gd( $raw );
		}
		if ( ! $dec && $info[2] === IMAGETYPE_PNG ) {
			$dec = $this->decode_png( $raw );
		}
		if ( ! $dec ) {
			$this->note( __('This image format could not be decoded (use PNG or JPEG, or enable the PHP GD extension).', 'gf-fillable-pdf-generator') );
			return null;
		}
		$w = $dec['w']; $h = $dec['h'];
		$has_alpha = $dec['alpha'] !== null;

		$dict = new GFFPDF_Pdf_Dict( [
			'Type' => new GFFPDF_Pdf_Name( 'XObject' ), 'Subtype' => new GFFPDF_Pdf_Name( 'Image' ),
			'Width' => $w, 'Height' => $h, 'ColorSpace' => new GFFPDF_Pdf_Name( 'DeviceRGB' ),
			'BitsPerComponent' => 8, 'Filter' => new GFFPDF_Pdf_Name( 'FlateDecode' ),
		] );
		if ( $has_alpha ) {
			$mask = $this->doc->add( new GFFPDF_Pdf_Stream( new GFFPDF_Pdf_Dict( [
				'Type' => new GFFPDF_Pdf_Name( 'XObject' ), 'Subtype' => new GFFPDF_Pdf_Name( 'Image' ),
				'Width' => $w, 'Height' => $h, 'ColorSpace' => new GFFPDF_Pdf_Name( 'DeviceGray' ),
				'BitsPerComponent' => 8, 'Filter' => new GFFPDF_Pdf_Name( 'FlateDecode' ),
			] ), gzcompress( $dec['alpha'], 6 ) ) );
			$dict->set( 'SMask', $mask );
		}
		$ref = $this->doc->add( new GFFPDF_Pdf_Stream( $dict, gzcompress( $dec['rgb'], 6 ) ) );
		return [ 'ref' => $ref, 'w' => $w, 'h' => $h ];
	}

	/** @return array|null [ 'w', 'h', 'rgb' => string, 'alpha' => string|null ] */
	private function decode_with_gd( string $raw ): ?array {
		$im = @imagecreatefromstring( $raw );
		if ( ! $im ) return null;
		$w = imagesx( $im ); $h = imagesy( $im );
		if ( max( $w, $h ) > 1200 ) {
			$r  = 1200 / max( $w, $h );
			$nw = max( 1, (int) round( $w * $r ) ); $nh = max( 1, (int) round( $h * $r ) );
			$im2 = imagecreatetruecolor( $nw, $nh );
			imagealphablending( $im2, false ); imagesavealpha( $im2, true );
			imagefill( $im2, 0, 0, imagecolorallocatealpha( $im2, 255, 255, 255, 127 ) );
			imagecopyresampled( $im2, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
			imagedestroy( $im ); $im = $im2; $w = $nw; $h = $nh;
		}
		if ( ! imageistruecolor( $im ) ) imagepalettetotruecolor( $im );
		$rgb = []; $alpha = []; $has_alpha = false;
		for ( $y = 0; $y < $h; $y++ ) {
			$rr = ''; $ra = '';
			for ( $x = 0; $x < $w; $x++ ) {
				$c = imagecolorat( $im, $x, $y );
				$a = ( $c >> 24 ) & 0x7F;
				if ( $a > 0 ) $has_alpha = true;
				$rr .= chr( ( $c >> 16 ) & 255 ) . chr( ( $c >> 8 ) & 255 ) . chr( $c & 255 );
				$ra .= chr( 255 - (int) round( $a * 255 / 127 ) );
			}
			$rgb[] = $rr; $alpha[] = $ra;
		}
		imagedestroy( $im );
		return [ 'w' => $w, 'h' => $h, 'rgb' => implode( '', $rgb ), 'alpha' => $has_alpha ? implode( '', $alpha ) : null ];
	}

	/**
	 * Pure-PHP PNG reader (non-interlaced; gray / RGB / palette / +alpha; 1-16 bit).
	 * @return array|null [ 'w', 'h', 'rgb', 'alpha' ]
	 */
	private function decode_png( string $raw ): ?array {
		if ( strncmp( $raw, "\x89PNG\r\n\x1a\n", 8 ) !== 0 ) return null;
		$pos = 8; $n = strlen( $raw );
		$ihdr = null; $plte = ''; $trns = ''; $idat = '';
		while ( $pos + 8 <= $n ) {
			$len  = unpack( 'N', substr( $raw, $pos, 4 ) )[1];
			$type = substr( $raw, $pos + 4, 4 );
			$data = substr( $raw, $pos + 8, $len );
			$pos += 12 + $len;
			if ( $type === 'IHDR' )      $ihdr = unpack( 'Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $data );
			elseif ( $type === 'PLTE' )  $plte = $data;
			elseif ( $type === 'tRNS' )  $trns = $data;
			elseif ( $type === 'IDAT' )  $idat .= $data;
			elseif ( $type === 'IEND' )  break;
		}
		if ( ! $ihdr || $ihdr['il'] !== 0 || $ihdr['w'] < 1 || $ihdr['h'] < 1 || $ihdr['w'] * $ihdr['h'] > 2500000 ) return null;
		$w = $ihdr['w']; $h = $ihdr['h']; $bd = $ihdr['bd']; $ct = $ihdr['ct'];
		$channels = [ 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4 ][ $ct ] ?? 0;
		if ( ! $channels || ! in_array( $bd, [ 1, 2, 4, 8, 16 ], true ) ) return null;
		$data = @gzuncompress( $idat );
		if ( $data === false ) return null;

		$bits_pp = $channels * $bd;
		$bpp     = max( 1, intdiv( $bits_pp + 7, 8 ) );
		$rowlen  = intdiv( $w * $bits_pp + 7, 8 );
		if ( strlen( $data ) < $h * ( $rowlen + 1 ) ) return null;

		$rgb = []; $alpha = []; $has_alpha = false;
		$prev = array_fill( 0, $rowlen, 0 );
		$maxv = ( 1 << min( $bd, 8 ) ) - 1;
		$pal  = $ct === 3 ? str_split( $plte, 3 ) : [];
		$tr   = $ct === 3 && $trns !== '' ? array_values( unpack( 'C*', $trns ) ) : [];

		for ( $y = 0; $y < $h; $y++ ) {
			$ft  = ord( $data[ $y * ( $rowlen + 1 ) ] );
			$cur = array_values( unpack( 'C*', substr( $data, $y * ( $rowlen + 1 ) + 1, $rowlen ) ) );
			for ( $i = 0; $i < $rowlen; $i++ ) {
				$a = $i >= $bpp ? $cur[ $i - $bpp ] : 0;
				$b = $prev[ $i ];
				$c = $i >= $bpp ? $prev[ $i - $bpp ] : 0;
				switch ( $ft ) {
					case 1: $cur[ $i ] = ( $cur[ $i ] + $a ) & 255; break;
					case 2: $cur[ $i ] = ( $cur[ $i ] + $b ) & 255; break;
					case 3: $cur[ $i ] = ( $cur[ $i ] + ( ( $a + $b ) >> 1 ) ) & 255; break;
					case 4:
						$pp = $a + $b - $c; $pa = abs( $pp - $a ); $pb = abs( $pp - $b ); $pc = abs( $pp - $c );
						$cur[ $i ] = ( $cur[ $i ] + ( ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c ) ) ) & 255;
						break;
				}
			}
			$prev = $cur;

			$rr = ''; $ra = '';
			if ( $bd === 8 && ( $ct === 2 || $ct === 6 ) ) {
				if ( $ct === 2 ) {
					$rr = pack( 'C*', ...$cur );
				} else {
					for ( $x = 0; $x < $w; $x++ ) {
						$o = $x * 4;
						$rr .= chr( $cur[ $o ] ) . chr( $cur[ $o + 1 ] ) . chr( $cur[ $o + 2 ] );
						$ra .= chr( $cur[ $o + 3 ] );
						if ( $cur[ $o + 3 ] < 255 ) $has_alpha = true;
					}
				}
			} else {
				// Generic (slower) path for gray, palette and 16-bit images.
				for ( $x = 0; $x < $w; $x++ ) {
					$px = [];
					for ( $ch = 0; $ch < $channels; $ch++ ) {
						if ( $bd === 8 )       $v = $cur[ $x * $channels + $ch ];
						elseif ( $bd === 16 )  $v = $cur[ ( $x * $channels + $ch ) * 2 ];
						else {
							$bit = ( $x * $channels + $ch ) * $bd;
							$v   = ( $cur[ $bit >> 3 ] >> ( 8 - $bd - ( $bit & 7 ) ) ) & $maxv;
							if ( $ct !== 3 ) $v = (int) round( $v * 255 / $maxv );
						}
						$px[] = $v;
					}
					switch ( $ct ) {
						case 0: $rr .= str_repeat( chr( $px[0] ), 3 ); break;
						case 2: $rr .= chr( $px[0] ) . chr( $px[1] ) . chr( $px[2] ); break;
						case 3:
							$rr .= $pal[ $px[0] ] ?? "\0\0\0";
							if ( $tr ) { $av = $tr[ $px[0] ] ?? 255; $ra .= chr( $av ); if ( $av < 255 ) $has_alpha = true; }
							break;
						case 4: $rr .= str_repeat( chr( $px[0] ), 3 ); $ra .= chr( $px[1] ); if ( $px[1] < 255 ) $has_alpha = true; break;
						case 6: $rr .= chr( $px[0] ) . chr( $px[1] ) . chr( $px[2] ); $ra .= chr( $px[3] ); if ( $px[3] < 255 ) $has_alpha = true; break;
					}
				}
			}
			$rgb[] = $rr;
			if ( $ra !== '' ) $alpha[] = $ra;
		}
		return [ 'w' => $w, 'h' => $h, 'rgb' => implode( '', $rgb ), 'alpha' => ( $has_alpha && count( $alpha ) === $h ) ? implode( '', $alpha ) : null ];
	}

	/* =======================================================================
	 * Widget geometry / chrome (background + border) / form XObject
	 * ==================================================================== */

	private function widget_geometry( array $w ): ?array {
		$d    = $w['dict'];
		$rect = $this->doc->nums( $d->get( 'Rect' ) );
		if ( count( $rect ) < 4 ) return null;
		$W = abs( $rect[2] - $rect[0] );
		$H = abs( $rect[3] - $rect[1] );
		if ( $W < 1 || $H < 1 ) return null;

		$mk = $this->doc->dict( $d->get( 'MK' ) );
		$R  = $mk ? ( (int) $this->doc->num( $mk->get( 'R' ), 0 ) % 360 + 360 ) % 360 : 0;

		switch ( $R ) {
			case 90:  $dw = $H; $dh = $W; $matrix = [ 0, 1, -1, 0, $W, 0 ]; break;
			case 180: $dw = $W; $dh = $H; $matrix = [ -1, 0, 0, -1, $W, $H ]; break;
			case 270: $dw = $H; $dh = $W; $matrix = [ 0, -1, 1, 0, 0, $H ]; break;
			default:  $dw = $W; $dh = $H; $matrix = null;
		}

		// Border width: /BS /W, else /Border[2], else 1.
		$bw = 1.0; $underline = false;
		$bs = $this->doc->dict( $d->get( 'BS' ) );
		if ( $bs ) {
			if ( $bs->has( 'W' ) ) $bw = (float) $this->doc->num( $bs->get( 'W' ), 1 );
			$underline = $this->doc->name( $bs->get( 'S' ) ) === 'U';
		} else {
			$b = $this->doc->nums( $d->get( 'Border' ) );
			if ( count( $b ) >= 3 ) $bw = $b[2];
		}

		$bg = $mk ? $this->color_nums( $this->doc->nums( $mk->get( 'BG' ) ) ) : null;
		$bc = $mk ? $this->color_nums( $this->doc->nums( $mk->get( 'BC' ) ) ) : null;

		$chrome = '';
		if ( $bg ) {
			$chrome .= "q\n" . $bg['fill'] . "\n0 0 " . $this->n( $dw ) . ' ' . $this->n( $dh ) . " re f\nQ\n";
		}
		if ( $bc && $bw > 0 ) {
			if ( $underline ) {
				$chrome .= "q\n" . $bc['stroke'] . "\n" . $this->n( $bw ) . " w\n0 " . $this->n( $bw / 2 ) . ' m ' . $this->n( $dw ) . ' ' . $this->n( $bw / 2 ) . " l S\nQ\n";
			} else {
				$chrome .= "q\n" . $bc['stroke'] . "\n" . $this->n( $bw ) . " w\n" . $this->n( $bw / 2 ) . ' ' . $this->n( $bw / 2 ) . ' '
					. $this->n( $dw - $bw ) . ' ' . $this->n( $dh - $bw ) . " re S\nQ\n";
			}
		}

		return [ 'dw' => $dw, 'dh' => $dh, 'matrix' => $matrix, 'bw' => $bc ? $bw : 0.0, 'chrome' => $chrome ];
	}

	private function color_nums( array $c ): ?array {
		$s = implode( ' ', array_map( [ $this, 'n' ], $c ) );
		switch ( count( $c ) ) {
			case 1: return [ 'fill' => "$s g",  'stroke' => "$s G" ];
			case 3: return [ 'fill' => "$s rg", 'stroke' => "$s RG" ];
			case 4: return [ 'fill' => "$s k",  'stroke' => "$s K" ];
		}
		return null;
	}

	private function form_xobject( array $g, string $body, GFFPDF_Pdf_Dict $resources ): GFFPDF_Pdf_Ref {
		$d = new GFFPDF_Pdf_Dict( [
			'Type'      => new GFFPDF_Pdf_Name( 'XObject' ),
			'Subtype'   => new GFFPDF_Pdf_Name( 'Form' ),
			'BBox'      => new GFFPDF_Pdf_Arr( [ 0, 0, round( $g['dw'], 4 ), round( $g['dh'], 4 ) ] ),
			'Resources' => $resources,
		] );
		if ( $g['matrix'] ) {
			$d->set( 'Matrix', new GFFPDF_Pdf_Arr( array_map( static function ( $v ) { return (float) $v; }, $g['matrix'] ) ) );
		}
		return $this->doc->add( new GFFPDF_Pdf_Stream( $d, $body ) );
	}

	/* =======================================================================
	 * Finishing: embedded fonts, AcroForm flags, changed objects
	 * ==================================================================== */

	private function finish(): void {
		// Embedded Unicode fonts (only those that were actually used).
		foreach ( $this->uni as $key => $u ) {
			if ( ! $u['used'] ) continue;
			$def = $u['def'];
			$ff  = @file_get_contents( $def['dir'] . $def['file'] );
			$ctg = @file_get_contents( $def['dir'] . $def['ctg'] );
			if ( $ff === false || $ctg === false ) {
				$this->need_appearances = true;
				$this->note( "Font data for '$key' is missing." );
				continue;
			}
			$fontfile = $this->doc->add( new GFFPDF_Pdf_Stream( new GFFPDF_Pdf_Dict( [
				'Filter' => new GFFPDF_Pdf_Name( 'FlateDecode' ), 'Length1' => (int) ( $def['originalsize'] ?? 0 ),
			] ), $ff ) );
			$cidmap = $this->doc->add( new GFFPDF_Pdf_Stream( new GFFPDF_Pdf_Dict( [
				'Filter' => new GFFPDF_Pdf_Name( 'FlateDecode' ),
			] ), $ctg ) );

			$desc = $def['desc'] ?? [];
			preg_match_all( '/-?\d+/', (string) ( $desc['FontBBox'] ?? '' ), $bb );
			$fdesc = $this->doc->add( new GFFPDF_Pdf_Dict( [
				'Type'        => new GFFPDF_Pdf_Name( 'FontDescriptor' ),
				'FontName'    => new GFFPDF_Pdf_Name( $def['name'] ),
				'Flags'       => (int) ( $desc['Flags'] ?? 32 ),
				'FontBBox'    => new GFFPDF_Pdf_Arr( array_map( 'intval', $bb[0] ?: [ -1000, -300, 2000, 1100 ] ) ),
				'ItalicAngle' => (int) ( $desc['ItalicAngle'] ?? 0 ),
				'Ascent'      => (int) ( $desc['Ascent'] ?? 900 ),
				'Descent'     => (int) ( $desc['Descent'] ?? -200 ),
				'CapHeight'   => (int) ( $desc['CapHeight'] ?? 700 ),
				'StemV'       => (int) ( $desc['StemV'] ?? 80 ),
				'FontFile2'   => $fontfile,
			] ) );

			ksort( $u['used'] );
			$w = [];
			foreach ( $u['used'] as $cid => $width ) {
				$w[] = (int) $cid;
				$w[] = new GFFPDF_Pdf_Arr( [ (int) $width ] );
			}
			$cid_font = $this->doc->add( new GFFPDF_Pdf_Dict( [
				'Type'           => new GFFPDF_Pdf_Name( 'Font' ),
				'Subtype'        => new GFFPDF_Pdf_Name( 'CIDFontType2' ),
				'BaseFont'       => new GFFPDF_Pdf_Name( $def['name'] ),
				'CIDSystemInfo'  => new GFFPDF_Pdf_Dict( [
					'Registry' => new GFFPDF_Pdf_Str( 'Adobe' ), 'Ordering' => new GFFPDF_Pdf_Str( 'Identity' ), 'Supplement' => 0,
				] ),
				'FontDescriptor' => $fdesc,
				'DW'             => (int) ( $def['dw'] ?? 600 ),
				'W'              => new GFFPDF_Pdf_Arr( $w ),
				'CIDToGIDMap'    => $cidmap,
			] ) );
			$this->doc->put( $u['ref']->num, new GFFPDF_Pdf_Dict( [
				'Type'            => new GFFPDF_Pdf_Name( 'Font' ),
				'Subtype'         => new GFFPDF_Pdf_Name( 'Type0' ),
				'BaseFont'        => new GFFPDF_Pdf_Name( $def['name'] ),
				'Encoding'        => new GFFPDF_Pdf_Name( 'Identity-H' ),
				'DescendantFonts' => new GFFPDF_Pdf_Arr( [ $cid_font ] ),
			] ) );
		}

		// AcroForm: drop XFA (viewers would otherwise prefer it over our values),
		// and only ask viewers to regenerate appearances if we could not build one.
		$acro = clone $this->acro;
		$acro_changed = false;
		if ( $acro->has( 'XFA' ) ) { $acro->remove( 'XFA' ); $acro_changed = true; }
		if ( $this->need_appearances ) { $acro->set( 'NeedAppearances', true ); $acro_changed = true; }
		if ( $this->acro_rebuilt ) {
			$acro->set( 'Fields', new GFFPDF_Pdf_Arr( $this->field_roots ) );
			if ( ! $acro->has( 'DA' ) ) $acro->set( 'DA', new GFFPDF_Pdf_Str( '/Helv 0 Tf 0 g' ) );
			$acro_changed = true;
		}
		if ( $acro_changed && ( $this->work || $this->doc->has_changes() ) ) {
			if ( $this->acro_num !== null ) {
				$this->doc->put( $this->acro_num, $acro );
			} else {
				$cat = clone $this->doc->catalog();
				$cat->set( 'AcroForm', $acro );
				$root = $this->doc->trailer()->get( 'Root' );
				$this->doc->put( $root->num, $cat );
			}
		}

		foreach ( $this->work as $num => $dict ) {
			$this->doc->put( $num, $dict );
		}
	}

	/* =======================================================================
	 * Small helpers
	 * ==================================================================== */

	private function note( string $msg ): void {
		$this->log[] = $msg;
		if ( class_exists( 'GFFPDF_Logger' ) ) {
			GFFPDF_Logger::warn( $msg );
		}
	}

	private function n( $v ): string {
		$s = rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' );
		return ( $s === '' || $s === '-0' ) ? '0' : $s;
	}

	/** PDF text string: plain ASCII stays as-is, everything else becomes UTF-16BE with BOM. */
	private function pdf_text( string $s ): GFFPDF_Pdf_Str {
		if ( preg_match( '/^[\x20-\x7E\n\r\t]*$/', $s ) ) return new GFFPDF_Pdf_Str( $s );
		return new GFFPDF_Pdf_Str( "\xFE\xFF" . mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' ) );
	}

	/** Decode a PDF text string to UTF-8. */
	private function text_of( $v ): string {
		if ( ! $v instanceof GFFPDF_Pdf_Str ) return '';
		$s = $v->v;
		if ( strncmp( $s, "\xFE\xFF", 2 ) === 0 )     return mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16BE' );
		if ( strncmp( $s, "\xFF\xFE", 2 ) === 0 )     return mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16LE' );
		if ( strncmp( $s, "\xEF\xBB\xBF", 3 ) === 0 ) return substr( $s, 3 );
		return preg_match( '//u', $s ) ? $s : mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
	}

	private function is_winansi( string $s ): bool {
		$enc = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		return $enc !== false && @mb_convert_encoding( $enc, 'UTF-8', 'Windows-1252' ) === $s;
	}

	private function to_winansi( string $s ): string {
		$enc = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		return $enc === false ? '' : $enc;
	}

	private function utf8_to_cps( string $s ): array {
		if ( $s === '' ) return [];
		return array_values( unpack( 'N*', mb_convert_encoding( $s, 'UTF-32BE', 'UTF-8' ) ) );
	}

	/**
	 * RTL handling is applied only when the RTL toggle is on (or, if the caller gave
	 * no toggle, automatically) AND the text really contains right-to-left script.
	 */
	private function contains_rtl( string $text ): bool {
		if ( $this->rtl_mode === false ) return false;
		return $this->has_rtl_chars( $text );
	}

	/**
	 * True if the text contains a character from ANY right-to-left script —
	 * not just Arabic and Hebrew. Covers every RTL Unicode block, so Persian, Urdu,
	 * Pashto, Sindhi, Kurdish (Sorani), Uyghur, Yiddish, Dhivehi (Thaana), Syriac,
	 * N'Ko, Samaritan, Mandaic, Adlam, Hanifi Rohingya, Phoenician and the
	 * historic RTL scripts are all recognised.
	 */
	private function has_rtl_chars( string $text ): bool {
		if ( strlen( $text ) === mb_strlen( $text, 'UTF-8' ) ) return false; // pure ASCII
		return (bool) preg_match(
			'/[\x{0590}-\x{08FF}\x{200F}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u',
			$text
		);
	}

	/** Code points in the order they should be drawn (bidi-reordered and Arabic-shaped when RTL applies). */
	private function visual_cps( string $text ): array {
		$cps = $this->utf8_to_cps( $text );
		if ( $cps && $this->contains_rtl( $text ) && class_exists( 'TCPDF_FONTS' ) ) {
			try {
				$cf  = [ 'cw' => [], 'subsetchars' => [] ];
				$vis = TCPDF_FONTS::utf8Bidi( $cps, $text, false, true, $cf );
				if ( is_array( $vis ) && $vis ) $cps = array_values( $vis );
			} catch ( \Throwable $e ) {}
		}
		return $cps;
	}

	/**
	 * Choose a Unicode font that has a glyph for every character in $text.
	 * Candidates (in order): the admin's chosen font, aeAlArabiya for pure Arabic
	 * text, then DejaVu Sans, FreeSerif, FreeSans and FreeMono. Measured coverage of
	 * these: Hebrew/Yiddish/Cyrillic/Greek/N'Ko = DejaVu; Arabic, Persian, Urdu,
	 * Pashto, Sindhi, Kurdish and Thaana (Dhivehi) = FreeSerif; Syriac = FreeSans.
	 * Scripts none of them cover need a custom font uploaded in Fonts settings.
	 * If nothing covers the text completely, the best partial match is used.
	 */
	private function pick_unicode_family( string $text, string $preferred = '' ): ?string {
		$need = [];
		foreach ( $this->visual_cps( $text ) as $c ) {
			if ( $c > 32 && $c !== 0xA0 && ! ( $c >= 0x200B && $c <= 0x200F ) && ! ( $c >= 0x202A && $c <= 0x202E ) ) $need[ $c ] = true;
		}
		$cands = [];
		if ( $preferred !== '' ) $cands[] = strtolower( $preferred );
		if ( $this->has_arabic( $text ) && ! $this->has_hebrew( $text ) ) $cands[] = 'aealarabiya';
		foreach ( [ 'dejavusans', 'freeserif', 'freesans', 'freemono' ] as $k ) $cands[] = $k;

		$best = null; $best_n = -1;
		foreach ( array_unique( $cands ) as $k ) {
			$d = $this->tcpdf_def( $k );
			if ( ! $d || ( $d['type'] ?? '' ) !== 'TrueTypeUnicode' || empty( $d['file'] ) || empty( $d['ctg'] ) ) continue;
			if ( ! is_file( $d['dir'] . $d['file'] ) || ! is_file( $d['dir'] . $d['ctg'] ) ) continue;
			$n = 0;
			foreach ( $need as $c => $_ ) { if ( isset( $d['cw'][ $c ] ) ) $n++; }
			if ( $n === count( $need ) ) return $k;
			if ( $n > $best_n ) { $best_n = $n; $best = $k; }
		}
		if ( $best !== null && count( $need ) > 0 && $best_n < count( $need ) ) {
			$this->note( __('Some characters have no glyph in any available font; upload a font that supports this script in Fonts settings.', 'gf-fillable-pdf-generator') );
		}
		return $best;
	}

	private function has_hebrew( string $text ): bool {
		return (bool) preg_match( '/[\x{0590}-\x{05FF}\x{FB1D}-\x{FB4F}]/u', $text );
	}

	private function has_arabic( string $text ): bool {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text );
	}
}