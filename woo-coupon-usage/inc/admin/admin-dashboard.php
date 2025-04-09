<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays header section on dashboard pages.
 */
add_action( 'wcusage_hook_dashboard_page_header', 'wcusage_dashboard_page_header' );
function wcusage_dashboard_page_header() {
    $rss_items = wcusage_changelog_fetch_rss_feed('https://couponaffiliates.com/category/updates/feed/');
    $feed_html = wcusage_changelog_generate_feed_html($rss_items);

    echo '<div id="changelog-modal" style="display: none; position: fixed; z-index: 1; left: 0; top: 0;width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div style="border-radius: 20px; background-color: #fefefe; margin: 15% auto; padding: 5px 20px; border: 1px solid #888; box-shadow: 0px 0px 10px #333; width: 500px; max-width: 100%;">
            <span id="close-changelog-modal" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">×</span>
            '.wp_kses_post($feed_html).'<br/><br/>
        </div>
    </div>';

    // Get affiliate orders and clicks for last 2 months
    $wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');
    $statuses = !$wcusage_field_order_type_custom ? array_diff_key(wc_get_order_statuses(), ['wc-refunded' => '']) : $wcusage_field_order_type_custom;

    $orders = wc_get_orders(array(
        'limit' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
        'post_status' => array_keys($statuses),
        'meta_key' => 'wcusage_affiliate_user',
        'meta_compare' => 'EXISTS',
        'date_query' => array(
            array(
                'after' => '2 months ago',
                'inclusive' => true,
            ),
        ),
    ));

    $orders_data = array();
    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $calculateorder = wcusage_calculate_order_data($order_id, '', 0, 1);
        $orders_data[] = array(
            'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'total' => $calculateorder['totalordersexcl'],
            'discounts' => $calculateorder['totaldiscounts'],
            'commission' => $calculateorder['totalcommission'],
            'subtotal' => $calculateorder['totalorders']
        );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_clicks';
    $two_months_ago = gmdate("Y-m-d", strtotime('-2 months'));
    $clicks = $wpdb->get_results($wpdb->prepare(
        "SELECT date FROM $table_name WHERE date >= %s ORDER BY date DESC",
        $two_months_ago
    ));
    $clicks_data = array_map(function($click) {
        return $click->date;
    }, $clicks);
?>

<script>
jQuery(document).ready(function($) {
    setTimeout(function() {
        $('.updated.success, .notice.is-dismissible, .notice.notice-warning').insertBefore('.wcusage-admin-page-col3');
    }, 100);

    // Store all data in JS
    var allOrders = <?php echo json_encode($orders_data); ?>;
    var allClicks = <?php echo json_encode($clicks_data); ?>;
    var currencySymbol = '<?php echo esc_js(get_woocommerce_currency_symbol()); ?>';

    function formatPrice(value) {
        return currencySymbol + Number(value).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    function calculateStats(range) {
        var now = new Date();
        var filteredOrders = allOrders;
        var filteredClicks = allClicks;

        switch(range) {
            case 'last7days':
                var last7days = new Date(now.setDate(now.getDate() - 7));
                filteredOrders = allOrders.filter(o => new Date(o.date) >= last7days);
                filteredClicks = allClicks.filter(c => new Date(c) >= last7days);
                break;
            case 'thismonth':
                var thisMonthStart = new Date(now.getFullYear(), now.getMonth(), 1);
                filteredOrders = allOrders.filter(o => new Date(o.date) >= thisMonthStart);
                filteredClicks = allClicks.filter(c => new Date(c) >= thisMonthStart);
                break;
            case 'lastmonth':
                var lastMonthStart = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                var lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0);
                filteredOrders = allOrders.filter(o => {
                    var d = new Date(o.date);
                    return d >= lastMonthStart && d <= lastMonthEnd;
                });
                filteredClicks = allClicks.filter(c => {
                    var d = new Date(c);
                    return d >= lastMonthStart && d <= lastMonthEnd;
                });
                break;
        }

        var stats = filteredOrders.reduce(function(acc, order) {
            acc.referrals++;
            acc.sales += parseFloat(order.total);
            acc.discounts += parseFloat(order.discounts);
            acc.commission += parseFloat(order.commission);
            return acc;
        }, {referrals: 0, sales: 0, discounts: 0, commission: 0});

        stats.clicks = filteredClicks.length;

        $('.total-usage').text(stats.referrals);
        $('.total-sales').html(formatPrice(stats.sales));
        $('.total-discounts').html(formatPrice(stats.discounts));
        $('.total-commission').html(formatPrice(stats.commission));
        $('.total-clicks').text(stats.clicks);
    }

    // Initial load
    calculateStats('last7days');

    // Handle toggle clicks
    $('.stats-range-toggle a').on('click', function(e) {
        e.preventDefault();
        $('.stats-range-toggle a').removeClass('active');
        $(this).addClass('active');
        var range = $(this).data('range');
        calculateStats(range);
    });
});
</script>

<div class="wcusage-admin-page-col3">
    <?php if( wcu_fs()->is_free_plan() ) { ?>
    <a href="<?php echo esc_url(get_admin_url()); ?>admin.php?page=wcusage-pricing" target="_blank"><div class="wcusage-admin-dash-button"
      style="background: linear-gradient(-45deg,#1a9612,#0c5a07,#1a9612,#0c5a07); border-radius: 10px; padding: 10px;
      background-size: 250% 250% !important; color: #fff;">
      <span class="fa-solid fa-star"></span> Upgrade</div></a>
    <?php } ?>
    <a href="<?php echo esc_url(get_admin_url()); ?>admin.php?page=wcusage" title="View Dashboard"><img src="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL); ?>images/coupon-affiliates-logo.png" style="display: inline-block; width: 100%; max-width: 290px; text-align: left; margin: 12px 0 10px 0;"></a>
    <?php if( wcu_fs()->is_free_plan() ) { ?>
      <a href="https://wordpress.org/support/plugin/woo-coupon-usage/#new-topic-0" target="_blank"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-circle-question"></span> <?php echo esc_html__('Support Ticket', 'woo-coupon-usage'); ?></div></a>
    <?php } else { ?>
      <a href="<?php echo esc_url(get_admin_url()); ?>admin.php?page=wcusage-contact" target="_blank"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-star"></span> <?php echo esc_html__('Support Ticket', 'woo-coupon-usage'); ?></div></a>
    <?php } ?>
    <a href="https://couponaffiliates.com/docs?utm_campaign=plugin&utm_source=dashboard-header&utm_medium=button" target="_blank"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-book"></span> <?php echo esc_html__('Documentation', 'woo-coupon-usage'); ?></div></a>
    <a href="#" id="show-changelog"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-sync"></span> Updates <span class="changelog-new" style="display: none; background: green; padding: 2px; font-size: 10px; line-height: 10px; border-radius: 2px; color: #fff;">New</span></div></a>
    <a href="https://roadmap.couponaffiliates.com/roadmap" target="_blank"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-list"></span> <?php echo esc_html__('Roadmap', 'woo-coupon-usage'); ?></div></a>
    <a href="<?php echo esc_url(get_admin_url()); ?>admin.php?page=wcusage_settings"><div class="wcusage-admin-dash-button"><span class="fa-solid fa-cog"></span> <?php echo esc_html__('Settings', 'woo-coupon-usage'); ?></div></a>
</div>

<div style="clear: both;"></div>

<script type="text/javascript">
document.getElementById("show-changelog").onclick = function() {
    document.getElementById("changelog-modal").style.display = "block";
};

document.getElementById("close-changelog-modal").onclick = function() {
    document.getElementById("changelog-modal").style.display = "none";
};

window.onclick = function(event) {
    if (event.target == document.getElementById("changelog-modal")) {
        document.getElementById("changelog-modal").style.display = "none";
    }
};
</script>

<?php
}

