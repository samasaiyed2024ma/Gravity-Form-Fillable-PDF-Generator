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

	<!-- ── Feed list ──────────────────────────────────────────────── -->
	<div id="gffpdf-feed-list-wrap">
		<?php GFFPDF_Feed_List::render( $form['id'] ); ?>
	</div>

	<!-- =====================================================================
	     INLINE FEED EDITOR (hidden until Add / Edit clicked)
	====================================================================== -->
	<div id="gffpdf-editor" style="display:none;">

		<hr style="margin:24px 0;">

		<div class="gffpdf-editor-header">
			<h2 id="gffpdf-editor-title"><?php esc_html_e( 'Feed Settings', 'gf-fillable-pdf' ); ?></h2>
			<button type="button" id="gffpdf-editor-cancel" class="button">
				<?php esc_html_e( '✕ Cancel', 'gf-fillable-pdf' ); ?>
			</button>
		</div>

		<div class="gffpdf-editor-body">

			<input type="hidden" id="gffpdf-feed-id" value="">
			<input type="hidden" id="gffpdf-form-id" value="<?php echo esc_attr( $form['id'] ); ?>">

			<!-- ── SECTION: Basic ──────────────────────────────────── -->
			<div class="gffpdf-section">
				<h3 class="gffpdf-section-title"><?php esc_html_e( 'Basic Settings', 'gf-fillable-pdf' ); ?></h3>

				<div class="gffpdf-field-row">
					<label for="gffpdf-feed-name"><?php esc_html_e( 'Feed Name', 'gf-fillable-pdf' ); ?> <span class="required">*</span></label>
					<input type="text" id="gffpdf-feed-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Invoice PDF', 'gf-fillable-pdf' ); ?>" required>
				</div>

				<div class="gffpdf-field-row gffpdf-field-row--inline">
					<label for="gffpdf-is-active"><?php esc_html_e( 'Active', 'gf-fillable-pdf' ); ?></label>
					<label class="gffpdf-toggle">
						<input type="checkbox" id="gffpdf-is-active" checked>
						<span class="gffpdf-toggle-slider"></span>
					</label>
				</div>

				<div class="gffpdf-field-row" id="gffpdf-shortcode-row" style="display:none;">
					<label><?php esc_html_e( 'Shortcode', 'gf-fillable-pdf' ); ?></label>
					<div class="gffpdf-shortcode-box">
						<code id="gffpdf-shortcode-display"></code>
						<button type="button" class="button button-small" id="gffpdf-copy-shortcode"><?php esc_html_e( 'Copy', 'gf-fillable-pdf' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Use this shortcode anywhere — including email notifications — to insert a PDF download link. Replace {entry_id} with the actual entry ID or a GF merge tag.', 'gf-fillable-pdf' ); ?></p>
				</div>

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

				<div class="gffpdf-field-row">
					<label for="gffpdf-filename-pattern"><?php esc_html_e( 'Filename Pattern', 'gf-fillable-pdf' ); ?></label>
					<input type="text" id="gffpdf-filename-pattern" class="regular-text" placeholder="submission-{entry_id}-{date}">
					<p class="description"><?php esc_html_e( 'Available tags: {entry_id}, {form_id}, {date}, {field:N}', 'gf-fillable-pdf' ); ?></p>
				</div>
			</div><!-- /.gffpdf-section -->

			<!-- ── SECTION: Notifications ──────────────────────────── -->
			<div class="gffpdf-section">
				<h3 class="gffpdf-section-title"><?php esc_html_e( 'Email Notifications', 'gf-fillable-pdf' ); ?></h3>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Select which notifications should receive this PDF as an attachment. If none are selected, the PDF will not be attached to any notification.', 'gf-fillable-pdf' ); ?></p>

				<div id="gffpdf-notifications-list" class="gffpdf-notifications-list">
					<?php if ( empty( $notifications ) ) : ?>
						<p class="description"><?php esc_html_e( 'No notifications found for this form.', 'gf-fillable-pdf' ); ?></p>
					<?php else : ?>
						<?php foreach ( $notifications as $notif ) : ?>
							<label class="gffpdf-notification-item">
								<input type="checkbox" class="gffpdf-notification-cb" value="<?php echo esc_attr( $notif['id'] ); ?>">
								<?php echo esc_html( $notif['name'] ); ?> <br>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div><!-- /.gffpdf-section -->

			<!-- ── SECTION: Typography ─────────────────────────────── -->
			<div class="gffpdf-section">
				<h3 class="gffpdf-section-title"><?php esc_html_e( 'Typography', 'gf-fillable-pdf' ); ?></h3>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Override global font defaults for this feed only. Leave blank to use global settings.', 'gf-fillable-pdf' ); ?></p>

				<!-- Font Family -->
				<div class="gffpdf-field-row">
					<label for="gffpdf-font-family"><?php esc_html_e( 'Font Family', 'gf-fillable-pdf' ); ?></label>
					<select id="gffpdf-font-family" name="font_family" style="min-width:250px;">
						<option value=""><?php esc_html_e( '— Use global setting —', 'gf-fillable-pdf' ); ?></option>
						<?php foreach ( $all_fonts as $fval => $flabel ) : ?>
							<option value="<?php echo esc_attr( $fval ); ?>"><?php echo esc_html( $flabel ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Font Size -->
				<div class="gffpdf-field-row">
					<label for="gffpdf-font-size"><?php esc_html_e( 'Font Size (pt)', 'gf-fillable-pdf' ); ?></label>
					<input type="number" id="gffpdf-font-size" name="font_size" min="6" max="72" class="small-text" placeholder="<?php esc_attr_e( 'global', 'gf-fillable-pdf' ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty to use global default or auto-size from field height.', 'gf-fillable-pdf' ); ?></p>
				</div>

				<!-- Font Color -->
				<div class="gffpdf-field-row">
					<label for="gffpdf-font-color"><?php esc_html_e( 'Font Color', 'gf-fillable-pdf' ); ?></label>
					<div style="display:flex;gap:8px;align-items:center;">
						<input type="color" id="gffpdf-font-color-picker" value="#000000">
						<input type="text" id="gffpdf-font-color" name="font_color" class="small-text" placeholder="#000000" maxlength="7" style="width:90px;">
						<button type="button" id="gffpdf-clear-font-color" class="button button-small"><?php esc_html_e( 'Clear (use global)', 'gf-fillable-pdf' ); ?></button>
					</div>
				</div>

				<!-- Reverse Text -->
				<div class="gffpdf-field-row gffpdf-field-row--inline">
					<label for="gffpdf-reverse-text"><?php esc_html_e( 'Reverse Text', 'gf-fillable-pdf' ); ?></label>
					<label class="gffpdf-toggle">
						<input type="checkbox" id="gffpdf-reverse-text">
						<span class="gffpdf-toggle-slider"></span>
					</label>
					<p class="description" style="margin-left:60px;"><?php esc_html_e( 'Reverses each field value character by character before writing to PDF.', 'gf-fillable-pdf' ); ?></p>
				</div>
			</div><!-- /.gffpdf-section -->

			<!-- ── SECTION: Conditional Logic ──────────────────────── -->
			<div class="gffpdf-section">
				<h3 class="gffpdf-section-title"><?php esc_html_e( 'Conditional Logic', 'gf-fillable-pdf' ); ?></h3>

				<div class="gffpdf-field-row gffpdf-field-row--inline">
					<label for="gffpdf-cl-enabled"><?php esc_html_e( 'Enable Conditional Logic', 'gf-fillable-pdf' ); ?></label>
					<label class="gffpdf-toggle">
						<input type="checkbox" id="gffpdf-cl-enabled">
						<span class="gffpdf-toggle-slider"></span>
					</label>
				</div>

				<div id="gffpdf-cl-settings" style="display:none;margin-top:12px;">
					<div class="gffpdf-field-row">
						<label><?php esc_html_e( 'Generate this PDF if', 'gf-fillable-pdf' ); ?></label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
							<select id="gffpdf-cl-logic-type">
								<option value="all"><?php esc_html_e( 'ALL', 'gf-fillable-pdf' ); ?></option>
								<option value="any"><?php esc_html_e( 'ANY', 'gf-fillable-pdf' ); ?></option>
							</select>
							<span><?php esc_html_e( 'of the following rules match:', 'gf-fillable-pdf' ); ?></span>
						</div>
					</div>
					<div id="gffpdf-cl-rules"></div>
					<button type="button" id="gffpdf-cl-add-rule" class="button button-secondary" style="margin-top:8px;">
						<?php esc_html_e( '+ Add Rule', 'gf-fillable-pdf' ); ?>
					</button>
				</div>
			</div><!-- /.gffpdf-section -->

			<!-- ── SECTION: Field Mappings ────────────────────────── -->
			<div class="gffpdf-field-row" id="gffpdf-mappings-section" style="display:none;">
				<div class="gffpdf-section">
					<h3 class="gffpdf-section-title"><?php esc_html_e( 'Field Mappings', 'gf-fillable-pdf' ); ?></h3>
					<div class="gffpdf-mapping-actions">
						<button type="button" id="gffpdf-auto-map" class="button button-secondary">
							<?php esc_html_e( '⚡ Auto Map', 'gf-fillable-pdf' ); ?>
						</button>
					</div>

					<div class="gffpdf-table-scroll-container">
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
					</div>
					<p class="description"><?php esc_html_e( 'Map each PDF field to a Gravity Forms field. Unmapped fields will be left blank.', 'gf-fillable-pdf' ); ?></p>
				</div>
			</div>

			<!-- ── SECTION: Manage Fonts (inline) ─────────────────── -->
			<div class="gffpdf-section" id="gffpdf-manage-fonts-panel">
				<div class="gffpdf-section-toggle-header" id="gffpdf-fonts-toggle" style="cursor:pointer;display:flex;align-items:center;gap:8px;">
					<span class="gffpdf-toggle-arrow">▶</span>
					<h3 class="gffpdf-section-title" style="margin:0;"><?php esc_html_e( '🔤 Manage Fonts', 'gf-fillable-pdf' ); ?></h3>
					<span style="font-size:12px;color:#646970;font-weight:400;"><?php esc_html_e( '(click to expand)', 'gf-fillable-pdf' ); ?></span>
				</div>
				<div id="gffpdf-fonts-panel-body" style="display:none;margin-top:16px;">
					<?php include GFFPDF_PATH . 'templates/settings/manage-fonts.php'; ?>
				</div>
			</div><!-- /.gffpdf-section -->

			<!-- GF Fields data for JS -->
			<script type="application/json" id="gffpdf-gf-fields">
				<?php echo wp_json_encode( $fields ); ?>
			</script>
			<script type="application/json" id="gffpdf-gf-fields-cl">
				<?php echo wp_json_encode( array_filter( $fields, fn( $f ) => ! in_array( $f['type'], [ 'html', 'section', 'page' ], true ) ) ); ?>
			</script>

		</div><!-- .gffpdf-editor-body -->

		<div class="gffpdf-editor-footer">
			<button type="button" id="gffpdf-save-feed" class="button button-primary">
				<?php esc_html_e( 'Save Feed', 'gf-fillable-pdf' ); ?>
			</button>
			<button type="button" id="gffpdf-editor-cancel-bottom" class="button">
				<?php esc_html_e( 'Cancel', 'gf-fillable-pdf' ); ?>
			</button>
		</div>

	</div><!-- #gffpdf-editor -->

</div><!-- .gffpdf-wrap -->