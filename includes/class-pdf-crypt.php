<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PDF "Standard" security handler — read side only.
 *
 * A very common kind of "fillable" PDF is encrypted with an EMPTY user
 * password: anyone can open it, but the owner password restricts editing /
 * copying / form filling. Acrobat, browsers and phone viewers open those
 * without asking for anything, so users expect them to work as templates.
 *
 * This class derives the file key using the empty user password and
 * decrypts strings and streams object-by-object, so the rest of the plugin
 * can read the form exactly as it would an unencrypted file.
 *
 * Supported: RC4 40-bit / 128-bit (V1, V2, R2-R3), AES-128 (V4, R4) and
 * AES-256 (V5, R5 and R6). Files that really need a password to open are
 * rejected with a clear message.
 */
class GFFPDF_Pdf_Crypt {

	const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

	/** @var string File encryption key */
	private $key = '';
	private $v = 0;
	private $r = 0;
	/** @var string none|rc4|aesv2|aesv3 */
	private $stm_method = 'rc4';
	private $str_method = 'rc4';
	private $encrypt_metadata = true;

	/**
	 * @param GFFPDF_Pdf_Document $doc  Used only for its dict/num/name helpers.
	 * @param GFFPDF_Pdf_Dict     $enc  The /Encrypt dictionary.
	 * @param string              $id0  First element of the trailer /ID.
	 * @throws RuntimeException if the file cannot be opened without a password.
	 */
	public function __construct( GFFPDF_Pdf_Document $doc, GFFPDF_Pdf_Dict $enc, string $id0 ) {
		$filter = $doc->name( $enc->get( 'Filter' ) );
		if ( $filter !== '' && $filter !== 'Standard' ) {
			throw new RuntimeException( 
				sprintf(
					/* translators: %s: Encryption handler filter name (e.g., Standard, Adobe.PubSec) */
					__( 'This PDF uses an unsupported encryption handler (%s), so its form fields cannot be filled.', 'gf-fillable-pdf-generator' ),
					$filter
				)
			);
		}

		$this->v = (int) $doc->num( $enc->get( 'V' ), 0 );
		$this->r = (int) $doc->num( $enc->get( 'R' ), 2 );
		$length  = (int) $doc->num( $enc->get( 'Length' ), 40 );
		$o       = $this->str( $doc, $enc->get( 'O' ) );
		$u       = $this->str( $doc, $enc->get( 'U' ) );
		$p       = (int) $doc->num( $enc->get( 'P' ), 0 );

		$em = $doc->resolve( $enc->get( 'EncryptMetadata' ) );
		$this->encrypt_metadata = ( $em === false ) ? false : true;

		// Crypt filters (V4 / V5)
		if ( $this->v >= 4 ) {
			$this->stm_method = $this->cf_method( $doc, $enc, 'StmF' );
			$this->str_method = $this->cf_method( $doc, $enc, 'StrF' );
		} else {
			$this->stm_method = $this->str_method = 'rc4';
		}

		if ( $this->v >= 5 ) {
			$this->key = $this->open_r56( $doc, $enc, $u );
			return;
		}

		// Key length in bytes
		if ( $this->v === 1 || $this->r === 2 ) {
			$n = 5;
		} elseif ( $this->v === 4 ) {
			$n = 16;
		} else {
			$n = max( 5, min( 16, intdiv( $length, 8 ) ) );
		}

		// Algorithm 2: file key from the (empty) user password
		$p_bytes = pack( 'V', $p & 0xFFFFFFFF );
		$h       = self::PAD . substr( $o, 0, 32 ) . $p_bytes . $id0;
		if ( $this->r >= 4 && ! $this->encrypt_metadata ) {
			$h .= "\xFF\xFF\xFF\xFF";
		}
		$h = md5( $h, true );
		if ( $this->r >= 3 ) {
			for ( $i = 0; $i < 50; $i++ ) {
				$h = md5( substr( $h, 0, $n ), true );
			}
		}
		$this->key = substr( $h, 0, $n );

		// Verify it really is the user password (Algorithms 4 / 5)
		if ( $this->r === 2 ) {
			$ok = hash_equals( $u, self::rc4( $this->key, self::PAD ) );
		} else {
			$x = self::rc4( $this->key, md5( self::PAD . $id0, true ) );
			for ( $i = 1; $i <= 19; $i++ ) {
				$k = '';
				for ( $j = 0; $j < strlen( $this->key ); $j++ ) {
					$k .= chr( ord( $this->key[ $j ] ) ^ $i );
				}
				$x = self::rc4( $k, $x );
			}
			$ok = hash_equals( substr( $u, 0, 16 ), $x );
		}
		if ( ! $ok ) {
			throw new RuntimeException( __( 'This PDF needs a password to open, so its form fields cannot be filled. Please upload a copy without a password.', 'gf-fillable-pdf-generator') );
		}
	}

	/* -----------------------------------------------------------------------
	 * Public API
	 * -------------------------------------------------------------------- */

	public function decrypt_string( string $data, int $num, int $gen ): string {
		return $this->apply( $this->str_method, $data, $num, $gen );
	}

	public function decrypt_stream( string $data, int $num, int $gen ): string {
		return $this->apply( $this->stm_method, $data, $num, $gen );
	}

