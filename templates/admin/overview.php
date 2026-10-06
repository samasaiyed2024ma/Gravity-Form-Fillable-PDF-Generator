<?php if ( ! defined( 'ABSPATH' ) ) exit;
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying custom table counts for overview screen.
$gffpdf_total_feeds    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_feeds" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying active feed count for overview screen.
$gffpdf_active_feeds   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_feeds WHERE is_active = 1" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Querying total PDF count for overview screen.
$gffpdf_total_pdfs     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_entries" );
$gffpdf_templates      = ( new GFFPDF_Template_Handler() )->list_templates();
?>
<div class="wrap gffpdf-wrap">
	<h1><?php esc_html_e( 'GF Fillable PDF Generator', 'gf-fillable-pdf-generator' ); ?></h1>

	<div class="gffpdf-stats-row">
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $gffpdf_total_feeds ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'Total Feeds', 'gf-fillable-pdf-generator' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $gffpdf_active_feeds ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'Active Feeds', 'gf-fillable-pdf-generator' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( count( $gffpdf_templates ) ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'PDF Templates', 'gf-fillable-pdf-generator' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $gffpdf_total_pdfs ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'PDFs Generated', 'gf-fillable-pdf-generator' ); ?></span>
		</div>
	</div>

	<div class="gffpdf-quick-links">
		<h2><?php esc_html_e( 'Quick Links', 'gf-fillable-pdf-generator' ); ?></h2>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_settings&subview=gffpdf' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Global Settings', 'gf-fillable-pdf-generator' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_forms' ) ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Manage Forms', 'gf-fillable-pdf-generator' ); ?>
		</a>
	</div>

	<?php if ( ! empty( $gffpdf_templates ) ) : ?>
	<div class="gffpdf-template-list">
		<h2><?php esc_html_e( 'Stored Templates', 'gf-fillable-pdf-generator' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filename', 'gf-fillable-pdf-generator' ); ?></th>
					<th><?php esc_html_e( 'Size', 'gf-fillable-pdf-generator' ); ?></th>
					<th><?php esc_html_e( 'Modified', 'gf-fillable-pdf-generator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gffpdf_templates as $gffpdf_template ) : ?>
				<tr>
					<td><?php echo esc_html( $gffpdf_template['name'] ); ?></td>
					<td><?php echo esc_html( $gffpdf_template['size'] ); ?></td>
					<td><?php echo esc_html( $gffpdf_template['modified'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>