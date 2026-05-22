<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Settings {

	public function __construct() {
		add_filter( 'gform_settings_menu', [ $this, 'add_settings_menu' ] );
		add_action( 'gform_settings_gffpdf', [ $this, 'render_settings_page' ] );

		add_action( 'wp_ajax_gffpdf_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_gffpdf_clear_logs',    [ $this, 'ajax_clear_logs' ] );
	}

	public function add_settings_menu( array $menu ): array {
		$menu[] = [
			'name'  => 'gffpdf',
			'label' => __( 'Fillable PDF Generator', 'gf-fillable-pdf' ),
		];
		return $menu;
	}

	public function render_settings_page(): void {
		if ( ! GFFPDF_Security::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'gf-fillable-pdf' ) );
		}

		$settings = self::get_settings();
		$logs     = GFFPDF_Logger::get_recent_logs( 200 );
		$nonce    = GFFPDF_Security::create_nonce();

		wp_enqueue_style(
			'gffpdf-admin',
			GFFPDF_URL . 'assets/css/admin.css',
			[],
			GFFPDF_VERSION
		);

		wp_enqueue_script(
			'gffpdf-admin',
			GFFPDF_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			GFFPDF_VERSION,
			true
		);

		wp_localize_script( 'gffpdf-admin', 'GFFPDF_Admin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => $nonce,
		] );

		include GFFPDF_PATH . 'templates/settings/global-settings.php';
	}

	public function ajax_save_settings(): void {
		GFFPDF_Security::check_ajax();

		$raw = $_POST['settings'] ?? [];
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid data.', 'gf-fillable-pdf' ) ] );
		}

		$clean = GFFPDF_Security::sanitize_settings( $raw );
		update_option( 'gffpdf_settings', $clean );

		GFFPDF_Logger::info( 'Global settings saved' );
		wp_send_json_success( [ 'message' => __( 'Settings saved.', 'gf-fillable-pdf' ) ] );
	}

	public function ajax_clear_logs(): void {
		GFFPDF_Security::check_ajax();

		$files = GFFPDF_Logger::list_log_files();
		foreach ( $files as $file ) {
			unlink( $file );
		}
		wp_send_json_success( [ 'message' => __( 'Logs cleared.', 'gf-fillable-pdf' ) ] );
	}

	/* -----------------------------------------------------------------------
	 * Static getter
	 * -------------------------------------------------------------------- */

	public static function get_settings(): array {
		$defaults = [
			'default_font_family' => 'helvetica',
			'default_font_size'   => 12,
			'default_font_color'  => '#000000',
			'filename_pattern'    => 'submission-{entry_id}-{date}',
			'save_pdfs'           => true,
			'enable_logs'         => true,
			'flatten_pdf'         => true,
			'rtl_support'         => false,
			'storage_path'        => GFFPDF_UPLOAD_DIR,
		];
		return wp_parse_args( get_option( 'gffpdf_settings', [] ), $defaults );
	}
}