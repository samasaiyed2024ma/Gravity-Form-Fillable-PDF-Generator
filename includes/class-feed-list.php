<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Feed_List {

	public function __construct() {
		// Hooks are registered by Feed_Settings; nothing extra needed here
	}

	/**
	 * Render an HTML table of feeds for a given form.
	 */
	public static function render( int $form_id ): void {
		$feeds = GFFPDF_Feed_Settings::get_feeds_by_form( $form_id );

		include GFFPDF_PATH . 'templates/feeds/feed-list.php';
	}

	/**
	 * Render a single feed row (used when AJAX-appending after save).
	 */
	public static function render_row( object $feed ): string {
		$settings = json_decode( $feed->settings, true ) ?? [];
		$mappings = json_decode( $feed->mappings, true ) ?? [];

		ob_start();
		include GFFPDF_PATH . 'templates/feeds/feed-row.php';
		return ob_get_clean();
	}
}