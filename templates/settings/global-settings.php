<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap gffpdf-wrap">
	<h1><?php esc_html_e( 'Fillable PDF Generator — Settings', 'gf-fillable-pdf' ); ?></h1>

	<div id="gffpdf-settings-notice" class="gffpdf-notice" style="display:none;"></div>

	<div class="gffpdf-settings-container">

		<!-- ===================== SETTINGS PANEL ======================== -->
		<div class="gffpdf-settings-panel">
			<div class="gffpdf-section-head">
				<span class="gffpdf-section-icon">⚙️</span>
				<h3><?php esc_html_e( 'Global Defaults', 'gf-fillable-pdf' ); ?></h3>
			</div>

			<div class="gffpdf-section-body">
				<table class="form-table" role="presentation">
					<tbody>

						<!-- Font Family -->
						<tr>
							<th scope="row">
								<label for="gffpdf-font-family"><?php esc_html_e( 'Default Font Family', 'gf-fillable-pdf' ); ?></label>
							</th>
							<td>
								<select id="gffpdf-font-family" name="default_font_family">
									<?php
									$families = GFFPDF_Font_Manager::get_all_fonts();
									foreach ( $families as $val => $label ) {
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $val ),
											selected( $settings['default_font_family'], $val, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>

						<!-- Font Size -->
						<tr>
							<th scope="row">
								<label for="gffpdf-font-size"><?php esc_html_e( 'Default Font Size (pt)', 'gf-fillable-pdf' ); ?></label>
							</th>
							<td>
								<input type="number" id="gffpdf-font-size" name="default_font_size"
									value="<?php echo esc_attr( $settings['default_font_size'] ); ?>" min="6" max="72" class="small-text">
							</td>
						</tr>

						<!-- Font Color -->
						<tr>
							<th scope="row">
								<label for="gffpdf-font-color"><?php esc_html_e( 'Default Font Color', 'gf-fillable-pdf' ); ?></label>
							</th>
							<td>
								<input type="color" id="gffpdf-font-color" name="default_font_color"
									value="<?php echo esc_attr( $settings['default_font_color'] ); ?>">
							</td>
						</tr>

						<!-- Filename Pattern -->
						<tr>
							<th scope="row">
								<label for="gffpdf-filename-pattern"><?php esc_html_e( 'Default Filename Pattern', 'gf-fillable-pdf' ); ?></label>
							</th>
							<td>
								<input type="text" id="gffpdf-filename-pattern" name="filename_pattern"
									value="<?php echo esc_attr( $settings['filename_pattern'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Tags: {entry_id}, {form_id}, {date}, {field:N}', 'gf-fillable-pdf' ); ?></p>
							</td>
						</tr>

						<!-- Save PDFs -->
						<tr>
							<th scope="row"><?php esc_html_e( 'Save Generated PDFs', 'gf-fillable-pdf' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="gffpdf-save-pdfs" name="save_pdfs" value="1"
										<?php checked( $settings['save_pdfs'] ); ?>>
									<?php esc_html_e( 'Save PDFs to server storage', 'gf-fillable-pdf' ); ?>
								</label>
							</td>
						</tr>

						<!-- Flatten PDF -->
						<tr>
							<th scope="row"><?php esc_html_e( 'Flatten PDFs', 'gf-fillable-pdf' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="gffpdf-flatten" name="flatten_pdf" value="1"
										<?php checked( $settings['flatten_pdf'] ); ?>>
									<?php esc_html_e( 'Flatten after filling (prevents editing)', 'gf-fillable-pdf' ); ?>
								</label>
							</td>
						</tr>

						<!-- RTL Support -->
						<tr>
							<th scope="row"><?php esc_html_e( 'RTL Support', 'gf-fillable-pdf' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="gffpdf-rtl" name="rtl_support" value="1"
										<?php checked( $settings['rtl_support'] ); ?>>
									<?php esc_html_e( 'Enable right-to-left text direction', 'gf-fillable-pdf' ); ?>
								</label>
							</td>
						</tr>

						<!-- Enable Logs -->
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Logging', 'gf-fillable-pdf' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="gffpdf-logs" name="enable_logs" value="1"
										<?php checked( $settings['enable_logs'] ); ?>>
									<?php esc_html_e( 'Write plugin events to log files', 'gf-fillable-pdf' ); ?>
								</label>
							</td>
						</tr>

						<!-- Storage Path -->
						<tr>
							<th scope="row">
								<label for="gffpdf-storage-path"><?php esc_html_e( 'Storage Path', 'gf-fillable-pdf' ); ?></label>
							</th>
							<td>
								<input type="text" id="gffpdf-storage-path" name="storage_path"
									value="<?php echo esc_attr( $settings['storage_path'] ); ?>" class="large-text">
								<p class="description"><?php esc_html_e( 'Absolute server path for PDF storage. Default is wp-content/uploads/gffpdf/', 'gf-fillable-pdf' ); ?></p>
							</td>
						</tr>

					</tbody>
				</table>

				<p class="submit">
					<button type="button" id="gffpdf-save-settings" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'gf-fillable-pdf' ); ?>
					</button>
				</p>
			</div>
		</div><!-- .gffpdf-settings-panel -->

		<!-- ===================== MANAGE FONTS (inline) ================== -->
		<div class="gffpdf-settings-panel" style="margin-top:24px;">
			<div class="gffpdf-section-toggle-header gffpdf-section-head" id="gffpdf-global-fonts-toggle" style="cursor:pointer;display:flex;align-items:center;gap:8px;margin-bottom:0;">
				<span class="gffpdf-toggle-arrow">▶</span>
				<h2 style="margin:1em 0;"><?php esc_html_e( '🔤 Manage Fonts', 'gf-fillable-pdf' ); ?></h2>
				<span style="font-size:13px;color:#646970;font-weight:400;"><?php esc_html_e( '(click to expand)', 'gf-fillable-pdf' ); ?></span>
			</div>
			<!-- <div class="gffpdf-section-body"> -->
				<div id="gffpdf-global-fonts-body" style="display:none;margin-top:16px;">
					<?php include GFFPDF_PATH . 'templates/settings/manage-fonts.php'; ?>
				</div>
			<!-- </div> -->
		</div>


		<!-- ===================== LOGS PANEL ============================ -->
		<?php if ( $settings['enable_logs'] ) : ?>
		<div class="gffpdf-logs-panel">
			<div class="gffpdf-section-head">
				<span class="gffpdf-section-icon"></span>
				<h3><?php esc_html_e( 'Recent Logs', 'gf-fillable-pdf' ); ?></h3>
			</div>

			<div class="gffpdf-section-body">
				<p>
					<button type="button" id="gffpdf-clear-logs" class="button button-secondary">
						<?php esc_html_e( 'Clear All Logs', 'gf-fillable-pdf' ); ?>
					</button>
				</p>
				<div class="gffpdf-log-viewer">
					<?php if ( empty( $logs ) ) : ?>
						<p><?php esc_html_e( 'No log entries yet.', 'gf-fillable-pdf' ); ?></p>
					<?php else : ?>
						<pre class="gffpdf-log-pre"><?php echo esc_html( implode( "\n", $logs ) ); ?></pre>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>

	</div><!-- .gffpdf-settings-container -->
</div>