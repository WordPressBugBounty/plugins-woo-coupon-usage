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
            $columns['unpaidcommission'] = 'Payouts' . wcusage_admin_tooltip(esc_html__('• Unpaid: Earned from completed orders but not yet paid.', 'woo-coupon-usage') . '<br/>' . esc_html__('• Pending: Payout requests currently awaiting approval.', 'woo-coupon-usage') . '<br/>' . esc_html__('• Paid: Successfully paid to affiliate.', 'woo-coupon-usage'));
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

        // Inputs
        $search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : false;
        $affiliate_only = isset( $_GET['affiliate_only'] ) && 'true' === $_GET['affiliate_only'];
        $orderby_coupon = isset( $_GET['orderby_coupon'] ) ? sanitize_key( wp_unslash( $_GET['orderby_coupon'] ) ) : 'id';

        // Filters
        $filter_aff_user = isset( $_GET['affiliate_user'] ) ? sanitize_text_field( wp_unslash( $_GET['affiliate_user'] ) ) : '';
        $filter_coupon   = isset( $_GET['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon_code'] ) ) : '';
        $filter_status   = isset( $_GET['coupon_status'] ) ? sanitize_key( wp_unslash( $_GET['coupon_status'] ) ) : '';

        $filters = array(
            'affiliate_user' => $filter_aff_user,
            'coupon_code'    => $filter_coupon,
            'coupon_status'  => $filter_status,
        );

        // Pagination
        $per_page     = 20; // Keep modest for performance; could be made user-configurable
        $current_page = max( 1, $this->get_pagenum() );

        // Build base query args (shared by both sort paths)
        $args = array(
            'post_type'      => 'shop_coupon',
            's'              => $search,
            'no_found_rows'  => false, // we need total for pagination
        );

        // Apply filters
        $this->apply_coupon_filters_to_query_args( $args, $filters );

        // Affiliate-only filter
        if ( $affiliate_only ) {
            $meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
            $meta_query[] = array(
                'key'     => 'wcu_select_coupon_user',
                'value'   => array( '' ),
                'compare' => 'NOT IN',
            );
            $args['meta_query'] = $meta_query;
        }

        // Helper: validate a batch of posts (remove invalid/orphaned coupons)
        $validate_batch = function( $posts ) {
            $valid = array();
            foreach ( (array) $posts as $item ) {
                if ( ! is_object( $item ) || empty( $item->ID ) ) { continue; }
                if ( ! $item->post_title ) { continue; }
                $resolved_coupon_id = wc_get_coupon_id_by_code( $item->post_title );
                if ( ! $resolved_coupon_id ) { continue; }
                $assigned_user_id = get_post_meta( (int) $item->ID, 'wcu_select_coupon_user', true );
                if ( ! empty( $assigned_user_id ) && ! get_userdata( (int) $assigned_user_id ) ) { continue; }
                $valid[] = $item;
            }
            return $valid;
        };

        // ------------------------------------------------------------------
        // Sorting by derived metrics (usage, sales, commission) requires
        // fetching ALL matching coupons so we can sort globally, then paginate.
        // ------------------------------------------------------------------
        $is_metric_sort = in_array( $orderby_coupon, array( 'usage', 'sales', 'commission' ), true );

        if ( $is_metric_sort ) {
            // Fetch ALL matching coupons (no per-page limit) so we can sort globally
            $args_all = $args;
            $args_all['posts_per_page'] = -1;
            $args_all['no_found_rows']  = true;
            $args_all['orderby']        = 'ID';
            $args_all['order']          = 'DESC';
            $q_all = new WP_Query( $args_all );
            $all_valid = $validate_batch( $q_all->posts );

            // Compute metrics and sort all valid items globally
            $all_stats_enabled = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
            usort( $all_valid, function( $a, $b ) use ( $orderby_coupon, $all_stats_enabled ) {
                $get_metrics = function( $it ) use ( $all_stats_enabled ) {
                    $id   = isset( $it->ID ) ? (int) $it->ID : 0;
                    $code = isset( $it->post_title ) ? $it->post_title : '';
                    $stats = $id ? get_post_meta( $id, 'wcu_alltime_stats', true ) : array();
                    $usage = 0;
                    if ( $code ) {
                        try {
                            $wc = new WC_Coupon( $code );
                            $usage = $stats && isset( $stats['total_count'] ) ? (float) $stats['total_count'] : (float) $wc->get_usage_count();
                        } catch ( Exception $e ) { $usage = 0; }
                    }
                    $sales = 0.0;
                    $commission = 0.0;
                    if ( $all_stats_enabled && $stats ) {
                        $sales = isset( $stats['total_orders'] ) ? (float) $stats['total_orders'] : 0.0;
                        // The saved stats store the discount under 'full_discount' - see
                        // wcusage_update_all_stats_single(). Reading 'total_discount' here never
                        // matched, so sorting by sales used the totals before the discount.
                        if ( isset( $stats['full_discount'] ) ) { $sales = max( 0, $sales - (float) $stats['full_discount'] ); }
                        $commission = isset( $stats['total_commission'] ) ? (float) $stats['total_commission'] : 0.0;
                    }
                    return array( 'usage' => $usage, 'sales' => $sales, 'commission' => $commission, 'id' => (float) $id );
                };
                $ma = $get_metrics( $a );
                $mb = $get_metrics( $b );
                if ( $ma[ $orderby_coupon ] === $mb[ $orderby_coupon ] ) {
                    // Tie-breaker by ID desc
                    return $mb['id'] <=> $ma['id'];
                }
                return $mb[ $orderby_coupon ] <=> $ma[ $orderby_coupon ]; // Desc
            } );

            // Paginate the globally-sorted results
            $total_items = count( $all_valid );
            $offset = ( $current_page - 1 ) * $per_page;
            $items  = array_slice( $all_valid, $offset, $per_page );

        } else {
            // Default sorting by ID (newest first) — use paginated WP_Query
            $args['posts_per_page'] = $per_page;
            $args['paged']          = $current_page;
            $args['orderby']        = 'ID';
            $args['order']          = 'DESC';

            // Determine the correct valid offset to avoid duplicates across pages
            $desired_valid_offset = ( $current_page - 1 ) * $per_page;
            $skipped_valid        = 0;
            $items                = array();
            $filtered_out_total   = 0;

            // First page query (capture totals)
            $args_first = $args;
            $args_first['paged'] = 1;
            $args_first['no_found_rows'] = false;
            $q_first = new WP_Query( $args_first );
            $max_pages = max( 1, (int) $q_first->max_num_pages );
            $found_posts = (int) $q_first->found_posts;

            $batch_valid = $validate_batch( $q_first->posts );
            $filtered_out_total += max( 0, (int) $q_first->post_count - count( $batch_valid ) );

            $scan_page = 1;
            // Skip valid items until we reach desired offset
            while ( $skipped_valid < $desired_valid_offset && $scan_page <= $max_pages ) {
                if ( $scan_page > 1 ) {
                    $args_page = $args;
                    $args_page['paged'] = $scan_page;
                    $args_page['no_found_rows'] = true;
                    $q_page = new WP_Query( $args_page );
                    $batch_valid = $validate_batch( $q_page->posts );
                    $filtered_out_total += max( 0, (int) $q_page->post_count - count( $batch_valid ) );
                }

                $need_to_skip = $desired_valid_offset - $skipped_valid;
                if ( $need_to_skip >= count( $batch_valid ) ) {
                    $skipped_valid += count( $batch_valid );
                    $scan_page++;
                    continue;
                }

                // We reached the offset within this batch; take the remainder for current page
                $remainder = array_slice( $batch_valid, $need_to_skip );
                foreach ( $remainder as $post_obj ) {
                    $items[] = $post_obj;
                    if ( count( $items ) >= $per_page ) { break; }
                }
                $skipped_valid = $desired_valid_offset; // offset satisfied
                $scan_page++;
                break;
            }

            // If offset already satisfied and we still don't have enough items, continue scanning next pages
            while ( count( $items ) < $per_page && $scan_page <= $max_pages ) {
                $args_page = $args;
                $args_page['paged'] = $scan_page;
                $args_page['no_found_rows'] = true;
                $q_page = new WP_Query( $args_page );
                if ( empty( $q_page->posts ) ) { break; }
                $batch_valid = $validate_batch( $q_page->posts );
                $filtered_out_total += max( 0, (int) $q_page->post_count - count( $batch_valid ) );
                foreach ( $batch_valid as $post_obj ) {
                    $items[] = $post_obj;
                    if ( count( $items ) >= $per_page ) { break 2; }
                }
                $scan_page++;
            }

            // Adjust totals by subtracting the number of items we filtered out in the pages we scanned.
            $total_items = max( 0, (int) $found_posts - (int) $filtered_out_total );
        }

        // Assign and paginate
        $this->items = $items;

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );
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
                return '<a class="wcusage-coupon-id-link" href="' . esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ) . '"><span class="dashicons dashicons-edit"></span>' . esc_html( $item->ID ) . '</a>';
            case 'post_title':
                // A coupon is locked while the affiliate it belongs to is suspended.
                $suspended_lock = function_exists( 'wcusage_get_suspended_coupon_lock_html' ) ? wcusage_get_suspended_coupon_lock_html( $item->ID ) : '';
                return '<a href="' . esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ) . '">' . esc_html( $coupon_code ) . '</a>' . $suspended_lock;
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
                    return '<span title="' . esc_html( $qmessage ) . '"><strong><i class="fa-solid fa-ellipsis"></i></strong></span>';
                }
                $sales = isset( $wcu_alltime_stats['total_orders'] ) ? $wcu_alltime_stats['total_orders'] : 0;
                // The saved stats store the discount under 'full_discount' - see
                // wcusage_update_all_stats_single(). Reading 'total_discount' here never matched,
                // so this column showed sales before the coupon discount was taken off, while
                // every other screen (View Affiliate, affiliate dashboard, reports) showed it after.
                if ( isset( $wcu_alltime_stats['full_discount'] ) ) {
                    // Floored at zero so statistics saved before the totals were corrected
                    // (where the discount can exceed the sales) cannot show a minus figure.
                    $sales = max( 0, (float) $sales - (float) $wcu_alltime_stats['full_discount'] );
                }
                // Stats are calculated at this point (the empty-stats case returned
                // above), so show the value even when it is a genuine 0.00 rather
                // than falsely flagging the coupon as still needing a refresh.
                return wcusage_format_price( $sales );
            case 'commission':
                if ( $disable_commission && wcusage_get_setting_value( 'wcusage_field_commission_disable_non_affiliate', '0' ) ) {
                    return '<div class="wcusage-coupon-commission-cell">' . wcusage_coupons_get_rate_subline_output( $coupon_id ) . '</div>';
                }
                return wcusage_coupons_get_commission_column_output( $coupon_id, $wcu_alltime_stats, $usage, $qmessage );
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
                $pending_payments = (float) get_post_meta( $item->ID, 'wcu_text_pending_payment_commission', true );
                $total_commission = $wcu_alltime_stats && isset( $wcu_alltime_stats['total_commission'] ) ? (float) $wcu_alltime_stats['total_commission'] : 0;
                
                $paid_commission = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM {$payouts_table} WHERE couponid = %d AND status = 'paid'",
                    $item->ID
                )); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

                $output = '<div style="line-height: 1.4;">';
                $output .= '<div><strong>Unpaid:</strong> ' . wcusage_format_price( $unpaid_commission ) . '</div>';
                $output .= '<hr style="margin: 2px 0; border: 0; border-top: 1px solid #ddd;">';
                $output .= '<div><strong>Pending:</strong> ' . wcusage_format_price( $pending_payments ) . '</div>';
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
            $currency_symbol = get_woocommerce_currency_symbol();
            $coupon_user_id = wcusage_get_coupon_info_by_id( $item->ID )[1];
            $user_info = get_userdata( $coupon_user_id );

            echo '<tr id="coupon-row-' . esc_attr( $coupon_id ) . '">';
            $this->single_row_columns( $item );
            echo '</tr>';
            // Shared quick edit row
            include_once WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/admin/tools/quick-edit-coupon.php';
            wcusage_render_quick_edit_row( $coupon_id, count( $this->get_columns() ) );
        }
    }
    
    /**
     * Get affiliate coupons
     *
     * @param string $search
     * @return array
     */
    public function get_affiliate_coupons( $search = '', $filters = array() ) {
        // Deprecated in favor of paginated query inside prepare_items().
        // Kept for backward compatibility if referenced elsewhere.
        $args = array(
            'post_type'      => 'shop_coupon',
            'posts_per_page' => 20,
            'paged'          => 1,
            's'              => $search,
            'meta_query'     => array(
                array(
                    'key'     => 'wcu_select_coupon_user',
                    'value'   => array( '' ),
                    'compare' => 'NOT IN',
                ),
            ),
        );
        $this->apply_coupon_filters_to_query_args( $args, $filters );
        $q = new WP_Query( $args );
        return $q->posts;
    }

    /**
     * Get all coupons
     *
     * @param string $search
     * @return array
     */
    public function get_all_coupons( $search = '', $filters = array() ) {
        // Deprecated in favor of paginated query inside prepare_items().
        // Kept for backward compatibility if referenced elsewhere.
        $args = array(
            'post_type'      => 'shop_coupon',
            's'              => $search,
            'posts_per_page' => 20,
            'paged'          => 1,
        );
        $this->apply_coupon_filters_to_query_args( $args, $filters );
        $q = new WP_Query( $args );
        return $q->posts;
    }

    /**
     * Apply affiliate user, coupon code, status, and date filters to WP_Query args
     */
    private function apply_coupon_filters_to_query_args( &$args, $filters ) {
        if ( ! is_array( $filters ) ) { return; }
        $meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

        // Affiliate user -> convert username to user ID
        if ( ! empty( $filters['affiliate_user'] ) ) {
            $user = get_user_by( 'login', $filters['affiliate_user'] );
            if ( ! $user && is_numeric( $filters['affiliate_user'] ) ) {
                $user = get_user_by( 'id', (int) $filters['affiliate_user'] );
            }
            if ( $user ) {
                $meta_query[] = array(
                    'key'   => 'wcu_select_coupon_user',
                    'value' => (string) $user->ID,
                );
            } else {
                // No user match: ensure no results
                $meta_query[] = array(
                    'key'   => 'wcu_select_coupon_user',
                    'value' => '__no_such_user__',
                );
            }
        }

        // Coupon code filter (overrides generic search if provided)
        if ( ! empty( $filters['coupon_code'] ) ) {
            $args['s'] = $filters['coupon_code'];
        }

        // Coupon status (post status)
        if ( ! empty( $filters['coupon_status'] ) ) {
            $args['post_status'] = sanitize_key( $filters['coupon_status'] );
        }

        if ( ! empty( $meta_query ) ) {
            $args['meta_query'] = $meta_query;
        }
    }

    /**
     * Render filters next to bulk actions in the table toolbar
     */
    public function extra_tablenav( $which ) {
        if ( 'top' !== $which ) return;

    $current_aff_user = isset($_REQUEST['affiliate_user']) ? sanitize_text_field( wp_unslash( $_REQUEST['affiliate_user'] ) ) : '';
        $current_coupon   = isset($_REQUEST['coupon_code']) ? sanitize_text_field( wp_unslash( $_REQUEST['coupon_code'] ) ) : '';
        $current_status   = isset($_REQUEST['coupon_status']) ? sanitize_key( wp_unslash( $_REQUEST['coupon_status'] ) ) : '';
        $current_orderby  = isset($_REQUEST['orderby_coupon']) ? sanitize_key( wp_unslash( $_REQUEST['orderby_coupon'] ) ) : 'id';
    $current_aff_only = isset($_REQUEST['affiliate_only']) ? sanitize_text_field( wp_unslash( $_REQUEST['affiliate_only'] ) ) : '';
        $current_page_slug = isset($_REQUEST['page']) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : 'wcusage_coupons';
        $action_url = esc_url( admin_url( 'admin.php?page=' . $current_page_slug ) );

        echo '<div class="alignleft actions wcusage-admin-title-filters" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        // User filter input (label removed, placeholder used instead)
        echo '<div style="display:flex;align-items:center;gap:6px;">'
            . '<input type="text" class="wcu-autocomplete-user" name="affiliate_user" data-label="username"'
            . ' value="' . esc_attr( $current_aff_user ) . '" placeholder="' . esc_attr__( 'Username...', 'woo-coupon-usage' ) . '" style="min-width:140px;" />'
            . '</div>';
        // Coupon filter input (label removed, placeholder used instead)
        echo '<div style="display:flex;align-items:center;gap:6px;">'
            . '<input type="text" name="coupon_code" value="' . esc_attr( $current_coupon ) . '" placeholder="' . esc_attr__( 'Coupon...', 'woo-coupon-usage' ) . '" style="min-width:140px;" />'
            . '</div>';
        // Status select (label removed; keep options as-is)
        echo '<div style="display:flex;align-items:center;gap:6px;">'
            . '<select name="coupon_status">'
            . '<option value="">' . esc_html__( 'Any Status', 'woo-coupon-usage' ) . '</option>';            
            $statuses = array(
                'publish' => esc_html__( 'Published', 'woo-coupon-usage' ),
                'draft'   => esc_html__( 'Draft', 'woo-coupon-usage' ),
                'pending' => esc_html__( 'Pending', 'woo-coupon-usage' ),
                'private' => esc_html__( 'Private', 'woo-coupon-usage' ),
            );
            foreach ( $statuses as $key => $label ) {
                echo '<option value="' . esc_attr( $key ) . '"' . selected( $current_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
            }
        echo '</select></div>';
        // Show scope selector (All vs Affiliate Only)
        echo '<div style="display:flex;align-items:center;gap:6px;">'
            . '<select name="affiliate_only">'
                . '<option value="">' . esc_html__( 'All Coupons', 'woo-coupon-usage' ) . '</option>'
                . '<option value="true"' . selected( $current_aff_only, 'true', false ) . '>' . esc_html__( 'Affiliate Coupons Only', 'woo-coupon-usage' ) . '</option>'
            . '</select>'
        . '</div>';

        // (Removed duplicate Show scope selector with label)

        // Filter submit with GET, so it doesn't interfere with bulk POST
    echo '<button class="button" type="submit" formmethod="get" formaction="' . esc_url( $action_url ) . '">' . esc_html__( 'Filter', 'woo-coupon-usage' ) . '</button>';
    echo '<a href="' . esc_url( $action_url ) . '">' . esc_html__( 'Reset', 'woo-coupon-usage' ) . '</a>';
        echo '</div>';

        // Separate Sort controls (next to bulk actions) - use JS to apply via GET to avoid nested forms
        echo '<div class="alignleft actions wcusage-admin-sort" style="display:flex;align-items:center;gap:6px;">';
            echo '<select name="orderby_coupon" class="wcusage-orderby-coupon">';
                    $sort_opts = array(
                        ''           => esc_html__( 'Sort By...', 'woo-coupon-usage' ),
                        'id'         => esc_html__( 'ID (Newest First)', 'woo-coupon-usage' ),
                        'usage'      => esc_html__( 'Total Usage', 'woo-coupon-usage' ),
                        'sales'      => esc_html__( 'Total Sales', 'woo-coupon-usage' ),
                        'commission' => esc_html__( 'Total Commission', 'woo-coupon-usage' ),
                    );
                    foreach ( $sort_opts as $k => $lbl ) {
                        echo '<option value="' . esc_attr( $k ) . '"' . selected( $current_orderby, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
                    }
            echo '</select>';
            echo '<button class="button wcusage-apply-sort" type="button">' . esc_html__( 'Sort', 'woo-coupon-usage' ) . '</button>';
        echo '</div>';

        // Sorting behavior handled in js/admin-coupons.js
    }
}

if ( ! function_exists( 'wcusage_coupons_rate_is_valid' ) ) {
    function wcusage_coupons_rate_is_valid( $value ) {
        return '' !== $value && is_numeric( $value ) && (float) $value >= 0;
    }
}

if ( ! function_exists( 'wcusage_coupons_rate_is_configured' ) ) {
    function wcusage_coupons_rate_is_configured( $value, $allow_zero = true ) {
        return wcusage_coupons_rate_is_valid( $value ) && ( $allow_zero || (float) $value > 0 );
    }
}

if ( ! function_exists( 'wcusage_coupons_format_rate_value' ) ) {
    function wcusage_coupons_format_rate_value( $value, $type ) {
        if ( 'percent' === $type ) {
            return esc_html( $value ) . '%';
        }

        $formatted = function_exists( 'wcusage_format_price' ) ? wcusage_format_price( $value ) : wc_price( $value );
        if ( 'fixed_product' === $type ) {
            $formatted .= esc_html__( ' / Product', 'woo-coupon-usage' );
        }

        return $formatted;
    }
}

if ( ! function_exists( 'wcusage_coupons_format_rate_value_plain' ) ) {
    function wcusage_coupons_format_rate_value_plain( $value, $type ) {
        if ( 'percent' === $type ) {
            return $value . '%';
        }

        if ( function_exists( 'wcusage_format_price_plain' ) ) {
            $formatted = wcusage_format_price_plain( $value );
        } else {
            $formatted = html_entity_decode( wp_strip_all_tags( wcusage_coupons_format_rate_value( $value, $type ) ) );
        }

        if ( 'fixed_product' === $type ) {
            $formatted .= ' / Product';
        }

        return $formatted;
    }
}

if ( ! function_exists( 'wcusage_coupons_get_role_rate_label' ) ) {
    function wcusage_coupons_get_role_rate_label( $role ) {
        $role_name = $role;
        $wp_roles = wp_roles();
        if ( $wp_roles && isset( $wp_roles->roles[ $role ]['name'] ) ) {
            $role_name = translate_user_role( $wp_roles->roles[ $role ]['name'] );
        }

        if ( wcusage_is_affiliate_group_role( $role ) ) {
            return sprintf( __( 'Group: %s', 'woo-coupon-usage' ), $role_name );
        }

        return sprintf( __( 'Role: %s', 'woo-coupon-usage' ), $role_name );
    }
}

if ( ! function_exists( 'wcusage_coupons_get_role_rate_candidates' ) ) {
    function wcusage_coupons_get_role_rate_candidates( $user, $setting_prefix, $type, $label ) {
        if ( ! $user || ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
            return array();
        }

        $candidates = array();
        foreach ( $user->roles as $role ) {
            // Read through the same parser the calculation uses, so a rate stored as
            // "10%" is listed here as 10 rather than skipped - and is displayed as a
            // plain number, not "10%%".
            $role_rate = wcusage_parse_rate_value( wcusage_get_setting_value( $setting_prefix . $role, '' ) );
            if ( ! wcusage_coupons_rate_is_valid( $role_rate ) ) {
                continue;
            }

            $candidates[] = array(
                'label'        => $label,
                'type'         => $type,
                'value'        => $role_rate,
                'source_id'    => 'role:' . $role,
                'source_level' => 'role',
                'source_label' => wcusage_coupons_get_role_rate_label( $role ),
                'role'         => $role,
            );
        }

        return $candidates;
    }
}

if ( ! function_exists( 'wcusage_coupons_get_highest_rate_candidate' ) ) {
    function wcusage_coupons_get_highest_rate_candidate( $candidates ) {

        // Only ever receives the role candidates for one component, so this mirrors
        // wcusage_get_role_rate(), which decides the rate an order actually pays: a
        // role that is an affiliate group beats any other role the affiliate happens
        // to hold, since an assigned group is a deliberate choice and "customer" is
        // not. Highest wins within whichever set applies. Picking the highest across
        // every role instead made this column advertise, for an affiliate holding more
        // than one role, a rate that is never paid.
        $group_candidates = array();
        foreach ( $candidates as $candidate ) {
            if ( isset( $candidate['role'] ) && wcusage_is_affiliate_group_role( $candidate['role'] ) ) {
                $group_candidates[] = $candidate;
            }
        }
        if ( ! empty( $group_candidates ) ) {
            $candidates = $group_candidates;
        }

        $selected_candidate = false;
        foreach ( $candidates as $candidate ) {
            if ( false === $selected_candidate || (float) $candidate['value'] > (float) $selected_candidate['value'] ) {
                $selected_candidate = $candidate;
            }
        }

        return $selected_candidate;
    }
}

if ( ! function_exists( 'wcusage_coupons_format_rate_display' ) ) {
    function wcusage_coupons_format_rate_display( $components ) {
        $display_parts = array();
        foreach ( $components as $component ) {
            if ( wcusage_coupons_rate_is_valid( $component['value'] ) && (float) $component['value'] > 0 ) {
                $display_parts[] = wcusage_coupons_format_rate_value( $component['value'], $component['type'] );
            }
        }

        if ( empty( $display_parts ) ) {
            return '0';
        }

        return implode( ' + ', $display_parts );
    }
}

if ( ! function_exists( 'wcusage_coupons_get_rate_tooltip_sections' ) ) {
    function wcusage_coupons_get_rate_tooltip_sections( $components, $candidates ) {
        $sections = array();

        foreach ( $components as $component_key => $component ) {
            if ( empty( $candidates[ $component_key ] ) ) {
                continue;
            }

            $rows = array();
            foreach ( $candidates[ $component_key ] as $candidate ) {
                $rows[] = array(
                    'level'  => $candidate['source_label'],
                    'value'  => wcusage_coupons_format_rate_value_plain( $candidate['value'], $candidate['type'] ),
                    'active' => $candidate['source_id'] === $component['source_id'],
                );
            }

            $sections[] = array(
                'label' => $component['label'],
                'rows'  => $rows,
            );
        }

        return $sections;
    }
}

if ( ! function_exists( 'wcusage_coupons_get_rate_visual_tooltip' ) ) {
    function wcusage_coupons_get_rate_visual_tooltip( $rate_details ) {
        $sections = isset( $rate_details['sections'] ) ? $rate_details['sections'] : array();
        $message = isset( $rate_details['message'] ) ? $rate_details['message'] : __( 'No configured rates for this coupon/user.', 'woo-coupon-usage' );

        $output  = '<span class="wcusage-rate-tooltip">';
        $output .= '<button type="button" class="wcusage-rate-tooltip-trigger" aria-label="' . esc_attr__( 'View commission rate levels', 'woo-coupon-usage' ) . '">?</button>';
        $output .= '<span class="wcusage-rate-tooltip-panel" role="tooltip">';
        $output .= '<span class="wcusage-rate-tooltip-title">' . esc_html__( 'Commission Levels', 'woo-coupon-usage' ) . '</span>';

        if ( empty( $sections ) ) {
            $output .= '<span class="wcusage-rate-tooltip-empty">' . esc_html( $message ) . '</span>';
        } else {
            foreach ( $sections as $section ) {
                $output .= '<span class="wcusage-rate-tooltip-section">';
                $output .= '<span class="wcusage-rate-tooltip-section-title">' . esc_html( $section['label'] ) . '</span>';

                foreach ( $section['rows'] as $row ) {
                    $row_class = $row['active'] ? ' is-active' : ' is-overridden';
                    $status = $row['active'] ? esc_html__( 'Using', 'woo-coupon-usage' ) : esc_html__( 'Overridden', 'woo-coupon-usage' );

                    $output .= '<span class="wcusage-rate-tooltip-row' . esc_attr( $row_class ) . '">';
                    $output .= '<span class="wcusage-rate-tooltip-level">' . esc_html( $row['level'] ) . '</span>';
                    $output .= '<span class="wcusage-rate-tooltip-value">' . esc_html( $row['value'] ) . '</span>';
                    $output .= '<span class="wcusage-rate-tooltip-status">' . esc_html( $status ) . '</span>';
                    $output .= '</span>';
                }

                $output .= '</span>';
            }
        }

        $output .= '</span>';
        $output .= '</span>';

        return $output;
    }
}

if ( ! function_exists( 'wcusage_coupons_get_rate_details' ) ) {
    function wcusage_coupons_get_rate_details( $coupon_id ) {
        $coupon_user_id = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
        $user = $coupon_user_id ? get_userdata( $coupon_user_id ) : false;

        $component_definitions = array(
            'percent'       => array(
                'label'          => __( 'Percent', 'woo-coupon-usage' ),
                'type'           => 'percent',
                'global_setting' => 'wcusage_field_affiliate',
                'role_setting'   => 'wcusage_field_affiliate_percent_role_',
                'coupon_meta'    => 'wcu_text_coupon_commission',
            ),
            'fixed_order'   => array(
                'label'          => __( 'Per order', 'woo-coupon-usage' ),
                'type'           => 'fixed_order',
                'global_setting' => 'wcusage_field_affiliate_fixed_order',
                'role_setting'   => 'wcusage_field_affiliate_fixed_order_role_',
                'coupon_meta'    => 'wcu_text_coupon_commission_fixed_order',
            ),
            'fixed_product' => array(
                'label'          => __( 'Per product', 'woo-coupon-usage' ),
                'type'           => 'fixed_product',
                'global_setting' => 'wcusage_field_affiliate_fixed_product',
                'role_setting'   => 'wcusage_field_affiliate_fixed_product_role_',
                'coupon_meta'    => 'wcu_text_coupon_commission_fixed_product',
            ),
        );

        $components = array();
        $candidates = array();

        foreach ( $component_definitions as $component_key => $definition ) {
            $global_value = wcusage_get_setting_value( $definition['global_setting'], '0' );
            if ( ! is_numeric( $global_value ) ) {
                $global_value = '0';
            }

            $components[ $component_key ] = array(
                'label'        => $definition['label'],
                'type'         => $definition['type'],
                'value'        => $global_value,
                'source_id'    => 'global',
                'source_level' => 'global',
                'source_label' => __( 'Global', 'woo-coupon-usage' ),
            );
            $candidates[ $component_key ] = array();

            if ( wcusage_coupons_rate_is_configured( $global_value, false ) ) {
                $candidates[ $component_key ][] = $components[ $component_key ];
            }
        }

        $affiliate_per_user = wcusage_get_setting_value( 'wcusage_field_affiliate_per_user', '0' );
        if ( wcu_fs()->is__premium_only() && $affiliate_per_user && $user ) {
            foreach ( $component_definitions as $component_key => $definition ) {
                $role_candidates = wcusage_coupons_get_role_rate_candidates( $user, $definition['role_setting'], $definition['type'], $definition['label'] );
                if ( empty( $role_candidates ) ) {
                    continue;
                }

                $components[ $component_key ] = wcusage_coupons_get_highest_rate_candidate( $role_candidates );
                $candidates[ $component_key ] = array_merge( $candidates[ $component_key ], $role_candidates );
            }
        }

        foreach ( $component_definitions as $component_key => $definition ) {
            $coupon_value = get_post_meta( $coupon_id, $definition['coupon_meta'], true );
            if ( '' === $coupon_value ) {
                continue;
            }

            if ( ! wcusage_coupons_rate_is_valid( $coupon_value ) ) {
                continue;
            }

            $coupon_candidate = array(
                'label'        => $definition['label'],
                'type'         => $definition['type'],
                'value'        => $coupon_value,
                'source_id'    => 'coupon',
                'source_level' => 'coupon',
                'source_label' => __( 'Coupon', 'woo-coupon-usage' ),
            );

            $candidates[ $component_key ][] = $coupon_candidate;

            if ( 'fixed_order' === $component_key && (float) $coupon_value <= 0 && (float) $components[ $component_key ]['value'] > 0 ) {
                continue;
            }

            $components[ $component_key ] = $coupon_candidate;
        }

        $display = wcusage_coupons_format_rate_display( $components );

        return array(
            'display'  => $display,
            'sections' => wcusage_coupons_get_rate_tooltip_sections( $components, $candidates ),
        );
    }
}

if ( ! function_exists( 'wcusage_coupons_get_rate_subline_output' ) ) {
    function wcusage_coupons_get_rate_subline_output( $coupon_id ) {
        $coupon_id = absint( $coupon_id );
        if ( ! $coupon_id ) {
            return '';
        }

        if ( wcusage_coupon_disable_commission( $coupon_id ) ) {
            $rate_details = array(
                'sections' => array(),
                'message'  => __( 'Commission disabled for this coupon.', 'woo-coupon-usage' ),
            );
            return '<div class="wcusage-coupon-rate-subline"><span class="wcusage-coupon-rate-label">' . esc_html__( 'Rate:', 'woo-coupon-usage' ) . '</span> N/A' . wcusage_coupons_get_rate_visual_tooltip( $rate_details ) . '</div>';
        }

        $rate_details = wcusage_coupons_get_rate_details( $coupon_id );

        return '<div class="wcusage-coupon-rate-subline"><span class="wcusage-coupon-rate-label">' . esc_html__( 'Rate:', 'woo-coupon-usage' ) . '</span> <strong>' . wp_kses_post( $rate_details['display'] ) . '</strong>' . wcusage_coupons_get_rate_visual_tooltip( $rate_details ) . '</div>';
    }
}

if ( ! function_exists( 'wcusage_coupons_get_commission_column_output' ) ) {
    function wcusage_coupons_get_commission_column_output( $coupon_id, $alltime_stats, $usage, $message ) {
        $commission = $alltime_stats && isset( $alltime_stats['total_commission'] ) ? $alltime_stats['total_commission'] : 0;

        // Stats that have been calculated (even to a genuine zero) are stored as
        // a non-empty wcu_alltime_stats array. Only a coupon whose stats have
        // never been calculated should show the "needs refresh" indicator — a
        // coupon with a real 0.00 commission must show the value, not the dots.
        $stats_calculated = ( is_array( $alltime_stats ) && ! empty( $alltime_stats ) );

        if ( $usage > 0 && ! $commission && ! $stats_calculated ) {
            $commission_output = "<span title='" . esc_html( $message ) . "'><strong><i class='fa-solid fa-ellipsis'></i></strong></span>";
        } else {
            $commission_output = wcusage_format_price( $commission );
        }

        return '<div class="wcusage-coupon-commission-cell">' . $commission_output . wcusage_coupons_get_rate_subline_output( $coupon_id ) . '</div>';
    }
}


/**
 * Coupons page handler
 */
function wcusage_coupons_page() {
    if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'admin_add_registration_form' ) && wcusage_check_admin_access() ) {
        echo wcusage_post_submit_application(1); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    if ( isset( $_GET['delete_coupon'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_coupon' ) && wcusage_check_admin_access() ) {
        $coupon_id = sanitize_text_field( wp_unslash( $_GET['delete_coupon'] ) );
        $coupon = get_post( $coupon_id );
        if ( $coupon ) {
            $coupon_name = $coupon->post_title;
            wp_delete_post( $coupon_id );
            echo '<p class="notice notice-success is-dismissible" style="padding: 10px; margin: 10px 0;">' 
                . sprintf(
                    esc_html__( 'Coupon "%s" deleted successfully.', 'woo-coupon-usage' ),
                    esc_html( $coupon_name )
                ) 
                . '</p>';
        }
    }

    $wcusage_coupons_asset_version = defined( 'WCUSAGE_VERSION' ) ? WCUSAGE_VERSION : '1.0.0';

    // Enqueue styles
    wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
    wp_enqueue_style( 'wcusage-font-awesome', WCUSAGE_UNIQUE_PLUGIN_URL . 'fonts/font-awesome/css/all.min.css', array(), '5.15.4' );
    wp_enqueue_style( 'wcusage-coupons', WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-coupons.css', array(), $wcusage_coupons_asset_version );

    // Enqueue scripts
    wp_enqueue_script( 'jquery-ui-autocomplete' );
    wp_enqueue_script( 'wcusage-coupons', WCUSAGE_UNIQUE_PLUGIN_URL . 'js/admin-coupons.js', array( 'jquery' ), $wcusage_coupons_asset_version, true );
    
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
        // Bulk action confirmation messages (moved from inline script)
        'bulk_confirm' => array(
            'bulk_unassign' => __( 'Are you sure you want to unassign the selected affiliates from these coupons? This will remove the affiliate assignment but will NOT delete coupons or users.', 'woo-coupon-usage' ),
            'bulk_delete_coupons' => __( 'Are you sure you want to delete the selected coupons?', 'woo-coupon-usage' ),
            'bulk_delete_coupons_and_user' => __( 'Are you sure you want to delete the selected coupons AND their assigned affiliate users? This will also delete all coupons belonging to those users and permanently remove their user accounts.', 'woo-coupon-usage' ),
        ),
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
                <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=shop_coupon' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Add Coupon', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage_add_affiliate' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Add Affiliate Coupon', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage-bulk-coupon-creator' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Bulk Create Coupons', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcusage-bulk-edit-coupon' ) ); ?>" class="wcusage-settings-button"><?php esc_html_e( 'Bulk Edit Coupons', 'woo-coupon-usage' ); ?> <span class="fa-solid fa-circle-arrow-right"></span></a>
            </span>
            <br/>
            
        </h1>
        <form method="get" id="wcusage-coupons-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
            
            <?php
            $current_aff_user = isset($_GET['affiliate_user']) ? sanitize_text_field( wp_unslash( $_GET['affiliate_user'] ) ) : '';
            $current_coupon   = isset($_GET['coupon_code']) ? sanitize_text_field( wp_unslash( $_GET['coupon_code'] ) ) : '';
            $current_status   = isset($_GET['coupon_status']) ? sanitize_key( wp_unslash( $_GET['coupon_status'] ) ) : '';
            $current_orderby  = isset($_GET['orderby_coupon']) ? sanitize_key( wp_unslash( $_GET['orderby_coupon'] ) ) : 'id';
            ?>
            <?php
            $table->prepare_items();
            ?>
        </form>
        <form method="post" id="wcusage-coupons-bulk-actions">
            <?php wp_nonce_field( 'wcusage_coupons_bulk_action', '_wcusage_bulk_nonce' ); ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( isset( $_REQUEST['page'] ) ? esc_html( wp_unslash( $_REQUEST['page'] ) ) : '' ); ?>" />
            <input type="hidden" name="affiliate_only" value="<?php echo $affiliate_only ? 'true' : ''; ?>" />
            <input type="hidden" name="affiliate_user" value="<?php echo esc_attr( $current_aff_user ); ?>" />
            <input type="hidden" name="coupon_code" value="<?php echo esc_attr( $current_coupon ); ?>" />
            <input type="hidden" name="coupon_status" value="<?php echo esc_attr( $current_status ); ?>" />
            <input type="hidden" name="orderby_coupon" value="<?php echo esc_attr( $current_orderby ); ?>" />
            <?php $table->display(); ?>
        </form>
    </div>
    <style>
    /* Vertically center all table cell contents on this page.
       The ID column is the primary column, so WordPress renders it as <th scope="row">
       (not <td>) and core sets .widefat tbody th { vertical-align: top } -- include it. */
    .wp-list-table tbody td,
    .wp-list-table tbody th,
    .wp-list-table thead th {
        vertical-align: middle;
    }
    .wp-list-table .column-cb {
        width: 40px !important;
    }
    .wp-list-table .column-cb input, .check-column input {
        margin-top: 1px !important;
        margin-left: 0px !important;
    }
    </style>
    
    <?php
}

/**
 * Save coupon data via AJAX
 */
function wcusage_save_coupon_data() {
    check_ajax_referer( 'wcusage_coupon_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        return;
    }
    
    $coupon_id = intval( $_POST['coupon_id'] );
    $coupon = new WC_Coupon( $coupon_id );

    // Get old user ID before update (for cache clearing)
    $old_user_id = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
    
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
        'wcu_text_pending_order_commission'     => floatval( wp_unslash( $_POST['wcu_text_pending_order_commission'] ) ),
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
    if(!isset($_POST['wcu_text_pending_order_commission']) || $_POST['wcu_text_pending_order_commission'] == '') {
        $meta['wcu_text_pending_order_commission'] = "0";
    }

    // Remove PRO fields if not using PRO
    if ( ! wcu_fs()->can_use_premium_code() ) {
        unset( $meta['wcu_text_coupon_commission_fixed_order'] );
        unset( $meta['wcu_text_coupon_commission_fixed_product'] );
        unset( $meta['wcu_text_unpaid_commission'] );
        unset( $meta['wcu_text_pending_payment_commission'] );
        unset( $meta['wcu_text_pending_order_commission'] );
    }
    
    // Quick-edit commission changes are genuine manual admin edits, so allow the
    // activity log to record them (see wcusage_after_update_function). Flag only
    // around the actual writes so it can't leak onto later automated updates.
    if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
        wcusage_set_manual_commission_edit( true );
    }

    foreach ( $meta as $key => $value ) {

        update_post_meta( $coupon_id, $key, $value );

    }

    if ( function_exists( 'wcusage_set_manual_commission_edit' ) ) {
        wcusage_set_manual_commission_edit( false );
    }

    // Coupon History Start Date (available in free and PRO). Force a statistics
    // refresh when it changes, since it affects which orders are included in the
    // coupon's statistics (same behaviour as the full coupon edit screen).
    if ( isset( $_POST['wcu_text_coupon_start_date'] ) ) {
        $wcu_new_start_date      = sanitize_text_field( wp_unslash( $_POST['wcu_text_coupon_start_date'] ) );
        $wcu_previous_start_date = get_post_meta( $coupon_id, 'wcu_text_coupon_start_date', true );
        if ( $wcu_previous_start_date != $wcu_new_start_date ) {
            delete_post_meta( $coupon_id, 'wcu_last_refreshed' );
        }
        update_post_meta( $coupon_id, 'wcu_text_coupon_start_date', $wcu_new_start_date );
    }

    // Clear user caches for both old and new users (if user assignment changed)
    if ( $old_user_id && $old_user_id != $user_id ) {
        wcusage_clear_user_cache( $old_user_id );
    }
    if ( $user_id ) {
        wcusage_clear_user_cache( $user_id );
    }
    
    // Clear the is_coupon_users cache for this specific coupon + user combination
    $coupon_code = get_the_title($coupon_id);
    if ($old_user_id && $coupon_code) {
        delete_transient( wcusage_cache_key( 'user', 'wcusage_is_coupon_users_' . md5($coupon_code . '_' . $old_user_id) ) );
    }
    if ($user_id && $coupon_code) {
        delete_transient( wcusage_cache_key( 'user', 'wcusage_is_coupon_users_' . md5($coupon_code . '_' . $user_id) ) );
    }
    
    // Clear coupon cache
    $coupon->save();

    $wcu_alltime_stats = get_post_meta( $coupon_id, 'wcu_alltime_stats', true );
    $usage = $wcu_alltime_stats && isset( $wcu_alltime_stats['total_count'] ) ? $wcu_alltime_stats['total_count'] : $coupon->get_usage_count();
    $qmessage = esc_html__( 'The affiliate dashboard for this coupon needs to be loaded at-least once.', 'woo-coupon-usage' );
    
    wp_send_json_success( array(
        'commission_html' => wcusage_coupons_get_commission_column_output( $coupon_id, $wcu_alltime_stats, $usage, $qmessage ),
    ) );
}
add_action( 'wp_ajax_wcusage_save_coupon_data', 'wcusage_save_coupon_data' );

/**
 * Search users via AJAX
 */
function wcusage_coupons_list_search_users() {
    check_ajax_referer( 'wcusage_coupon_nonce', 'nonce' );

    // Wildcard username lookup - restrict it the same way the sibling handler
    // wcusage_save_coupon_data() is restricted.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ) ), 403 );
    }

    $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
    $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
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