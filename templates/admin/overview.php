<?php if ( ! defined( 'ABSPATH' ) ) exit;
global $wpdb;
$total_feeds    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_feeds" );
$active_feeds   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_feeds WHERE is_active = 1" );
$total_pdfs     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gffpdf_entries" );
$templates      = ( new GFFPDF_Template_Handler() )->list_templates();
?>
<div class="wrap gffpdf-wrap">
	<h1><?php esc_html_e( 'GF Fillable PDF Generator', 'gf-fillable-pdf' ); ?></h1>

	<div class="gffpdf-stats-row">
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $total_feeds ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'Total Feeds', 'gf-fillable-pdf' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $active_feeds ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'Active Feeds', 'gf-fillable-pdf' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( count( $templates ) ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'PDF Templates', 'gf-fillable-pdf' ); ?></span>
		</div>
		<div class="gffpdf-stat-card">
			<span class="gffpdf-stat-number"><?php echo esc_html( $total_pdfs ); ?></span>
			<span class="gffpdf-stat-label"><?php esc_html_e( 'PDFs Generated', 'gf-fillable-pdf' ); ?></span>
		</div>
	</div>

	<div class="gffpdf-quick-links">
		<h2><?php esc_html_e( 'Quick Links', 'gf-fillable-pdf' ); ?></h2>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_settings&subview=gffpdf' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Global Settings', 'gf-fillable-pdf' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_forms' ) ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Manage Forms', 'gf-fillable-pdf' ); ?>
		</a>
	</div>

	<?php if ( ! empty( $templates ) ) : ?>
	<div class="gffpdf-template-list">
		<h2><?php esc_html_e( 'Stored Templates', 'gf-fillable-pdf' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Filename', 'gf-fillable-pdf' ); ?></th>
					<th><?php esc_html_e( 'Size', 'gf-fillable-pdf' ); ?></th>
					<th><?php esc_html_e( 'Modified', 'gf-fillable-pdf' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $t ) : ?>
				<tr>
					<td><?php echo esc_html( $t['name'] ); ?></td>
					<td><?php echo esc_html( $t['size'] ); ?></td>
					<td><?php echo esc_html( $t['modified'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>