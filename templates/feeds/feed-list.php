<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php if ( empty( $feeds ) ) : ?>
	<div class="gffpdf-empty-state">
		<span class="dashicons dashicons-media-document"></span>
		<p><?php esc_html_e( 'No feeds configured yet. Click "Add New Feed" to get started.', 'gf-fillable-pdf' ); ?></p>
	</div>
<?php else : ?>
	<table class="wp-list-table widefat fixed striped gffpdf-feed-table" id="gffpdf-feed-table">
		<thead>
			<tr>
				<th class="column-status"><?php esc_html_e( 'Status', 'gf-fillable-pdf' ); ?></th>
				<th class="column-name"><?php esc_html_e( 'Feed Name', 'gf-fillable-pdf' ); ?></th>
				<th class="column-template"><?php esc_html_e( 'PDF Template', 'gf-fillable-pdf' ); ?></th>
				<th class="column-mappings"><?php esc_html_e( 'Mappings', 'gf-fillable-pdf' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $feeds as $feed ) : ?>
				<?php
				$mappings       = json_decode( $feed->mappings, true ) ?? [];
				$mapping_count  = count( array_filter( $mappings ) );
				$template_name  = $feed->template_path ? basename( $feed->template_path ) : '—';
				$missing        = $feed->template_path && ! file_exists( $feed->template_path );
				?>
				<tr id="gffpdf-row-<?php echo esc_attr( $feed->id ); ?>" data-feed-id="<?php echo esc_attr( $feed->id ); ?>">

					<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'gf-fillable-pdf' ); ?>">
						<label class="gffpdf-toggle" title="<?php esc_attr_e( 'Toggle active state', 'gf-fillable-pdf' ); ?>">
							<input
								type="checkbox"
								class="gffpdf-status-toggle"
								data-feed-id="<?php echo esc_attr( $feed->id ); ?>"
								<?php checked( $feed->is_active, 1 ); ?>
							>
							<span class="gffpdf-toggle-slider"></span>
						</label>
					</td>
				
				    <td class="column-name column-primary">
						<strong><?php echo esc_html( $feed->feed_name ); ?></strong>
						<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'gf-fillable-pdf' ); ?></span></button>
						<div class="action-btn">
							<a 	class="gffpdf-edit-feed"
								data-feed-id="<?php echo esc_attr( $feed->id ); ?>">
								<?php esc_html_e( 'Edit', 'gf-fillable-pdf' ); ?>
							</a> <span>|</span>
							<a	class="gffpdf-duplicate-feed"
								data-feed-id="<?php echo esc_attr( $feed->id ); ?>">
								<?php esc_html_e( 'Duplicate', 'gf-fillable-pdf' ); ?>
							</a> <span>|</span>
							<a class="button-link-delete gffpdf-delete-feed"
								data-feed-id="<?php echo esc_attr( $feed->id ); ?>">
								<?php esc_html_e( 'Delete', 'gf-fillable-pdf' ); ?>
							</a>
						</div>
					</td>

					<td class="column-template" data-colname="<?php esc_attr_e( 'Template', 'gf-fillable-pdf' ); ?>">
						<?php if ( $missing ) : ?>
							<span class="gffpdf-badge gffpdf-badge--error"><?php esc_html_e( 'Missing', 'gf-fillable-pdf' ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $template_name ); ?>
						<?php endif; ?>
					</td>

					<td class="column-mappings" data-colname="<?php esc_attr_e( 'Mappings', 'gf-fillable-pdf' ); ?>">
						<?php
						printf(
							/* translators: %d: number of mapped fields */
							esc_html( _n( '%d field mapped', '%d fields mapped', $mapping_count, 'gf-fillable-pdf' ) ),
							$mapping_count
						);
						?>
					</td>

				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>