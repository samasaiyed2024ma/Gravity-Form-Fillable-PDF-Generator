<?php
/**
 * Wires up all action/filter hooks for the plugin.
 */

if(!defined('ABSPATH')){
    exit;
}

class GFFPDF_Loader{
    public static function init(){
        // Admin
        if(is_admin()){
            new GFFPDF_Admin_Menu();
            new GFFPDF_Settings();
            new GFFPDF_Feed_Settings();
            new GFFPDF_Feed_List();
        }

        // Front-end + AJAX entry processing
        new GFFPDF_Entry_Handler();

        // REST API
        add_action('rest_api_init', function(){
           (new GFFPDF_REST_API())->register_routes();
        });

        // Daily cleanup: sweeps stray temp files and, if a retention period
        // is configured, prunes old generated PDFs so storage doesn't grow
        // unbounded on high-volume sites.
        add_action( 'gffpdf_daily_cleanup', [ 'GFFPDF_File_Handler', 'run_scheduled_cleanup' ] );
        if ( ! wp_next_scheduled( 'gffpdf_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'gffpdf_daily_cleanup' );
        }
    }
}