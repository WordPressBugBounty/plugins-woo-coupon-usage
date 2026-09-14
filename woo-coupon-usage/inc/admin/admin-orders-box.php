<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Add a custom meta box to the WooCommerce order edit page
 */
function wcusage_add_custom_box() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        $screen = wc_get_page_screen_id( 'shop-order' );
    } else {
        $screen = 'shop_order';
    }
    add_meta_box(
        'wcusage_affiliate_info',
        'Coupon Affiliate',
        'wcusage_custom_box_html',
        $screen,
        'side',
        'high'
    );
}

add_action( 'add_meta_boxes', 'wcusage_add_custom_box' );
/**
 * One label/value line inside a referral card.
 *
 * @param string $label
 * @param string $value_html Already-escaped HTML.
 * @param string $class      Extra class for the row.
 *
 * @return string
 */
if ( !function_exists( 'wcusage_order_box_row' ) ) {
    function wcusage_order_box_row(  $label, $value_html, $class = ''  ) {
        return '<div class="' . esc_attr( trim( 'wcusage-orderbox-row ' . $class ) ) . '">' . '<span class="wcusage-orderbox-row-label">' . esc_html( $label ) . '</span>' . '<span class="wcusage-orderbox-row-value">' . $value_html . '</span>' . '</div>';
    }

}
/**
 * Collects what each referral card worked out to as it is drawn, so the panel can
 * state one verdict for the order above them all.
 *
 * @param string $push 'reset' to clear, a state key to record, or '' to just read.
 *
 * @return array
 */
if ( !function_exists( 'wcusage_order_box_states' ) ) {
    function wcusage_order_box_states(  $push = ''  ) {
        static $states = array();
        if ( $push === 'reset' ) {
            $states = array();
        } elseif ( $push !== '' ) {
            $states[] = $push;
        }
        return $states;
    }

}
/**
 * Turns those per-coupon states into the single chip shown at the top of the panel.
 *
 * Only one coupon can own an order's commission even when several are stacked on
 * it, so the most significant state wins rather than the last one seen.
 *
 * @param array $states
 * @param bool  $has_order Whether there is an order to describe at all.
 *
 * @return array label, class and dashicon.
 */
if ( !function_exists( 'wcusage_order_box_verdict' ) ) {
    function wcusage_order_box_verdict(  $states, $has_order = true  ) {
        if ( !$has_order || empty( $states ) ) {
            return array(
                'label' => __( 'No Referral', 'woo-coupon-usage' ),
                'class' => 'is-neutral',
                'icon'  => 'minus',
                'title' => __( 'No affiliate coupon or referral was recorded for this order.', 'woo-coupon-usage' ),
            );
        }
        if ( in_array( 'refunded', $states, true ) ) {
            return array(
                'label' => __( 'Refunded', 'woo-coupon-usage' ),
                'class' => 'is-neutral',
                'icon'  => 'undo',
                'title' => __( 'The order was refunded, so it earns no commission.', 'woo-coupon-usage' ),
            );
        }
        // "granted" and "referral" are the same verdict: the second is what the free
        // version reaches, where commission is shown but never granted to a balance.
        if ( in_array( 'granted', $states, true ) || in_array( 'referral', $states, true ) ) {
            return array(
                'label' => __( 'Successful Referral', 'woo-coupon-usage' ),
                'class' => 'is-success',
                'icon'  => 'yes-alt',
                'title' => __( 'This order was referred by an affiliate coupon and has earned commission.', 'woo-coupon-usage' ),
            );
        }
        if ( in_array( 'pending', $states, true ) ) {
            return array(
                'label' => __( 'Pending Referral', 'woo-coupon-usage' ),
                'class' => 'is-pending',
                'icon'  => 'clock',
                'title' => __( 'Commission will be granted to the affiliate once the order reaches the payout status.', 'woo-coupon-usage' ),
            );
        }
        if ( in_array( 'unpaid', $states, true ) ) {
            return array(
                'label' => __( 'Not Credited', 'woo-coupon-usage' ),
                'class' => 'is-alert',
                'icon'  => 'warning',
                'title' => __( 'The order is finished but its commission was never granted to the affiliate.', 'woo-coupon-usage' ),
            );
        }
        if ( in_array( 'void', $states, true ) ) {
            return array(
                'label' => __( 'Not Earned', 'woo-coupon-usage' ),
                'class' => 'is-neutral',
                'icon'  => 'dismiss',
                'title' => __( 'The order was cancelled or failed, so it will not grant commission.', 'woo-coupon-usage' ),
            );
        }
        return array(
            'label' => __( 'Tracked Only', 'woo-coupon-usage' ),
            'class' => 'is-neutral',
            'icon'  => 'visibility',
            'title' => __( 'A coupon was used, but it earns no commission - it has no affiliate assigned, or commission is disabled for it.', 'woo-coupon-usage' ),
        );
    }

}
/**
 * The "nothing to show" block, so an order with no referral reads as a deliberate
 * state rather than as an empty panel.
 *
 * @param string $message
 * @param string $icon Dashicon suffix.
 *
 * @return string
 */
if ( !function_exists( 'wcusage_order_box_empty' ) ) {
    function wcusage_order_box_empty(  $message, $icon = 'tickets-alt'  ) {
        return '<p class="wcusage-orderbox-empty">' . '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span>' . '<span>' . esc_html( $message ) . '</span>' . '</p>';
    }

}
/**
 * Handles the "recalculate stats" action for an order.
 *
 * Runs on admin_init rather than while the metabox is drawn, because it finishes
 * with a redirect: from inside the metabox the headers have long since been sent,
 * so the redirect was dropped and the exit() left the page half-rendered.
 *
 * It is also order-scoped, and it used to live inside the per-coupon renderer,
 * where a stacked order ran it - and drew its button - once per coupon.
 */
