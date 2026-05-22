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

        // i18n     
        add_action('init', [__CLASS__, 'load_textdomain']);
    }

    public static function load_textdomain(){
        load_plugin_textdomain(
            'gf-fillable-pdf',
            false,
            dirname(plugin_basename(GFFPDF_PATH . 'gf-fillable-pdf-generator.php')) . '/languages'
        );
    }
}