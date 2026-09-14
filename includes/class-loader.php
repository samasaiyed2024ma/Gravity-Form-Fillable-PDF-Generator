<?php
/**
 * Wires up all action/filter hooks for the plugin.
 */

if(!defined('ABSPATH')){
    exit;
}

class GFFPDF_Loader{
    public static function init(){
        // Admin-only UI (settings pages, feed list table, etc).
        if(is_admin()){
            new GFFPDF_Admin_Menu();
            new GFFPDF_Settings();
            new GFFPDF_Feed_List();
        }

        // GFFPDF_Feed_Settings must NOT be gated behind is_admin(): besides
        // the admin-only feed-editing AJAX handlers, its constructor also
        // registers the public [gffpdf] shortcode and the nopriv AJAX
        // download handler that shortcode's link depends on (see the
        // comments in class-feed-settings.php). Both of those need to be
        // available on ordinary front-end requests — i.e. whenever a real
        // visitor submits the form and Gravity Forms sends notifications —
        // not just when viewing wp-admin. Restricting this to is_admin()
        // meant the shortcode was never registered for a real front-end
        // submission (so it printed as literal text) and only ever worked
        // when triggered from within wp-admin, e.g. via "Resend Notifications".
        new GFFPDF_Feed_Settings();

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