if ( !function_exists( 'wcusage_order_box_refresh_stats' ) ) {
    function wcusage_order_box_refresh_stats() {
        if ( empty( $_GET['refresh_stats'] ) ) {
            return;
        }
        // "post" on the legacy post table, "id" with High-Performance Order Storage.
        $order_id = 0;
        if ( !empty( $_GET['post'] ) ) {
            $order_id = absint( $_GET['post'] );
        } elseif ( !empty( $_GET['id'] ) ) {
            $order_id = absint( $_GET['id'] );
        }
        if ( !$order_id ) {
            return;
        }
        // Nothing happens without our own nonce, so a "refresh_stats" parameter that
        // belongs to something else is left alone rather than redirected away.
        $nonce = ( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
        if ( !wp_verify_nonce( $nonce, 'wcusage_refresh_order_stats_' . $order_id ) || !current_user_can( 'edit_shop_orders' ) ) {
            return;
        }
        if ( function_exists( 'wcusage_update_pending_commission_action' ) ) {
            wcusage_update_pending_commission_action( $order_id, 'remove' );
        }
        // All three commission keys, so nothing is left to be read back as a stale
        // partial figure by wcusage_get_order_saved_commission() before the
        // recalculation runs - "wcusage_product_commission" was being kept.
        //
        // Deleted through the order object, not delete_post_meta(): with
        // High-Performance Order Storage enabled the order's meta is not in the
        // post meta table, so delete_post_meta() cleared nothing at all and this
        // button silently did nothing on those stores.
        wcusage_delete_order_meta_bulk( $order_id, array(
            'wcusage_commission_summary',
            'wcusage_stats',
            'wcu_mla_commission',
            'wcusage_total_commission',
            'wcusage_fixed_order_commission',
            'wcusage_product_commission'
        ) );
        wp_safe_redirect( remove_query_arg( array('refresh_stats', '_wpnonce') ) );
        exit;
    }

}
add_action( 'admin_init', 'wcusage_order_box_refresh_stats' );
/**
 * Custom box HTML
 *
 * @param object $post The post object.
 */
function wcusage_custom_box_html(  $post  ) {
    $options = wcusage_get_options();
    $wcusage_show_column_code = wcusage_get_setting_value( 'wcusage_field_show_orders_aff_info', '1' );
    $coupon_code = "";
    $lifetimeaffiliate = "";
    $affiliatereferrer = "";
    $coupon_codes = array();
    $wcusage_referrer_coupon = "";
    if ( !empty( $post ) && $post instanceof WP_Post && property_exists( $post, 'ID' ) ) {
        $post_id = $post->ID;
    } else {
        if ( method_exists( $post, 'get_id' ) ) {
            $post_id = $post->get_id();
        } else {
            $post_id = "";
        }
    }
    $order = wc_get_order( $post_id );
    $order_status = ( $order ? $order->get_status() : "" );
    echo '<div class="wcusage-orderbox">';
    // The cards are drawn into a buffer first: each one records what it worked out
    // to, and the verdict chip built from those has to print above them.
    wcusage_order_box_states( 'reset' );
    ob_start();
    if ( $order ) {
        if ( $wcusage_show_column_code ) {
            $lifetimeaffiliate = wcusage_order_meta( $post_id, 'lifetime_affiliate_coupon_referrer' );
            $affiliatereferrer = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon' );
            if ( $lifetimeaffiliate ) {
                $coupon_code = $lifetimeaffiliate;
                wcusage_custom_box_html_content(
                    $lifetimeaffiliate,
                    $post,
                    $order,
                    1
                );
            } elseif ( $affiliatereferrer ) {
                wcusage_custom_box_html_content(
                    $affiliatereferrer,
                    $post,
                    $order,
                    2
                );
            } else {
                if ( class_exists( 'WooCommerce' ) ) {
                    if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
                        foreach ( $order->get_coupon_codes() as $coupon_code ) {
                            if ( $coupon_code ) {
                                wcusage_custom_box_html_content(
                                    $coupon_code,
                                    $post,
                                    $order,
                                    0
                                );
                                $coupon_codes[] = $coupon_code;
                            }
                        }
                    }
                }
            }
            if ( !$order->get_coupon_codes() && !$lifetimeaffiliate && !$affiliatereferrer ) {
                echo wp_kses_post( wcusage_order_box_empty( esc_html__( 'No coupons were used for this order.', 'woo-coupon-usage' ) ) );
            }
        } else {
            echo wp_kses_post( wcusage_order_box_empty( esc_html__( 'Affiliate info for orders is turned off in the Coupon Affiliates settings.', 'woo-coupon-usage' ), 'hidden' ) );
        }
        $wcusage_referrer_coupon = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon', true );
        if ( $lifetimeaffiliate ) {
            $wcusage_referrer_coupon = "";
        }
        wp_nonce_field( basename( __FILE__ ), 'wcusage_referrer_coupon_nonce' );
    } else {
        echo wp_kses_post( wcusage_order_box_empty( esc_html__( 'Affiliate info is not available for this order.', 'woo-coupon-usage' ), 'info-outline' ) );
    }
    $cards = ob_get_clean();
    // ***** Toolbar: what happened on the left, what you can do about it on the right *****
    if ( $order && $wcusage_show_column_code ) {
        $has_referral = $lifetimeaffiliate || $affiliatereferrer || !empty( $coupon_codes );
        $verdict = wcusage_order_box_verdict( wcusage_order_box_states(), true );
        echo '<div class="wcusage-orderbox-toolbar">';
        echo '<span class="wcusage-orderbox-verdict ' . esc_attr( $verdict['class'] ) . '" title="' . esc_attr( $verdict['title'] ) . '">' . '<span class="dashicons dashicons-' . esc_attr( $verdict['icon'] ) . '" aria-hidden="true"></span>' . esc_html( $verdict['label'] ) . '</span>';
        if ( $has_referral ) {
            $refresh_url = wp_nonce_url( add_query_arg( 'refresh_stats', '1', $order->get_edit_order_url() ), 'wcusage_refresh_order_stats_' . $order->get_id() );
            echo '<a href="' . esc_url( $refresh_url ) . '" class="wcusage-orderbox-action"' . ' onClick="return confirm(\'' . esc_js( __( 'Are you sure you want to refresh the affiliate stats for this order? This will delete the current referral stats/commission and recalculate them.', 'woo-coupon-usage' ) ) . '\');"' . ' title="' . esc_attr__( 'Recalculate the affiliate stats for this order.', 'woo-coupon-usage' ) . '">' . '<span class="dashicons dashicons-update" aria-hidden="true"></span>' . esc_html__( 'Recalculate', 'woo-coupon-usage' ) . '</a>';
        }
        echo '</div>';
    }
    echo $cards;
    // Escaped as each card is built.
    do_action(
        'wcusage_hook_order_box_before_custom_referrer',
        $post_id,
        $order,
        $coupon_code,
        $lifetimeaffiliate,
        $affiliatereferrer
    );
    if ( $order_status != 'completed' || $wcusage_referrer_coupon ) {
        // Only meaningful as a placeholder when a single coupon could take the slot.
        $referrer_placeholder = '';
        if ( !$wcusage_referrer_coupon && $coupon_code && count( $coupon_codes ) <= 1 ) {
            $referrer_placeholder = $coupon_code;
        }
        $referrer_readonly = '';
        $referrer_note = '';
        if ( $lifetimeaffiliate ) {
            $referrer_readonly = esc_html__( 'This can not be edited for a lifetime affiliate referral.', 'woo-coupon-usage' );
        } elseif ( $order_status == 'completed' ) {
            $referrer_readonly = esc_html__( 'This can not be edited when the order is completed.', 'woo-coupon-usage' );
        } else {
            $referrer_note = esc_html__( 'Leave empty to use coupons applied to the order.', 'woo-coupon-usage' );
        }
        ?>
        <div class="wcusage-orderbox-field">
            <label class="wcusage-orderbox-field-label" for="wcusage_referrer_coupon">
                <span><?php 
        echo esc_html__( 'Affiliate Referrer Coupon', 'woo-coupon-usage' );
        ?></span>
                <?php 
        echo wp_kses_post( wc_help_tip( esc_html__( 'Set the primary referral coupon for this order. This will override all other settings, as the default and only coupon that will earn commission from this order.', 'woo-coupon-usage' ), false ) );
        ?>
            </label>
            <input type="text" id="wcusage_referrer_coupon" name="wcusage_referrer_coupon" class="wcusage-orderbox-input"
                value="<?php 
        echo esc_attr( $wcusage_referrer_coupon );
        ?>"
                <?php 
        if ( $referrer_placeholder ) {
            ?>placeholder="<?php 
            echo esc_attr( $referrer_placeholder );
            ?>"<?php 
        }
        ?>
                <?php 
        if ( $referrer_readonly ) {
            ?>title="<?php 
            echo esc_attr( $referrer_readonly );
            ?>" readonly<?php 
        }
        ?>>
            <?php 
        if ( $referrer_readonly ) {
            ?>
                <span class="wcusage-orderbox-note is-locked"><span class="dashicons dashicons-lock"></span><?php 
            echo esc_html( $referrer_readonly );
            ?></span>
            <?php 
        } elseif ( $referrer_note ) {
            ?>
                <span class="wcusage-orderbox-note"><?php 
            echo esc_html( $referrer_note );
            ?></span>
            <?php 
        }
        ?>
        </div>
        <?php 
    }
    do_action(
        'wcusage_hook_order_box_after_custom_referrer',
        $post_id,
        $order,
        $coupon_code,
        $lifetimeaffiliate,
        $affiliatereferrer
    );
    echo '</div>';
}

