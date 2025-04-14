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
            $columns['unpaid_commission'] = esc_html__( 'Unpaid Commission', 'woo-coupon-usage' );
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
            case 'unpaid_commission':
                if ( $disable_commission ) {
                    return '-';
                }
                return wcusage_format_price( get_post_meta( $item->ID, 'wcu_text_unpaid_commission', true ) );
            case 'affiliate':
                return $user_info ? '<a href="' . get_edit_user_link( $coupon_user_id ) . '" target="_blank">' . esc_html( $user_info->user_login ) . '</a>' : '-';
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
            $coupon = new WC_Coupon( $item->post_title );
            $users = get_users( array( 'fields' => array( 'ID', 'user_login' ), 'orderby' => 'login' ) );
            $currency_symbol = get_woocommerce_currency_symbol();
            $coupon_user_id = wcusage_get_coupon_info_by_id( $item->ID )[1];
            $user_info = get_userdata( $coupon_user_id );

            echo '<tr id="coupon-row-' . esc_attr( $coupon_id ) . '">';
            $this->single_row_columns( $item );
            echo '</tr>';
            ?>
            <tr class="quick-edit-row" id="quick-edit-<?php echo esc_attr( $coupon_id ); ?>" style="display: none;">
                <td colspan="<?php echo count( $this->get_columns() ); ?>">
                    <div class="quick-edit-form">
                        <div class="quick-edit-fields" data-coupon-id="<?php echo esc_attr( $coupon_id ); ?>">
                            <div class="section-left">
                                <h3 class="section-heading"><?php esc_html_e( 'Coupon Details', 'woo-coupon-usage' ); ?></h3>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="coupon_code_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Coupon Code', 'woo-coupon-usage' ); ?></label>
                                        <input type="text" id="coupon_code_<?php echo esc_attr( $coupon_id ); ?>" value="<?php echo esc_attr( $coupon->get_code() ); ?>" required>
                                    </div>
                                    <div class="form-field">
                                        <label for="coupon_description_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Description', 'woo-coupon-usage' ); ?></label>
                                        <input type="text" id="coupon_description_<?php echo esc_attr( $coupon_id ); ?>" value="<?php echo esc_attr( $coupon->get_description() ); ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="discount_type_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Discount Type', 'woo-coupon-usage' ); ?></label>
                                        <select id="discount_type_<?php echo esc_attr( $coupon_id ); ?>">
                                            <option value="fixed_cart" <?php selected( $coupon->get_discount_type(), 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart discount', 'woo-coupon-usage' ); ?></option>
                                            <option value="percent" <?php selected( $coupon->get_discount_type(), 'percent' ); ?>><?php esc_html_e( 'Percentage discount', 'woo-coupon-usage' ); ?></option>
                                            <option value="fixed_product" <?php selected( $coupon->get_discount_type(), 'fixed_product' ); ?>><?php esc_html_e( 'Fixed product discount', 'woo-coupon-usage' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="form-field">
                                        <label for="coupon_amount_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Discount Amount', 'woo-coupon-usage' ); ?></label>
                                        <input type="number" id="coupon_amount_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( $coupon->get_amount() ); ?>">
                                    </div>
                                </div>
                                <h3 class="section-heading"><?php esc_html_e( 'Spend Limits', 'woo-coupon-usage' ); ?></h3>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="minimum_amount_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Minimum Spend', 'woo-coupon-usage' ); ?></label>
                                        <input type="number" id="minimum_amount_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( $coupon->get_minimum_amount() ); ?>">
                                    </div>
                                    <div class="form-field">
                                        <label for="maximum_amount_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Maximum Spend', 'woo-coupon-usage' ); ?></label>
                                        <input type="number" id="maximum_amount_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( $coupon->get_maximum_amount() ); ?>">
                                    </div>
                                </div>
                                <h3 class="section-heading"><?php esc_html_e( 'Usage Limits', 'woo-coupon-usage' ); ?></h3>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="expiry_date_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Expiry Date', 'woo-coupon-usage' ); ?></label>
                                        <input type="date" id="expiry_date_<?php echo esc_attr( $coupon_id ); ?>" value="<?php echo $coupon->get_date_expires() ? esc_attr( $coupon->get_date_expires()->date( 'Y-m-d' ) ) : ''; ?>">
                                    </div>
                                    <div class="form-field">
                                        <label for="usage_limit_per_user_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Limit Per User', 'woo-coupon-usage' ); ?></label>
                                        <input type="number" id="usage_limit_per_user_<?php echo esc_attr( $coupon_id ); ?>" min="0" value="<?php echo esc_attr( $coupon->get_usage_limit_per_user() ?: '' ); ?>">
                                    </div>
                                </div>
                                <h3 class="section-heading"><?php esc_html_e( 'Other Settings', 'woo-coupon-usage' ); ?></h3>
                                <div class="form-field checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="free_shipping_<?php echo esc_attr( $coupon_id ); ?>" <?php checked( $coupon->get_free_shipping() ); ?>>
                                        <?php esc_html_e( 'Free Shipping', 'woo-coupon-usage' ); ?>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="exclude_sale_items_<?php echo esc_attr( $coupon_id ); ?>" <?php checked( $coupon->get_exclude_sale_items() ); ?>>
                                        <?php esc_html_e( 'Exclude Sale Items', 'woo-coupon-usage' ); ?>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="individual_use_<?php echo esc_attr( $coupon_id ); ?>" <?php checked( $coupon->get_individual_use() ); ?>>
                                        <?php esc_html_e( 'Individual Use Only', 'woo-coupon-usage' ); ?>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" id="wcu_enable_first_order_only_<?php echo esc_attr( $coupon_id ); ?>" <?php checked( get_post_meta( $coupon_id, 'wcu_enable_first_order_only', true ), 'yes' ); ?>>
                                        <?php esc_html_e( 'New Customers Only', 'woo-coupon-usage' ); ?>
                                    </label>
                                </div>
                            </div>
                            <div class="section-right">
                                <h3 class="section-heading"><?php esc_html_e( 'Coupon Affiliates', 'woo-coupon-usage' ); ?></h3>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="wcu_select_coupon_user_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Affiliate User', 'woo-coupon-usage' ); ?></label>
                                        <input type="text" 
                                            id="wcu_select_coupon_user_<?php echo esc_attr( $coupon_id ); ?>" 
                                            class="wcu-autocomplete-user" 
                                            value="<?php echo $user_info ? esc_attr( $user_info->user_login ) : ''; ?>"
                                            placeholder="<?php esc_html_e( 'Search for a user...', 'woo-coupon-usage' ); ?>">
                                    </div>
                                    <div class="form-field" <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                                        <label for="wcu_text_coupon_commission_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Commission (%) Per Order', 'woo-coupon-usage' ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></label>
                                        <input type="number" id="wcu_text_coupon_commission_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( get_post_meta( $coupon_id, 'wcu_text_coupon_commission', true ) ); ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-field" <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                                        <label for="wcu_text_coupon_commission_fixed_order_<?php echo esc_attr( $coupon_id ); ?>"><?php printf( esc_html__( 'Commission (%s) Per Order', 'woo-coupon-usage' ), esc_html( $currency_symbol ) ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></label>
                                        <input type="number" id="wcu_text_coupon_commission_fixed_order_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( get_post_meta( $coupon_id, 'wcu_text_coupon_commission_fixed_order', true ) ); ?>">
                                    </div>
                                    <div class="form-field" <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                                        <label for="wcu_text_coupon_commission_fixed_product_<?php echo esc_attr( $coupon_id ); ?>"><?php printf( esc_html__( 'Commission (%s) Per Product', 'woo-coupon-usage' ), esc_html( $currency_symbol ) ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></label>
                                        <input type="number" id="wcu_text_coupon_commission_fixed_product_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( get_post_meta( $coupon_id, 'wcu_text_coupon_commission_fixed_product', true ) ); ?>">
                                    </div>
                                </div>

                                <h3 class="section-heading"><?php esc_html_e( 'Commission', 'woo-coupon-usage' ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></h3>
                                <div class="form-row">
                                    <div class="form-field" <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                                        <label for="wcu_text_unpaid_commission_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Unpaid Commission', 'woo-coupon-usage' ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></label>
                                        <input type="number" id="wcu_text_unpaid_commission_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( get_post_meta( $coupon_id, 'wcu_text_unpaid_commission', true ) ); ?>">
                                    </div>
                                    <div class="form-field" <?php if ( !wcu_fs()->can_use_premium_code() ) { ?>style="opacity: 0.5; pointer-events: none;"<?php } ?>>
                                        <label for="wcu_text_pending_payment_commission_<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Pending Payout', 'woo-coupon-usage' ); ?><?php if ( !wcu_fs()->can_use_premium_code() ) { ?> (PRO)<?php } ?></label>
                                        <input type="number" id="wcu_text_pending_payment_commission_<?php echo esc_attr( $coupon_id ); ?>" step="0.01" value="<?php echo esc_attr( get_post_meta( $coupon_id, 'wcu_text_pending_payment_commission', true ) ); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="submit inline-edit-save">
                            <button class="button button-primary save-quick-edit" data-coupon-id="<?php echo esc_attr( $coupon_id ); ?>"><?php esc_html_e( 'Save Changes', 'woo-coupon-usage' ); ?></button>
                            <button class="button cancel-quick-edit"><?php esc_html_e( 'Cancel', 'woo-coupon-usage' ); ?></button>
                            <span class="spinner"></span>
                        </p>
                    </div>
                </td>
            </tr>
            <?php
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
        'edit_user_url'   => esc_url( get_edit_user_link( 0 ) ), // Base URL with '0' as placeholder
    ) );

    $table = new wcusage_Coupons_Table();
    $affiliate_only = isset( $_GET['affiliate_only'] ) && 'true' === $_GET['affiliate_only'];
    $page_url = admin_url( 'admin.php?page=wcusage-coupons' );
    ?>
    <div class="wrap">
        <?php do_action( 'wcusage_hook_dashboard_page_header', '' ); ?>
        <form method="get">
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
                    <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
                    <input type="checkbox" name="affiliate_only" value="true" <?php checked( $affiliate_only ); ?> onchange="this.form.submit();">
                    <?php esc_html_e( 'Show Affiliate Coupons Only', 'woo-coupon-usage' ); ?>
                </span>
            </h1>
            <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
            <?php
            $table->prepare_items();
            $table->search_box( 'Search Coupons', 'search_id' );
            $table->display();
            ?>
        </form>
    </div>
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