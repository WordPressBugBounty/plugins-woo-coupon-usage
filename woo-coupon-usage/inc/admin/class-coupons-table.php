<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class wcusage_Coupons_Table
 */
class wcusage_Coupons_Table extends WP_List_Table {

    /**
     * @var array
     */
    public $coupons = array();

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'Affiliate Coupon',
            'plural'   => 'Affiliate Coupons',
            'ajax'     => false,
        ) );
    }

    /**
     * Get table columns
     *
     * @return array
     */
    public function get_columns() {
        $columns = array(
            'cb'                => '<input type="checkbox" />',
            'ID'                => esc_html__( 'ID', 'woo-coupon-usage' ),
            'post_title'        => esc_html__( 'Coupon Code', 'woo-coupon-usage' ),
            'coupon_type'       => esc_html__( 'Coupon Type', 'woo-coupon-usage' ),
            'usage'             => esc_html__( 'Total Usage', 'woo-coupon-usage' ),
        );

        $all_stats = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
        if ( $all_stats ) {
            $columns['sales']      = esc_html__( 'Total Sales', 'woo-coupon-usage' );
            $columns['commission'] = esc_html__( 'Total Commission', 'woo-coupon-usage' );
        }

        if ( wcu_fs()->can_use_premium_code() ) {
            $columns['unpaidcommission'] = 'Commission Payouts' . wcusage_admin_tooltip(esc_html__('• Unpaid: Earned from completed orders but not yet paid.', 'woo-coupon-usage') . '<br/>' . esc_html__('• Pending: Payout requests currently awaiting approval.', 'woo-coupon-usage') . '<br/>' . esc_html__('• Paid: Successfully paid to affiliate.', 'woo-coupon-usage'));
        }

        $columns['affiliate']     = esc_html__( 'Affiliate User', 'woo-coupon-usage' );
        $columns['dashboard_link'] = esc_html__( 'Dashboard Link', 'woo-coupon-usage' ) . wcusage_admin_tooltip( esc_html__( 'This link will take you to the affiliate dashboard for this specific coupon.', 'woo-coupon-usage' ) );

        $wcusage_field_urls_enable = wcusage_get_setting_value( 'wcusage_field_urls_enable', 1 );
        if ( $wcusage_field_urls_enable ) {
            $columns['referral_link'] = esc_html__( 'Referral Link', 'woo-coupon-usage' ) . wcusage_admin_tooltip( esc_html__( 'This is the default referral link your affiliates can share.', 'woo-coupon-usage' ) );
        }

        $columns['the-actions'] = esc_html__( 'Actions', 'woo-coupon-usage' );

        return $columns;
    }

    /**
     * Checkbox column for bulk actions
     */
    public function column_cb( $item ) {
        if ( ! is_object( $item ) || ! property_exists( $item, 'ID' ) ) {
            return '';
        }
        return sprintf(
            '<input type="checkbox" name="bulk-coupons[]" value="%s" />',
            esc_attr( $item->ID )
        );
    }

    /**
     * Bulk actions available on the coupons table
     */
    public function get_bulk_actions() {
        return array(
            'bulk-unassign'                 => esc_html__( 'Unassign Affiliates From Coupons', 'woo-coupon-usage' ),
            'bulk-delete-coupons'           => esc_html__( 'Delete Coupons', 'woo-coupon-usage' ),
            'bulk-delete-coupons-and-user'  => esc_html__( 'Delete Coupons & Assigned Affiliate User', 'woo-coupon-usage' ),
        );
    }

    /**
     * Handle bulk actions
     */
    public function process_bulk_action() {
        // Nonce check
        if ( empty( $_POST['_wcusage_bulk_nonce'] ) ) {
            return;
        }
        $nonce_value = sanitize_text_field( wp_unslash( $_POST['_wcusage_bulk_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce_value, 'wcusage_coupons_bulk_action' ) ) {
            return;
        }

        // Permission check
        if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
            return;
        }

        $action = $this->current_action();
        if ( ! $action ) {
            return;
        }

        $ids = isset( $_POST['bulk-coupons'] ) ? array_map( 'absint', (array) $_POST['bulk-coupons'] ) : array();
        if ( empty( $ids ) ) {
            return;
        }

        if ( 'bulk-unassign' === $action ) {
            foreach ( $ids as $coupon_id ) {
                update_post_meta( $coupon_id, 'wcu_select_coupon_user', '' );
            }
        }

        if ( 'bulk-delete-coupons' === $action ) {
            foreach ( $ids as $coupon_id ) {
                wp_delete_post( $coupon_id );
            }
        }

        if ( 'bulk-delete-coupons-and-user' === $action ) {
            $user_ids = array();
            foreach ( $ids as $coupon_id ) {
                $user_id = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
                if ( is_numeric( $user_id ) && $user_id ) {
                    $user_ids[] = (int) $user_id;
                }
            }
            $user_ids = array_unique( $user_ids );
            foreach ( $user_ids as $uid ) {
                // Delete all coupons belonging to this user
                if ( function_exists( 'wcusage_get_users_coupons_ids' ) ) {
                    $coupons_of_user = (array) wcusage_get_users_coupons_ids( $uid );
                    foreach ( $coupons_of_user as $c_id ) {
                        wp_delete_post( $c_id );
                    }
                }
                // Then delete the user
                if ( $uid && $uid !== get_current_user_id() ) {
                    wp_delete_user( $uid );
                }
            }
        }
    }

    /**
     * Prepare table items
     */
    public function prepare_items() {
        $columns = $this->get_columns();
        $this->_column_headers = array( $columns, array(), array() );

        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : false;
        $affiliate_only = isset( $_GET['affiliate_only'] ) && 'true' === $_GET['affiliate_only'];

        if ( $affiliate_only ) {
            $this->coupons = $this->get_affiliate_coupons( $search );
        } else {
            $this->coupons = $this->get_all_coupons( $search );
        }

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = $this->coupons ? count( $this->coupons ) : 0;

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );

        if ( $total_items > 0 ) {
            $this->items = array_slice( $this->coupons, ( ( $current_page - 1 ) * $per_page ), $per_page );
        }
    }

    /**
     * Default column renderer
     *
     * @param object $item
     * @param string $column_name
     * @return string
     */
    public function column_default( $item, $column_name ) {
        if ( ! is_object( $item ) || ! property_exists( $item, 'ID' ) ) {
            return '';
        }

        $coupon = $item->ID;
        if ( ! $coupon ) {
            return '';
        }

        $coupon_code = $item->post_title;
        if ( ! $coupon_code || empty( $coupon_code ) ) {
            return '';
        }

        $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
        if ( ! $coupon_id ) {
            return '';
        }

        $disable_commission = wcusage_coupon_disable_commission( $coupon );
        $c = new WC_Coupon( $coupon_code );
        if ( ! $c ) {
            return '';
        }

        $qmessage = esc_html__( 'The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage' );
        $coupon_info = wcusage_get_coupon_info_by_id( $item->ID );
        $coupon_user_id = $coupon_info[1];
        $user_info = get_userdata( $coupon_user_id );
        $wcusage_urls_prefix = wcusage_get_setting_value( 'wcusage_field_urls_prefix', 'coupon' );
        $wcu_alltime_stats = get_post_meta( $coupon, 'wcu_alltime_stats', true );

        $usage = $wcu_alltime_stats && isset( $wcu_alltime_stats['total_count'] ) ? $wcu_alltime_stats['total_count'] : $c->get_usage_count();

        switch ( $column_name ) {
            case 'ID':
                return '<a href="' . esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ) . '"><span class="dashicons dashicons-edit" style="font-size: 15px; margin-top: 4px;"></span> ' . esc_html( $item->ID ) . '</a>';
            case 'post_title':
                return '<a href="' . esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ) . '">' . esc_html( $coupon_code ) . '</a>';
            case 'coupon_type':
                $coupon_type = get_post_meta( $item->ID, 'discount_type', true ) ?: $c->get_discount_type();
                $coupon_amount = get_post_meta( $item->ID, 'coupon_amount', true ) ?: $c->get_amount();
                $types = array(
                    'percent'        => esc_html__( 'Percentage Discount', 'woo-coupon-usage' ),
                    'fixed_cart'     => esc_html__( 'Fixed Cart Discount', 'woo-coupon-usage' ),
                    'fixed_product'  => esc_html__( 'Fixed Product Discount', 'woo-coupon-usage' ),
                    'percent_product' => esc_html__( 'Percentage Product Discount', 'woo-coupon-usage' ),
                );
                $display = isset( $types[ $coupon_type ] ) ? $types[ $coupon_type ] : $coupon_type;
                return $coupon_amount ? "$display (" . ( 'percent' === $coupon_type ? "$coupon_amount%" : wc_price( $coupon_amount ) ) . ")" : $display;
            case 'usage':
                return $usage;
            case 'sales':
                $all_stats = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
                if ( ! $all_stats || ! $wcu_alltime_stats ) {
                    return '';
                }
                $sales = isset( $wcu_alltime_stats['total_orders'] ) ? $wcu_alltime_stats['total_orders'] : 0;
                if ( isset( $wcu_alltime_stats['total_discount'] ) ) {
                    $sales = (float) $sales - (float) $wcu_alltime_stats['total_discount'];
                }
                return $usage > 0 && ! $sales ? "<span title='" . esc_html( $qmessage ) . "'><strong><i class='fa-solid fa-ellipsis'></i></strong></span>" : wcusage_format_price( $sales );
            case 'commission':
                if ( $disable_commission && wcusage_get_setting_value( 'wcusage_field_commission_disable_non_affiliate', '0' ) ) {
                    return '-';
                }
                $commission = $wcu_alltime_stats && isset( $wcu_alltime_stats['total_commission'] ) ? $wcu_alltime_stats['total_commission'] : 0;
                return $usage > 0 && ! $commission ? "<span title='" . esc_html( $qmessage ) . "'><strong><i class='fa-solid fa-ellipsis'></i></strong></span>" : wcusage_format_price( $commission );
            case 'unpaidcommission':
                if ( $disable_commission ) {
                    return '-';
                }
                global $wpdb;
                $payouts_table = $wpdb->prefix . 'wcusage_payouts';
                
                // Get the affiliate user ID for this coupon
                $coupon_user_id = wcusage_get_coupon_info_by_id( $item->ID )[1];
                
                // Calculate commission breakdown for this individual coupon
                $unpaid_commission = (float) get_post_meta( $item->ID, 'wcu_text_unpaid_commission', true );
                $total_commission = $wcu_alltime_stats && isset( $wcu_alltime_stats['total_commission'] ) ? (float) $wcu_alltime_stats['total_commission'] : 0;
                $paid_commission = $total_commission - $unpaid_commission;
                if ( $paid_commission < 0 ) $paid_commission = 0;
                
                // Calculate actual pending payments for this coupon's affiliate
                $pending_payments = 0;
                if ($coupon_user_id && $wpdb->get_var("SHOW TABLES LIKE '$payouts_table'") == $payouts_table) {
                    $pending_payouts = $wpdb->get_results($wpdb->prepare(
                        "SELECT amount FROM $payouts_table WHERE userid = %d AND status IN ('pending', 'created')",
                        $coupon_user_id
                    ));
                    foreach ($pending_payouts as $payout) {
                        $pending_payments += (float)$payout->amount;
                    }
                }
                
                $output = '<div style="line-height: 1.4;">';
                $output .= '<div><strong>Unpaid:</strong> ' . wcusage_format_price( $unpaid_commission ) . '</div>';
                $output .= '<hr style="margin: 2px 0; border: 0; border-top: 1px solid #ddd;">';
                $output .= '<div><strong>Pending Payments:</strong> ' . wcusage_format_price( $pending_payments ) . '</div>';
                $output .= '<hr style="margin: 2px 0; border: 0; border-top: 1px solid #ddd;">';
                $output .= '<div><strong>Paid:</strong> ' . wcusage_format_price( $paid_commission ) . '</div>';
                $output .= '</div>';
                return $output;
            case 'affiliate':
                return $user_info ? '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . $coupon_user_id ) ) . '" target="_blank">' . esc_html( $user_info->user_login ) . '</a>' : '-';
            case 'dashboard_link':
                return '<a href="' . esc_url( $coupon_info[4] ) . '" target="_blank">' . esc_html__( 'View Dashboard', 'woo-coupon-usage' ) . ' <span class="dashicons dashicons-external"></span></a>';
            case 'referral_link':
                $link = get_home_url() . '?' . $wcusage_urls_prefix . '=' . esc_html( $coupon_code );
                return '<div class="wcusage-copyable-link">
                    <input type="text" id="wcusageLink' . esc_attr( $coupon_code ) . '" class="wcusage-copy-link-text" value="' . esc_url( $link ) . '" style="max-width: 100px;width: 75%;max-height: 24px;min-height: 24px;font-size: 10px;" readonly>
                    <button type="button" class="wcusage-copy-link-button" style="max-height: 20px;min-height: 20px;background: none;border: none;"><i class="fa-regular fa-copy" style="cursor: pointer;"></i></button>
                </div>';
            case 'the-actions':
                $actions = array(
                    'quick-edit' => sprintf( '<a href="#" class="button button-primary quick-edit-coupon" data-coupon-id="%s">%s</a>', $item->ID, esc_html__( 'Quick Edit', 'woo-coupon-usage' ) ),
                    'edit'      => sprintf( '<a href="%s" class="button button-secondary">%s</a>', esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ), esc_html__( 'Edit', 'woo-coupon-usage' ) ),
                    'delete'    => sprintf( '<a href="%s" onclick="return confirm(\'%s\');" style="color: #7a0707; margin-top: 5px;">%s</a>',
                        esc_url( wp_nonce_url( admin_url( 'admin.php?page=wcusage_coupons&delete_coupon=' . $item->ID ), 'delete_coupon' ) ),
                        esc_html__( 'Are you sure you want to delete this coupon?', 'woo-coupon-usage' ),
                        esc_html__( 'Delete', 'woo-coupon-usage' )
                    ),
                );
                foreach ( $actions as $key => $action ) {
                    $actions[ $key ] = '<span class="' . esc_attr( $key ) . '">' . $action . '</span>';
                }
                return implode( ' ', $actions );
            default:
                return isset( $item->$column_name ) ? $item->$column_name : '';
        }
    }

    /**
     * Display table rows
     */ 
    public function display_rows() {
        foreach ( $this->items as $item ) {
            $coupon_id = $item->ID;
            try {
                $coupon = new WC_Coupon( $item->post_title );
            } catch ( Exception $e ) {
                continue;
            }
            $users = get_users( array( 'fields' => array( 'ID', 'user_login' ), 'orderby' => 'login' ) );
            $currency_symbol = get_woocommerce_currency_symbol();
            $coupon_user_id = wcusage_get_coupon_info_by_id( $item->ID )[1];
            $user_info = get_userdata( $coupon_user_id );

            echo '<tr id="coupon-row-' . esc_attr( $coupon_id ) . '">';
            $this->single_row_columns( $item );
            echo '</tr>';
            // Shared quick edit row
            include_once WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/admin/partials/quick-edit-coupon.php';
            wcusage_render_quick_edit_row( $coupon_id, count( $this->get_columns() ) );
        }
    }
    
    /**
     * Get affiliate coupons
     *
     * @param string $search
     * @return array
     */
    public function get_affiliate_coupons( $search = '' ) {
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => -1,
            's'              => $search,
            'meta_query'     => array(
                array(
                    'key'     => 'wcu_select_coupon_user',
                    'value'   => array( '' ),
                    'compare' => 'NOT IN',
                ),
            ),
        );

        $coupons = get_posts( $args );
        $valid_coupons = array();
        foreach ( $coupons as $coupon ) {
            $coupon_user_id = get_post_meta( $coupon->ID, 'wcu_select_coupon_user', true );
            if ( $coupon_user_id && get_userdata( $coupon_user_id ) ) {
                $valid_coupons[] = $coupon;
            }
        }
        return $valid_coupons;
    }

    /**
     * Get all coupons
     *
     * @param string $search
     * @return array
     */
    public function get_all_coupons( $search = '' ) {
        $args = array(
            'post_type'      => 'shop_coupon',
            's'              => $search,
            'posts_per_page' => -1,
        );
        $coupons_query = new WP_Query( $args );
        return $coupons_query->posts;
    }
}