/**
 * Custom box HTML content
 *
 * @param string $coupon_code The coupon code.
 * @param object $post The post object.
 * @param object $order The order object.
 * @param int $type The type of referral (1 for lifetime, 2 for custom).
 */
function wcusage_custom_box_html_content(
    $coupon_code,
    $post,
    $order,
    $type
) {
    $order_id = $order->get_id();
    $order = wc_get_order( $order_id );
    $paidcommission = wcusage_order_meta( $order_id, 'wcu_commission_paid', true );
    $lifetimeaffiliatedone = false;
    // One card is drawn per coupon on a stacked order, and the block below walks
    // every coupon itself - without the guard a two-coupon order granted the
    // commission twice, since $paidcommission is read before the first grant.
    static $unpaid_commission_done = false;
    if ( !$unpaid_commission_done && !empty( $_GET['update_unpaid_commission'] ) ) {
        $update_nonce = ( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
        if ( wp_verify_nonce( $update_nonce, 'wcusage_update_unpaid_commission_' . $order_id ) && current_user_can( 'edit_shop_orders' ) ) {
            $unpaid_commission_done = true;
            $paidcommission = wcusage_order_meta( $order_id, 'wcu_commission_paid', true );
            $lifetimeaffiliate = wcusage_order_meta( $order_id, 'lifetime_affiliate_coupon_referrer' );
            if ( $lifetimeaffiliate && !$lifetimeaffiliatedone ) {
                wcusage_do_action_order_update_commission(
                    $order,
                    $order_id,
                    $lifetimeaffiliate,
                    $paidcommission
                );
                $lifetimeaffiliatedone = true;
            }
            if ( !$lifetimeaffiliate ) {
                $affiliatereferrer = wcusage_order_meta( $order_id, 'wcusage_referrer_coupon' );
                if ( $affiliatereferrer ) {
                    wcusage_do_action_order_update_commission(
                        $order,
                        $order_id,
                        $affiliatereferrer,
                        $paidcommission
                    );
                } else {
                    foreach ( $order->get_coupon_codes() as $order_coupon_code ) {
                        wcusage_do_action_order_update_commission(
                            $order,
                            $order_id,
                            $order_coupon_code,
                            $paidcommission
                        );
                    }
                }
            }
        }
    }
    $getinfo = wcusage_get_the_order_coupon_info( $coupon_code, "", $order_id );
    // Returns nothing at all when the coupon column is turned off in the settings.
    if ( !is_array( $getinfo ) ) {
        $getinfo = array(
            'thecommission'    => '',
            'thecommissionnum' => 0,
            'uniqueurl'        => '',
            'theuserid'        => 0,
        );
    }
    $coupon_info = wcusage_get_coupon_info( $coupon_code );
    $coupon_id = $coupon_info[2];
    $order_status = $order->get_status();
    // Check if pending commission needs to be added
    if ( function_exists( 'wcusage_check_and_add_pending_commission' ) ) {
        wcusage_check_and_add_pending_commission( $order_id );
    }
    // No amount to show, so no grant prompt or status either - the panel used to
    // offer "Grant commission" directly under "Commission: disabled for this coupon".
    $commission_disabled = $order_status == 'refunded' || wcusage_coupon_disable_commission( $coupon_id );
    // ***** Grant / deduct state *****
    $status_class = '';
    $status_label = '';
    $status_title = '';
    $grant_url = '';
    $deduct_notice = '';
    // ***** Card *****
    $card_class = 'wcusage-orderbox-card';
    $badge = '';
    if ( (int) $type === 1 ) {
        $card_class .= ' is-lifetime';
        $badge = __( 'Lifetime', 'woo-coupon-usage' );
    } elseif ( (int) $type === 2 ) {
        $card_class .= ' is-referral';
        $badge = __( 'URL / Custom', 'woo-coupon-usage' );
    }
    $applied_coupons = $order->get_coupon_codes();
    $is_applied = false;
    foreach ( $applied_coupons as $applied_coupon ) {
        if ( wcusage_coupon_codes_match( $applied_coupon, $coupon_code ) ) {
            $is_applied = true;
            break;
        }
    }
    echo '<div class="' . esc_attr( $card_class ) . '">';
    // Header: the code itself is the heading, since it is what identifies the card.
    echo '<div class="wcusage-orderbox-card-head">';
    if ( $coupon_id ) {
        echo '<a class="wcusage-orderbox-code" href="' . esc_url( admin_url( 'post.php?post=' . absint( $coupon_id ) . '&action=edit' ) ) . '" target="_blank"' . ' title="' . esc_attr__( 'Edit this coupon', 'woo-coupon-usage' ) . '">' . esc_html( $coupon_code ) . '</a>';
    } else {
        echo '<span class="wcusage-orderbox-code is-missing" title="' . esc_attr__( 'This coupon no longer exists.', 'woo-coupon-usage' ) . '">' . esc_html( $coupon_code ) . '</span>';
    }
    if ( $badge ) {
        echo '<span class="wcusage-orderbox-badge">' . esc_html( $badge ) . '</span>';
    }
    // Only offered where there is something to remove. A lifetime or URL referral
    // coupon need not be applied to the order at all, and the request for one that
    // is not would come back as "Coupon not found in order".
    if ( $coupon_id && $is_applied && ($order_status == 'processing' || $order_status == 'completed') ) {
        echo '<button type="button" class="wcusage-orderbox-remove delete-coupon"' . ' data-order-id="' . esc_attr( $order_id ) . '" data-coupon-code="' . esc_attr( $coupon_code ) . '"' . ' title="' . esc_attr__( 'Remove this coupon from the order', 'woo-coupon-usage' ) . '">' . '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' . '<span class="screen-reader-text">' . esc_html__( 'Remove this coupon from the order', 'woo-coupon-usage' ) . '</span>' . '</button>';
    }
    echo '</div>';
    // Rows
    $rows = '';
    $wcusage_affiliate_user = $coupon_info[1];
    $affiliate = ( $wcusage_affiliate_user ? get_user_by( 'ID', $wcusage_affiliate_user ) : false );
    if ( $affiliate ) {
        $affiliate_label = ( function_exists( 'wcusage_get_affiliate_text' ) ? wcusage_get_affiliate_text( __( 'Affiliate', 'woo-coupon-usage' ) ) : __( 'Affiliate', 'woo-coupon-usage' ) );
        $rows .= wcusage_order_box_row( $affiliate_label, '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . absint( $wcusage_affiliate_user ) ) ) . '" target="_blank">' . esc_html( $affiliate->user_login ) . '</a>' );
    } elseif ( $coupon_id ) {
        $rows .= wcusage_order_box_row( __( 'Affiliate', 'woo-coupon-usage' ), '<span class="wcusage-orderbox-muted">' . esc_html__( 'Not assigned', 'woo-coupon-usage' ) . '</span>' );
    }
    if ( $order_status == 'refunded' ) {
        $rows .= wcusage_order_box_row( __( 'Commission', 'woo-coupon-usage' ), '<span class="wcusage-orderbox-muted">' . esc_html__( 'None (order refunded)', 'woo-coupon-usage' ) . '</span>' );
    } elseif ( wcusage_coupon_disable_commission( $coupon_id ) ) {
        $rows .= wcusage_order_box_row( __( 'Commission', 'woo-coupon-usage' ), '<span class="wcusage-orderbox-muted">' . esc_html__( 'Disabled', 'woo-coupon-usage' ) . '</span>' );
    } else {
        // Every part of the breakdown is escaped where it is built. Passing it back
        // through wp_kses_post here would strip the trigger's tabindex, which is what
        // opens the panel for keyboard users.
        $commission_value = wcusage_get_order_commission_breakdown(
            $order_id,
            $coupon_code,
            $coupon_id,
            $type,
            wp_kses_post( $getinfo['thecommission'] )
        );
        if ( $status_label ) {
            $commission_value .= '<span class="wcusage-orderbox-status ' . esc_attr( $status_class ) . '" title="' . esc_attr( $status_title ) . '">' . esc_html( $status_label ) . '</span>';
        }
        $rows .= wcusage_order_box_row( __( 'Commission', 'woo-coupon-usage' ), $commission_value, 'is-commission' );
    }
    if ( $is_applied ) {
        $discount_amount = 0;
        foreach ( $order->get_items( 'coupon' ) as $item_id => $item ) {
            if ( $item->get_code() === $coupon_code ) {
                $discount_amount = $item->get_discount();
                break;
            }
        }
        if ( $discount_amount > 0 ) {
            $rows .= wcusage_order_box_row( __( 'Discount', 'woo-coupon-usage' ), wp_kses_post( wcusage_format_price( $discount_amount ) ) );
        } else {
            $rows .= wcusage_order_box_row( __( 'Discount', 'woo-coupon-usage' ), '<span class="wcusage-orderbox-muted">' . esc_html__( 'None (tracking only)', 'woo-coupon-usage' ) . '</span>' );
        }
    }
    echo '<div class="wcusage-orderbox-rows">' . $rows . '</div>';
    // Escaped as each row is built.
    // What this card amounts to, for the verdict chip at the top of the panel.
    // $status_class is only ever set by the premium block, so the free version
    // falls through to "referral" - it shows commission but never grants it.
    if ( $commission_disabled || !$affiliate ) {
        wcusage_order_box_states( ( $order_status == 'refunded' ? 'refunded' : 'tracked' ) );
    } elseif ( $status_class === 'is-granted' ) {
        wcusage_order_box_states( 'granted' );
    } elseif ( $status_class === 'is-pending' ) {
        wcusage_order_box_states( 'pending' );
    } elseif ( $status_class === 'is-unpaid' ) {
        wcusage_order_box_states( 'unpaid' );
    } elseif ( $status_class === 'is-void' ) {
        wcusage_order_box_states( 'void' );
    } else {
        wcusage_order_box_states( 'referral' );
    }
    // Anything that needs acting on, given its own block rather than a bare link
    // tucked in beside the amount.
    if ( $grant_url ) {
        echo '<div class="wcusage-orderbox-notice is-warning">' . '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' . '<span class="wcusage-orderbox-notice-body">' . '<span class="wcusage-orderbox-notice-text">' . esc_html__( 'This commission has not been granted to the affiliate yet.', 'woo-coupon-usage' ) . '</span>' . '<a class="button button-small wcusage-orderbox-notice-button" href="' . esc_url( $grant_url ) . '"' . ' onClick="return confirm(\'' . esc_js( sprintf( 
            /* translators: %s: commission amount */
            __( 'Give %s unpaid commission to this affiliate coupon?', 'woo-coupon-usage' ),
            wp_strip_all_tags( $getinfo['thecommission'] )
         ) ) . '\');"' . ' title="' . esc_attr__( 'Give unpaid commission to affiliate.', 'woo-coupon-usage' ) . '">' . esc_html__( 'Grant commission', 'woo-coupon-usage' ) . '</a></span></div>';
    }
    if ( !empty( $deduct_notice ) ) {
        echo $deduct_notice;
        // Escaped when built; wp_kses_post would strip the onClick confirm.
    }
    if ( !$is_applied && (int) $type === 0 ) {
        echo '<p class="wcusage-orderbox-note">' . esc_html__( 'This coupon is no longer applied to the order.', 'woo-coupon-usage' ) . '</p>';
    }
    // ***** Multi-level commission *****
    if ( wcu_fs()->can_use_premium_code() ) {
        $wcusage_field_mla_enable = wcusage_get_setting_value( 'wcusage_field_mla_enable', '0' );
        if ( $wcusage_field_mla_enable && !wcusage_coupon_disable_commission( $coupon_id ) ) {
            $get_parents = get_user_meta( $getinfo['theuserid'], 'wcu_ml_affiliate_parents', true );
            if ( !empty( $get_parents ) && is_array( $get_parents ) ) {
                // Try to read stored MLA commission from order meta (persisted at order time)
                $order_obj = wc_get_order( $order_id );
                $stored_mla_raw = ( $order_obj ? $order_obj->get_meta( 'wcu_mla_commission', true ) : '' );
                $stored_mla = ( is_string( $stored_mla_raw ) ? json_decode( $stored_mla_raw, true ) : $stored_mla_raw );
                $needs_mla_meta_save = false;
                $mla_rows = '';
                foreach ( $get_parents as $key => $parent_id ) {
                    $parent_user_info = get_user_by( 'ID', $parent_id );
                    $parent_user_name = ( $parent_user_info ? $parent_user_info->user_login : '#' . $parent_id );
                    $parent_user_id = ( $parent_user_info ? $parent_user_info->ID : $parent_id );
                    // Use stored commission if available, otherwise recalculate and flag for saving
                    if ( is_array( $stored_mla ) && isset( $stored_mla[$key]['commission'] ) ) {
                        $parent_commission = (float) $stored_mla[$key]['commission'];
                    } else {
                        $coupon_info = wcusage_get_coupon_info( $coupon_code );
                        $coupon_id = $coupon_info[2];
                        $parent_commission = wcusage_mla_get_commission_from_tier(
                            $getinfo['thecommissionnum'],
                            $key,
                            1,
                            $order_id,
                            $coupon_code,
                            0,
                            $parent_user_id
                        );
                        // Collect recalculated data so we can persist it
                        $tier_rates = ( function_exists( 'wcusage_mla_get_tier_rates' ) ? wcusage_mla_get_tier_rates( $key, $parent_user_id ) : array() );
                        if ( !is_array( $stored_mla ) ) {
                            $stored_mla = array();
                        }
                        $stored_mla[$key] = array(
                            'parent_id'  => (int) $parent_user_id,
                            'commission' => round( (float) $parent_commission, 2 ),
                            'rates'      => $tier_rates,
                        );
                        $needs_mla_meta_save = true;
                    }
                    $mla_rows .= wcusage_order_box_row( sprintf( 
                        /* translators: %s: multi-level tier number */
                        __( 'Tier %s', 'woo-coupon-usage' ),
                        $key
                     ) . ' - ' . $parent_user_name, '<a href="' . esc_url( admin_url( 'admin.php?page=wcusage_view_affiliate&user_id=' . absint( $parent_user_id ) ) ) . '" target="_blank">' . wp_kses_post( wcusage_format_price( $parent_commission ) ) . '</a>' );
                }
                if ( $mla_rows ) {
                    echo '<div class="wcusage-orderbox-sub">';
                    echo '<span class="wcusage-orderbox-sub-title">' . esc_html__( 'Multi-level commission', 'woo-coupon-usage' ) . '</span>';
                    echo '<div class="wcusage-orderbox-rows">' . $mla_rows . '</div>';
                    // Escaped as each row is built.
                    echo '</div>';
                }
                // Persist recalculated MLA data for old orders so it won't recalculate again
                if ( $needs_mla_meta_save && $order_obj && !empty( $stored_mla ) ) {
                    $order_obj->update_meta_data( 'wcu_mla_commission', json_encode( $stored_mla ) );
                    $order_obj->save_meta_data();
                }
            }
        }
    }
    // Card actions
    if ( !empty( $getinfo['uniqueurl'] ) ) {
        echo '<div class="wcusage-orderbox-actions">';
        echo '<a class="wcusage-orderbox-action" href="' . esc_url( $getinfo['uniqueurl'] ) . '" target="_blank"' . ' title="' . esc_attr__( 'View the affiliate dashboard for this affiliate coupon.', 'woo-coupon-usage' ) . '">' . '<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>' . esc_html__( 'View dashboard', 'woo-coupon-usage' ) . '</a>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Gets the current commission for an order/coupon, including partial refunds
 * that were not allocated to line items (amount-only refunds), which the
 * per-line-item calculation cannot see.
 *
 * @param int $order_id The order ID.
 * @param string $coupon_code The coupon code.
 *
 * @return float
 */
if ( !function_exists( 'wcusage_get_order_commission_after_refunds' ) ) {
    function wcusage_get_order_commission_after_refunds(  $order_id, $coupon_code  ) {
        $orderdata = wcusage_calculate_order_data(
            $order_id,
            $coupon_code,
            1,
            0,
            1
        );
        $commission = ( isset( $orderdata['totalcommission'] ) ? (float) $orderdata['totalcommission'] : 0 );
        $order = wc_get_order( $order_id );
        if ( !$order || !is_a( $order, 'WC_Order' ) ) {
            return $commission;
        }
        // Refund amounts with no line items allocated are invisible to the
        // per-line calculation, so deduct them at the order's percentage rate.
        $unallocated = 0;
        foreach ( $order->get_refunds() as $refund ) {
            $allocated = 0;
            foreach ( $refund->get_items( array('line_item', 'shipping', 'fee') ) as $refund_item ) {
                $allocated += abs( (float) $refund_item->get_total() );
            }
            $allocated += abs( (float) $refund->get_total_tax() );
            $refund_total = abs( (float) $refund->get_total() );
            if ( $refund_total > $allocated + 0.001 ) {
                $unallocated += $refund_total - $allocated;
            }
        }
        if ( $unallocated > 0 ) {
            $percent = ( isset( $orderdata['commissionpercentage'] ) && is_numeric( $orderdata['commissionpercentage'] ) ? (float) $orderdata['commissionpercentage'] : 0 );
            if ( $percent > 0 ) {
                $enablecurrency = wcusage_get_setting_value( 'wcusage_field_enable_currency', '0' );
                if ( $enablecurrency && $order->get_currency() ) {
                    $saved_rate = wcusage_order_meta( $order_id, 'wcusage_currency_conversion', true );
                    $enable_save_rate = wcusage_get_setting_value( 'wcusage_field_enable_currency_save_rate', '0' );
                    if ( !$saved_rate || !$enable_save_rate ) {
                        $saved_rate = "";
                    }
                    $unallocated = wcusage_calculate_currency( $order->get_currency(), $unallocated, $saved_rate );
                }
                $deduct_percent = wcusage_get_setting_value( 'wcusage_field_affiliate_deduct_percent', '0' );
                $deduct_percent = (100 - (float) $deduct_percent) / 100;
                $commission -= $unallocated * ($percent / 100) * $deduct_percent;
            }
        }
        if ( $commission < 0 ) {
            $commission = 0;
        }
        return (float) wcusage_round_commission_amount( $commission, 2 );
    }

}
/**
 * Save the custom meta box data
 *
 * @param int $post_id The ID of the post being saved.
 */
function wcusage_save_postdata(  $post_id  ) {
    if ( array_key_exists( 'wcusage_field', $_POST ) ) {
        update_post_meta( $post_id, '_wcusage_meta_key', sanitize_text_field( $_POST['wcusage_field'] ) );
    }
}

add_action( 'save_post', 'wcusage_save_postdata' );
/**
 * Save the custom meta box data
 *
 * @param int $post_id The ID of the post being saved.
 */
function wcusage_wcusage_referrer_coupon_meta_box_save(  $post_id  ) {
    if ( !isset( $_POST['wcusage_referrer_coupon_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcusage_referrer_coupon_nonce'] ) ), basename( __FILE__ ) ) ) {
        return;
    }
    if ( !current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    $coupon_code = '';
    if ( isset( $_POST['wcusage_referrer_coupon'] ) ) {
        $coupon_code = $_POST['wcusage_referrer_coupon'];
    }
    $coupon_id = wc_get_coupon_id_by_code( $coupon_code );
    $wcusage_referrer_coupon = ( isset( $_POST['wcusage_referrer_coupon'] ) ? sanitize_text_field( $_POST['wcusage_referrer_coupon'] ) : '' );
    $wcusage_referrer_coupon_old = wcusage_order_meta( $post_id, 'wcusage_referrer_coupon', true );
    if ( $coupon_code && !$coupon_id ) {
        echo '<div class="error"><p>' . esc_html__( 'The coupon code you entered does not exist.', 'woo-coupon-usage' ) . '</p></div>';
        return;
    }
    $meta_data = [];
    $meta_data['wcusage_referrer_coupon'] = $wcusage_referrer_coupon;
    if ( !$wcusage_referrer_coupon_old && $wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
    }
    if ( $wcusage_referrer_coupon_old && !$wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
        $meta_data['wcusage_referrer_refresh_prev'] = $wcusage_referrer_coupon_old;
    }
    if ( $wcusage_referrer_coupon_old && $wcusage_referrer_coupon && $wcusage_referrer_coupon_old != $wcusage_referrer_coupon ) {
        $meta_data['wcusage_referrer_refresh'] = 1;
        $meta_data['wcusage_referrer_refresh_prev'] = $wcusage_referrer_coupon_old;
    }
    $wcusage_field_enable_coupon_all_stats_meta = wcusage_get_setting_value( 'wcusage_field_enable_coupon_all_stats_meta', '1' );
    if ( $wcusage_field_enable_coupon_all_stats_meta ) {
        $order = wc_get_order( $post_id );
        if ( version_compare( WC_VERSION, 3.7, ">=" ) ) {
            $coupons_array = $order->get_coupon_codes();
        } else {
            $coupons_array = $order->get_used_coupons();
        }
        if ( $wcusage_referrer_coupon_old != $wcusage_referrer_coupon ) {
            if ( $wcusage_referrer_coupon ) {
                do_action(
                    'wcusage_hook_update_all_stats_single',
                    $wcusage_referrer_coupon,
                    $post_id,
                    1,
                    1
                );
            } else {
                foreach ( $coupons_array as $this_coupon_code ) {
                    do_action(
                        'wcusage_hook_update_all_stats_single',
                        $this_coupon_code,
                        $post_id,
                        1,
                        1
                    );
                }
            }
            if ( $wcusage_referrer_coupon_old ) {
                do_action(
                    'wcusage_hook_update_all_stats_single',
                    $wcusage_referrer_coupon_old,
                    $post_id,
                    0,
                    1
                );
            } else {
                foreach ( $coupons_array as $this_coupon_code ) {
                    do_action(
                        'wcusage_hook_update_all_stats_single',
                        $this_coupon_code,
                        $post_id,
                        0,
                        1
                    );
                }
            }
        }
    }
    if ( !empty( $meta_data ) ) {
        wcusage_update_order_meta_bulk( $post_id, $meta_data );
    }
}

add_action( 'woocommerce_process_shop_order_meta', 'wcusage_wcusage_referrer_coupon_meta_box_save' );
/*
 * Add a link to add a coupon below the coupons in the order edit page
 */
add_action(
    'wcusage_hook_order_box_after_custom_referrer',
    'add_coupon_link_below_coupons',
    10,
    1
);
function add_coupon_link_below_coupons(  $order_id  ) {
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $order_status = $order->get_status();
    $wcusage_referrer_coupon = wcusage_order_meta( $order_id, 'wcusage_referrer_coupon', true );
    $show_add_form = ($order_status == 'completed' || $order_status == 'processing') && !$wcusage_referrer_coupon;
    ?>

    <?php 
    if ( $show_add_form ) {
        ?>
    <div class="wcusage-orderbox-add">
        <a href="#" class="wcusage-orderbox-action add-coupon-link" aria-expanded="false">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php 
        echo esc_html__( 'Add a referrer coupon', 'woo-coupon-usage' );
        ?>
        </a>
        <div class="wcusage-orderbox-addform add-coupon-form" style="display: none;">
            <p><?php 
        echo sprintf( esc_html__( 'Add a coupon to this order for tracking. Since the order is already %s, the coupon will be added with a zero discount.', 'woo-coupon-usage' ), esc_html( $order_status ) );
        ?>
            <?php 
        if ( $order_status == 'completed' ) {
            ?> <?php 
            echo esc_html__( 'Unpaid commission will also NOT be automatically granted to this affiliate coupon and should be done manually.', 'woo-coupon-usage' );
        }
        ?></p>
            <div class="wcusage-orderbox-addrow">
                <label class="screen-reader-text" for="add_coupon_code"><?php 
        echo esc_html__( 'Coupon code', 'woo-coupon-usage' );
        ?></label>
                <input type="text" id="add_coupon_code" name="add_coupon_code" placeholder="<?php 
        echo esc_attr__( 'Coupon code', 'woo-coupon-usage' );
        ?>" />
                <button type="button" class="button button-small add-coupon-to-order"><?php 
        echo esc_html__( 'Add', 'woo-coupon-usage' );
        ?></button>
            </div>
        </div>
    </div>
    <?php 
    }
    ?>

    <script>
        jQuery(document).ready(function($) {
            $('.add-coupon-link').on('click', function(e) {
                e.preventDefault();
                var $form = $('.add-coupon-form');
                var opening = !$form.is(':visible');
                $form.slideToggle(120);
                $(this).attr('aria-expanded', opening ? 'true' : 'false');
                if (opening) {
                    $('#add_coupon_code').trigger('focus');
                }
            });

            $('.add-coupon-to-order').on('click', function() {
                var $button = $(this);
                var couponCode = $('#add_coupon_code').val();
                if (!couponCode) {
                    $('#add_coupon_code').trigger('focus');
                    return;
                }

                $button.data('label', $button.text()).text('<?php 
    echo esc_js( __( 'Adding...', 'woo-coupon-usage' ) );
    ?>').prop('disabled', true);

                $.ajax({
                    url: '<?php 
    echo esc_url( admin_url( 'admin-ajax.php' ) );
    ?>',
                    type: 'POST',
                    data: {
                        action: 'add_coupon_to_order',
                        order_id: '<?php 
    echo esc_js( $order->get_id() );
    ?>',
                        coupon_code: couponCode,
                        security: '<?php 
    echo esc_js( wp_create_nonce( 'add_coupon_nonce' ) );
    ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            $button.text($button.data('label')).prop('disabled', false);
                            window.alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        $button.text($button.data('label')).prop('disabled', false);
                        window.alert('<?php 
    echo esc_js( __( 'Error adding coupon.', 'woo-coupon-usage' ) );
    ?>');
                    }
                });
            });

            $('.delete-coupon').on('click', function() {
                var $button = $(this);
                var couponCode = $button.data('coupon-code');
                var orderId = $button.data('order-id');

                if (confirm('<?php 
    echo esc_js( esc_html__( 'Are you sure you want to remove this coupon from the order?', 'woo-coupon-usage' ) );
    ?> - <?php 
    echo esc_js( esc_html__( 'This will NOT affect the discount that has already been applied unless you recalculate the order.', 'woo-coupon-usage' ) );
    if ( $order_status == 'completed' && wcu_fs()->can_use_premium_code() ) {
        ?> <?php 
        echo esc_js( esc_html__( 'This will only affect the affiliate dashboard statistics. Any unpaid commission already granted will NOT be deducted.', 'woo-coupon-usage' ) );
    }
    ?>')) {
                    $button.prop('disabled', true).addClass('is-busy');
                    $.ajax({
                        url: '<?php 
    echo esc_url( admin_url( 'admin-ajax.php' ) );
    ?>',
                        type: 'POST',
                        data: {
                            action: 'remove_coupon_from_order',
                            order_id: orderId,
                            coupon_code: couponCode,
                            security: '<?php 
    echo esc_js( wp_create_nonce( 'remove_coupon_nonce' ) );
    ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                $button.prop('disabled', false).removeClass('is-busy');
                                window.alert('Error: ' + response.data.message);
                            }
                        },
                        error: function() {
                            $button.prop('disabled', false).removeClass('is-busy');
                            window.alert('<?php 
    echo esc_js( __( 'Error removing coupon.', 'woo-coupon-usage' ) );
    ?>');
                        }
                    });
                }
            });
        });
    </script>
    <?php 
}

add_action( 'wp_ajax_add_coupon_to_order', 'handle_add_coupon_to_order' );
function handle_add_coupon_to_order() {
    check_ajax_referer( 'add_coupon_nonce', 'security' );
    // Adding a coupon to an order bumps its usage count and re-runs the commission
    // calculation, i.e. it credits an affiliate. Gate it on the same capability that
    // gates the order edit screen this button lives on.
    if ( !current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ),
        ] );
    }
    $order_id = ( isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0 );
    $coupon_code = ( isset( $_POST['coupon_code'] ) ? sanitize_text_field( $_POST['coupon_code'] ) : '' );
    if ( !$order_id || !$coupon_code ) {
        wp_send_json_error( [
            'message' => 'Invalid order ID or coupon code.',
        ] );
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        wp_send_json_error( [
            'message' => 'Order not found.',
        ] );
    }
    $coupon = new WC_Coupon($coupon_code);
    if ( !$coupon->get_id() ) {
        wp_send_json_error( [
            'message' => 'Coupon code does not exist.',
        ] );
    }
    $existing_coupons = $order->get_coupon_codes();
    if ( in_array( $coupon_code, $existing_coupons ) ) {
        wp_send_json_error( [
            'message' => 'Coupon already added to this order.',
        ] );
    }
    $coupon_item = new WC_Order_Item_Coupon();
    $coupon_item->set_props( [
        'code'         => $coupon_code,
        'discount'     => 0,
        'discount_tax' => 0,
    ] );
    $order->add_item( $coupon_item );
    $order->save();
    do_action(
        'wcusage_hook_update_all_stats_single',
        $coupon_code,
        $order_id,
        1,
        1
    );
    $coupon->increase_usage_count();
    wp_send_json_success( [
        'message' => 'Coupon added to order.',
    ] );
}

add_action( 'wp_ajax_remove_coupon_from_order', 'handle_remove_coupon_from_order' );
function handle_remove_coupon_from_order() {
    check_ajax_referer( 'remove_coupon_nonce', 'security' );
    // Removing a coupon reverses the affiliate's commission for this order - same
    // capability requirement as adding one.
    if ( !current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'You do not have permission to do this.', 'woo-coupon-usage' ),
        ] );
    }
    $order_id = ( isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0 );
    $coupon_code = ( isset( $_POST['coupon_code'] ) ? sanitize_text_field( $_POST['coupon_code'] ) : '' );
    if ( !$order_id || !$coupon_code ) {
        wp_send_json_error( [
            'message' => 'Invalid order ID or coupon code.',
        ] );
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        wp_send_json_error( [
            'message' => 'Order not found.',
        ] );
    }
    $order_status = $order->get_status();
    if ( $order_status != 'processing' && $order_status != 'completed' ) {
        wp_send_json_error( [
            'message' => 'Coupon can only be removed from processing or completed orders.',
        ] );
    }
    $existing_coupons = $order->get_items( 'coupon' );
    $coupon_found = false;
    foreach ( $existing_coupons as $item_id => $item ) {
        if ( strtolower( $item->get_code() ) === strtolower( $coupon_code ) ) {
            do_action(
                'wcusage_hook_update_all_stats_single',
                $coupon_code,
                $order_id,
                0,
                1
            );
            $order->remove_item( $item_id );
            $coupon_found = true;
            break;
        }
    }
    if ( !$coupon_found ) {
        wp_send_json_error( [
            'message' => 'Coupon not found in order.',
        ] );
    }
    $order->save();
    wp_send_json_success( [
        'message' => 'Coupon removed from order.',
    ] );
}

