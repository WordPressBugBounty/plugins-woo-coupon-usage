<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_admin_activity_page_html() {
// check user capabilities
if ( ! wcusage_check_admin_access() ) {
return;
}
?>

<link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

<!-- Output Page -->
<div class="wrap wcusage-admin-page">

	<?php do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

	<div class="wcu-page-header">
		<h1><i class="fas fa-history" style="color: #2271b1; margin-right: 8px;"></i><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p class="wcu-page-subtitle"><?php echo esc_html__( 'View a log of all activity in your affiliate program.', 'woo-coupon-usage' ); ?></p>
	</div>

	<?php
	if(isset($_POST['submit_days'])){
		// Check nonce for security
		if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'delete_logs_nonce')) {
			echo '<div><p>Sorry, your nonce did not verify.</p></div>';
			exit;
		}
		if ( !wcusage_check_admin_access() ) {
			echo '<div><p>Sorry, you do not have permission to delete logs.</p></div>';
			exit;
		}
		// Delete logs
		global $wpdb;
		$days = sanitize_text_field($_POST['days']);
		if(!$days) $days = 0;
		$days = intval($days);
		$tablename = $wpdb->prefix . 'wcusage_activity';
		$date_limit = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
		$result = $wpdb->query($wpdb->prepare("DELETE FROM `$tablename` WHERE `date` < %s", $date_limit)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		echo wp_kses_post("<div><p>Logs older than $days day(s) have been deleted.</p></div>");
	}
	$ListTable = new wcusage_activity_List_Table();
	$ListTable->prepare_items();
	?>

	<div style="margin-top: -30px;">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ); ?>" />
			<?php $ListTable->display(); ?>
		</form>
	</div>

	<!-- Add form for days input -->
	<form method="post" onsubmit="return confirm('Are you sure you want to delete logs? This action cannot be undone.')"
	style="margin-top: 16px; padding: 16px 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
		<label for="days" style="font-size: 13px; color: #50575e; font-weight: 500;">Delete logs older than </label>
		<input type="number" id="days" name="days" min="0" value="90" placeholder="0" style="width: 60px; padding: 6px 10px; border: 1px solid #d0d5dd; border-radius: 6px; font-size: 13px; min-height: 36px;">
		<span style="font-size: 13px; color: #50575e;">days:</span>
		<?php wp_nonce_field('delete_logs_nonce'); ?>
		<input type="submit" name="submit_days" value="Delete Logs" class="button" style="padding: 8px 18px; border-radius: 6px; font-size: 13px; cursor: pointer;">
	</form>

</div>

<?php
}
