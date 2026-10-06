<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Minimal, dependency-free PDF object model + incremental-update writer.
 *
 * Why this exists: filling a PDF's own AcroForm fields (so the result stays
 * editable) means reading the original file's objects and appending changed
 * objects to it — an "incremental update", exactly what Acrobat does when you
 * type into a form and press Save. Doing that in pure PHP removes the need
 * for pdftk, Ghostscript, qpdf, or any other binary on the server.
 *
 * Supports classic xref tables, PDF 1.5 cross-reference streams, object
 * streams, hybrid files and /Prev chains. Falls back to rebuilding the xref
 * by scanning when the file's own xref is damaged.
 *
 * Encrypted files that open with an EMPTY user password (the usual "locked
 * against editing" PDF) are decrypted on read via GFFPDF_Pdf_Crypt and
 * written back out as a clean, unencrypted file. Files that need a real
 * password are rejected.
 */
require_once __DIR__ . '/class-pdf-crypt.php';


/* ---------------------------------------------------------------------------
 * Value classes (numbers, booleans and null are plain PHP scalars)
 * ------------------------------------------------------------------------ */
class GFFPDF_Pdf_Name   { public $v; public function __construct( $v ) { $this->v = (string) $v; } }
class GFFPDF_Pdf_Ref    { public $num; public $gen; public function __construct( $n, $g = 0 ) { $this->num = (int) $n; $this->gen = (int) $g; } }
class GFFPDF_Pdf_Str    { public $v; public function __construct( $v ) { $this->v = (string) $v; } }
class GFFPDF_Pdf_Kw     { public $v; public function __construct( $v ) { $this->v = (string) $v; } }
class GFFPDF_Pdf_Arr    { public $a = []; public function __construct( array $a = [] ) { $this->a = $a; } }
class GFFPDF_Pdf_Dict {
	public $d = [];
	public function __construct( array $d = [] ) { $this->d = $d; }
	public function get( string $k ) { return $this->d[ $k ] ?? null; }
	public function set( string $k, $v ): self { $this->d[ $k ] = $v; return $this; }
	public function has( string $k ): bool { return array_key_exists( $k, $this->d ); }
	public function remove( string $k ): self { unset( $this->d[ $k ] ); return $this; }
}
class GFFPDF_Pdf_Stream {
	public $dict; public $data; // $data = raw bytes exactly as stored (still filter-encoded)
	public function __construct( GFFPDF_Pdf_Dict $dict, string $data ) { $this->dict = $dict; $this->data = $data; }
}

class GFFPDF_Pdf_Document {

	private $s;
	private $len;
	private $p = 0;

	/** @var array<int,array> objnum => [type, a, b]  type 0 free | 1 [offset, gen] | 2 [objstm, index] */
	private $xref = [];
	/** @var GFFPDF_Pdf_Dict */
	private $trailer;
	private $cache = [];
	private $objstm_loaded = [];
	private $startxref = 0;
	private $newest_is_stream = false;
	private $rebuilt = false;
	private $size = 0;

	/** @var GFFPDF_Pdf_Crypt|null Set when the source file is encrypted. */
	private $crypt = null;
	private $encrypt_num = null;

	/** @var array<int,array> objnum => [gen, value] — objects added/changed by us */
	private $new = [];
	private $next_num = 0;

	/* =======================================================================
	 * Construction / xref loading
	 * ==================================================================== */

	public function __construct( string $data ) {
		if ( strpos( substr( $data, 0, 2048 ), '%PDF-' ) === false ) {
			throw new RuntimeException( __('Not a PDF file.', 'gf-fillable-pdf-generator') );
		}
		$this->s   = $data;
		$this->len = strlen( $data );

		try {
			$this->load_xref();
			if ( ! $this->trailer instanceof GFFPDF_Pdf_Dict || ! $this->trailer->get( 'Root' ) instanceof GFFPDF_Pdf_Ref ) {
				throw new RuntimeException( __('Trailer has no Root.', 'gf-fillable-pdf-generator') );
			}
			// Sanity check that the catalog is actually reachable.
			if ( ! $this->catalog() ) {
				throw new RuntimeException( __('Catalog unreadable.', 'gf-fillable-pdf-generator') );
			}
		} catch ( \Throwable $e ) {
			$this->rebuild_xref();
		}

		if ( $this->trailer->get( 'Encrypt' ) !== null ) {
			$this->setup_encryption();
		}

		$max = $this->xref ? max( array_keys( $this->xref ) ) : 0;
		$sz  = $this->trailer->get( 'Size' );
		$this->size     = max( is_int( $sz ) ? $sz : 0, $max + 1 );
		$this->next_num = $this->size;
	}

	private function load_xref(): void {
		$tail = substr( $this->s, -2048 );
		$pos  = strrpos( $tail, 'startxref' );
		if ( $pos === false || ! preg_match( '/startxref\s+(\d+)/', $tail, $m, 0, $pos ) ) {
			throw new RuntimeException( __('startxref not found.', 'gf-fillable-pdf-generator') );
		}
		$this->startxref = (int) $m[1];

		$queue = [ $this->startxref ];
		$seen  = [];
		$first = true;

		while ( $queue ) {
			$off = array_shift( $queue );
			if ( isset( $seen[ $off ] ) ) {
				continue;
			}
			$seen[ $off ] = true;
			if ( $off < 0 || $off >= $this->len ) {
				if ( $first ) throw new RuntimeException( __('Bad startxref.', 'gf-fillable-pdf-generator') );
				continue;
			}

			$is_stream = false;
			$trailer   = $this->read_xref_section( $off, $is_stream );

			if ( $first ) {
				$this->trailer          = $trailer;
				$this->newest_is_stream = $is_stream;
				$first                  = false;
			} else {
				foreach ( [ 'Root', 'Info', 'ID', 'Encrypt' ] as $k ) {
					if ( ! $this->trailer->has( $k ) && $trailer->has( $k ) ) {
						$this->trailer->set( $k, $trailer->get( $k ) );
					}
				}
			}

			// Hybrid file: classic table first, then its XRefStm, then /Prev.
			$xs = $trailer->get( 'XRefStm' );
			if ( is_int( $xs ) ) $queue[] = $xs;
			$pv = $trailer->get( 'Prev' );
			if ( is_int( $pv ) || is_float( $pv ) ) $queue[] = (int) $pv;
		}
	}

	private function read_xref_section( int $off, bool &$is_stream ): GFFPDF_Pdf_Dict {
		$this->p = $off;
		$this->skip_ws();

		if ( substr( $this->s, $this->p, 4 ) === 'xref' ) {
			$is_stream = false;
			$this->p  += 4;
			while ( true ) {
				$this->skip_ws();
				if ( substr( $this->s, $this->p, 7 ) === 'trailer' ) {
					$this->p += 7;
					break;
				}
				if ( ! preg_match( '/\G(\d+)[ \t\r\n]+(\d+)[ \t\r\n]*/', $this->s, $m, 0, $this->p ) ) {
					throw new RuntimeException( __('Bad xref subsection.', 'gf-fillable-pdf-generator') );
				}
				$this->p += strlen( $m[0] );
				$start = (int) $m[1];
				$count = (int) $m[2];
				for ( $i = 0; $i < $count; $i++ ) {
					if ( ! preg_match( '/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->s, $e, 0, $this->p ) ) {
						throw new RuntimeException( __('Bad xref entry.', 'gf-fillable-pdf-generator') );
					}
					$this->p += strlen( $e[0] );
					// Common producer bug: subsection says "1 N" but first entry is the free head.
					if ( $i === 0 && $start === 1 && $e[3] === 'f' && (int) $e[2] === 65535 ) {
						$start = 0;
					}
					$num = $start + $i;
					if ( ! isset( $this->xref[ $num ] ) ) {
						$this->xref[ $num ] = $e[3] === 'n' ? [ 1, (int) $e[1], (int) $e[2] ] : [ 0, 0, 0 ];
					}
				}
			}
			$tr = $this->parse_value();
			if ( ! $tr instanceof GFFPDF_Pdf_Dict ) {
				throw new RuntimeException( __('Bad trailer.', 'gf-fillable-pdf-generator') );
			}
			return $tr;
		}

		// Cross-reference stream.
		$is_stream = true;
		$obj = $this->parse_indirect_at( $off );
		$st  = $obj[2];
		if ( ! $st instanceof GFFPDF_Pdf_Stream ) {
			throw new RuntimeException( __('Expected xref stream.', 'gf-fillable-pdf-generator') );
		}
		$d    = $st->dict;
		$data = $this->decode_stream( $st );
		$w    = $this->arr_ints( $d->get( 'W' ) );
		if ( count( $w ) < 3 ) throw new RuntimeException( __('Bad xref /W.', 'gf-fillable-pdf-generator') );
		$size  = (int) $d->get( 'Size' );
		$index = $this->arr_ints( $d->get( 'Index' ) );
		if ( ! $index ) $index = [ 0, $size ];

		$rowlen = array_sum( $w );
		$pos    = 0;
		for ( $i = 0; $i + 1 < count( $index ); $i += 2 ) {
			for ( $j = 0; $j < $index[ $i + 1 ]; $j++ ) {
				if ( $pos + $rowlen > strlen( $data ) ) break 2;
				$f = [];
				foreach ( $w as $wi => $width ) {
					$v = 0;
					for ( $b = 0; $b < $width; $b++ ) {
						$v = ( $v << 8 ) | ord( $data[ $pos++ ] );
					}
					$f[ $wi ] = $v;
				}
				$type = $w[0] === 0 ? 1 : $f[0];
				$num  = $index[ $i ] + $j;
				if ( isset( $this->xref[ $num ] ) ) continue;
				if ( $type === 1 )      $this->xref[ $num ] = [ 1, $f[1], $f[2] ];
				elseif ( $type === 2 )  $this->xref[ $num ] = [ 2, $f[1], $f[2] ];
				else                    $this->xref[ $num ] = [ 0, 0, 0 ];
			}
		}
		return $d;
	}

	/** Last-resort recovery: scan the whole file for "N G obj" markers. */
	private function rebuild_xref(): void {
		$this->rebuilt    = true;
		$this->xref       = [];
		$this->cache      = [];
		$this->objstm_loaded = [];
		$this->newest_is_stream = false;

		if ( preg_match_all( '/(?<![0-9])(\d{1,10})[ \t\r\n]+(\d{1,5})[ \t\r\n]+obj\b/', $this->s, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $i => $hit ) {
				$this->xref[ (int) $hit[0] ] = [ 1, $m[0][ $i ][1], (int) $m[2][ $i ][0] ]; // later definitions win
			}
		}

		$trailer = null;
		if ( preg_match_all( '/trailer\s*(<<)/', $this->s, $tm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $tm[1] as $hit ) {
				$this->p = $hit[1];
				try {
					$d = $this->parse_value();
					if ( $d instanceof GFFPDF_Pdf_Dict && $d->get( 'Root' ) ) {
						$trailer = $d; // keep the last one
					}
				} catch ( \Throwable $e ) {}
			}
		}

		// Object streams + xref-stream dictionaries + catalog discovery.
		$catalog_ref = null;
		foreach ( array_keys( $this->xref ) as $num ) {
			$e = $this->xref[ $num ];
			if ( $e[0] !== 1 ) continue;
			try {
				$o = $this->parse_indirect_at( $e[1] );
			} catch ( \Throwable $ex ) { continue; }
			$val  = $o[2];
			$dict = $val instanceof GFFPDF_Pdf_Stream ? $val->dict : $val;
			if ( ! $dict instanceof GFFPDF_Pdf_Dict ) continue;
			$type = $dict->get( 'Type' );
			$tn   = $type instanceof GFFPDF_Pdf_Name ? $type->v : '';
			if ( $tn === 'ObjStm' && $val instanceof GFFPDF_Pdf_Stream ) {
				try {
					$data  = $this->decode_stream( $val );
					$n     = (int) $dict->get( 'N' );
					$first = (int) $dict->get( 'First' );
					preg_match_all( '/\d+/', substr( $data, 0, $first ), $hm );
					for ( $i = 0; $i < $n && isset( $hm[0][ $i * 2 ] ); $i++ ) {
						$on = (int) $hm[0][ $i * 2 ];
						if ( ! isset( $this->xref[ $on ] ) || $this->xref[ $on ][0] !== 1 ) {
							$this->xref[ $on ] = [ 2, $num, $i ];
						}
					}
				} catch ( \Throwable $ex ) {}
			} elseif ( $tn === 'Catalog' ) {
				$catalog_ref = new GFFPDF_Pdf_Ref( $num, $e[2] );
			} elseif ( $tn === 'XRef' && ! $trailer ) {
				$trailer = new GFFPDF_Pdf_Dict( $dict->d );
			}
		}
		// Catalogs living inside object streams.
		if ( ! $catalog_ref ) {
			foreach ( $this->xref as $num => $e ) {
				if ( $e[0] !== 2 ) continue;
				try { $v = $this->get_object( $num ); } catch ( \Throwable $ex ) { continue; }
				if ( $v instanceof GFFPDF_Pdf_Dict && $v->get( 'Type' ) instanceof GFFPDF_Pdf_Name && $v->get( 'Type' )->v === 'Catalog' ) {
					$catalog_ref = new GFFPDF_Pdf_Ref( $num, 0 );
				}
			}
		}

		if ( ! $trailer ) {
			$trailer = new GFFPDF_Pdf_Dict();
		}
		if ( ! $trailer->get( 'Root' ) instanceof GFFPDF_Pdf_Ref ) {
			if ( ! $catalog_ref ) {
				throw new RuntimeException( __('Could not locate the PDF catalog; the file appears to be damaged.', 'gf-fillable-pdf-generator') );
			}
			$trailer->set( 'Root', $catalog_ref );
		}
		$this->trailer = $trailer;
		$this->startxref = 0;
	}

	/* =======================================================================
	 * Object access
	 * ==================================================================== */

	public function get_object( int $num ) {
		if ( array_key_exists( $num, $this->cache ) ) {
			return $this->cache[ $num ];
		}
		$e = $this->xref[ $num ] ?? null;
		if ( ! $e || $e[0] === 0 ) {
			return null;
		}
		if ( $e[0] === 1 ) {
			$o = $this->parse_indirect_at( $e[1] );
			if ( $o[0] !== $num ) {
				/* translators: %s: PDF object number */
				throw new RuntimeException( sprintf(esc_html__( 'Object %s not found at its xref offset.', 'gf-fillable-pdf-generator' ), esc_html( $num )) );
			}
			$val = $o[2];
			if ( $this->crypt && $num !== $this->encrypt_num ) {
				$val = $this->decrypt_value( $val, $num, (int) $o[1] );
			}
			return $this->cache[ $num ] = $val;
		}
		$this->load_objstm( $e[1] );
		return $this->cache[ $num ] ?? null;
	}


	/* =======================================================================
	 * Encryption (empty user password only)
	 * ==================================================================== */

	private function setup_encryption(): void {
		$raw = $this->trailer->get( 'Encrypt' );
		if ( $raw instanceof GFFPDF_Pdf_Ref ) {
			$this->encrypt_num = $raw->num;
		}
		$enc = $this->dict( $raw );
		if ( ! $enc ) {
			throw new RuntimeException( __('This PDF is encrypted and its encryption settings could not be read.', 'gf-fillable-pdf-generator') );
		}
		$id  = $this->resolve( $this->trailer->get( 'ID' ) );
		$id0 = '';
		if ( $id instanceof GFFPDF_Pdf_Arr && $id->a ) {
			$f = $this->resolve( $id->a[0] );
			if ( $f instanceof GFFPDF_Pdf_Str ) $id0 = $f->v;
		}

		$this->crypt = new GFFPDF_Pdf_Crypt( $this, $enc, $id0 );

		// Anything parsed before the key existed holds undecrypted strings.
		$this->cache         = [];
		$this->objstm_loaded = [];
	}

	/** True when the source file was encrypted (output is then a full, unencrypted rewrite). */
	public function was_encrypted(): bool {
		return $this->crypt !== null;
	}

	private function decrypt_value( $v, int $num, int $gen ) {
		if ( $v instanceof GFFPDF_Pdf_Str ) {
			return new GFFPDF_Pdf_Str( $this->crypt->decrypt_string( $v->v, $num, $gen ) );
		}
		if ( $v instanceof GFFPDF_Pdf_Arr ) {
			foreach ( $v->a as $i => $x ) $v->a[ $i ] = $this->decrypt_value( $x, $num, $gen );
			return $v;
		}
		if ( $v instanceof GFFPDF_Pdf_Dict ) {
			foreach ( $v->d as $k => $x ) $v->d[ $k ] = $this->decrypt_value( $x, $num, $gen );
			return $v;
		}
		if ( $v instanceof GFFPDF_Pdf_Stream ) {
			$type = $v->dict->get( 'Type' );
			$type = $type instanceof GFFPDF_Pdf_Name ? $type->v : '';
			$skip = $type === 'XRef' || ( $type === 'Metadata' && ! $this->crypt->encrypts_metadata() );
			// Explicit /Crypt filter with the Identity filter = stored in clear text.
			$flt = $v->dict->get( 'Filter' );
			$first = $flt instanceof GFFPDF_Pdf_Arr ? ( $flt->a[0] ?? null ) : $flt;
			if ( $first instanceof GFFPDF_Pdf_Name && $first->v === 'Crypt' ) $skip = true;

			$v->dict = $this->decrypt_value( $v->dict, $num, $gen );
			if ( ! $skip ) {
				$v->data = $this->crypt->decrypt_stream( $v->data, $num, $gen );
			}
			return $v;
		}
		return $v;
	}

	/** Generation number to use when rewriting an existing object. */
	public function gen_of( int $num ): int {
		$e = $this->xref[ $num ] ?? null;
		return ( $e && $e[0] === 1 ) ? (int) $e[2] : 0;
	}

	public function resolve( $v ) {
		$guard = 0;
		while ( $v instanceof GFFPDF_Pdf_Ref && $guard++ < 32 ) {
			$v = $this->get_object( $v->num );
		}
		return $v instanceof GFFPDF_Pdf_Ref ? null : $v;
	}

	public function dict( $v ): ?GFFPDF_Pdf_Dict {
		$v = $this->resolve( $v );
		if ( $v instanceof GFFPDF_Pdf_Stream ) return $v->dict;
		return $v instanceof GFFPDF_Pdf_Dict ? $v : null;
	}

	public function arr( $v ): ?GFFPDF_Pdf_Arr {
		$v = $this->resolve( $v );
		return $v instanceof GFFPDF_Pdf_Arr ? $v : null;
	}

	public function name( $v ): string {
		$v = $this->resolve( $v );
		return $v instanceof GFFPDF_Pdf_Name ? $v->v : '';
	}

	public function num( $v, $default = 0 ) {
		$v = $this->resolve( $v );
		return ( is_int( $v ) || is_float( $v ) ) ? $v : $default;
	}

	/** @return float[] */
	public function nums( $v ): array {
		$a = $this->arr( $v );
		if ( ! $a ) return [];
		$out = [];
		foreach ( $a->a as $x ) {
			$x = $this->resolve( $x );
			$out[] = ( is_int( $x ) || is_float( $x ) ) ? (float) $x : 0.0;
		}
		return $out;
	}

	public function trailer(): GFFPDF_Pdf_Dict { return $this->trailer; }

	public function catalog(): ?GFFPDF_Pdf_Dict {
		return $this->dict( $this->trailer->get( 'Root' ) );
	}

	/**
	 * Flat list of pages in order.
	 * @return array[] each: [ 'num'=>?int, 'dict'=>Dict, 'box'=>[x0,y0,x1,y1], 'rotate'=>int ]
	 */
	public function pages(): array {
		$cat = $this->catalog();
		if ( ! $cat ) return [];
		$out  = [];
		$seen = [];
		$walk = function ( $ref, array $inh, int $depth ) use ( &$walk, &$out, &$seen ) {
			if ( $depth > 64 ) return;
			$num = $ref instanceof GFFPDF_Pdf_Ref ? $ref->num : null;
			if ( $num !== null ) {
				if ( isset( $seen[ $num ] ) ) return;
				$seen[ $num ] = true;
			}
			$d = $this->dict( $ref );
			if ( ! $d ) return;
			foreach ( [ 'MediaBox', 'Rotate' ] as $k ) {
				if ( $d->has( $k ) ) $inh[ $k ] = $d->get( $k );
			}
			$kids = $this->arr( $d->get( 'Kids' ) );
			if ( $kids ) {
				foreach ( $kids->a as $kid ) $walk( $kid, $inh, $depth + 1 );
				return;
			}
			$box = $this->nums( $inh['MediaBox'] ?? null );
			if ( count( $box ) < 4 ) $box = [ 0, 0, 612, 792 ];
			$box = [ min( $box[0], $box[2] ), min( $box[1], $box[3] ), max( $box[0], $box[2] ), max( $box[1], $box[3] ) ];
			$out[] = [
				'num'    => $num,
				'dict'   => $d,
				'box'    => $box,
				'rotate' => (int) $this->num( $inh['Rotate'] ?? 0, 0 ),
			];
		};
		$walk( $cat->get( 'Pages' ), [], 0 );
		return $out;
	}

	/* =======================================================================
	 * Parsing
	 * ==================================================================== */

	private function skip_ws(): void {
		if ( preg_match( '/\G(?:[\x00\t\n\f\r ]+|%[^\r\n]*)*/', $this->s, $m, 0, $this->p ) ) {
			$this->p += strlen( $m[0] );
		}
	}

	/** @return array [ objnum, gen, value ] */
	private function parse_indirect_at( int $off ): array {
		if ( ! preg_match( '/\G[\x00\t\n\f\r ]*(\d+)[ \t\r\n]+(\d+)[ \t\r\n]+obj\b/', $this->s, $m, 0, $off ) ) {
			/* translators: %s: File offset position */
			throw new RuntimeException( sprintf( esc_html__( 'No object at offset %s.', 'gf-fillable-pdf-generator' ), esc_html( $off ) ) );
		}
		$this->p = $off + strlen( $m[0] );
		$val     = $this->parse_value();

		if ( $val instanceof GFFPDF_Pdf_Dict ) {
			$save = $this->p;
			$this->skip_ws();
			if ( substr( $this->s, $this->p, 6 ) === 'stream' ) {
				$this->p += 6;
				if ( ( $this->s[ $this->p ] ?? '' ) === "\r" && ( $this->s[ $this->p + 1 ] ?? '' ) === "\n" ) $this->p += 2;
				elseif ( in_array( $this->s[ $this->p ] ?? '', [ "\n", "\r" ], true ) ) $this->p += 1;

				$start  = $this->p;
				$length = $val->get( 'Length' );
				if ( $length instanceof GFFPDF_Pdf_Ref ) {
					try { $length = $this->resolve( $length ); } catch ( \Throwable $e ) { $length = null; }
				}
				$data = null;
				if ( is_int( $length ) && $length >= 0 && $start + $length <= $this->len ) {
					if ( preg_match( '/\G[\x00\t\n\f\r ]*endstream/', $this->s, $mm, 0, $start + $length ) ) {
						$data = substr( $this->s, $start, $length );
					}
				}
				if ( $data === null ) {
					$end = strpos( $this->s, 'endstream', $start );
					if ( $end === false ) throw new RuntimeException( __('Unterminated stream.', 'gf-fillable-pdf-generator') );
					$data = substr( $this->s, $start, $end - $start );
					$data = preg_replace( '/(\r\n|\n|\r)$/', '', $data );
				}
				$val = new GFFPDF_Pdf_Stream( $val, $data );
			} else {
				$this->p = $save;
			}
		}
		return [ (int) $m[1], (int) $m[2], $val ];
	}

	private function load_objstm( int $stm_num ): void {
		if ( isset( $this->objstm_loaded[ $stm_num ] ) ) return;
		$this->objstm_loaded[ $stm_num ] = true;

		$st = $this->get_object( $stm_num );
		if ( ! $st instanceof GFFPDF_Pdf_Stream ) return;
		$data  = $this->decode_stream( $st );
		$n     = (int) $st->dict->get( 'N' );
		$first = (int) $st->dict->get( 'First' );
		preg_match_all( '/\d+/', substr( $data, 0, $first ), $hm );

		$saved = [ $this->s, $this->p, $this->len ];
		$this->s   = $data;
		$this->len = strlen( $data );
		try {
			for ( $i = 0; $i < $n && isset( $hm[0][ $i * 2 + 1 ] ); $i++ ) {
				$onum = (int) $hm[0][ $i * 2 ];
				$ooff = (int) $hm[0][ $i * 2 + 1 ];
				$e    = $this->xref[ $onum ] ?? null;
				if ( ! $e || $e[0] !== 2 || $e[1] !== $stm_num ) continue; // superseded
				$this->p = $first + $ooff;
				$this->cache[ $onum ] = $this->parse_value();
			}
		} finally {
			[ $this->s, $this->p, $this->len ] = $saved;
		}
	}

	private function parse_value() {
		$this->skip_ws();
		if ( $this->p >= $this->len ) {
			throw new RuntimeException( __('Unexpected end of data.', 'gf-fillable-pdf-generator') );
		}
		$c = $this->s[ $this->p ];

		if ( $c === '/' ) {
			preg_match( '/\G\/([^\x00\t\n\f\r ()<>\[\]{}\/%]*)/', $this->s, $m, 0, $this->p );
			$this->p += strlen( $m[0] );
			$n = $m[1];
			if ( strpos( $n, '#' ) !== false ) {
				$n = preg_replace_callback( '/#([0-9A-Fa-f]{2})/', static function ( $x ) { return chr( hexdec( $x[1] ) ); }, $n );
			}
			return new GFFPDF_Pdf_Name( $n );
		}

		if ( $c === '(' ) {
			return $this->parse_literal_string();
		}

		if ( $c === '<' ) {
			if ( ( $this->s[ $this->p + 1 ] ?? '' ) === '<' ) {
				$this->p += 2;
				$d = new GFFPDF_Pdf_Dict();
				while ( true ) {
					$this->skip_ws();
					if ( $this->p >= $this->len ) throw new RuntimeException( __('Unterminated dictionary.', 'gf-fillable-pdf-generator') );
					if ( substr( $this->s, $this->p, 2 ) === '>>' ) { $this->p += 2; break; }
					$k = $this->parse_value();
					if ( ! $k instanceof GFFPDF_Pdf_Name ) {
						continue; // tolerate junk
					}
					$this->skip_ws();
					if ( substr( $this->s, $this->p, 2 ) === '>>' ) { $d->d[ $k->v ] = null; $this->p += 2; break; }
					$d->d[ $k->v ] = $this->parse_value();
				}
				return $d;
			}
			$end = strpos( $this->s, '>', $this->p );
			if ( $end === false ) throw new RuntimeException( __('Unterminated hex string.', 'gf-fillable-pdf-generator') );
			$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $this->s, $this->p + 1, $end - $this->p - 1 ) );
			if ( strlen( $hex ) % 2 ) $hex .= '0';
			$this->p = $end + 1;
			return new GFFPDF_Pdf_Str( (string) hex2bin( $hex ) );
		}

		if ( $c === '[' ) {
			$this->p++;
			$a = new GFFPDF_Pdf_Arr();
			while ( true ) {
				$this->skip_ws();
				if ( $this->p >= $this->len ) throw new RuntimeException( __('Unterminated array.', 'gf-fillable-pdf-generator') );
				if ( $this->s[ $this->p ] === ']' ) { $this->p++; break; }
				$a->a[] = $this->parse_value();
			}
			return $a;
		}

		if ( ! preg_match( '/\G[^\x00\t\n\f\r ()<>\[\]{}\/%]+/', $this->s, $m, 0, $this->p ) ) {
			$this->p++; // stray delimiter such as ")" or ">"
			return new GFFPDF_Pdf_Kw( $c );
		}
		$tok     = $m[0];
		$this->p += strlen( $tok );

		if ( preg_match( '/^[+-]?\d+$/', $tok ) ) {
			$int = (int) $tok;
			if ( $int >= 0 && $tok[0] !== '+' && $tok[0] !== '-'
				&& preg_match( '/\G[\x00\t\n\f\r ]+(\d+)[\x00\t\n\f\r ]+R(?![^\x00\t\n\f\r ()<>\[\]{}\/%])/', $this->s, $r, 0, $this->p ) ) {
				$this->p += strlen( $r[0] );
				return new GFFPDF_Pdf_Ref( $int, (int) $r[1] );
			}
			return $int;
		}
		if ( preg_match( '/^[+-]?(\d+\.?\d*|\.\d+)$/', $tok ) ) return (float) $tok;
		if ( $tok === 'true' )  return true;
		if ( $tok === 'false' ) return false;
		if ( $tok === 'null' )  return null;
		return new GFFPDF_Pdf_Kw( $tok );
	}

	private function parse_literal_string(): GFFPDF_Pdf_Str {
		$s = $this->s; $i = $this->p + 1; $depth = 1; $out = '';
		$n = $this->len;
		while ( $i < $n ) {
			$ch = $s[ $i++ ];
			if ( $ch === '\\' ) {
				$nx = $s[ $i ] ?? '';
				$i++;
				switch ( $nx ) {
					case 'n': $out .= "\n"; break;
					case 'r': $out .= "\r"; break;
					case 't': $out .= "\t"; break;
					case 'b': $out .= "\x08"; break;
					case 'f': $out .= "\x0C"; break;
					case "\r": if ( ( $s[ $i ] ?? '' ) === "\n" ) $i++; break;
					case "\n": break;
					case '(': case ')': case '\\': $out .= $nx; break;
					default:
						if ( $nx >= '0' && $nx <= '7' ) {
							$oct = $nx;
							for ( $k = 0; $k < 2 && isset( $s[ $i ] ) && $s[ $i ] >= '0' && $s[ $i ] <= '7'; $k++ ) $oct .= $s[ $i++ ];
							$out .= chr( octdec( $oct ) & 0xFF );
						} else {
							$out .= $nx;
						}
				}
			} elseif ( $ch === '(' ) {
				$depth++; $out .= $ch;
			} elseif ( $ch === ')' ) {
				if ( --$depth === 0 ) break;
				$out .= $ch;
			} else {
				$out .= $ch;
			}
		}
		$this->p = $i;
		return new GFFPDF_Pdf_Str( $out );
	}

	private function arr_ints( $v ): array {
		if ( $v instanceof GFFPDF_Pdf_Ref ) $v = $this->resolve( $v );
		if ( ! $v instanceof GFFPDF_Pdf_Arr ) return [];
		return array_map( 'intval', array_filter( $v->a, 'is_numeric' ) );
	}

	/* =======================================================================
	 * Stream decoding (only what object/xref streams need)
	 * ==================================================================== */

	public function decode_stream( GFFPDF_Pdf_Stream $st ): string {
		$data    = $st->data;
		$filters = $this->resolve( $st->dict->get( 'Filter' ) );
		$parms   = $this->resolve( $st->dict->get( 'DecodeParms' ) ?? $st->dict->get( 'DP' ) );
		$fl      = [];
		$pl      = [];
		if ( $filters instanceof GFFPDF_Pdf_Name ) {
			$fl = [ $filters->v ];
			$pl = [ $this->dict( $parms ) ];
		} elseif ( $filters instanceof GFFPDF_Pdf_Arr ) {
			foreach ( $filters->a as $i => $f ) {
				$fl[] = $this->name( $f );
				$pa   = $parms instanceof GFFPDF_Pdf_Arr ? ( $parms->a[ $i ] ?? null ) : null;
				$pl[] = $this->dict( $pa );
			}
		}
		foreach ( $fl as $i => $f ) {
			if ( $f === 'FlateDecode' || $f === 'Fl' ) {
				$out = @gzuncompress( $data );
				if ( $out === false ) $out = @gzinflate( substr( $data, 2 ) );
				if ( $out === false ) $out = @zlib_decode( $data );
				if ( $out === false ) throw new RuntimeException( __('Could not inflate stream.', 'gf-fillable-pdf-generator') );
				$data = $out;
				$pd   = $pl[ $i ] ?? null;
				if ( $pd ) {
					$pred = (int) $this->num( $pd->get( 'Predictor' ), 1 );
					if ( $pred >= 10 ) {
						$data = $this->png_unpredict(
							$data,
							(int) $this->num( $pd->get( 'Columns' ), 1 ),
							(int) $this->num( $pd->get( 'Colors' ), 1 ),
							(int) $this->num( $pd->get( 'BitsPerComponent' ), 8 )
						);
					}
				}
			} elseif ( $f === 'Crypt' ) {
				continue; // handled when the object was read
			} elseif ( $f === 'ASCIIHexDecode' || $f === 'AHx' ) {
				$hex  = preg_replace( '/[^0-9A-Fa-f]/', '', strstr( $data . '>', '>', true ) );
				if ( strlen( $hex ) % 2 ) $hex .= '0';
				$data = (string) hex2bin( $hex );
			} else {
				/* translators: %s: PDF stream filter name */
				throw new RuntimeException( sprintf( esc_html__( 'Unsupported stream filter %s.', 'gf-fillable-pdf-generator' ), esc_html( $f ) ) );
			}
		}
		return $data;
	}

	private function png_unpredict( string $data, int $columns, int $colors, int $bpc ): string {
		$bpp    = max( 1, intdiv( $colors * $bpc + 7, 8 ) );
		$rowlen = intdiv( $colors * $columns * $bpc + 7, 8 );
		$rows   = intdiv( strlen( $data ), $rowlen + 1 );
		$out    = '';
		$prev   = array_fill( 0, $rowlen, 0 );
		for ( $r = 0; $r < $rows; $r++ ) {
			$ft  = ord( $data[ $r * ( $rowlen + 1 ) ] );
			$cur = array_values( unpack( 'C*', substr( $data, $r * ( $rowlen + 1 ) + 1, $rowlen ) ) );
			for ( $i = 0; $i < $rowlen; $i++ ) {
				$a = $i >= $bpp ? $cur[ $i - $bpp ] : 0;
				$b = $prev[ $i ];
				$c = $i >= $bpp ? $prev[ $i - $bpp ] : 0;
				switch ( $ft ) {
					case 1: $cur[ $i ] = ( $cur[ $i ] + $a ) & 255; break;
					case 2: $cur[ $i ] = ( $cur[ $i ] + $b ) & 255; break;
					case 3: $cur[ $i ] = ( $cur[ $i ] + ( ( $a + $b ) >> 1 ) ) & 255; break;
					case 4:
						$pp = $a + $b - $c;
						$pa = abs( $pp - $a ); $pb = abs( $pp - $b ); $pc = abs( $pp - $c );
						$pr = ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );
						$cur[ $i ] = ( $cur[ $i ] + $pr ) & 255;
						break;
				}
			}
			$out .= pack( 'C*', ...$cur );
			$prev = $cur;
		}
		return $out;
	}

	/* =======================================================================
	 * Writing: collect changes, then append them as an incremental update
	 * ==================================================================== */

	/** Reserve an object number for a brand-new object. */
	public function alloc(): int {
		return $this->next_num++;
	}

	/** Add or replace an object (new or existing number). */
	public function put( int $num, $value ): void {
		$this->new[ $num ] = [ $this->gen_of( $num ), $value ];
	}

	/** Add a brand-new object and return a reference to it. */
	public function add( $value ): GFFPDF_Pdf_Ref {
		$num = $this->alloc();
		$this->new[ $num ] = [ 0, $value ];
		return new GFFPDF_Pdf_Ref( $num, 0 );
	}

	public function has_changes(): bool {
		return ! empty( $this->new );
	}

	public static function ser( $v ): string {
		if ( $v === null ) return 'null';
		if ( $v === true ) return 'true';
		if ( $v === false ) return 'false';
		if ( is_int( $v ) ) return (string) $v;
		if ( is_float( $v ) ) {
			$s = rtrim( rtrim( number_format( $v, 5, '.', '' ), '0' ), '.' );
			return ( $s === '' || $s === '-0' ) ? '0' : $s;
		}
		if ( $v instanceof GFFPDF_Pdf_Name ) {
			return '/' . preg_replace_callback(
				'/[^\x21-\x7E]|[#\/%()<>\[\]{}]/',
				static function ( $m ) { return sprintf( '#%02X', ord( $m[0] ) ); },
				$v->v
			);
		}
		if ( $v instanceof GFFPDF_Pdf_Ref )  return $v->num . ' ' . $v->gen . ' R';
		if ( $v instanceof GFFPDF_Pdf_Str )  return '<' . strtoupper( bin2hex( $v->v ) ) . '>';
		if ( $v instanceof GFFPDF_Pdf_Kw )   return $v->v;
		if ( $v instanceof GFFPDF_Pdf_Arr ) {
			return '[' . implode( ' ', array_map( [ self::class, 'ser' ], $v->a ) ) . ']';
		}
		if ( $v instanceof GFFPDF_Pdf_Dict ) {
			$o = '<<';
			foreach ( $v->d as $k => $val ) {
				$o .= self::ser( new GFFPDF_Pdf_Name( (string) $k ) ) . ' ' . self::ser( $val ) . "\n";
			}
			return $o . '>>';
		}
		throw new RuntimeException( __('Cannot serialize value.', 'gf-fillable-pdf-generator') );
	}

	/** Produce the original file with all changes appended (original bytes untouched). */
	public function save(): string {
		if ( $this->crypt ) {
			return $this->save_full();
		}
		if ( ! $this->new ) {
			return $this->s;
		}
		// Make sure we always work from the true original (objstm parsing swaps buffers temporarily).
		$out = $this->s;
		if ( substr( $out, -1 ) !== "\n" ) {
			$out .= "\n";
		}

		ksort( $this->new );
		$entries = []; // num => [offset, gen]
		foreach ( $this->new as $num => $pair ) {
			[ $gen, $val ] = $pair;
			$entries[ $num ] = [ strlen( $out ), $gen ];
			$out .= $num . ' ' . $gen . " obj\n";
			if ( $val instanceof GFFPDF_Pdf_Stream ) {
				$d = clone $val->dict;
				$d->set( 'Length', strlen( $val->data ) );
				$out .= self::ser( $d ) . "\nstream\n" . $val->data . "\nendstream";
			} else {
				$out .= self::ser( $val );
			}
			$out .= "\nendobj\n";
		}

		$size = max( $this->size, $this->next_num, max( array_keys( $entries ) ) + 1 );

		$tr = new GFFPDF_Pdf_Dict();
		$tr->set( 'Root', $this->trailer->get( 'Root' ) );
		foreach ( [ 'Info', 'ID' ] as $k ) {
			if ( $this->trailer->has( $k ) ) $tr->set( $k, $this->trailer->get( $k ) );
		}
		if ( $this->startxref > 0 ) {
			$tr->set( 'Prev', $this->startxref );
		}

		$xref_pos = strlen( $out );

		if ( $this->newest_is_stream || $this->rebuilt ) {
			$xnum = $size++;
			$entries[ $xnum ] = [ $xref_pos, 0 ];

			// Normal case: one row per object we wrote. After a xref rebuild the
			// damaged original table can't be chained with /Prev, so this stream
			// lists every object (including those inside object streams) itself.
			$rows = [];
			if ( $this->rebuilt ) {
				$rows[0] = [ 0, 0, 65535 ];
				foreach ( $this->xref as $n => $x ) {
					$rows[ $n ] = $x[0] === 0 ? [ 0, 0, 0 ] : [ $x[0], $x[1], $x[2] ];
				}
			}
			foreach ( $entries as $n => $e ) {
				$rows[ $n ] = [ 1, $e[0], $e[1] ];
			}
			ksort( $rows );
			if ( $this->rebuilt ) {
				$tr->remove( 'Prev' );
				$size = max( $size, max( array_keys( $rows ) ) + 1 );
			}

			$index = []; $data = '';
			$run_start = null; $run_len = 0; $last = null;
			foreach ( $rows as $n => $e ) {
				if ( $last !== null && $n === $last + 1 ) {
					$run_len++;
				} else {
					if ( $run_start !== null ) { $index[] = $run_start; $index[] = $run_len; }
					$run_start = $n; $run_len = 1;
				}
				$last  = $n;
				$data .= pack( 'CNn', $e[0], $e[1], $e[2] );
			}
			$index[] = $run_start; $index[] = $run_len;

			$xd = clone $tr;
			$xd->set( 'Type', new GFFPDF_Pdf_Name( 'XRef' ) );
			$xd->set( 'Size', $size );
			$xd->set( 'W', new GFFPDF_Pdf_Arr( [ 1, 4, 2 ] ) );
			$xd->set( 'Index', new GFFPDF_Pdf_Arr( $index ) );
			$xd->set( 'Length', strlen( $data ) );
			$out .= $xnum . " 0 obj\n" . self::ser( $xd ) . "\nstream\n" . $data . "\nendstream\nendobj\n";
		} else {
			ksort( $entries );
			$tr->set( 'Size', $size );
			$out .= "xref\n";
			$nums = array_keys( $entries );
			$i = 0; $c = count( $nums );
			while ( $i < $c ) {
				$j = $i;
				while ( $j + 1 < $c && $nums[ $j + 1 ] === $nums[ $j ] + 1 ) $j++;
				$out .= $nums[ $i ] . ' ' . ( $j - $i + 1 ) . "\n";
				for ( $k = $i; $k <= $j; $k++ ) {
					$e    = $entries[ $nums[ $k ] ];
					$out .= sprintf( '%010d %05d n ', $e[0], $e[1] ) . "\n";
				}
				$i = $j + 1;
			}
			$out .= "trailer\n" . self::ser( $tr ) . "\n";
		}

		$out .= "startxref\n" . $xref_pos . "\n%%EOF\n";
		return $out;
	}

	/**
	 * Encrypted source: the old bytes cannot be reused (every string/stream is
	 * ciphered), so write a brand-new, unencrypted file containing the
	 * decrypted objects plus our changes. Object/xref streams are flattened
	 * into ordinary objects.
	 */
	private function save_full(): string {
		$nums = [];
		foreach ( $this->xref as $n => $e ) {
			if ( $e[0] !== 0 && $n !== $this->encrypt_num ) $nums[ $n ] = true;
		}
		foreach ( $this->new as $n => $_ ) $nums[ $n ] = true;
		ksort( $nums );

		$out     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ( array_keys( $nums ) as $n ) {
			$n = (int) $n;
			if ( isset( $this->new[ $n ] ) ) {
				[ $gen, $val ] = $this->new[ $n ];
			} else {
				try { $val = $this->get_object( $n ); } catch ( \Throwable $t ) { continue; }
				$gen = $this->gen_of( $n );
				if ( $val === null ) continue;
			}
			if ( $val instanceof GFFPDF_Pdf_Stream ) {
				$t = $val->dict->get( 'Type' );
				if ( $t instanceof GFFPDF_Pdf_Name && ( $t->v === 'ObjStm' || $t->v === 'XRef' ) ) continue;
			}
			$offsets[ $n ] = [ strlen( $out ), $gen ];
			$out .= $n . ' ' . $gen . " obj\n";
			if ( $val instanceof GFFPDF_Pdf_Stream ) {
				$d = clone $val->dict;
				$d->set( 'Length', strlen( $val->data ) );
				if ( $d->has( 'DL' ) ) $d->remove( 'DL' );
				$out .= self::ser( $d ) . "\nstream\n" . $val->data . "\nendstream";
			} else {
				$out .= self::ser( $val );
			}
			$out .= "\nendobj\n";
		}

		$size = max( $this->size, $this->next_num, $offsets ? max( array_keys( $offsets ) ) + 1 : 1 );
		$xref_pos = strlen( $out );
		$out .= "xref\n0 " . $size . "\n";
		for ( $i = 0; $i < $size; $i++ ) {
			if ( $i === 0 )                $out .= "0000000000 65535 f \n";
			elseif ( isset( $offsets[ $i ] ) ) $out .= sprintf( "%010d %05d n \n", $offsets[ $i ][0], $offsets[ $i ][1] );
			else                           $out .= "0000000000 00000 f \n";
		}

		$tr = new GFFPDF_Pdf_Dict();
		$tr->set( 'Size', $size );
		$tr->set( 'Root', $this->trailer->get( 'Root' ) );
		if ( $this->trailer->has( 'Info' ) ) $tr->set( 'Info', $this->trailer->get( 'Info' ) );
		// Keep the file ID (without it some viewers complain); it is plain bytes, not secret.
		if ( $this->trailer->has( 'ID' ) ) {
			$id = $this->resolve( $this->trailer->get( 'ID' ) );
			if ( $id instanceof GFFPDF_Pdf_Arr ) $tr->set( 'ID', $id );
		}
		$out .= "trailer\n" . self::ser( $tr ) . "\nstartxref\n" . $xref_pos . "\n%%EOF\n";
		return $out;
	}
}