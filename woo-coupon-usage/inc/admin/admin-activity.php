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

<?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

<style>@media screen and (min-width: 540px) { .column-user_id { width: 250px; max-width: 100%; } }</style>
<style>@media screen and (min-width: 540px) { .column-event { text-align: left !important; } }</style>

<!-- Output Page -->
<div class="wrap plugin-settings">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

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
		$sql = "DELETE FROM `$tablename` WHERE `date` < %s";
		$prepared_query = $wpdb->prepare($sql, $date_limit);
		$result = $wpdb->query($prepared_query);
		echo wp_kses_post("<div><p>Logs older than $days day(s) have been deleted.</p></div>");
	}
	$ListTable = new wcusage_activity_List_Table();
	$ListTable->prepare_items();
	?>

	<div class="wrap" style="margin-top: -30px;">
		<input type="hidden" name="page" value="<?php echo esc_html( $_GET['page'] ); ?>" />
		<?php $ListTable->display() ?>
	</div>

	<!-- Add form for days input -->
	<form method="post" onsubmit="return confirm('Are you sure you want to delete logs? This action cannot be undone.')"
	style="margin-top: 10px;">
		<label for="days">Delete logs older than </label>
		<input type="number" id="days" name="days" min="0" value="90" placeholder="0" style="width: 50px;">
		days:
		<?php wp_nonce_field('delete_logs_nonce'); ?>
		<input type="submit" name="submit_days" value="Delete Logs">
	</form>

</div>

<?php
}
