<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wcusage_tools_page() {
?>

    <div class="wrap admin-tools" style="margin: 0;">

        <?php echo do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

        <div class="wrap">

            <h1>Coupon Affiliates - Admin Tools</h1>
            <br/>
        
            <div class="wcusage-tools-container">

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Admin Reports', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('Generate some advanced detailed reports and analytics for your affiliate program.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage_admin_reports" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Activity Log', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('View a log of all the activity that has taken place in your affiliate program.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage_activity" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Bulk Create: Affiliate Coupons', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('Bulk create or import a list of new affiliate coupons (and users) to be automatically created.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-bulk-coupon-creator" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Bulk Edit: Coupon Settings', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('Bulk edit, import, or export existing coupon commission rates and assigned user.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-bulk-edit-coupon" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Bulk Assign: Coupons to Orders', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('Bulk assign affiliate coupons to orders for the affiliate to earn commission for that order.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-bulk-assign-coupons" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box">
                    <h2><?php esc_html_e('Import / Export Custom Tables', 'woo-coupon-usage'); ?></h2>
                    <p><?php esc_html_e('Import and Export the custom database tables created by this plugin.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-data-import-export" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box"<?php if( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                    <h2><?php esc_html_e('Bulk Edit: Product Settings', 'woo-coupon-usage');
                        if (!wcu_fs()->can_use_premium_code()) { ?> (PRO)<?php } ?></h2>
                    <p><?php esc_html_e('Bulk edit, import, or export the per-product commission settings.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-bulk-edit-product" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

                <div class="wcusage-tools-box"<?php if( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                    <h2><?php esc_html_e('Bulk Assign: Per-Affiliate Product Rates', 'woo-coupon-usage');
                        if (!wcu_fs()->can_use_premium_code()) { ?> (PRO)<?php } ?></h2>
                    <p><?php esc_html_e('Bulk assign per-product commission rates, on a per-affiliate basis.', 'woo-coupon-usage'); ?></p>
                    <a href="admin.php?page=wcusage-bulk-product-rates" class="button"><?php esc_html_e('Go to Page', 'woo-coupon-usage'); ?></a>
                </div>

            </div>

    </div>
<?php
}