function wcusage_custom_page_header() {
    $screen = get_current_screen();
    if ( $screen->post_type == 'wcu-statements' || $screen->post_type == 'wcu-creatives' || $screen->post_type == 'wcu-short-url'
    || isset($_GET['page']) && $_GET['page'] == 'wcusage-account' ) {
        echo do_action( 'wcusage_hook_dashboard_page_header', '');
        echo '<style type="text/css">
        #screen-meta-links { position: absolute; float: right; right: 0; top: 94px; }
        </style>';
    }
}
add_action( 'all_admin_notices', 'wcusage_custom_page_header' );

function wcusage_changelog_fetch_rss_feed($feed_url) {
    $feed = fetch_feed($feed_url);
    if (is_wp_error($feed)) {
        return array();
    }
    $max_items = $feed->get_item_quantity(4);
    $rss_items = $feed->get_items(0, $max_items);
    return $rss_items;
}

function wcusage_changelog_generate_feed_html($rss_items) {
    $output = '<div class="rss-feed-items">';
    $output = '<h2>Latest Major Updates</h2>';

    foreach ($rss_items as $item) {
        $title = esc_html($item->get_title());
        $title = str_replace('Coupon Affiliates –', '', $title);
        $date = $item->get_date('jS F Y');
        $the_date = date_create($date);
        $now = date_create();
        $diff = date_diff($the_date, $now);
        $days = $diff->format("%a");
        $new = ($days <= 7) ? ' <span style="background: green; padding: 2px; font-size: 10px; line-height: 10px; border-radius: 2px; color: #fff;">New</span>' : '';

        $output .= '<div class="rss-feed-item">';
        $output .= '<h4>'.$date.$new.'<br/><a href="' . esc_url($item->get_permalink()) . "?utm_campaign=plugin&utm_source=settings-changelog&utm_medium=textlink" . '">' . esc_html($title) . '</a></h4>';
        $output .= '</div>';
    }

    $output .= '<a href="https://roadmap.couponaffiliates.com/updates/" target="_blank" style="display: inline-block; background: #000; color: #fff; text-decoration: none; padding: 5px 10px; margin-bottom: 20px;">View Full Changelog</a>';
    return $output;
}