	/** Metadata streams stay in clear text when /EncryptMetadata is false. */
	public function encrypts_metadata(): bool {
		return $this->encrypt_metadata;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	private function str( GFFPDF_Pdf_Document $doc, $v ): string {
		$v = $doc->resolve( $v );
		return $v instanceof GFFPDF_Pdf_Str ? $v->v : '';
	}

	private function cf_method( GFFPDF_Pdf_Document $doc, GFFPDF_Pdf_Dict $enc, string $which ): string {
		$name = $doc->name( $enc->get( $which ) );
		if ( $name === '' || $name === 'Identity' ) {
			return 'none';
		}
		$cf  = $doc->dict( $enc->get( 'CF' ) );
		$ent = $cf ? $doc->dict( $cf->get( $name ) ) : null;
		if ( ! $ent ) {
			return 'none';
		}
		switch ( $doc->name( $ent->get( 'CFM' ) ) ) {
			case 'V2':    return 'rc4';
			case 'AESV2': return 'aesv2';
			case 'AESV3': return 'aesv3';
		}
		return 'none';
	}

	private function apply( string $method, string $data, int $num, int $gen ): string {
		if ( $method === 'none' || $data === '' ) {
			return $data;
		}
		switch ( $method ) {
			case 'rc4':
				return self::rc4( $this->object_key( $num, $gen, false ), $data );
			case 'aesv2':
				return self::aes_cbc_decrypt( $this->object_key( $num, $gen, true ), $data );
			case 'aesv3':
				return self::aes_cbc_decrypt( $this->key, $data );
		}
		return $data;
	}

	private function object_key( int $num, int $gen, bool $aes ): string {
		$k = $this->key . substr( pack( 'V', $num ), 0, 3 ) . substr( pack( 'V', $gen ), 0, 2 );
		if ( $aes ) {
			$k .= 'sAlT';
		}
		return substr( md5( $k, true ), 0, min( strlen( $this->key ) + 5, 16 ) );
	}

	/** R5 / R6 (AES-256): file key from the empty user password. */
	private function open_r56( GFFPDF_Pdf_Document $doc, GFFPDF_Pdf_Dict $enc, string $u ): string {
		$ue = $this->str( $doc, $enc->get( 'UE' ) );
		if ( strlen( $u ) < 48 || strlen( $ue ) < 32 ) {
			throw new RuntimeException( __('This PDF uses an encryption layout that could not be read, so its form fields cannot be filled.', 'gf-fillable-pdf-generator') );
		}
		$vsalt = substr( $u, 32, 8 );
		$ksalt = substr( $u, 40, 8 );
		$pw    = '';

		if ( ! hash_equals( substr( $u, 0, 32 ), $this->hash_r56( $pw, $vsalt, '' ) ) ) {
			throw new RuntimeException( __('This PDF needs a password to open, so its form fields cannot be filled. Please upload a copy without a password.', 'gf-fillable-pdf-generator') );
		}
		$ik = $this->hash_r56( $pw, $ksalt, '' );
		$fk = openssl_decrypt( substr( $ue, 0, 32 ), 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat( "\0", 16 ) );
		if ( $fk === false || strlen( $fk ) !== 32 ) {
			throw new RuntimeException( __('Could not derive the encryption key for this PDF.', 'gf-fillable-pdf-generator') );
		}
		return $fk;
	}

	private function hash_r56( string $pw, string $salt, string $udata ): string {
		$k = hash( 'sha256', $pw . $salt . $udata, true );
		if ( $this->r < 6 ) {
			return $k;
		}
		// ISO 32000-2 Algorithm 2.B
		$i = 0;
		while ( true ) {
			$k1 = str_repeat( $pw . $k . $udata, 64 );
			$e  = openssl_encrypt( $k1, 'aes-128-cbc', substr( $k, 0, 16 ), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr( $k, 16, 16 ) );
			$mod = 0;
			for ( $j = 0; $j < 16; $j++ ) {
				$mod += ord( $e[ $j ] );
			}
			$mod %= 3;
			$algo = [ 'sha256', 'sha384', 'sha512' ][ $mod ];
			$k    = hash( $algo, $e, true );
			$i++;
			if ( $i >= 64 && ord( $e[ strlen( $e ) - 1 ] ) <= $i - 32 ) {
				break;
			}
		}
		return substr( $k, 0, 32 );
	}

	private static function aes_cbc_decrypt( string $key, string $data ): string {
		if ( strlen( $data ) < 32 ) {
			return ''; // IV only / empty payload
		}
		$iv  = substr( $data, 0, 16 );
		$ct  = substr( $data, 16 );
		$ct  = substr( $ct, 0, strlen( $ct ) - ( strlen( $ct ) % 16 ) );
		$cipher = strlen( $key ) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
		$pt  = openssl_decrypt( $ct, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv );
		if ( $pt === false || $pt === '' ) {
			return '';
		}
		$pad = ord( $pt[ strlen( $pt ) - 1 ] );
		if ( $pad >= 1 && $pad <= 16 ) {
			$pt = substr( $pt, 0, strlen( $pt ) - $pad );
		}
		return $pt;
	}

	public static function rc4( string $key, string $data ): string {
		$s = range( 0, 255 );
		$j = 0;
		$kl = strlen( $key );
		if ( $kl === 0 ) {
			return $data;
		}
		for ( $i = 0; $i < 256; $i++ ) {
			$j = ( $j + $s[ $i ] + ord( $key[ $i % $kl ] ) ) & 255;
			$t = $s[ $i ]; $s[ $i ] = $s[ $j ]; $s[ $j ] = $t;
		}
		$i = $j = 0;
		$n = strlen( $data );
		$out = '';
		for ( $x = 0; $x < $n; $x++ ) {
			$i = ( $i + 1 ) & 255;
			$j = ( $j + $s[ $i ] ) & 255;
			$t = $s[ $i ]; $s[ $i ] = $s[ $j ]; $s[ $j ] = $t;
			$out .= chr( ord( $data[ $x ] ) ^ $s[ ( $s[ $i ] + $s[ $j ] ) & 255 ] );
		}
		return $out;
	}
}