/**
 * Loads the shared admin styles on the order edit screen.
 *
 * The commission breakdown tooltip reuses the "Commission Levels" tooltip styles
 * from the coupons list, which were previously only loaded on the plugin's own
 * admin pages.
 */
function wcusage_enqueue_order_box_styles() {
    if ( !function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( !$screen || !isset( $screen->id ) ) {
        return;
    }
    $order_screen = ( function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order' );
    if ( $screen->id !== $order_screen && $screen->id !== 'shop_order' ) {
        return;
    }
    $css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-coupons.css';
    $css_ver = ( file_exists( $css_path ) ? filemtime( $css_path ) : WCUSAGE_VERSION );
    wp_enqueue_style(
        'wcusage-admin-coupons',
        WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-coupons.css',
        array(),
        $css_ver
    );
    // The panel itself. Kept out of admin-coupons.css, which is also loaded on the
    // coupons list and the affiliate view, where none of it applies.
    $box_css_path = WCUSAGE_UNIQUE_PLUGIN_PATH . 'css/admin-order-box.css';
    $box_css_ver = ( file_exists( $box_css_path ) ? filemtime( $box_css_path ) : WCUSAGE_VERSION );
    wp_enqueue_style(
        'wcusage-admin-order-box',
        WCUSAGE_UNIQUE_PLUGIN_URL . 'css/admin-order-box.css',
        array('buttons', 'wcusage-admin-coupons'),
        $box_css_ver
    );
    wp_enqueue_style( 'dashicons' );
}

add_action( 'admin_enqueue_scripts', 'wcusage_enqueue_order_box_styles' );
/**
 * One row of the commission breakdown tooltip.
 *
 * @param string $label
 * @param string $value Already-formatted HTML.
 * @param string $class Extra class for the row.
 *
 * @return string
 *
 */
if ( !function_exists( 'wcusage_commission_breakdown_row' ) ) {
    function wcusage_commission_breakdown_row(  $label, $value, $class = ''  ) {
        return '<span class="' . esc_attr( trim( 'wcusage-rate-tooltip-row is-plain ' . $class ) ) . '">' . '<span class="wcusage-rate-tooltip-level">' . esc_html( $label ) . '</span>' . '<span class="wcusage-rate-tooltip-value">' . wp_kses_post( $value ) . '</span>' . '</span>';
    }

}
/**
 * Wraps an order's commission amount in a hover panel explaining exactly where
 * that figure came from: which coupon earned it and how it was attributed, which
 * of the configured rates won, what each line of the order contributed, and how
 * the parts add up to the saved total.
 *
 * Everything is read from what was saved against the order at calculation time
 * ("wcusage_commission_summary" and the three commission keys), so the panel
 * explains the figure actually on screen rather than recalculating a second
 * opinion that could disagree with it.
 *
 * @param int    $order_id
 * @param string $coupon_code
 * @param int    $coupon_id
 * @param int    $type        1 = lifetime, 2 = custom/URL referral, otherwise a coupon on the order.
 * @param string $amount_html The formatted commission amount to wrap.
 *
 * @return string
 *
 */
if ( !function_exists( 'wcusage_get_order_commission_breakdown' ) ) {
    function wcusage_get_order_commission_breakdown(
        $order_id,
        $coupon_code,
        $coupon_id,
        $type,
        $amount_html
    ) {
        $order = wc_get_order( $order_id );
        if ( !$order instanceof WC_Order ) {
            return $amount_html;
        }
        $panel = '';
        // The figure on screen is the order's saved commission, which only one coupon
        // authors even when several are stacked on the order - and the metabox prints
        // an entry per coupon. So the panel describes the coupon that actually earned
        // it, and says so when that is not the coupon this entry is listed under.
        $owner_code = ( function_exists( 'wcusage_get_order_commission_coupon' ) ? wcusage_get_order_commission_coupon( $order_id ) : '' );
        if ( !$owner_code ) {
            $owner_code = $coupon_code;
        }
        $is_owner = wcusage_coupon_codes_match( $owner_code, $coupon_code );
        if ( !$is_owner ) {
            $owner_info = wcusage_get_coupon_info( $owner_code );
            $coupon_id = ( isset( $owner_info[2] ) ? $owner_info[2] : 0 );
        }
        // ***** Where this commission was attributed *****
        $order_coupons = $order->get_coupon_codes();
        $on_order = false;
        foreach ( $order_coupons as $order_coupon_code ) {
            if ( wcusage_coupon_codes_match( $order_coupon_code, $owner_code ) ) {
                $on_order = true;
                break;
            }
        }
        if ( (int) $type === 1 ) {
            $attribution = __( 'Lifetime commission', 'woo-coupon-usage' );
        } elseif ( (int) $type === 2 ) {
            $attribution = __( 'Referral URL / custom referrer', 'woo-coupon-usage' );
        } else {
            $attribution = __( 'Coupon used on this order', 'woo-coupon-usage' );
        }
        $panel .= '<span class="wcusage-rate-tooltip-section">';
        $panel .= '<span class="wcusage-rate-tooltip-section-title">' . esc_html__( 'Earned by', 'woo-coupon-usage' ) . '</span>';
        $panel .= wcusage_commission_breakdown_row( __( 'Coupon', 'woo-coupon-usage' ), '<code>' . esc_html( $owner_code ) . '</code>' );
        $panel .= wcusage_commission_breakdown_row( __( 'Attributed by', 'woo-coupon-usage' ), esc_html( $attribution ) );
        $coupon_user_id = ( $coupon_id ? get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) : '' );
        $affiliate = ( $coupon_user_id ? get_userdata( $coupon_user_id ) : false );
        if ( $affiliate ) {
            $panel .= wcusage_commission_breakdown_row( __( 'Affiliate', 'woo-coupon-usage' ), esc_html( $affiliate->user_login ) );
        }
        // The rate comes from the coupon above, which is not always one the customer
        // actually entered - a referral link sets the referrer to its own coupon. Saying
        // so here is the difference between "the rate is wrong" and "the rate came from
        // a different coupon than the one you were looking at".
        if ( !$is_owner ) {
            $panel .= '<span class="wcusage-rate-tooltip-empty">' . sprintf( 
                /* translators: %s: the coupon code that earns the order's commission */
                esc_html__( 'The commission for this order is earned by %s, so its rates are the ones shown below.', 'woo-coupon-usage' ),
                esc_html( $owner_code )
             ) . '</span>';
        }
        if ( !$on_order ) {
            $applied = ( !empty( $order_coupons ) ? implode( ', ', $order_coupons ) : __( 'none', 'woo-coupon-usage' ) );
            $panel .= '<span class="wcusage-rate-tooltip-empty">' . sprintf( 
                /* translators: %s: the coupon codes applied to the order */
                esc_html__( 'This coupon was not applied to the order. Coupons used: %s', 'woo-coupon-usage' ),
                esc_html( $applied )
             ) . '</span>';
        }
        $panel .= '</span>';
        // ***** Which configured rate won *****
        // The same resolution, and the same "Using / Overridden" levels, as the
        // "Commission Levels" tooltip on the coupons list.
        if ( $coupon_id && function_exists( 'wcusage_coupons_get_rate_details' ) ) {
            $rate_details = wcusage_coupons_get_rate_details( $coupon_id );
            if ( !empty( $rate_details['sections'] ) ) {
                foreach ( $rate_details['sections'] as $section ) {
                    $panel .= '<span class="wcusage-rate-tooltip-section">';
                    $panel .= '<span class="wcusage-rate-tooltip-section-title">' . sprintf( 
                        /* translators: %s: rate component name, e.g. "Percent" */
                        esc_html__( 'Rate: %s', 'woo-coupon-usage' ),
                        esc_html( $section['label'] )
                     ) . '</span>';
                    foreach ( $section['rows'] as $row ) {
                        $row_class = ( $row['active'] ? ' is-active' : ' is-overridden' );
                        $status = ( $row['active'] ? esc_html__( 'Using', 'woo-coupon-usage' ) : esc_html__( 'Overridden', 'woo-coupon-usage' ) );
                        $panel .= '<span class="wcusage-rate-tooltip-row' . esc_attr( $row_class ) . '">';
                        $panel .= '<span class="wcusage-rate-tooltip-level">' . esc_html( $row['level'] ) . '</span>';
                        $panel .= '<span class="wcusage-rate-tooltip-value">' . esc_html( $row['value'] ) . '</span>';
                        $panel .= '<span class="wcusage-rate-tooltip-status">' . esc_html( $status ) . '</span>';
                        $panel .= '</span>';
                    }
                    $panel .= '</span>';
                }
            }
        }
        // ***** What each line of the order contributed *****
        $summary = wcusage_order_meta( $order_id, 'wcusage_commission_summary', true );
        if ( is_array( $summary ) && !empty( $summary ) ) {
            $before_discount = wcusage_get_setting_value( 'wcusage_field_commission_before_discount', '0' );
            $lines = '';
            foreach ( $summary as $key => $value ) {
                $line_commission = ( isset( $value['commission'] ) ? (float) $value['commission'] : 0 );
                if ( is_numeric( $key ) ) {
                    // A product line, named as it is on the order and showing the value
                    // the percentage was worked out on so the arithmetic can be followed.
                    $product = wc_get_product( $key );
                    $label = ( $product ? $product->get_name() : get_the_title( $key ) );
                    if ( !$label ) {
                        $label = '#' . $key;
                    }
                    if ( !empty( $value['number'] ) && (int) $value['number'] > 1 ) {
                        $label .= ' x' . (int) $value['number'];
                    }
                    $line_base = ( $before_discount ? ( isset( $value['subtotal'] ) ? (float) $value['subtotal'] : 0 ) : (( isset( $value['total'] ) ? (float) $value['total'] : 0 )) );
                    $line_value = wcusage_format_price( $line_commission );
                    if ( $line_base ) {
                        $line_value = '<span class="wcusage-breakdown-base">' . wcusage_format_price( $line_base ) . ' &rarr; </span>' . $line_value;
                    }
                    $lines .= wcusage_commission_breakdown_row( $label, $line_value );
                } else {
                    // "Fees", "Custom Discounts", "Shipping", "Store Credit" - the
                    // adjustments the calculation adds to or takes off the order overall.
                    if ( !$line_commission ) {
                        continue;
                    }
                    $lines .= wcusage_commission_breakdown_row( $key, wcusage_format_price( $line_commission ), ( $line_commission < 0 ? 'is-negative' : '' ) );
                }
            }
            if ( $lines ) {
                $panel .= '<span class="wcusage-rate-tooltip-section">';
                $panel .= '<span class="wcusage-rate-tooltip-section-title">' . esc_html__( 'Order lines', 'woo-coupon-usage' ) . '</span>';
                $panel .= $lines;
                $panel .= '</span>';
            }
        }
        // ***** How the parts add up *****
        $percent_part = (float) wcusage_order_meta( $order_id, 'wcusage_total_commission', true );
        $fixed_order_part = (float) wcusage_order_meta( $order_id, 'wcusage_fixed_order_commission', true );
        $fixed_product_part = (float) wcusage_order_meta( $order_id, 'wcusage_product_commission', true );
        $total = wcusage_get_order_saved_commission( $order_id );
        $totals = '';
        if ( $percent_part ) {
            $totals .= wcusage_commission_breakdown_row( __( 'Percentage', 'woo-coupon-usage' ), wcusage_format_price( $percent_part ) );
        }
        if ( $fixed_order_part ) {
            $totals .= wcusage_commission_breakdown_row( __( 'Fixed per order', 'woo-coupon-usage' ), wcusage_format_price( $fixed_order_part ) );
        }
        if ( $fixed_product_part ) {
            $totals .= wcusage_commission_breakdown_row( __( 'Fixed per product', 'woo-coupon-usage' ), wcusage_format_price( $fixed_product_part ) );
        }
        // Only worth naming the cap when it actually bit.
        $max_commission = wcusage_get_setting_value( 'wcusage_field_order_max_commission', '' );
        if ( $max_commission && $percent_part + $fixed_order_part + $fixed_product_part > (float) $max_commission ) {
            $totals .= wcusage_commission_breakdown_row( __( 'Capped at maximum', 'woo-coupon-usage' ), wcusage_format_price( $max_commission ), 'is-negative' );
        }
        // What the panel has added up is the CALCULATED commission. Once an amount has
        // been granted to the affiliate the metabox shows that granted figure instead,
        // and the two can differ - after a partial refund, or where the order was
        // recalculated after the grant. Showing both is the point of the panel: the
        // number being hovered is always the last row.
        $granted = $order->get_meta( 'wcu_commission_paid' );
        $has_granted = $granted !== '' && $granted !== null;
        $granted_differs = $has_granted && abs( (float) $granted - (float) $total ) >= 0.005;
        $totals .= wcusage_commission_breakdown_row( ( $granted_differs ? __( 'Calculated', 'woo-coupon-usage' ) : __( 'Total', 'woo-coupon-usage' ) ), wcusage_format_price( $total ), ( $granted_differs ? '' : 'is-total' ) );
        if ( $granted_differs ) {
            $totals .= wcusage_commission_breakdown_row( __( 'Granted to affiliate', 'woo-coupon-usage' ), wcusage_format_price( $granted ), 'is-total' );
        }
        $panel .= '<span class="wcusage-rate-tooltip-section">';
        $panel .= '<span class="wcusage-rate-tooltip-section-title">' . esc_html__( 'Total', 'woo-coupon-usage' ) . '</span>';
        $panel .= $totals;
        $panel .= '</span>';
        // ***** Wrap the amount *****
        // One entry per coupon is printed for a stacked order, so the id has to vary
        // with the coupon or aria-describedby would point at a duplicate element.
        $panel_id = 'wcusage-commission-breakdown-' . absint( $order_id ) . '-' . substr( md5( strtolower( (string) $coupon_code ) ), 0, 8 );
        $output = '<span class="wcusage-rate-tooltip wcusage-rate-tooltip--orderbox">';
        $output .= '<span class="wcusage-commission-trigger" tabindex="0" aria-describedby="' . esc_attr( $panel_id ) . '">' . $amount_html . '</span>';
        $output .= '<span class="wcusage-rate-tooltip-panel" id="' . esc_attr( $panel_id ) . '" role="tooltip">';
        $output .= '<span class="wcusage-rate-tooltip-title">' . esc_html__( 'Commission Breakdown', 'woo-coupon-usage' ) . '</span>';
        $output .= $panel;
        $output .= '</span>';
        $output .= '</span>';
        return $output;
    }

}