/**
 * Displays statistics section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_statistics', 'wcusage_dashboard_page_section_statistics' );
function wcusage_dashboard_page_section_statistics() {
    $date_ranges = array(
        'last7days' => 'Last 7 Days',
        'thismonth' => 'This Month',
        'lastmonth' => 'Last Month',
    );
?>

<style>
.wcusage-info-box-title { margin-top: 5px; margin-bottom: 0px !important; }
.stats-range-toggle { margin: 10px 0; }
.stats-range-toggle a { margin-right: 10px; text-decoration: none; color: #0073aa; cursor: pointer; }
.stats-range-toggle a.active { color: #000; font-weight: bold; }
</style>

<div>
    <div class="stats-range-toggle">
        <?php foreach ($date_ranges as $key => $label): ?>
            <a data-range="<?php echo esc_attr($key); ?>" class="<?php echo $key === 'last7days' ? 'active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="wcusage-info-box2 wcusage-info-box-usage">
        <p><span class="wcusage-info-box-title">Referrals:</span><span class="total-usage">0</span></p>
    </div>

    <div class="wcusage-info-box2 wcusage-info-box-sales">
        <p><span class="wcusage-info-box-title">Sales:</span><span class="total-sales">0</span></p>
    </div>

    <div class="wcusage-info-box2 wcusage-info-box-discounts">
        <p><span class="wcusage-info-box-title">Discounts:</span><span class="total-discounts">0</span></p>
    </div>

    <div class="wcusage-info-box2 wcusage-info-box-dollar">
        <p><span class="wcusage-info-box-title">Commission:</span><span class="total-commission">0</span></p>
    </div>

    <div class="wcusage-info-box2 wcusage-info-box-clicks">
        <p><span class="wcusage-info-box-title">Clicks:</span><span class="total-clicks">0</span></p>
    </div>
</div>

<?php
}

/**
 * Displays activity section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_activity', 'wcusage_dashboard_page_section_activity' );
function wcusage_dashboard_page_section_activity() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_activity';
    $sql = $wpdb->prepare("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", 5);
    $get_activity = $wpdb->get_results($sql);
?>

<div>
    <?php if(!empty($get_activity)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th>Date</th>
                <th>Event</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($get_activity as $result) {
                $event_id = $result->event_id;
                $the_date = $result->date;
                $date = date_i18n('F jS', strtotime($the_date));
                $time = gmdate('H:i', strtotime($the_date));
                $user_id = $result->user_id;
                $user = get_userdata($user_id);
                $event = $result->event;
                $info = $result->info;

                if($event == "referral") {
                    $user_id = get_post_meta($event_id, 'wcusage_affiliate_user', true);
                }
                
                $name = "";
                if (is_object($user)) {
                    if (isset($user->first_name) || isset($user->last_name)) {
                        $name = trim($user->first_name . ' ' . $user->last_name);
                    }
                    if (empty($name)) {
                        $name = $user->user_login;
                    }
                }

                $event_message = wcusage_activity_message($event, $event_id, $info);
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><?php echo esc_html($date); ?> (<?php echo esc_html($time); ?>)</td>
                <td><?php echo wp_kses_post($event_message); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="5"><a href="<?php echo admin_url('admin.php?page=wcusage_activity'); ?>" style="text-decoration: none;">View All Activity <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('No recent activity found.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays referrals section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_referrals', 'wcusage_dashboard_page_section_referrals' );
function wcusage_dashboard_page_section_referrals() {
    $wcusage_field_order_type_custom = wcusage_get_setting_value('wcusage_field_order_type_custom', '');
    $statuses = !$wcusage_field_order_type_custom ? array_diff_key(wc_get_order_statuses(), ['wc-refunded' => '']) : $wcusage_field_order_type_custom;

    $orders = wc_get_orders(array(
        'orderby' => 'date',
        'order' => 'DESC',
        'post_status' => array_keys($statuses),
        'meta_key' => 'wcusage_affiliate_user',
        'meta_compare' => 'EXISTS',
        'limit' => '5',
    ));
?>

<div>
    <?php if(!empty($orders)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th>Affiliate</th>
                <th>Date</th>
                <th>Order ID</th>
                <th>Total</th>
                <th>Commission</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($orders as $order) {
                $order_id = $order->get_id();
                $orderinfo = wc_get_order($order_id);
                $calculateorder = wcusage_calculate_order_data($order_id, '', 0, 1);
                $order_date = get_the_time('F jS', $order_id);
                $status = $orderinfo->get_status();
                $total = $calculateorder['totalordersexcl'];
                $commission = $calculateorder['totalcommission'];
                $user_id = wcusage_order_meta($order_id, 'wcusage_affiliate_user');
                $user = get_userdata($user_id);

                $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login;
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><a href="<?php echo esc_url(get_edit_user_link($user_id)); ?>" title="<?php echo esc_html($user->user_login); ?>" target="_blank"><?php echo esc_html($name); ?></a></td>
                <td><?php echo esc_html($order_date); ?></td>
                <td><a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>">#<?php echo esc_html($order_id); ?></a></td>
                <td><?php echo wp_kses_post(wcusage_format_price(number_format($total, 2, '.', ''))); ?></td>
                <td><?php echo wp_kses_post(wcusage_format_price(number_format($commission, 2, '.', ''))); ?></td>
                <td><?php echo ucfirst(esc_html($status)); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="7"><a href="<?php echo esc_url(admin_url("admin.php?page=wcusage_referrals")); ?>" style="text-decoration: none;">View All Referrals <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('No recent referral orders found.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays visits section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_visits', 'wcusage_dashboard_page_section_visits' );
function wcusage_dashboard_page_section_visits() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_clicks';
    $get_visits = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", 5));
?>

<div>
    <?php if(!empty($get_visits)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Referrer Domain', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Converted', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($get_visits as $result) {
                $date = date_i18n('F jS (H:i)', strtotime($result->date));
                $coupon = get_the_title($result->couponid);
                $referrer = $result->referrer;
                if(!$referrer) {
                    $referrer = '-';
                }
                $converted = $result->converted ? "yes" : "no";
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><?php echo esc_html($date); ?></td>
                <td><?php echo esc_html($coupon); ?></td>
                <td><?php echo esc_html($referrer); ?></td>
                <td><?php echo ucfirst(esc_html($converted)); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="5"><a href="<?php echo admin_url('admin.php?page=wcusage_clicks'); ?>" style="text-decoration: none;">View All Clicks <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('No recent clicks found.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays coupons section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_coupons', 'wcusage_dashboard_page_section_coupons' );
function wcusage_dashboard_page_section_coupons() {
    $args = array(
        'post_type' => 'shop_coupon',
        'posts_per_page' => 5,
        'meta_query' => array(
            array(
                'key' => 'wcu_select_coupon_user',
                'value' => '0',
                'compare' => '>'
            )
        )
    );
    $coupons = get_posts($args);
?>

<div>
    <?php if(!empty($coupons)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th><?php echo esc_html__('Affiliate', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Created', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($coupons as $coupon) {
                $coupon_id = $coupon->ID;
                $date = date_i18n('F jS (H:i)', strtotime($coupon->post_date));
                $user_id = get_post_meta($coupon_id, 'wcu_select_coupon_user', true);
                $user = get_userdata($user_id);
                $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login;
                $coupon_info = wcusage_get_coupon_info_by_id($coupon_id);
                $uniqueurl = $coupon_info[4];
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><a href="<?php echo esc_url(get_edit_user_link($user_id)); ?>" title="<?php echo esc_html($name); ?>" target="_blank"><?php echo esc_html($name); ?></a></td>
                <td><a href="<?php echo esc_html($uniqueurl); ?>" title="View Dashboard" target="_blank"><?php echo esc_html(get_the_title($coupon_id)); ?></a></td>
                <td><?php echo esc_html($date); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="5"><a href="<?php echo esc_url(admin_url("admin.php?page=wcusage_coupons")) ?>" style="text-decoration: none;">View All Affiliate Coupons <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('No new affiliate coupons found.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays registrations section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_registrations', 'wcusage_dashboard_page_section_registrations' );
function wcusage_dashboard_page_section_registrations() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_register';
    $get_visits = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table_name}` WHERE status = %s ORDER BY id DESC LIMIT 5", 'pending'));
?>

<div>
    <?php if(!empty($get_visits)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th><?php echo esc_html__('Affiliate', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($get_visits as $result) {
                $user = get_userdata($result->userid);
                $date = date_i18n('F jS (H:i)', strtotime($result->date));
                $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login;
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><a href="<?php echo esc_url(get_edit_user_link($result->userid)); ?>" title="<?php echo esc_html($name); ?>" target="_blank"><?php echo esc_html($name); ?></a></td>
                <td><?php echo esc_html($date); ?></td>
                <td><?php echo esc_html($result->couponcode); ?></td>
                <td><?php echo ucfirst(esc_html($result->status)); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="5"><a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_registrations')); ?>" style="text-decoration: none;">View Registrations <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('you have no pending affiliate registrations.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays payouts section on dashboard page.
 */
