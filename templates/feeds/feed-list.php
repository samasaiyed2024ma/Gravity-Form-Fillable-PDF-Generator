<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php if ( empty( $feeds ) ) : ?>
	<div class="gffpdf-empty-state">
		<span class="dashicons dashicons-media-document"></span>
		<p><?php esc_html_e( 'No feeds configured yet. Click "Add New Feed" to get started.', 'gf-fillable-pdf-generator' ); ?></p>
	</div>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped gffpdf-feed-table" id="gffpdf-feed-table">
		<thead>
			<tr>
				<th class="column-status"><?php esc_html_e( 'Status', 'gf-fillable-pdf-generator' ); ?></th>
				<th class="column-name"><?php esc_html_e( 'Feed Name', 'gf-fillable-pdf-generator' ); ?></th>
				<th class="column-template"><?php esc_html_e( 'PDF Template', 'gf-fillable-pdf-generator' ); ?></th>
				<th class="column-mappings"><?php esc_html_e( 'Mappings', 'gf-fillable-pdf-generator' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $feeds as $gffpdf_feed ) : ?>
				<?php
				$gffpdf_mappings       = json_decode( $gffpdf_feed->mappings, true ) ?? [];
				$gffpdf_mapping_count  = count( array_filter( $gffpdf_mappings ) );
				$gffpdf_template_name  = $gffpdf_feed->template_path ? basename( $gffpdf_feed->template_path ) : '—';
				$gffpdf_missing        = $gffpdf_feed->template_path && ! file_exists( $gffpdf_feed->template_path );
				?>
				<tr id="gffpdf-row-<?php echo esc_attr( $gffpdf_feed->id ); ?>" data-feed-id="<?php echo esc_attr( $gffpdf_feed->id ); ?>">

					<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'gf-fillable-pdf-generator' ); ?>">
						<label class="gffpdf-toggle" title="<?php esc_attr_e( 'Toggle active state', 'gf-fillable-pdf-generator' ); ?>">
							<input
								type="checkbox"
								class="gffpdf-status-toggle"
								data-feed-id="<?php echo esc_attr( $gffpdf_feed->id ); ?>"
								<?php checked( $gffpdf_feed->is_active, 1 ); ?>
							>
							<span class="gffpdf-toggle-slider"></span>
						</label>
					</td>

					<td class="column-name column-primary">
						<strong><?php echo esc_html( $gffpdf_feed->feed_name ); ?></strong>
						<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'gf-fillable-pdf-generator' ); ?></span></button>
						<div class="action-btn">
							<a class="gffpdf-edit-feed" data-feed-id="<?php echo esc_attr( $gffpdf_feed->id ); ?>">
								<?php esc_html_e( 'Edit', 'gf-fillable-pdf-generator' ); ?>
							</a> <span>|</span>
							<a class="gffpdf-duplicate-feed" data-feed-id="<?php echo esc_attr( $gffpdf_feed->id ); ?>">
								<?php esc_html_e( 'Duplicate', 'gf-fillable-pdf-generator' ); ?>
							</a> <span>|</span>
							<a class="button-link-delete gffpdf-delete-feed" data-feed-id="<?php echo esc_attr( $gffpdf_feed->id ); ?>">
								<?php esc_html_e( 'Delete', 'gf-fillable-pdf-generator' ); ?>
							</a>
						</div>
					</td>

					<td class="column-template" data-colname="<?php esc_attr_e( 'Template', 'gf-fillable-pdf-generator' ); ?>">
						<?php if ( $gffpdf_missing ) : ?>
							<span class="gffpdf-badge gffpdf-badge--error">⚠ <?php esc_html_e( 'Missing', 'gf-fillable-pdf-generator' ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $gffpdf_template_name ); ?>
						<?php endif; ?>
					</td>

					<td class="column-mappings" data-colname="<?php esc_attr_e( 'Mappings', 'gf-fillable-pdf-generator' ); ?>">
						<?php if ( $gffpdf_mapping_count > 0 ) : ?>
							<span class="gffpdf-mapping-chip">
								<?php printf(
									// translators: %d: The number of fields mapped.
									esc_html( _n( '%d mapped', '%d mapped', $gffpdf_mapping_count, 'gf-fillable-pdf-generator' ) ),
									absint( $gffpdf_mapping_count )
								); ?>
							</span>
						<?php else : ?>
							<span class="gffpdf-badge gffpdf-badge--gray"><?php esc_html_e( 'None', 'gf-fillable-pdf-generator' ); ?></span>
						<?php endif; ?>
					</td>

				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>