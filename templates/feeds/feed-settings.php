<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<div class="wrap gffpdf-wrap">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Fillable PDF Generator', 'gf-fillable-pdf' ); ?>
		&mdash; <?php echo esc_html( $form['title'] ); ?>
	</h1>

	<button type="button" id="gffpdf-add-feed" class="page-title-action">
		<?php esc_html_e( '+ Add New Feed', 'gf-fillable-pdf' ); ?>
	</button>

	<hr class="wp-header-end">

	<div id="gffpdf-notice" class="gffpdf-notice" style="display:none;"></div>

	<?php GFFPDF_Feed_List::render( $form['id'] ); ?>
</div>

<!-- =====================================================================
     FEED EDITOR MODAL
====================================================================== -->
<div id="gffpdf-modal" class="gffpdf-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="gffpdf-modal-title">
	<div class="gffpdf-modal-backdrop"></div>
	<div class="gffpdf-modal-dialog">

		<div class="gffpdf-modal-header">
			<h2 id="gffpdf-modal-title"><?php esc_html_e( 'Feed Settings', 'gf-fillable-pdf' ); ?></h2>
			<button type="button" class="gffpdf-modal-close" aria-label="<?php esc_attr_e( 'Close', 'gf-fillable-pdf' ); ?>">&times;</button>
		</div>

		<div class="gffpdf-modal-body">

			<input type="hidden" id="gffpdf-feed-id" value="">
			<input type="hidden" id="gffpdf-form-id" value="<?php echo esc_attr( $form['id'] ); ?>">

			<!-- Feed name -->
			<div class="gffpdf-field-row">
				<label for="gffpdf-feed-name"><?php esc_html_e( 'Feed Name', 'gf-fillable-pdf' ); ?> <span class="required">*</span></label>
				<input type="text" id="gffpdf-feed-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Invoice PDF', 'gf-fillable-pdf' ); ?>" required>
			</div>

			<!-- Active toggle -->
			<div class="gffpdf-field-row gffpdf-field-row--inline">
				<label for="gffpdf-is-active"><?php esc_html_e( 'Active', 'gf-fillable-pdf' ); ?></label>
				<label class="gffpdf-toggle">
					<input type="checkbox" id="gffpdf-is-active" checked>
					<span class="gffpdf-toggle-slider"></span>
				</label>
			</div>

			<!-- PDF upload -->
			<div class="gffpdf-field-row">
				<label><?php esc_html_e( 'PDF Template', 'gf-fillable-pdf' ); ?> <span class="required">*</span></label>
				<div class="gffpdf-upload-area" id="gffpdf-upload-area">
					<input type="file" id="gffpdf-pdf-file" accept=".pdf" style="display:none;">
					<button type="button" id="gffpdf-upload-btn" class="button button-secondary">
						<?php esc_html_e( 'Upload PDF', 'gf-fillable-pdf' ); ?>
					</button>
					<span id="gffpdf-upload-status" class="gffpdf-upload-status"></span>
					<p class="description"><?php esc_html_e( 'Only fillable AcroForm PDFs are supported.', 'gf-fillable-pdf' ); ?></p>
				</div>
				<input type="hidden" id="gffpdf-template-path">
			</div>

			<!-- Filename pattern -->
			<div class="gffpdf-field-row">
				<label for="gffpdf-filename-pattern"><?php esc_html_e( 'Filename Pattern', 'gf-fillable-pdf' ); ?></label>
				<input type="text" id="gffpdf-filename-pattern" class="regular-text" placeholder="submission-{entry_id}-{date}">
				<p class="description"><?php esc_html_e( 'Available tags: {entry_id}, {form_id}, {date}, {field:N}', 'gf-fillable-pdf' ); ?></p>
			</div>

			<!-- Attach to email -->
			<div class="gffpdf-field-row gffpdf-field-row--inline">
				<label for="gffpdf-attach-email"><?php esc_html_e( 'Attach to Email Notification', 'gf-fillable-pdf' ); ?></label>
				<label class="gffpdf-toggle">
					<input type="checkbox" id="gffpdf-attach-email">
					<span class="gffpdf-toggle-slider"></span>
				</label>
			</div>

			<!-- Field Mappings -->
			<div class="gffpdf-field-row" id="gffpdf-mappings-section" style="display:none;">
				<label><?php esc_html_e( 'Field Mappings', 'gf-fillable-pdf' ); ?></label>

				<div class="gffpdf-mapping-actions">
					<button type="button" id="gffpdf-auto-map" class="button button-secondary">
						<?php esc_html_e( '⚡ Auto Map', 'gf-fillable-pdf' ); ?>
					</button>
				</div>

				<table class="gffpdf-mapping-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'PDF Field', 'gf-fillable-pdf' ); ?></th>
							<th><?php esc_html_e( 'Gravity Forms Field', 'gf-fillable-pdf' ); ?></th>
						</tr>
					</thead>
					<tbody id="gffpdf-mapping-rows">
						<!-- Populated dynamically by mappings.js -->
					</tbody>
				</table>

				<p class="description"><?php esc_html_e( 'Map each PDF field to a Gravity Forms field. Unmapped fields will be left blank.', 'gf-fillable-pdf' ); ?></p>
			</div>

			<!-- GF Fields data for JS -->
			<script type="application/json" id="gffpdf-gf-fields">
				<?php echo wp_json_encode( $fields ); ?>
			</script>

		</div><!-- .gffpdf-modal-body -->

		<div class="gffpdf-modal-footer">
			<button type="button" id="gffpdf-save-feed" class="button button-primary">
				<?php esc_html_e( 'Save Feed', 'gf-fillable-pdf' ); ?>
			</button>
			<button type="button" class="button gffpdf-modal-close">
				<?php esc_html_e( 'Cancel', 'gf-fillable-pdf' ); ?>
			</button>
		</div>

	</div><!-- .gffpdf-modal-dialog -->
</div><!-- #gffpdf-modal -->