add_action( 'wcusage_hook_dashboard_page_section_payouts', 'wcusage_dashboard_page_section_payouts' );
function wcusage_dashboard_page_section_payouts() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wcusage_payouts';
    $query = $wpdb->prepare("SELECT * FROM {$table_name} WHERE status = %s ORDER BY id DESC LIMIT 5", 'pending');
    $get_visits = $wpdb->get_results($query);
?>

<div>
    <?php if(!empty($get_visits)) { ?>
    <table style="border: 2px solid #f3f3f3; width: 100%; text-align: center; border-collapse: collapse;">
        <thead>
            <tr class="wcusage-admin-table-col-head">
                <th><?php echo esc_html__('Affiliate', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Date', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Coupon', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Amount', 'woo-coupon-usage'); ?></th>
                <th><?php echo esc_html__('Status', 'woo-coupon-usage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($get_visits as $result) {
                $user = get_userdata($result->userid);
                $date = date_i18n('F jS (H:i)', strtotime($result->date));
                $coupon = get_the_title($result->couponid) ?: "(MLA)";
                $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login;
            ?>
            <tr class="wcusage-admin-table-col-row">
                <td><a href="<?php echo get_edit_user_link($result->userid); ?>" title="<?php echo esc_html($user->user_login); ?>" target="_blank"><?php echo esc_html($name); ?></a></td>
                <td><?php echo esc_html($date); ?></td>
                <td><?php echo esc_html($coupon); ?></td>
                <td><?php echo wp_kses_post(wcusage_format_price(number_format($result->amount, 2, '.', ''))); ?></td>
                <td><?php echo ucfirst(esc_html($result->status)); ?></td>
            </tr>
            <?php } ?>
            <tr class="wcusage-admin-table-col-footer">
                <td colspan="5"><a href="<?php echo admin_url('admin.php?page=wcusage_payouts'); ?>" style="text-decoration: none;">View Payouts <i class="fa-solid fa-arrow-right"></i></a></td>
            </tr>
        </tbody>
    </table>
    <?php } else { ?>
    <p><?php echo esc_html__('You have no pending payout requests.', 'woo-coupon-usage'); ?></p>
    <?php } ?>
</div>

<?php
}

