<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The tools shown on the Admin Tools page, grouped into sections.
 *
 * Most of these screens are registered with "wcusage_tools" as their parent,
 * which means WordPress never draws them in the sidebar - this page is the
 * only way to reach them. A tool that is not registered on this site (its
 * feature is switched off, or the current user cannot open it) is left out
 * rather than shown as a link that lands on a permissions error.
 *
 * @return array List of sections, each with a title, icon and list of tools.
 */
function wcusage_tools_page_sections() {

    $premium         = wcu_fs()->can_use_premium_code();
    $affiliate       = wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) );
    $affiliate_lower = strtolower( wcusage_get_affiliate_text( __( 'affiliate', 'woo-coupon-usage' ) ) );

    // Setup & Integrations.
    $setup = array(
        array(
            'title' => function_exists( 'wcusage_api_webhooks_available' ) && wcusage_api_webhooks_available()
                ? __( 'API & Webhooks', 'woo-coupon-usage' )
                : __( 'API', 'woo-coupon-usage' ),
            'desc'  => __( 'Enable the REST API, manage API keys, and connect external apps to your affiliate program.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-plug',
            'url'   => admin_url( 'admin.php?page=wcusage_api' ),
        ),
        array(
            'title' => __( 'Setup Wizard', 'woo-coupon-usage' ),
            'desc'  => __( 'Run through the guided setup again to check your dashboard, registration and commission settings.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-wand-magic-sparkles',
            'url'   => admin_url( 'admin.php?page=wcusage_setup' ),
        ),
        array(
            'title' => __( 'Instructions & Plugin Details', 'woo-coupon-usage' ),
            'desc'  => __( 'A walkthrough of how the plugin fits together, plus a reference for every shortcode it provides.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-circle-question',
            'url'   => admin_url( 'admin.php?page=wcusage_help' ),
        ),
    );

    // Bulk Editing.
    $bulk = array(
        array(
            /* translators: %s: affiliate label. */
            'title' => sprintf( __( 'Bulk Create: %s Coupons', 'woo-coupon-usage' ), $affiliate ),
            /* translators: %s: affiliate label. */
            'desc'  => sprintf( __( 'Bulk create or import a list of new %s coupons (and users) to be automatically created.', 'woo-coupon-usage' ), $affiliate_lower ),
            'icon'  => 'fa-solid fa-ticket',
            'url'   => admin_url( 'admin.php?page=wcusage-bulk-coupon-creator' ),
        ),
        array(
            'title' => __( 'Bulk Edit: Coupon Settings', 'woo-coupon-usage' ),
            'desc'  => __( 'Bulk edit, import, or export existing coupon commission rates and assigned user.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-pen-to-square',
            'url'   => admin_url( 'admin.php?page=wcusage-bulk-edit-coupon' ),
        ),
        array(
            'title' => __( 'Bulk Assign: Coupons to Orders', 'woo-coupon-usage' ),
            /* translators: %s: affiliate label. */
            'desc'  => sprintf( __( 'Bulk assign %s coupons to orders, so the coupon owner earns commission for that order.', 'woo-coupon-usage' ), $affiliate_lower ),
            'icon'  => 'fa-solid fa-arrow-right-arrow-left',
            'url'   => admin_url( 'admin.php?page=wcusage-bulk-assign-coupons' ),
        ),
        array(
            'title' => __( 'Bulk Edit: Product Settings', 'woo-coupon-usage' ),
            'desc'  => __( 'Bulk edit, import, or export the per-product commission settings.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-box',
            'url'   => admin_url( 'admin.php?page=wcusage-bulk-edit-product' ),
            'pro'   => true,
        ),
        array(
            /* translators: %s: affiliate label. */
            'title' => sprintf( __( 'Bulk Assign: Per-%s Product Rates', 'woo-coupon-usage' ), $affiliate ),
            /* translators: %s: affiliate label. */
            'desc'  => sprintf( __( 'Bulk assign per-product commission rates, on a per-%s basis.', 'woo-coupon-usage' ), $affiliate_lower ),
            'icon'  => 'fa-solid fa-percent',
            'url'   => admin_url( 'admin.php?page=wcusage-bulk-product-rates' ),
            'pro'   => true,
        ),
    );

    // Data & Reporting.
    $data = array(
        array(
            'title' => __( 'Admin Reports', 'woo-coupon-usage' ),
            'desc'  => __( 'Generate some advanced detailed reports and analytics for your affiliate program.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-chart-column',
            'url'   => admin_url( 'admin.php?page=wcusage_admin_reports' ),
        ),
    );

    // The Activity Log screen is only registered while the log is switched on,
    // so linking to it otherwise would land on a permissions error.
    if ( wcusage_get_setting_value( 'wcusage_enable_activity_log', '1' ) ) {
        $data[] = array(
            'title' => __( 'Activity Log', 'woo-coupon-usage' ),
            'desc'  => __( 'View a log of all the activity that has taken place in your affiliate program.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-clock-rotate-left',
            'url'   => admin_url( 'admin.php?page=wcusage_activity' ),
        );
    }

    $data[] = array(
        'title' => __( 'Import / Export Custom Tables', 'woo-coupon-usage' ),
        'desc'  => __( 'Import and Export the custom database tables created by this plugin.', 'woo-coupon-usage' ),
        'icon'  => 'fa-solid fa-database',
        'url'   => admin_url( 'admin.php?page=wcusage-data-import-export' ),
    );

    // Lives on the settings screen, which takes "manage_options" rather than
    // the tools capability - so it is only offered to users who can open it.
    if ( current_user_can( 'manage_options' ) ) {
        $data[] = array(
            'title' => __( 'Refresh All Data', 'woo-coupon-usage' ),
            'desc'  => __( 'Force a re-calculation of every saved dashboard statistic, after changing your commission settings.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-rotate',
            'url'   => admin_url( 'admin.php?page=wcusage_settings&section=tab-debug' ),
        );
    }

    // A one-off recovery tool, listed only while there are registration answers
    // left to copy onto affiliate profiles - so it takes itself off the page
    // once it has been run.
    if ( function_exists( 'wcusage_custom_fields_backfill_needed' ) && wcusage_custom_fields_backfill_needed() ) {
        $data[] = array(
            'title' => __( 'Restore Registration Field Answers', 'woo-coupon-usage' ),
            'desc'  => __( 'Copy custom registration field answers from existing applications onto the matching affiliate profiles.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-arrow-rotate-left',
            'url'   => admin_url( 'admin.php?page=wcusage-restore-registration-fields' ),
        );
    }

    // Page Generators.
    $generators = array(
        array(
            'title' => __( 'Signup Promo Page Generator', 'woo-coupon-usage' ),
            'desc'  => __( 'Generate a signup promo page that includes your program details and the registration form.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-bullhorn',
            'url'   => admin_url( 'admin.php?page=signup-page-generator' ),
        ),
        array(
            'title' => __( 'Terms & Conditions Generator', 'woo-coupon-usage' ),
            'desc'  => __( 'Generate and edit the Terms & Conditions page for your affiliate program.', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-file-contract',
            'url'   => admin_url( 'admin.php?page=wcusage-terms-generator' ),
            'pro'   => true,
        ),
    );

    $sections = array(
        array(
            'title' => __( 'Setup & Integrations', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-sliders',
            'tools' => $setup,
        ),
        array(
            'title' => __( 'Bulk Editing', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-layer-group',
            'tools' => $bulk,
        ),
        array(
            'title' => __( 'Data & Reporting', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-chart-pie',
            'tools' => $data,
        ),
        array(
            'title' => __( 'Page Generators', 'woo-coupon-usage' ),
            'icon'  => 'fa-solid fa-file-lines',
            'tools' => $generators,
        ),
    );

    // A PRO tool that this install cannot open is still listed, so the feature
    // is discoverable - but as a locked card rather than a dead link.
    foreach ( $sections as $i => $section ) {
        foreach ( $section['tools'] as $j => $tool ) {
            $sections[ $i ]['tools'][ $j ]['locked'] = ! empty( $tool['pro'] ) && ! $premium;
        }
    }

    return $sections;
}

function wcusage_tools_page() {
?>

    <div class="wrap admin-tools" style="margin: 0;">

        <?php wcusage_enqueue_font_awesome(); ?>

        <div class="wrap wcusage-admin-page wcusage-tools-page">

            <?php do_action( 'wcusage_hook_dashboard_page_header', ''); ?>

            <div class="wcu-page-header">
                <h1 class="wcusage-admin-title"><?php esc_html_e('Admin Tools', 'woo-coupon-usage'); ?></h1>
                <p class="wcu-page-subtitle"><?php esc_html_e('Access tools for bulk editing, importing, exporting, and managing your affiliate program.', 'woo-coupon-usage'); ?></p>
            </div>

            <?php foreach ( wcusage_tools_page_sections() as $section ) : ?>

                <?php if ( empty( $section['tools'] ) ) { continue; } ?>

                <div class="wcusage-tools-section">

                    <h2 class="wcu-section-title"><i class="<?php echo esc_attr( $section['icon'] ); ?>"></i> <?php echo esc_html( $section['title'] ); ?></h2>

                    <div class="wcusage-tools-container">

                        <?php foreach ( $section['tools'] as $tool ) : ?>

                            <?php // A locked card has no href, which also keeps it out of the tab order. ?>
                            <a class="wcusage-tools-box<?php echo $tool['locked'] ? ' is-locked' : ''; ?>" <?php if ( $tool['locked'] ) : ?>aria-disabled="true"<?php else : ?>href="<?php echo esc_url( $tool['url'] ); ?>"<?php endif; ?>>
                                <span class="wcusage-tools-box-icon">
                                    <i class="<?php echo esc_attr( $tool['locked'] ? 'fa-solid fa-lock' : $tool['icon'] ); ?>"></i>
                                </span>

                                <span class="wcusage-tools-box-content">
                                    <h3><?php echo esc_html( $tool['title'] ); ?><?php if ( $tool['locked'] ) : ?><span class="wcusage-tools-badge"><?php esc_html_e( 'PRO', 'woo-coupon-usage' ); ?></span><?php endif; ?></h3>
                                    <p><?php echo esc_html( $tool['desc'] ); ?></p>
                                </span>

                                <span class="wcusage-tools-box-arrow"><i class="fa-solid fa-arrow-right"></i></span>

                            </a>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>
<?php
}
