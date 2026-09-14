<?php
if ( ! defined( 'ABSPATH' ) ) exit;
 
class GFFPDF_Deactivator {
 
	public static function deactivate() {
		wp_clear_scheduled_hook( 'gffpdf_daily_cleanup' );
		flush_rewrite_rules();
	}

	public static function cleanup_data(): void {
		global $wpdb;

		// Initialize WordPress Filesystem API
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

		// Drop tables
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gffpdf_feeds");
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gffpdf_entries");

		// Remove options
		delete_option('gffpdf_version');
		delete_option('gffpdf_settings');
		delete_option('gffpdf_db_version');

		// Remove generated PDF files
		$upload_dir = WP_CONTENT_DIR . '/uploads/gffpdf';
		if(is_dir($upload_dir)){
			$files = glob($upload_dir . '*');
			if($files){
				foreach($files as $file){
					if(is_file($file)){
						wp_delete_file($file);
					}
				}
			}

            // Use delete() with the recursive flag set to true to remove directories
            $wp_filesystem->delete( $upload_dir, true );
		}
	}
}
 