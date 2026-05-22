<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Extracts AcroForm fields WITH coordinates from a fillable PDF.
 * Stores them in wp_gffpdf_pdf_fields so they can be reused every
 * time a PDF is generated without re-parsing the template.
 *
 * Each stored field row includes:
 *   field_name, field_type, page_number,
 *   rect_x1, rect_y1, rect_x2, rect_y2,  (in PDF points, origin bottom-left)
 *   page_width, page_height               (MediaBox, in PDF points)
 */
class GFFPDF_PDF_Field_Extractor {

	/**
	 * Extract fields from a PDF, store in DB, and return the field list.
	 * On re-upload of the same path, old records are replaced.
	 *
	 * @param  string $pdf_path  Absolute path to the PDF file.
	 * @return array|WP_Error    Array of field rows on success.
	 */
	public function extract( string $pdf_path ) {
		if ( ! file_exists( $pdf_path ) ) {
			return new WP_Error( 'file_not_found', esc_html__( 'PDF file not found.', 'gf-fillable-pdf' ) );
		}

		$fields = $this->parse_fields_from_pdf( $pdf_path );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'no_fields', esc_html__( 'No fillable AcroForm fields found in this PDF.', 'gf-fillable-pdf' ) );
		}

		// Persist to DB (replace any previous extraction for this template)
		$this->store_fields( $pdf_path, $fields );

		GFFPDF_Logger::info( 'PDF fields extracted', [ 'path' => $pdf_path, 'count' => count( $fields ) ] );
		return $fields;
	}

	/**
	 * Load previously stored fields for a template path from the DB.
	 * If not yet stored (e.g. old template), re-extracts and stores them.
	 *
	 * @param  string $pdf_path
	 * @return array  Array of field rows (may be empty).
	 */
	public function get_fields_for_template( string $pdf_path ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_pdf_fields WHERE template_path = %s ORDER BY page_number ASC, id ASC",
			$pdf_path
		) );

		if ( ! empty( $rows ) ) {
			return $rows;
		}

		// Not in DB yet — extract now
		$fields = $this->extract( $pdf_path );
		if ( is_wp_error( $fields ) || empty( $fields ) ) {
			return [];
		}

		// Re-fetch from DB after storing
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}gffpdf_pdf_fields WHERE template_path = %s ORDER BY page_number ASC, id ASC",
			$pdf_path
		) );
	}

	/* -----------------------------------------------------------------------
	 * Core parser — uses smalot/pdfparser to handle compressed ObjStm PDFs
	 * -------------------------------------------------------------------- */

	private function parse_fields_from_pdf( string $pdf_path ): array {
		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return $this->parse_fields_via_regex( $pdf_path );
		}

		try {
			$parser  = new \Smalot\PdfParser\Parser();
			$pdf_doc = $parser->parseFile( $pdf_path );
			$objects = $pdf_doc->getObjects();
			$pages   = $pdf_doc->getPages();

			// Build MediaBox lookup: page_object_id → [ width, height ] (in points)
			$page_sizes = [];
			foreach ( $pages as $page ) {
				$mb = $this->get_mediabox( $page );
				$page_sizes[ spl_object_id( $page ) ] = $mb;
			}

			// Default page size (letter: 612 × 792 pt)
			$default_size = [ 'width' => 612, 'height' => 792 ];

			$seen   = [];
			$fields = [];

			foreach ( $objects as $obj ) {
				try {
					$header      = $obj->getHeader();
					$subtype_raw = $header->get( 'Subtype' );
					$subtype     = $this->scalar( $subtype_raw );

					if ( trim( $subtype, '/' ) !== 'Widget' ) continue;

					// --- Field name ---
					$name = trim( $this->scalar( $header->get( 'T' ) ) );
					if ( $name === '' ) continue;
					if ( isset( $seen[ $name ] ) ) continue; // deduplicate
					$seen[ $name ] = true;

					// --- Field type ---
					$ft_raw = trim( $this->scalar( $header->get( 'FT' ) ), '/' );
					$type   = $this->normalise_type( $ft_raw );

					// --- Rect ---
					$rect = $this->parse_rect( $header->get( 'Rect' ) );
					if ( $rect === null ) continue;

					// --- Page reference + size ---
					$page_num  = 1;
					$page_size = $default_size;

					$p_ref = $header->get( 'P' );
					if ( is_object( $p_ref ) ) {
						foreach ( $pages as $idx => $page ) {
							if ( spl_object_id( $page ) === spl_object_id( $p_ref ) ) {
								$page_num  = $idx + 1;
								$page_size = $page_sizes[ spl_object_id( $page ) ] ?? $default_size;
								break;
							}
						}
					}

					// If /P didn't resolve, scan each page's /Annots
					if ( $page_num === 1 && count( $pages ) > 1 ) {
						foreach ( $pages as $idx => $page ) {
							if ( $this->widget_on_page( $obj, $page ) ) {
								$page_num  = $idx + 1;
								$page_size = $page_sizes[ spl_object_id( $page ) ] ?? $default_size;
								break;
							}
						}
					}

					$fields[] = [
						'field_name'  => $name,
						'field_type'  => $type,
						'page_number' => $page_num,
						'rect_x1'     => (float) $rect[0],
						'rect_y1'     => (float) $rect[1],
						'rect_x2'     => (float) $rect[2],
						'rect_y2'     => (float) $rect[3],
						'page_width'  => (float) $page_size['width'],
						'page_height' => (float) $page_size['height'],
					];

				} catch ( \Exception $e ) {
					// skip unparseable object
				}
			}

			return $fields;

		} catch ( \Exception $e ) {
			GFFPDF_Logger::warn( 'smalot parse failed, falling back to regex', [ 'error' => $e->getMessage() ] );
			return $this->parse_fields_via_regex( $pdf_path );
		}
	}

	/* -----------------------------------------------------------------------
	 * Regex fallback (uncompressed PDFs only)
	 * -------------------------------------------------------------------- */

	private function parse_fields_via_regex( string $pdf_path ): array {
		$content = file_get_contents( $pdf_path );
		if ( $content === false ) return [];

		// Extract MediaBox from raw content
		$page_height = 792.0; // default letter
		if ( preg_match( '/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $content, $mb ) ) {
			$page_height = (float) $mb[4];
		}
		$page_width = 612.0;
		if ( preg_match( '/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $content, $mb ) ) {
			$page_width = (float) $mb[3];
		}

		$fields = [];
		$seen   = [];

		// Match Widget objects containing /T and /Rect
		preg_match_all(
			'/\/T\s*\(([^)]+)\)[\s\S]{0,800}?\/Rect\s*\[\s*([\d.\s-]+)\]/U',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $m ) {
			$name = trim( $m[1] );
			if ( $name === '' || isset( $seen[ $name ] ) ) continue;
			$seen[ $name ] = true;

			$rect_parts = preg_split( '/\s+/', trim( $m[2] ) );
			if ( count( $rect_parts ) < 4 ) continue;

			$fields[] = [
				'field_name'  => $name,
				'field_type'  => 'text',
				'page_number' => 1,
				'rect_x1'     => (float) $rect_parts[0],
				'rect_y1'     => (float) $rect_parts[1],
				'rect_x2'     => (float) $rect_parts[2],
				'rect_y2'     => (float) $rect_parts[3],
				'page_width'  => $page_width,
				'page_height' => $page_height,
			];
		}

		return $fields;
	}

	/* -----------------------------------------------------------------------
	 * DB persistence
	 * -------------------------------------------------------------------- */

	private function store_fields( string $pdf_path, array $fields ): void {
		global $wpdb;

		// Delete old records for this template
		$wpdb->delete( $wpdb->prefix . 'gffpdf_pdf_fields', [ 'template_path' => $pdf_path ], [ '%s' ] );

		foreach ( $fields as $field ) {
			$wpdb->insert(
				$wpdb->prefix . 'gffpdf_pdf_fields',
				[
					'template_path' => $pdf_path,
					'field_name'    => $field['field_name'],
					'field_type'    => $field['field_type'],
					'page_number'   => $field['page_number'],
					'rect_x1'       => $field['rect_x1'],
					'rect_y1'       => $field['rect_y1'],
					'rect_x2'       => $field['rect_x2'],
					'rect_y2'       => $field['rect_y2'],
					'page_width'    => $field['page_width'],
					'page_height'   => $field['page_height'],
				],
				[ '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%f' ]
			);
		}
	}

	/* -----------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------- */

	/** Get MediaBox dimensions in points from a smalot Page object. */
	private function get_mediabox( $page ): array {
		try {
			$mb = $page->getHeader()->get( 'MediaBox' );
			if ( is_object( $mb ) && method_exists( $mb, 'getContent' ) ) {
				$rc = $mb->getContent();
				if ( is_array( $rc ) && count( $rc ) >= 4 ) {
					$vals = array_map( function( $v ) {
						return (float) ( is_object( $v ) && method_exists( $v, 'getContent' ) ? $v->getContent() : $v );
					}, array_values( $rc ) );
					return [ 'width' => $vals[2], 'height' => $vals[3] ];
				}
			}
		} catch ( \Exception $e ) {}
		return [ 'width' => 612, 'height' => 792 ]; // letter fallback
	}

	/** Extract a scalar string from a smalot header value. */
	private function scalar( $val ): string {
		if ( $val === null ) return '';
		if ( is_object( $val ) && method_exists( $val, 'getContent' ) ) {
			$c = $val->getContent();
			return is_string( $c ) ? $c : (string) $c;
		}
		return (string) $val;
	}

	/** Parse a /Rect value into [x1, y1, x2, y2] or null. */
	private function parse_rect( $raw ): ?array {
		if ( $raw === null || $raw === '' ) return null;

		if ( is_object( $raw ) && method_exists( $raw, 'getContent' ) ) {
			$raw = $raw->getContent();
		}

		if ( is_array( $raw ) && count( $raw ) >= 4 ) {
			$vals = array_map( function( $v ) {
				return (float) ( is_object( $v ) && method_exists( $v, 'getContent' ) ? $v->getContent() : $v );
			}, array_values( $raw ) );
			return array_slice( $vals, 0, 4 );
		}

		if ( is_string( $raw ) ) {
			preg_match_all( '/[-\d.]+/', $raw, $m );
			if ( count( $m[0] ) >= 4 ) return array_map( 'floatval', array_slice( $m[0], 0, 4 ) );
		}

		return null;
	}

	/** Check if a Widget object appears in a page's /Annots list. */
	private function widget_on_page( $widget, $page ): bool {
		try {
			$annots = $page->getHeader()->get( 'Annots' );
			if ( ! is_object( $annots ) || ! method_exists( $annots, 'getContent' ) ) return false;
			$list = $annots->getContent();
			if ( ! is_array( $list ) ) return false;
			$wid = spl_object_id( $widget );
			foreach ( $list as $a ) {
				if ( is_object( $a ) && spl_object_id( $a ) === $wid ) return true;
			}
		} catch ( \Exception $e ) {}
		return false;
	}

	private function normalise_type( string $raw ): string {
		return [ 'Tx' => 'text', 'Btn' => 'checkbox', 'Ch' => 'select', 'Sig' => 'signature' ][ $raw ] ?? 'text';
	}

	/**
	 * Return a simple [ ['name'=>..., 'type'=>...] ] list for the mapping UI.
	 * Used by ajax_upload_pdf and ajax_get_feed.
	 */
	public function get_simple_fields_for_ui( string $pdf_path ): array {
		$rows = $this->get_fields_for_template( $pdf_path );
		return array_map( fn( $r ) => [
			'name' => is_object( $r ) ? $r->field_name : $r['field_name'],
			'type' => is_object( $r ) ? $r->field_type : $r['field_type'],
		], $rows );
	}
}