/**
 * Displays dashboard page.
 */
function wcusage_dashboard_page_html() {
    if (!wcusage_check_admin_access()) {
        return;
    }
?>

<link rel="stylesheet" href="<?php echo esc_url(WCUSAGE_UNIQUE_PLUGIN_URL) .'fonts/font-awesome/css/all.min.css'; ?>" crossorigin="anonymous">

<?php echo do_action('wcusage_hook_dashboard_page_header', ''); ?>

<div class="wrap plugin-settings">

    <?php if (class_exists('WooCommerce')) { ?>
        <style>
        @media screen and (max-width: 1040px) { .wcusage-admin-page-col { width: calc(100% - 85px) !important; } }
        .wcusage-admin-page-col-section {
            padding: 10px 25px; margin: 0; list-style: none; display: -webkit-box; display: -moz-box; display: -ms-flexbox; display: -webkit-flex; display: flex; -webkit-flex-flow: row wrap; justify-content: space-around;
        }
        strong { color: green; font-size: 16px; }
        h2 { font-size: 22px; }
        .wcusage-admin-page-col3 { margin-left: 0px; width: calc(100% - 84px); }
        </style>

        <div class="wcusage-admin-page-col-section">
            <div class="wcusage-admin-page-col" style="width: calc(100% - 85px);">
                <h2><?php echo esc_html__('Affiliate Program Statistics', 'woo-coupon-usage'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wcusage_admin_reports')); ?>" style="text-decoration: none; float: right; margin-top: -5px; font-size: 14px;"
                class="button button-secondary button-large">
                    <?php echo esc_html__('View Full Report', 'woo-coupon-usage'); ?> <i class="fa-solid fa-arrow-right"></i>
                </a></h2>
                <?php echo do_action('wcusage_hook_dashboard_page_section_statistics', ''); ?>
            </div>

            <?php if(wcusage_get_setting_value('wcusage_enable_activity_log', '1')) { ?>
                <div class="wcusage-admin-page-col">
                    <h2><?php echo esc_html__('Recent Activity', 'woo-coupon-usage'); ?></h2>
                    <?php echo do_action('wcusage_hook_dashboard_page_section_activity', ''); ?>
                </div>
            <?php } ?>

            <div class="wcusage-admin-page-col">
                <h2><?php echo esc_html__('Latest Referrals', 'woo-coupon-usage'); ?></h2>
                <?php echo do_action('wcusage_hook_dashboard_page_section_referrals', ''); ?>
            </div>

            <?php if(wcusage_get_setting_value('wcusage_field_show_click_history', 1)) { ?>
                <div class="wcusage-admin-page-col">
                    <h2><?php echo esc_html__('Latest Referral Visits', 'woo-coupon-usage'); ?></h2>
                    <?php echo do_action('wcusage_hook_dashboard_page_section_visits', ''); ?>
                </div>
            <?php } ?>

            <div class="wcusage-admin-page-col">
                <h2><?php echo esc_html__('Newest Affiliate Coupons', 'woo-coupon-usage'); ?></h2>
                <?php echo do_action('wcusage_hook_dashboard_page_section_coupons', ''); ?>
            </div>

            <?php if(wcusage_get_setting_value('wcusage_field_registration_enable', '1')) { ?>
                <div class="wcusage-admin-page-col">
                    <h2><?php echo esc_html__('Pending Affiliate Registrations', 'woo-coupon-usage'); ?></h2>
                    <?php echo do_action('wcusage_hook_dashboard_page_section_registrations', ''); ?>
                </div>
            <?php } ?>

            <?php if(wcu_fs()->can_use_premium_code() && wcusage_get_setting_value('wcusage_field_tracking_enable', 1)) { ?>
                <div class="wcusage-admin-page-col">
                    <h2><?php echo esc_html__('Pending Payout Requests', 'woo-coupon-usage'); ?></h2>
                    <?php echo do_action('wcusage_hook_dashboard_page_section_payouts', ''); ?>
                </div>
            <?php } ?>
        </div>
    <?php } else {
        $path = 'woocommerce/woocommerce.php';
        $installed_plugins = get_plugins();
        if(isset($installed_plugins[$path])) {
            $activate_url = wp_nonce_url('plugins.php?action=activate&plugin=' . $path, 'activate-plugin_' . $path);
            echo '<p style="font-size: 15px; color: red;"><strong><span class="dashicons dashicons-bell"></span> WooCommerce is installed but not activated. <a href="' . esc_url($activate_url) . '">Click here to activate it.</a></strong></p>';
        } else {
            $install_url = self_admin_url('plugin-install.php?tab=plugin-information&plugin=woocommerce');
            echo '<p style="margin-left: 20px; font-size: 15px; color: red;"><strong><span class="dashicons dashicons-bell"></span> WooCommerce needs to be installed for this plugin to work. <a href="' . esc_url($install_url) . '">Click here to install it.</a></strong></p>';
        }
    } ?>
</div>

<?php
}