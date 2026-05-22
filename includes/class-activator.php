<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Activator {

	public static function activate() {
		self::check_requirements();
		self::create_tables();
		self::create_directories();
		self::set_defaults();
		flush_rewrite_rules();
	}

	private static function check_requirements() {
		if ( version_compare( PHP_VERSION, GFFPDF_MIN_PHP, '<' ) ) {
			deactivate_plugins( plugin_basename( GFFPDF_PATH . 'gf-fillable-pdf-generator.php' ) );
			wp_die( sprintf( esc_html__( 'GF Fillable PDF Generator requires PHP %s or higher.', 'gf-fillable-pdf' ), GFFPDF_MIN_PHP ) );
		}
		if ( ! class_exists( 'GFForms' ) ) {
			deactivate_plugins( plugin_basename( GFFPDF_PATH . 'gf-fillable-pdf-generator.php' ) );
			wp_die( esc_html__( 'GF Fillable PDF Generator requires Gravity Forms to be installed and activated.', 'gf-fillable-pdf' ) );
		}
	}

	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Feeds table
		$sql_feeds = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gffpdf_feeds (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			feed_name varchar(255) NOT NULL DEFAULT '',
			template_path text NOT NULL,
			settings longtext NOT NULL,
			mappings longtext NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset_collate;";

		// Generated PDFs table
		$sql_entries = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gffpdf_entries (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			form_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			feed_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			pdf_path text NOT NULL,
			generated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY form_id (form_id),
			KEY feed_id (feed_id)
		) $charset_collate;";

		// PDF template fields table — stores field name, type, rect, page per template
		// This is the key new table: coordinates are extracted once on upload and reused at fill time.
		$sql_pdf_fields = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gffpdf_pdf_fields (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			template_path text NOT NULL,
			field_name varchar(500) NOT NULL DEFAULT '',
			field_type varchar(50) NOT NULL DEFAULT 'text',
			page_number int(11) NOT NULL DEFAULT 1,
			rect_x1 float NOT NULL DEFAULT 0,
			rect_y1 float NOT NULL DEFAULT 0,
			rect_x2 float NOT NULL DEFAULT 0,
			rect_y2 float NOT NULL DEFAULT 0,
			page_width float NOT NULL DEFAULT 0,
			page_height float NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY template_path (template_path(255))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_feeds );
		dbDelta( $sql_entries );
		dbDelta( $sql_pdf_fields );

		update_option( 'gffpdf_db_version', GFFPDF_VERSION );
	}

	private static function create_directories() {
		$dirs = [
			GFFPDF_UPLOAD_DIR,
			GFFPDF_UPLOAD_DIR . 'templates/',
			GFFPDF_UPLOAD_DIR . 'generated/',
			GFFPDF_UPLOAD_DIR . 'logs/',
			GFFPDF_UPLOAD_DIR . 'temp/',
		];
		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
			$htaccess = $dir . '.htaccess';
			if ( ! file_exists( $htaccess ) ) file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
			$index = $dir . 'index.php';
			if ( ! file_exists( $index ) ) file_put_contents( $index, '<?php // Silence is golden.' );
		}
	}

	private static function set_defaults() {
		if ( ! get_option( 'gffpdf_settings' ) ) {
			update_option( 'gffpdf_settings', [
				'default_font_family' => 'helvetica',
				'default_font_size'   => 12,
				'default_font_color'  => '#000000',
				'filename_pattern'    => 'submission-{entry_id}-{date}',
				'save_pdfs'           => true,
				'enable_logs'         => true,
				'flatten_pdf'         => true,
				'rtl_support'         => false,
				'storage_path'        => GFFPDF_UPLOAD_DIR,
			] );
		}
		update_option( 'gffpdf_version', GFFPDF_VERSION );
	}
}