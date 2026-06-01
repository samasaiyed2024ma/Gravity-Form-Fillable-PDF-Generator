<?php

if ( ! defined( 'ABSPATH' ) ) exit;

$_custom_fonts  = GFFPDF_Font_Manager::get_custom_fonts();
$_builtin_fonts = GFFPDF_Font_Manager::get_builtin_fonts();
?>
<div class="gffpdf-fonts-partial" style="padding:20px;">

	<!-- Upload -->
	<div class="upload-custom-fonts" style="border-bottom:1px solid #e5e7eb;padding-bottom:20px;">
		<h3 style="margin-top:0;"><?php esc_html_e( 'Upload Custom Font', 'gf-fillable-pdf' ); ?></h3>
		<table class="form-table" style="margin:0;"><tbody>
			<tr>
				<th><label for="gffpdf-font-file-inline"><?php esc_html_e( 'Font File (.ttf / .otf)', 'gf-fillable-pdf' ); ?></label></th>
				<td><input type="file" id="gffpdf-font-file-inline" accept=".ttf,.otf"></td>
			</tr>
			<tr>
				<th><label for="gffpdf-font-label-inline"><?php esc_html_e( 'Display Name', 'gf-fillable-pdf' ); ?></label></th>
				<td><input type="text" id="gffpdf-font-label-inline" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. My Custom Font', 'gf-fillable-pdf' ); ?>"></td>
			</tr>
		</tbody></table>
		<p>
			<button type="button" id="gffpdf-font-upload-btn-inline" class="button button-primary"><?php esc_html_e( 'Upload Font', 'gf-fillable-pdf' ); ?></button>
			<span id="gffpdf-font-upload-status-inline" style="margin-left:8px;font-style:italic;color:#646970;"></span>
		</p>
	</div>

	<!-- Custom fonts list -->
	<div class="uploaded-custom-fonts-container" style="margin-top:24px;padding-bottom:20px;border-bottom:1px solid #e5e7eb;">
		<h3 style="margin-top:0;"><?php esc_html_e( 'Uploaded Custom Fonts', 'gf-fillable-pdf' ); ?></h3>
		<?php if ( empty( $_custom_fonts ) ) : ?>
			<p class="description" id="gffpdf-no-custom-fonts"><?php esc_html_e( 'No custom fonts uploaded yet.', 'gf-fillable-pdf' ); ?></p>
		<?php else : ?>
			<p class="description" id="gffpdf-no-custom-fonts" style="display:none;"><?php esc_html_e( 'No custom fonts uploaded yet.', 'gf-fillable-pdf' ); ?></p>
		<?php endif; ?>
		<table class="widefat" id="gffpdf-inline-custom-fonts-table" <?php echo empty($_custom_fonts) ? 'style="display:none;"' : ''; ?>>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Family Key', 'gf-fillable-pdf' ); ?></th>
					<th><?php esc_html_e( 'Display Name', 'gf-fillable-pdf' ); ?></th>
					<th><?php esc_html_e( 'Action', 'gf-fillable-pdf' ); ?></th>
				</tr>
			</thead>
			<tbody id="gffpdf-inline-custom-fonts-list" style="background:#f0f0f0cf">
				<?php foreach ( $_custom_fonts as $family => $label ) : ?>
					<tr id="gffpdf-inline-font-row-<?php echo esc_attr( $family ); ?>">
						<td><code><?php echo esc_html( $family ); ?></code></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td>
							<button type="button" class="button button-small gffpdf-delete-font-inline" data-family="<?php echo esc_attr( $family ); ?>">
								<?php esc_html_e( 'Delete', 'gf-fillable-pdf' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Built-in fonts reference -->
	<div class="built-in-fonts" style="margin-top:24px;padding-bottom:20px;">
		<h3 style="margin-top:0;"><?php esc_html_e( 'Built-in Fonts', 'gf-fillable-pdf' ); ?></h3>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Family Key', 'gf-fillable-pdf' ); ?></th>
					<th><?php esc_html_e( 'Display Name', 'gf-fillable-pdf' ); ?></th>
				</tr>
			</thead>
			<tbody style="background:#f0f0f0cf">
				<?php foreach ( $_builtin_fonts as $fam => $lbl ) : ?>
					<tr>
						<td><code><?php echo esc_html( $fam ); ?></code></td>
						<td><?php echo esc_html( $lbl ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</div><!-- .gffpdf-fonts-partial -->