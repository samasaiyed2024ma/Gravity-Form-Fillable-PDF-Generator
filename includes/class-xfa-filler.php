<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-pdf-document.php';

/**
 * Support for XFA forms (Adobe LiveCycle / "dynamic" forms) that have NO
 * AcroForm fields to fill.
 *
 * An XFA form keeps its fields in an XML <template> and its values in an XML
 * <datasets> packet. To fill one we:
 *   1. read the template and work out the field names (dotted path of the
 *      named subforms + the field name, e.g. "form1.address.city");
 *   2. write the values into the matching nodes of the datasets packet.
 *
 * Adobe Acrobat / Reader then lay the form out with the values in place.
 * (Other viewers cannot render XFA-only forms at all — that is a property of
 * the PDF, not of this plugin.)
 *
 * Limits: repeating sections are filled for their first instance only, and
 * explicit data-binding expressions (<bind ref="...">) are not evaluated —
 * fields are matched by name, which is XFA's default "normal" binding.
 */
class GFFPDF_XFA_Filler {

	const NS_DATA = 'http://www.xfa.org/schema/xfa-data/1.0/';

	/** @var GFFPDF_Pdf_Document */
	private $doc;
	private $mode = 'array';          // 'array' (packet pairs) or 'single' (one XDP stream)
	private $packets = [];            // name => [ 'ref' => Ref|null, 'xml' => string ]
	private $xfa_raw = null;
	private $acro = null;
	private $acro_ref = null;
	private $cat_new = null;          // catalog copy when AcroForm is a direct object
	private $log = [];

	/** @var array[] [ 'path' => string[], 'name' => string, 'type' => string, 'on' => string, 'off' => string ] */
	private $fields = [];
	private $root_name = '';

	/* =======================================================================
	 * Public API
	 * ==================================================================== */