/**
 * Coupons page handler
 */
function wcusage_coupons_page() {
    if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'admin_add_registration_form' ) && wcusage_check_admin_access() ) {
        echo wp_kses_post( wcusage_post_submit_application( 1 ) );
    }

    if ( isset( $_GET['delete_coupon'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_coupon' ) && wcusage_check_admin_access() ) {
        $coupon_id = sanitize_text_field( wp_unslash( $_GET['delete_coupon'] ) );
        $coupon = get_post( $coupon_id );
        if ( $coupon ) {
            $coupon_name = $coupon->post_title;
            wp_delete_post( $coupon_id );
            echo '<p class="notice notice-success is-dismissible" style="padding: 10px; margin: 10px 0;">' . esc_html__( 'Coupon "' . $coupon_name . '" deleted successfully.', 'woo-coupon-usage' ) . '</p>';
        }
    }

    // Enqueue styles
    wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
    wp_enqueue_style( 'wcusage-font-awesome', WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css', array(), '5.15.4' );
    wp_enqueue_style( 'wcusage-coupons', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-coupons.css', array(), '1.0.0' );

    // Enqueue scripts
    wp_enqueue_script( 'jquery-ui-autocomplete' );
    wp_enqueue_script( 'wcusage-coupons', WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-coupons.js', array( 'jquery' ), '1.0.0', true );
    
    wp_localize_script( 'wcusage-coupons', 'wcusage_coupons_vars', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wcusage_coupon_nonce' ),
        'types'    => array(
            'percent'        => esc_html__( 'Percentage Discount', 'woo-coupon-usage' ),
            'fixed_cart'     => esc_html__( 'Fixed Cart Discount', 'woo-coupon-usage' ),
            'fixed_product'  => esc_html__( 'Fixed Product Discount', 'woo-coupon-usage' ),
            'percent_product' => esc_html__( 'Percentage Product Discount', 'woo-coupon-usage' ),
        ),
    'currency_symbol' => get_woocommerce_currency_symbol(),
    // Base URL with 'USER_ID_PLACEHOLDER' as placeholder for dynamic user id
    'edit_user_url'   => esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=USER_ID_PLACEHOLDER' ) ),
    ) );

    $table = new wcusage_Coupons_Table();
    // Process any submitted bulk actions before preparing items
    $table->process_bulk_action();
    $affiliate_only = isset( $_GET['affiliate_only'] ) && 'true' === $_GET['affiliate_only'];
    $page_url = admin_url( 'admin.php?page=wcusage-coupons' );
    ?>
    <div class="wrap wcusage-admin-page">
        <?php do_action( 'wcusage_hook_dashboard_page_header', '' ); ?>
        <h1 class="wp-heading-inline wcusage-admin-title wcusage-admin-title-coupons">
            <?php esc_html_e( 'Coupons', 'woo-coupon-usage' ); ?>
            <span class="wcusage-admin-title-buttons">
                <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=shop_coupon' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Add Coupon', 'woo-coupon-usage' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_add_affiliate' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Add Affiliate Coupon', 'woo-coupon-usage' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage-bulk-coupon-creator' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Bulk Create Coupons', 'woo-coupon-usage' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage-bulk-edit-coupon' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Bulk Edit Coupons', 'woo-coupon-usage' ); ?></a>
            </span>
            <br/>
            <span class="wcusage-admin-title-filters" style="margin-bottom: 10px;">
                <form method="get" style="display: inline;">
                    <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
                    <input type="checkbox" name="affiliate_only" value="true" <?php checked( $affiliate_only ); ?> onchange="this.form.submit();">
                    <?php esc_html_e( 'Show Affiliate Coupons Only', 'woo-coupon-usage' ); ?>
                </form>
            </span>
        </h1>
        <form method="get" id="wcusage-coupons-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
            <input type="hidden" name="affiliate_only" value="<?php echo $affiliate_only ? 'true' : ''; ?>" />
            <?php
            $table->prepare_items();
            $table->search_box( 'Search Coupons', 'search_id' );
            ?>
        </form>
        <form method="post" id="wcusage-coupons-bulk-actions">
            <?php wp_nonce_field( 'wcusage_coupons_bulk_action', '_wcusage_bulk_nonce' ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
            <input type="hidden" name="affiliate_only" value="<?php echo $affiliate_only ? 'true' : ''; ?>" />
            <input type="hidden" name="s" value="<?php echo isset($_GET['s']) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; ?>" />
            <?php $table->display(); ?>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($) {
        function confirmFor(action) {
            switch(action) {
                case 'bulk-unassign':
                    return '<?php echo esc_js( __( 'Are you sure you want to unassign the selected affiliates from these coupons? This will remove the affiliate assignment but will NOT delete coupons or users.', 'woo-coupon-usage' ) ); ?>';
                case 'bulk-delete-coupons':
                    return '<?php echo esc_js( __( 'Are you sure you want to delete the selected coupons?', 'woo-coupon-usage' ) ); ?>';
                case 'bulk-delete-coupons-and-user':
                    return '<?php echo esc_js( __( 'Are you sure you want to delete the selected coupons AND their assigned affiliate users? This will also delete all coupons belonging to those users and permanently remove their user accounts.', 'woo-coupon-usage' ) ); ?>';
            }
            return '';
        }
        $('#doaction, #doaction2').on('click', function(e) {
            var $select = $(this).siblings('select');
            if (!$select.length) return;
            var action = $select.val();
            if (!action) return;
            var msg = confirmFor(action);
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                return false;
            }
        });
    });
    </script>
    <?php
}

/**
 * Save coupon data via AJAX
 */
function wcusage_save_coupon_data() {
    check_ajax_referer( 'wcusage_coupon_nonce', 'nonce' );
    
    $coupon_id = intval( $_POST['coupon_id'] );
    $coupon = new WC_Coupon( $coupon_id );
    
    // Update post data
    wp_update_post( array(
        'ID'         => $coupon_id,
        'post_title' => sanitize_text_field( wp_unslash( $_POST['post_title'] ) ),
        'post_name'  => sanitize_text_field( wp_unslash( $_POST['post_title'] ) ),
    ) );
    
    // Get user ID from username
    $username = sanitize_text_field( wp_unslash( $_POST['wcu_select_coupon_user'] ) );
    $user = get_user_by( 'login', $username );
    $user_id = $user ? $user->ID : '';

    // Update coupon meta
    $meta = array(
        'post_excerpt'                           => sanitize_text_field( wp_unslash( $_POST['post_excerpt'] ) ),
        'discount_type'                         => sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ),
        'coupon_amount'                         => floatval( wp_unslash( $_POST['coupon_amount'] ) ),
        'free_shipping'                         => sanitize_text_field( wp_unslash( $_POST['free_shipping'] ) ),
        'date_expires'                          => ! empty( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : '',
        'minimum_amount'                        => floatval( wp_unslash( $_POST['minimum_amount'] ) ),
        'maximum_amount'                        => floatval( wp_unslash( $_POST['maximum_amount'] ) ),
        'individual_use'                        => sanitize_text_field( wp_unslash( $_POST['individual_use'] ) ),
        'exclude_sale_items'                    => sanitize_text_field( wp_unslash( $_POST['exclude_sale_items'] ) ),
        'usage_limit_per_user'                  => ! empty( $_POST['usage_limit_per_user'] ) ? intval( wp_unslash( $_POST['usage_limit_per_user'] ) ) : '',
        'wcu_enable_first_order_only'           => sanitize_text_field( wp_unslash( $_POST['wcu_enable_first_order_only'] ) ),
        'wcu_select_coupon_user'                => $user_id,
        'wcu_text_coupon_commission'            => floatval( wp_unslash( $_POST['wcu_text_coupon_commission'] ) ),
        'wcu_text_coupon_commission_fixed_order' => floatval( wp_unslash( $_POST['wcu_text_coupon_commission_fixed_order'] ) ),
        'wcu_text_coupon_commission_fixed_product' => floatval( wp_unslash( $_POST['wcu_text_coupon_commission_fixed_product'] ) ),
        'wcu_text_unpaid_commission'            => floatval( wp_unslash( $_POST['wcu_text_unpaid_commission'] ) ),
        'wcu_text_pending_payment_commission'   => floatval( wp_unslash( $_POST['wcu_text_pending_payment_commission'] ) ),
    );

    if(!isset($_POST['wcu_text_coupon_commission']) || $_POST['wcu_text_coupon_commission'] == '') {
        $meta['wcu_text_coupon_commission'] = "";
    }
    if(!isset($_POST['wcu_text_coupon_commission_fixed_order']) || $_POST['wcu_text_coupon_commission_fixed_order'] == '') {
        $meta['wcu_text_coupon_commission_fixed_order'] = "";
    }
    if(!isset($_POST['wcu_text_coupon_commission_fixed_product']) || $_POST['wcu_text_coupon_commission_fixed_product'] == '') {
        $meta['wcu_text_coupon_commission_fixed_product'] = "";
    }
    if(!isset($_POST['wcu_text_unpaid_commission']) || $_POST['wcu_text_unpaid_commission'] == '') {
        $meta['wcu_text_unpaid_commission'] = "0";
    }
    if(!isset($_POST['wcu_text_pending_payment_commission']) || $_POST['wcu_text_pending_payment_commission'] == '') {
        $meta['wcu_text_pending_payment_commission'] = "0";
    }

    // Remove PRO fields if not using PRO
    if ( ! wcu_fs()->can_use_premium_code() ) {
        unset( $meta['wcu_text_coupon_commission_fixed_order'] );
        unset( $meta['wcu_text_coupon_commission_fixed_product'] );
        unset( $meta['wcu_text_unpaid_commission'] );
        unset( $meta['wcu_text_pending_payment_commission'] );
    }
    
    foreach ( $meta as $key => $value ) {

        update_post_meta( $coupon_id, $key, $value );
        
    }
    
    // Clear coupon cache
    $coupon->save();
    
    wp_send_json_success();
}
add_action( 'wp_ajax_wcusage_save_coupon_data', 'wcusage_save_coupon_data' );

/**
 * Search users via AJAX
 */
function wcusage_coupons_list_search_users() {
    check_ajax_referer( 'wcusage_coupon_nonce', 'nonce' );
    
    $search = sanitize_text_field( $_POST['search'] );
    $label = sanitize_text_field( $_POST['label'] );
    $users = get_users( array(
        // contain exactly the search term anywhere in the username, full phrase anywhere inside
        'search' => '*' . $search . '*',
        'search_columns' => array( 'user_login' ),
        'orderby' => 'login',
        'fields' => array( 'ID', 'user_login' ),
        'number' => 10, // Limit results for performance
    ));
    
    $results = array();
    foreach ( $users as $user ) {
        if($label == 'username') {
            $results[] = array(
                'id' => $user->ID,
                'label' => "{$user->user_login}",
                'value' => $user->user_login
            );
        } else {
            $results[] = array(
                'id' => $user->ID,
                'label' => "({$user->ID}) {$user->user_login}",
                'value' => $user->user_login
            );
        }
    }
    
    wp_send_json_success( $results );
}
add_action( 'wp_ajax_wcusage_search_users', 'wcusage_coupons_list_search_users' );