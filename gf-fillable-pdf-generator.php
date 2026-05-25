<?php
/**
 * Plugin Name: GF PDF Generator
 * Description: Generate PDFs automatically on Gravity Forms submission. Upload AcroForm PDFs, map fields, and produce filled PDFs on every entry.
 * Version: 1.0.0
 * Require at least: 5.8
 * Require PHP: 7.4
 * Author: Mervan Agency
 * Author URI: mervanagency.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gf-fillable-pdf-generator
 * Domain Path: /languages
 */

if(!defined('ABSPATH')){
    exit;
}

// Plugin constants
define('GFFPDF_VERSION', '1.0.0');
define('GFFPDF_PATH', plugin_dir_path(__FILE__) );
define('GFFPDF_URL', plugin_dir_url(__FILE__));
define('GFFPDF_UPLOAD_DIR', WP_CONTENT_DIR . '/uploads/gffpdf/');
define('GFFPDF_UPLOAD_URL', content_url('/uploads/gffpdf'));
define('GFFPDF_MIN_PHP', '7.4');
define('GFFPDF_MIN_WP', '5.8');

// Composer autoload
if(file_exists(GFFPDF_PATH . 'vendor/autoload.php')){
    require_once GFFPDF_PATH . 'vendor/autoload.php';
}

require_once GFFPDF_PATH . 'includes/class-activator.php';
require_once GFFPDF_PATH . 'includes/class-deactivator.php';

// Activation / Deactivation hooks
register_activation_hook( __FILE__,   [ 'GFFPDF_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'GFFPDF_Deactivator', 'deactivate' ] );

/**
 * Boot the plugin after all plugins are loaded
 */
add_action('plugins_loaded', 'gffpdf_init', 20);

function gffpdf_init(){
    // Check PHP version
    if(version_compare(PHP_VERSION, GFFPDF_MIN_PHP, '<')){
        add_action('admin_notices', function(){
            echo '<div class="notice notice-error"><p>' .
			     sprintf(
			     	/* translators: %s: required PHP version */
			     	esc_html__( 'GF Fillable PDF Generator requires PHP %s or higher.', 'gf-fillable-pdf' ),
			     	GFFPDF_MIN_PHP
			     ) .
			     '</p></div>';
        });
        return;
    }

    // Check Gravity Forms
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>' .
			     esc_html__( 'GF Fillable PDF Generator requires Gravity Forms to be installed and activated.', 'gf-fillable-pdf' ) .
			     '</p></div>';
		} );
		return;
	}

    // Load all plugin classes
	require_once GFFPDF_PATH . 'includes/class-helpers.php';
	require_once GFFPDF_PATH . 'includes/class-logger.php';
	require_once GFFPDF_PATH . 'includes/class-security.php';
	require_once GFFPDF_PATH . 'includes/class-file-handler.php';
	require_once GFFPDF_PATH . 'includes/class-font-manager.php';
	require_once GFFPDF_PATH . 'includes/class-pdf-field-extractor.php';
	require_once GFFPDF_PATH . 'includes/class-pdf-generator.php';
	require_once GFFPDF_PATH . 'includes/class-template-handler.php';
	require_once GFFPDF_PATH . 'includes/class-feed-settings.php';
	require_once GFFPDF_PATH . 'includes/class-feed-list.php';
	require_once GFFPDF_PATH . 'includes/class-settings.php';
	require_once GFFPDF_PATH . 'includes/class-admin-menu.php';
	require_once GFFPDF_PATH . 'includes/class-entry-handler.php';
	require_once GFFPDF_PATH . 'includes/class-rest-api.php';
	require_once GFFPDF_PATH . 'includes/class-loader.php';

    // Initialise
    GFFPDF_Loader::init();
}