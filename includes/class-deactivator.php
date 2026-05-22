<?php
if ( ! defined( 'ABSPATH' ) ) exit;
 
class GFFPDF_Deactivator {
 
	public static function deactivate() {
		flush_rewrite_rules();
		// Optionally clear scheduled events here if any are added later
	}

	public static function cleanup_data(): void {
		global $wpdb;

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
						@unlink($file);
					}
				}
			}
			@rmdir($upload_dir);
		}
	}
}
 