	/** True when the PDF carries an XFA form. */
	public static function has_xfa( string $bytes ): bool {
		try {
			$f = new self();
			$f->load( $bytes );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * @return array[] rows shaped like GFFPDF_AcroForm_Filler::list_fields()
	 * @throws RuntimeException
	 */
	public static function list_fields( string $bytes ): array {
		$f = new self();
		$f->load( $bytes );
		$rows = [];
		foreach ( $f->fields as $fl ) {
			$rows[] = [
				'field_name'  => $fl['name'],
				'field_type'  => $fl['type'],
				'page_number' => 1,
				'rect_x1' => 0, 'rect_y1' => 0, 'rect_x2' => 0, 'rect_y2' => 0,
				'page_width' => 612, 'page_height' => 792,
			];
		}
		return $rows;
	}

	/**
	 * @param array $values field name (full dotted path, or its last part when unique) => value
	 * @throws RuntimeException
	 */
	public function fill( string $bytes, array $values, array $options = [] ): string {
		$this->load( $bytes );

		$by_full = [];
		$by_last = [];
		foreach ( $this->fields as $i => $fl ) {
			$by_full[ $fl['name'] ] = $i;
			$by_last[ end( $fl['path'] ) ][] = $i;
		}

		$ds = $this->datasets_dom();
		$data = $this->data_node( $ds );

		foreach ( $values as $name => $value ) {
			$name  = (string) $name;
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( $value === '' ) continue;

			if ( isset( $by_full[ $name ] ) ) {
				$idx = $by_full[ $name ];
			} elseif ( isset( $by_last[ $name ] ) && count( $by_last[ $name ] ) === 1 ) {
				$idx = $by_last[ $name ][0];
			} else {
				$this->log[] = "No XFA field named '$name' in the template.";
				continue;
			}
			$fl = $this->fields[ $idx ];

			if ( $fl['type'] === 'checkbox' || $fl['type'] === 'radio' ) {
				$low   = strtolower( trim( $value ) );
				$is_off = in_array( $low, [ 'off', 'no', '0', 'false', 'unchecked', 'n' ], true );
				$value = $is_off ? $fl['off'] : ( ( $low === strtolower( $fl['off'] ) ) ? $fl['off'] : $fl['on'] );
			}
			try {
				$this->set_value( $ds, $data, $fl['path'], $value );
			} catch ( \Throwable $e ) {
				$this->log[] = "Field '$name' could not be filled: " . $e->getMessage();
			}
		}

		$this->write_back( $ds );
		return $this->doc->save();
	}

	public function get_notes(): array {
		return $this->log;
	}

	/* =======================================================================
	 * Loading
	 * ==================================================================== */

	private function load( string $bytes ): void {
		$this->doc = new GFFPDF_Pdf_Document( $bytes );
		$cat = $this->doc->catalog();
		if ( ! $cat ) throw new RuntimeException( 'PDF catalog not found.' );

		$raw = $cat->get( 'AcroForm' );
		$this->acro_ref = $raw instanceof GFFPDF_Pdf_Ref ? $raw : null;
		$this->acro     = $this->doc->dict( $raw );
		if ( ! $this->acro || ! $this->acro->has( 'XFA' ) ) {
			throw new RuntimeException( 'This PDF has no XFA form.' );
		}

		$this->xfa_raw = $this->acro->get( 'XFA' );
		$x = $this->doc->resolve( $this->xfa_raw );

		if ( $x instanceof GFFPDF_Pdf_Arr ) {
			$this->mode = 'array';
			for ( $i = 0; $i + 1 < count( $x->a ); $i += 2 ) {
				$n = $this->doc->resolve( $x->a[ $i ] );
				$n = $n instanceof GFFPDF_Pdf_Str ? $n->v : '';
				$r = $x->a[ $i + 1 ];
				$s = $this->doc->resolve( $r );
				if ( $n === '' || ! $s instanceof GFFPDF_Pdf_Stream ) continue;
				$this->packets[ $n ] = [ 'ref' => $r instanceof GFFPDF_Pdf_Ref ? $r : null, 'xml' => $this->doc->decode_stream( $s ) ];
			}
			if ( empty( $this->packets['template'] ) ) {
				throw new RuntimeException( 'The XFA form has no template packet.' );
			}
			$template_xml = $this->packets['template']['xml'];
			$tdom = $this->dom( $template_xml );
			$troot = $tdom->documentElement;
		} elseif ( $x instanceof GFFPDF_Pdf_Stream ) {
			$this->mode = 'single';
			$xml = $this->doc->decode_stream( $x );
			$this->packets['xdp'] = [ 'ref' => $this->xfa_raw instanceof GFFPDF_Pdf_Ref ? $this->xfa_raw : null, 'xml' => $xml ];
			$xdom = $this->dom( $xml );
			$troot = null;
			foreach ( $xdom->documentElement->childNodes as $c ) {
				if ( $c instanceof DOMElement && $c->localName === 'template' ) { $troot = $c; break; }
			}
			if ( ! $troot ) throw new RuntimeException( 'The XFA form has no template packet.' );
		} else {
			throw new RuntimeException( 'The XFA entry of this PDF could not be read.' );
		}

		$this->fields = [];
		$this->root_name = '';
		foreach ( $troot->childNodes as $c ) {
			if ( $c instanceof DOMElement && $c->localName === 'subform' ) {
				$this->root_name = $c->getAttribute( 'name' );
				break;
			}
		}
		$seen = [];
		$this->walk( $troot, [], $seen );
		if ( ! $this->fields ) {
			throw new RuntimeException( 'No fillable fields found in the XFA form.' );
		}
	}

	private function dom( string $xml ): DOMDocument {
		$d = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ok = $d->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $d->documentElement ) {
			throw new RuntimeException( 'The XFA XML could not be parsed.' );
		}
		return $d;
	}

	/* -----------------------------------------------------------------------
	 * Template walk
	 * -------------------------------------------------------------------- */

	private function walk( DOMNode $node, array $path, array &$seen ): void {
		foreach ( $node->childNodes as $c ) {
			if ( ! $c instanceof DOMElement ) continue;
			$ln   = $c->localName;
			$name = $c->getAttribute( 'name' );

			if ( $ln === 'subform' || $ln === 'subformSet' || $ln === 'area' ) {
				if ( $this->bind_none( $c ) ) continue;
				$p = ( $ln === 'subform' && $name !== '' ) ? array_merge( $path, [ $name ] ) : $path;
				$this->walk( $c, $p, $seen );
				continue;
			}

			if ( ( $ln === 'field' || $ln === 'exclGroup' ) && $name !== '' ) {
				if ( $this->bind_none( $c ) ) continue;
				$type = $ln === 'exclGroup' ? 'radio' : $this->field_type( $c );
				if ( $type === '' ) continue;

				$full = implode( '.', array_merge( $path, [ $name ] ) );
				if ( isset( $seen[ $full ] ) ) continue;
				$seen[ $full ] = true;

				[ $on, $off ] = $this->on_off( $c );
				$this->fields[] = [
					'path' => array_merge( $path, [ $name ] ),
					'name' => $full,
					'type' => $type,
					'on'   => $on,
					'off'  => $off,
				];
			}
		}
	}

	private function bind_none( DOMElement $el ): bool {
		foreach ( $el->childNodes as $c ) {
			if ( $c instanceof DOMElement && $c->localName === 'bind' ) {
				return strtolower( $c->getAttribute( 'match' ) ) === 'none';
			}
		}
		return false;
	}

	private function field_type( DOMElement $field ): string {
		foreach ( $field->childNodes as $c ) {
			if ( ! $c instanceof DOMElement || $c->localName !== 'ui' ) continue;
			foreach ( $c->childNodes as $u ) {
				if ( ! $u instanceof DOMElement ) continue;
				switch ( $u->localName ) {
					case 'checkButton': return 'checkbox';
					case 'choiceList':  return 'select';
					case 'button': case 'barcode': case 'imageEdit': case 'signature': return '';
					default:            return 'text';
				}
			}
		}
		return 'text';
	}

	private function on_off( DOMElement $field ): array {
		$on = '1'; $off = '0';
		foreach ( $field->childNodes as $c ) {
			if ( $c instanceof DOMElement && $c->localName === 'items' ) {
				$vals = [];
				foreach ( $c->childNodes as $v ) {
					if ( $v instanceof DOMElement ) $vals[] = trim( $v->textContent );
				}
				if ( isset( $vals[0] ) && $vals[0] !== '' ) $on = $vals[0];
				if ( isset( $vals[1] ) && $vals[1] !== '' ) $off = $vals[1];
				break;
			}
		}
		return [ $on, $off ];
	}

	/* =======================================================================
	 * Datasets
	 * ==================================================================== */

	private function datasets_dom(): DOMDocument {
		if ( $this->mode === 'single' ) {
			return $this->dom( $this->packets['xdp']['xml'] );
		}
		if ( ! empty( $this->packets['datasets']['xml'] ) ) {
			return $this->dom( $this->packets['datasets']['xml'] );
		}
		$d = new DOMDocument( '1.0', 'UTF-8' );
		$root = $d->createElementNS( self::NS_DATA, 'xfa:datasets' );
		$d->appendChild( $root );
		$root->appendChild( $d->createElementNS( self::NS_DATA, 'xfa:data' ) );
		return $d;
	}

	/** The <xfa:data> element (created inside <xfa:datasets> if missing). */
	private function data_node( DOMDocument $d ): DOMElement {
		$ds = null;
		if ( $this->mode === 'single' ) {
			foreach ( $d->documentElement->childNodes as $c ) {
				if ( $c instanceof DOMElement && $c->localName === 'datasets' ) { $ds = $c; break; }
			}
			if ( ! $ds ) {
				$ds = $d->createElementNS( self::NS_DATA, 'xfa:datasets' );
				$d->documentElement->appendChild( $ds );
			}
		} else {
			$ds = $d->documentElement;
		}
		foreach ( $ds->childNodes as $c ) {
			if ( $c instanceof DOMElement && $c->localName === 'data' ) return $c;
		}
		$data = $d->createElementNS( self::NS_DATA, 'xfa:data' );
		$ds->appendChild( $data );
		return $data;
	}

	/**
	 * Write $value at $path below <xfa:data>. The root subform maps to the
	 * first data group; unnamed subforms never produced a path segment.
	 */
	private function set_value( DOMDocument $d, DOMElement $data, array $path, string $value ): void {
		$cur = $data;
		foreach ( $path as $seg ) {
			$next = null;
			foreach ( $cur->childNodes as $c ) {
				if ( $c instanceof DOMElement && $c->nodeName === $seg ) { $next = $c; break; }
			}
			if ( ! $next ) {
				$next = $d->createElement( $seg );
				$cur->appendChild( $next );
			}
			$cur = $next;
		}
		while ( $cur->firstChild ) $cur->removeChild( $cur->firstChild );
		$cur->appendChild( $d->createTextNode( $value ) );
	}

	/* -----------------------------------------------------------------------
	 * Persist
	 * -------------------------------------------------------------------- */

	private function pack( string $xml ): GFFPDF_Pdf_Stream {
		return new GFFPDF_Pdf_Stream(
			new GFFPDF_Pdf_Dict( [ 'Filter' => new GFFPDF_Pdf_Name( 'FlateDecode' ) ] ),
			(string) gzcompress( $xml, 6 )
		);
	}

	private function write_back( DOMDocument $ds ): void {
		$xml = $ds->saveXML();

		if ( $this->mode === 'single' ) {
			$ref = $this->packets['xdp']['ref'];
			if ( ! $ref ) throw new RuntimeException( 'XFA stream is not an indirect object.' );
			$this->doc->put( $ref->num, $this->pack( $xml ) );
		} else {
			// The datasets packet is an element on its own — drop the XML declaration noise.
			$xml = $ds->saveXML( $ds->documentElement );
			$ref = $this->packets['datasets']['ref'] ?? null;
			if ( $ref ) {
				$this->doc->put( $ref->num, $this->pack( $xml ) );
			} else {
				// No datasets packet yet: add one (before the postamble if there is one).
				$new = $this->doc->add( $this->pack( $xml ) );
				$arr = $this->doc->resolve( $this->xfa_raw );
				$items = $arr->a;
				$pos = count( $items );
				for ( $i = 0; $i + 1 < count( $items ); $i += 2 ) {
					$n = $this->doc->resolve( $items[ $i ] );
					if ( $n instanceof GFFPDF_Pdf_Str && $n->v === 'postamble' ) { $pos = $i; break; }
				}
				array_splice( $items, $pos, 0, [ new GFFPDF_Pdf_Str( 'datasets' ), $new ] );
				$new_arr = new GFFPDF_Pdf_Arr( $items );
				if ( $this->xfa_raw instanceof GFFPDF_Pdf_Ref ) {
					$this->doc->put( $this->xfa_raw->num, $new_arr );
				} else {
					$a = clone $this->acro;
					$a->set( 'XFA', $new_arr );
					$this->put_acro( $a );
				}
			}
		}

		// Usage-rights signatures (/Perms) become invalid the moment we change
		// the file, which makes Acrobat show a "document has been changed" error.
		$cat = $this->cat_new ?: $this->doc->catalog();
		if ( $cat && $cat->has( 'Perms' ) ) {
			$c2 = clone $cat;
			$c2->remove( 'Perms' );
			$root = $this->doc->trailer()->get( 'Root' );
			if ( $root instanceof GFFPDF_Pdf_Ref ) $this->doc->put( $root->num, $c2 );
		}
	}

	private function put_acro( GFFPDF_Pdf_Dict $a ): void {
		if ( $this->acro_ref ) {
			$this->doc->put( $this->acro_ref->num, $a );
			return;
		}
		$this->cat_new = clone $this->doc->catalog();
		$this->cat_new->set( 'AcroForm', $a );
		$root = $this->doc->trailer()->get( 'Root' );
		$this->doc->put( $root->num, $this->cat_new );
	}
}