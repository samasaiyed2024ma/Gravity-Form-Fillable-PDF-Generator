<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GFFPDF_Admin_Menu {

	public function __construct() {
		add_action( 'admin_menu',     [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_global_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_deactivate_script' ]);
		add_action( 'wp_ajax_gffpdf_deactivate_cleanup', [ $this, 'ajax_deactivate_cleanup' ] );

		// Entry list & detail columns
		add_filter( 'gform_entries_action_links', [ $this, 'add_entry_list_actions' ], 10, 4 );
		add_action( 'gform_entry_detail_sidebar_middle', [ $this, 'render_entry_detail_box' ], 10, 2 );

		// AJAX: view / download PDF
		add_action( 'wp_ajax_gffpdf_view_pdf',     [ $this, 'ajax_view_pdf' ] );
		add_action( 'wp_ajax_gffpdf_download_pdf', [ $this, 'ajax_download_pdf' ] );
	}

	/* -----------------------------------------------------------------------
	 * Menus
	 * -------------------------------------------------------------------- */

	public function register_menus(): void {
		// Already accessible via GF Settings; add a standalone top-level page too
		add_submenu_page(
			null, // Hidden — accessed via direct URL or GF settings
			__( 'GF Fillable PDF Generator', 'gf-fillable-pdf' ),
			__( 'Fillable PDF', 'gf-fillable-pdf' ),
			GFFPDF_Security::CAPABILITY,
			'gffpdf-generator',
			[ $this, 'render_overview_page' ]
		);
	}

	public function render_overview_page(): void {
		GFFPDF_Security::require_capability();
		include GFFPDF_PATH . 'templates/admin/overview.php';
	}

	public function enqueue_deactivate_script($hook):void{
		if($hook !== 'plugins.php') return;

		wp_enqueue_script(
			'gffpdf-deactivate',
			GFFPDF_URL . 'assets/js/deactivate.js',
			[ 'jquery' ],
			GFFPDF_VERSION,
			true
		);

		wp_localize_script('gffpdf-deactivate', 'gffpdf_deactivate', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('gffpdf_deactivate_cleanup'),
		]);
	}
	/* -----------------------------------------------------------------------
	 * Global admin assets
	 * -------------------------------------------------------------------- */

	public function enqueue_global_assets( string $hook ): void {
		// Only load on relevant GF pages
		if ( strpos( $hook, 'gf_' ) === false && strpos( $hook, 'gffpdf' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'gffpdf-admin-global',
			GFFPDF_URL . 'assets/css/admin.css',
			[],
			GFFPDF_VERSION
		);
	}

	/* -----------------------------------------------------------------------
	 * Entry list – PDF column
	 * -------------------------------------------------------------------- */

	public function add_entry_list_actions( $actions, $filter, $entry, $form_id ) {
		// Temporary debug — remove after confirming values
		error_log( 'gform_entries_action_links args: ' . print_r( func_get_args(), true ) );

		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return $actions;
		}

		$pdfs = GFFPDF_Entry_Handler::get_entry_pdfs( (int) $entry['id'] );
		if ( empty( $pdfs ) ) {
			return $actions;
		}

		$pdf          = $pdfs[0];
		$nonce        = GFFPDF_Security::create_nonce();
		$view_url     = add_query_arg( [ 'action' => 'gffpdf_view_pdf',     'pdf_id' => $pdf->id, 'nonce' => $nonce ], admin_url( 'admin-ajax.php' ) );
		$download_url = add_query_arg( [ 'action' => 'gffpdf_download_pdf', 'pdf_id' => $pdf->id, 'nonce' => $nonce ], admin_url( 'admin-ajax.php' ) );

		$actions['view_pdf'] = [
			'class' => 'gffpdf-view-pdf',
			'link'  => '<a href="' . esc_url( $view_url ) . '" target="_blank">' . esc_html__( 'View PDF', 'gf-fillable-pdf' ) . '</a>',
		];
		$actions['download_pdf'] = [
			'class' => 'gffpdf-download-pdf',
			'link'  => '<a href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download PDF', 'gf-fillable-pdf' ) . '</a>',
		];

		return $actions;
	}

	/* -----------------------------------------------------------------------
	 * Entry detail – sidebar meta-box
	 * -------------------------------------------------------------------- */

	public function render_entry_detail_box( array $form, array $entry ): void {
		$pdfs     = GFFPDF_Entry_Handler::get_entry_pdfs( $entry['id'] );
		$entry_id = absint( $entry['id'] );
		$regen_nonce = GFFPDF_Security::create_nonce( 'gffpdf_regenerate_' . $entry_id );

		echo '<div class="postbox gffpdf-entry-box">';
		echo '<h3 class="hndle"><span>' . esc_html__( 'Fillable PDFs', 'gf-fillable-pdf' ) . '</span></h3>';
		echo '<div class="inside">';

		echo '<div id="gffpdf-regen-notice" style="display:none;margin-bottom:8px;padding:6px 10px;border-radius:3px;font-size:13px;"></div>';

		if ( empty( $pdfs ) ) {
			echo '<p>' . esc_html__( 'No PDFs generated for this entry yet.', 'gf-fillable-pdf' ) . '</p>';
		} else {
			echo '<ul class="gffpdf-pdf-list" id="gffpdf-pdf-list">';
			foreach ( $pdfs as $pdf ) {
				$nonce        = GFFPDF_Security::create_nonce();
				$view_url     = add_query_arg( [ 'action' => 'gffpdf_view_pdf',     'pdf_id' => $pdf->id, 'nonce' => $nonce ], admin_url( 'admin-ajax.php' ) );
				$download_url = add_query_arg( [ 'action' => 'gffpdf_download_pdf', 'pdf_id' => $pdf->id, 'nonce' => $nonce ], admin_url( 'admin-ajax.php' ) );
				$name         = basename( $pdf->pdf_path );

				echo '<li>';
				echo '<strong>' . esc_html( $name ) . '</strong><br>';
				echo '<a href="' . esc_url( $view_url ) . '" target="_blank">' . esc_html__( 'View PDF', 'gf-fillable-pdf' ) . '</a> &nbsp;';
				echo '<a href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'gf-fillable-pdf' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}

		// Regenerate button — fires AJAX, shows inline result, then reloads
		echo '<p>';
		echo '<button type="button" id="gffpdf-regen-btn" class="button button-secondary"';
		echo ' data-entry-id="' . esc_attr( $entry_id ) . '"';
		echo ' data-nonce="' . esc_attr( $regen_nonce ) . '"';
		echo ' data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		echo esc_html__( 'Regenerate PDFs', 'gf-fillable-pdf' );
		echo '</button>';
		echo '</p>';

		// Inline script — no extra JS file needed
		?>
		<script type="text/javascript">
		(function($){
			$('#gffpdf-regen-btn').on('click', function(){
				var $btn    = $(this);
				var $notice = $('#gffpdf-regen-notice');

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Generating…', 'gf-fillable-pdf' ) ); ?>');
				$notice.hide();

				$.post($btn.data('ajax-url'), {
					action:   'gffpdf_regenerate',
					entry_id: $btn.data('entry-id'),
					nonce:    $btn.data('nonce')
				})
				.done(function(res){
					if (res.success) {
						$notice
							.css({background:'#d4edda', color:'#155724', border:'1px solid #c3e6cb'})
							.text(res.data.message)
							.show();
						// Reload page after short delay so new PDF list is shown
						setTimeout(function(){ location.reload(); }, 1200);
					} else {
						var msg = (res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'An error occurred.', 'gf-fillable-pdf' ) ); ?>';
						$notice
							.css({background:'#f8d7da', color:'#721c24', border:'1px solid #f5c6cb'})
							.text(msg)
							.show();
						$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Regenerate PDFs', 'gf-fillable-pdf' ) ); ?>');
					}
				})
				.fail(function(){
					$notice
						.css({background:'#f8d7da', color:'#721c24', border:'1px solid #f5c6cb'})
						.text('<?php echo esc_js( __( 'Request failed. Please try again.', 'gf-fillable-pdf' ) ); ?>')
						.show();
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Regenerate PDFs', 'gf-fillable-pdf' ) ); ?>');
				});
			});
		}(jQuery));
		</script>
		<?php

		echo '</div></div>';
	}

	/* -----------------------------------------------------------------------
	 * AJAX: stream / download
	 * -------------------------------------------------------------------- */

	public function ajax_view_pdf(): void {
		$this->serve_pdf( 'view' );
	}

	public function ajax_download_pdf(): void {
		$this->serve_pdf( 'download' );
	}

	private function serve_pdf( string $mode ): void {
		$nonce  = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
		if ( ! GFFPDF_Security::verify_nonce( $nonce ) || ! GFFPDF_Security::current_user_can() ) {
			wp_die( esc_html__( 'Permission denied.', 'gf-fillable-pdf' ), 403 );
		}

		$pdf_id = absint( $_GET['pdf_id'] ?? 0 );
		$record = GFFPDF_Entry_Handler::get_pdf_record( $pdf_id );

		if ( ! $record || ! file_exists( $record->pdf_path ) ) {
			wp_die( esc_html__( 'PDF not found.', 'gf-fillable-pdf' ), 404 );
		}

		if ( $mode === 'download' ) {
			GFFPDF_File_Handler::download_pdf( $record->pdf_path, basename( $record->pdf_path ) );
		} else {
			GFFPDF_File_Handler::stream_pdf( $record->pdf_path, basename( $record->pdf_path ) );
		}
	}

	public function ajax_deactivate_cleanup():void{
		if(!check_ajax_referer('gffpdf_deactivate_cleanup', 'nonce', false) || ! GFFPDF_Security::current_user_can()){
			wp_send_json_error(['message' => 'Permission denied.'], 403);
		}

		// Delegate all cleanup to the deactivator class
		GFFPDF_Deactivator::cleanup_data();

		wp_send_json_success();
	}
}