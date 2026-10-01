<?php

/**
 * Coupon Affiliates - Abilities - Actions the REST API does not offer.
 *
 * Most abilities call a REST API v2 controller method directly. The ones
 * here have no REST endpoint, so they get a controller of their own, built
 * the same way: handler methods that take a WP_REST_Request and return data
 * or a WP_Error, and the permission callbacks every REST API controller
 * shares. The dispatcher then treats them exactly like the others - the
 * same permission layers, rate limits, output checks and activity log.
 *
 * Each handler goes through the functions the admin screens use, so the
 * emails, roles, hooks and activity log entries match what an admin doing
 * the same thing by hand would get.
 *
 * @package WooCouponUsage\Abilities
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WCUsage_Abilities_Controller' ) ) {
    /**
     * Handlers for the abilities without a REST endpoint.
     */
    class WCUsage_Abilities_Controller extends WCUsage_API_Controller {
        /**
         * Emails handed to the mailer since counting started.
         *
         * @var int
         */
        protected $emails_sent = 0;

        /**
         * No routes: these handlers are only reached through abilities.
         */
        public function register_routes() {
        }

        /**
         * Start or stop counting the emails the plugin sends.
         *
         * Whether an email goes out depends on the store's email settings as
         * well as on send_email, so results report what was actually handed
         * to the mailer rather than what was asked for - an agent should not
         * tell someone an email was sent when that email is switched off.
         *
         * @param bool $on Start (true) or stop (false).
         */
        protected function count_emails( $on ) {
            if ( $on ) {
                $this->emails_sent = 0;
                add_filter( 'wp_mail', array($this, 'count_email'), PHP_INT_MAX );
            } else {
                remove_filter( 'wp_mail', array($this, 'count_email'), PHP_INT_MAX );
            }
        }

        /**
         * "wp_mail" filter: count one email, changing nothing.
         *
         * @param array $atts wp_mail() arguments.
         *
         * @return array
         */
        public function count_email( $atts ) {
            ++$this->emails_sent;
            return $atts;
        }

        /*
         * ------------------------------------------------------------------
         * Referral links
         * ------------------------------------------------------------------
         */
        /**
         * Build a referral link for a coupon.
         *
         * The same link the affiliate dashboard's referral URL generator
         * builds: the landing page, then the coupon parameter, then the
         * campaign parameter. The store's default landing page goes through
         * wcusage_get_affiliate_url(), so vanity and shortened links set up
         * with its filter come back here too.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_referral_link( $request ) {
            $coupon_id = wcusage_api_get_valid_coupon_id( $request['coupon_id'] );
            if ( !$coupon_id ) {
                return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
            }
            $code = (string) get_post_field( 'post_title', $coupon_id, 'raw' );
            $page_url = trim( (string) $request['page_url'] );
            $product_id = absint( $request['product_id'] );
            $campaign = trim( (string) $request['campaign'] );
            if ( '' !== $page_url && $product_id ) {
                return wcusage_abilities_error( 'invalid_parameters', __( 'Pass page_url or product_id, not both.', 'woo-coupon-usage' ), 400 );
            }
            $landing = '';
            if ( $product_id ) {
                // Published products only: a link to a draft or private one
                // would send visitors to a page they cannot see.
                if ( 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
                    return wcusage_abilities_error( 'product_not_found', __( 'Product not found, or not published.', 'woo-coupon-usage' ), 404 );
                }
                $landing = (string) get_permalink( $product_id );
            } elseif ( '' !== $page_url ) {
                // A path on its own, such as "/shop/", is a page on this store.
                if ( '/' === substr( $page_url, 0, 1 ) && '//' !== substr( $page_url, 0, 2 ) ) {
                    $page_url = home_url( $page_url );
                }
                // A link to another site would never reach this one, so no
                // referral could be tracked through it.
                if ( !$this->is_store_url( $page_url ) ) {
                    return wcusage_abilities_error( 
                        'invalid_url',
                        /* translators: %s: the store's home URL */
                        sprintf( __( 'page_url must be a page on this store (%s).', 'woo-coupon-usage' ), home_url( '/' ) ),
                        400
                     );
                }
                $landing = $page_url;
                // The dashboard's generator adds the trailing slash pretty
                // permalinks use, so the link matches the one it would give.
                if ( get_option( 'permalink_structure' ) && apply_filters( 'wcusage_url_include_slash', true ) && false === strpos( $landing, '?' ) && false === strpos( $landing, '#' ) ) {
                    $landing = trailingslashit( $landing );
                }
            }
            if ( '' === $landing ) {
                $url = wcusage_get_affiliate_url( $code );
                $landing = wcusage_get_default_ref_url();
            } else {
                $url = $this->add_query_param( $landing, wcusage_get_setting_value( 'wcusage_field_urls_prefix', 'coupon' ), $code );
            }
            if ( '' !== $campaign ) {
                $url = $this->add_query_param( $url, wcusage_get_setting_value( 'wcusage_field_src_prefix', 'src' ), $campaign );
            }
            return rest_ensure_response( array(
                'coupon_id'    => $coupon_id,
                'code'         => sanitize_text_field( $code ),
                'url'          => esc_url_raw( $url ),
                'landing_page' => esc_url_raw( $landing ),
                'campaign'     => ( '' !== $campaign ? $campaign : null ),
            ) );
        }

        /**
         * Whether a URL is on this store.
         *
         * Accepts the site's own host and the host of the default referral
         * URL, which a store may point at a different domain that still
         * reaches it. "www." is ignored either way, as the plugin does
         * everywhere else it compares the two.
         *
         * @param string $url URL.
         *
         * @return bool
         */
        protected function is_store_url( $url ) {
            $parts = wp_parse_url( $url );
            if ( !is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || !in_array( strtolower( $parts['scheme'] ), array('http', 'https'), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
                return false;
            }
            $allowed = array($this->normalise_host( wp_parse_url( home_url(), PHP_URL_HOST ) ), $this->normalise_host( wp_parse_url( wcusage_get_default_ref_url(), PHP_URL_HOST ) ));
            return in_array( $this->normalise_host( $parts['host'] ), array_filter( $allowed ), true );
        }

        /**
         * Lower-case a host and drop a leading "www.".
         *
         * @param string|null $host Host.
         *
         * @return string
         */
        protected function normalise_host( $host ) {
            return preg_replace( '/^www\\./', '', strtolower( (string) $host ) );
        }

        /**
         * Add a query parameter to a URL, before any "#fragment".
         *
         * @param string $url   URL.
         * @param string $key   Parameter name.
         * @param string $value Parameter value.
         *
         * @return string
         */
        protected function add_query_param( $url, $key, $value ) {
            $fragment = '';
            $hash = strpos( $url, '#' );
            if ( false !== $hash ) {
                $fragment = substr( $url, $hash );
                $url = substr( $url, 0, $hash );
            }
            $separator = ( false !== strpos( $url, '?' ) ? '&' : '?' );
            return $url . $separator . $key . '=' . rawurlencode( $value ) . $fragment;
        }

        /*
         * ------------------------------------------------------------------
         * Orders
         * ------------------------------------------------------------------
         */
        /**
         * Who, if anyone, an order is credited to.
         *
         * Resolved by wcusage_get_order_commission_coupon(), the same rule
         * every commission calculation uses: the lifetime referrer, then a
         * referrer coupon (set by a referral link, or by an admin on the
         * order), then the first coupon on the order with an affiliate. No
         * customer details are returned.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function get_order_referral( $request ) {
            $order = ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $request['order_id'] ) ) : false );
            // Refunds are orders of their own in WooCommerce, but not ones a
            // referral is ever recorded against.
            if ( !$order instanceof WC_Order ) {
                return wcusage_abilities_error( 'order_not_found', __( 'Order not found.', 'woo-coupon-usage' ), 404 );
            }
            $order_id = $order->get_id();
            $code = ( function_exists( 'wcusage_get_order_commission_coupon' ) ? (string) wcusage_get_order_commission_coupon( $order ) : '' );
            $via = null;
            if ( '' !== (string) $order->get_meta( 'lifetime_affiliate_coupon_referrer', true ) ) {
                $via = 'lifetime';
            } elseif ( '' !== (string) $order->get_meta( 'wcusage_referrer_coupon', true ) ) {
                $via = 'referrer';
            } elseif ( '' !== $code ) {
                $via = 'coupon';
            }
            $coupon = null;
            $affiliate = null;
            if ( '' !== $code ) {
                $info = wcusage_get_coupon_info( $code );
                $coupon_id = wcusage_api_get_valid_coupon_id( ( isset( $info[2] ) ? $info[2] : 0 ) );
                if ( $coupon_id ) {
                    $user_id = absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) );
                    $affiliate = ( $user_id ? wcusage_api_prepare_user_summary( $user_id ) : null );
                    $coupon = array(
                        'id'      => $coupon_id,
                        'code'    => sanitize_text_field( get_post_field( 'post_title', $coupon_id, 'raw' ) ),
                        'user_id' => ( $affiliate ? $user_id : 0 ),
                    );
                }
            }
            $referred = null !== $affiliate;
            // Only the premium build tracks when an order's commission is
            // added to the affiliate's unpaid balance.
            $granted = null;
            $created = $order->get_date_created();
            return rest_ensure_response( array(
                'order_id'           => $order_id,
                'status'             => $order->get_status(),
                'date_created'       => ( $created ? wcusage_api_format_date( $created->date( 'Y-m-d H:i:s' ) ) : null ),
                'total'              => (float) $order->get_total(),
                'currency'           => $order->get_currency(),
                'coupons_used'       => array_values( array_map( 'strval', $order->get_coupon_codes() ) ),
                'referred'           => $referred,
                'credited_via'       => $via,
                'coupon'             => $coupon,
                'affiliate'          => $affiliate,
                'commission'         => ( $referred ? (float) wcusage_get_order_saved_commission( $order_id ) : 0.0 ),
                'commission_granted' => $granted,
            ) );
        }

        /*
         * ------------------------------------------------------------------
         * Affiliates and their coupons
         * ------------------------------------------------------------------
         */
        /**
         * Add a new affiliate.
         *
         * Runs the same steps as "Add New Affiliate" in the admin: create the
         * user when the email address has no account yet, then record the
         * registration as accepted, which creates the coupon from the
         * template, assigns the affiliate role and sends the emails.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function create_affiliate( $request ) {
            foreach ( array('wcusage_add_new_affiliate_user', 'wcusage_create_new_registration', 'wcusage_admin_registration_coupon_code_taken') as $function ) {
                if ( !function_exists( $function ) ) {
                    return wcusage_abilities_error( 'unavailable', __( 'The registration functions are not available.', 'woo-coupon-usage' ), 501 );
                }
            }
            $email = sanitize_email( (string) $request['email'] );
            $code = trim( sanitize_text_field( (string) $request['coupon_code'] ) );
            $send_email = (bool) $request['send_email'];
            $message = (string) $request['message'];
            if ( !is_email( $email ) ) {
                return wcusage_abilities_error( 'invalid_email', __( 'That is not a valid email address.', 'woo-coupon-usage' ), 400 );
            }
            if ( '' === $code ) {
                return wcusage_abilities_error( 'invalid_coupon_code', __( 'coupon_code cannot be empty.', 'woo-coupon-usage' ), 400 );
            }
            // Accepting a registration copies the new coupon from the template.
            // Without one it would create a coupon with no discount at all, so
            // refuse up front, as the admin screen's "add coupon" form does.
            $template = (string) wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
            if ( '' === $template || !wc_get_coupon_id_by_code( $template ) ) {
                return wcusage_abilities_error( 'no_template', __( 'No template coupon is set up, so the affiliate\'s coupon cannot be created. Choose one in the Coupon Affiliates registration settings first.', 'woo-coupon-usage' ), 409 );
            }
            // Two calls for the same code at once would both pass the check
            // below and create the coupon twice.
            $lock = 'ability_create_affiliate_' . strtolower( $code );
            if ( !wcusage_api_acquire_lock( $lock, 30 ) ) {
                return wcusage_abilities_error( 'busy', __( 'An affiliate with this coupon code is being created right now. Please try again in a moment.', 'woo-coupon-usage' ), 409 );
            }
            $this->count_emails( true );
            try {
                $result = $this->do_create_affiliate(
                    $request,
                    $email,
                    $code,
                    $send_email,
                    $message
                );
            } finally {
                $this->count_emails( false );
                wcusage_api_release_lock( $lock );
            }
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $result['emails_sent'] = $this->emails_sent;
            return rest_ensure_response( $result );
        }

        /**
         * The work behind create_affiliate(), run while holding its lock.
         *
         * @param WP_REST_Request $request    Request.
         * @param string          $email      Validated email address.
         * @param string          $code       Sanitised coupon code.
         * @param bool            $send_email Whether to email the affiliate.
         * @param string          $message    Message for the email.
         *
         * @return array|WP_Error
         */
        protected function do_create_affiliate(
            $request,
            $email,
            $code,
            $send_email,
            $message
        ) {
            if ( wcusage_admin_registration_coupon_code_taken( $code ) ) {
                return wcusage_abilities_error( 
                    'coupon_exists',
                    /* translators: %s: coupon code */
                    sprintf( __( 'The coupon code "%s" is already taken. Pick another code, or use assign-coupon to give an existing coupon to an affiliate.', 'woo-coupon-usage' ), $code ),
                    409
                 );
            }
            $user = get_user_by( 'email', $email );
            $new_user = false;
            if ( !$user ) {
                $username = sanitize_user( (string) $request['username'], true );
                if ( '' !== $username && username_exists( $username ) ) {
                    return wcusage_abilities_error( 
                        'username_exists',
                        /* translators: %s: username */
                        sprintf( __( 'The username "%s" is already taken. Pick another, or leave it out to have one made from the email address.', 'woo-coupon-usage' ), $username ),
                        409
                     );
                }
                if ( '' === $username ) {
                    $username = $this->username_for_email( $email );
                }
                // The role is left to the plugin's settings, which never give an
                // affiliate an administrator-level role.
                $created = wcusage_add_new_affiliate_user(
                    $username,
                    '',
                    $email,
                    sanitize_text_field( (string) $request['first_name'] ),
                    sanitize_text_field( (string) $request['last_name'] ),
                    $code,
                    '',
                    '',
                    '',
                    $send_email
                );
                $user = ( !empty( $created['userid'] ) ? get_user_by( 'id', $created['userid'] ) : false );
                if ( !$user ) {
                    return wcusage_abilities_error( 'user_not_created', __( 'The user account could not be created.', 'woo-coupon-usage' ), 500 );
                }
                $new_user = true;
            }
            // Created already accepted, which is what makes the coupon.
            $registration_id = wcusage_create_new_registration(
                $code,
                $user->user_login,
                '',
                '',
                '',
                1,
                '',
                '',
                $message,
                '',
                ( $send_email ? 'accepted' : '' )
            );
            if ( !$registration_id ) {
                return wcusage_abilities_error( 'not_created', __( 'The affiliate registration could not be saved.', 'woo-coupon-usage' ), 500 );
            }
            $coupon_id = $this->find_assigned_coupon( $code, $user->ID );
            if ( !$coupon_id ) {
                return wcusage_abilities_error(
                    'coupon_not_created',
                    __( 'The affiliate was registered, but their coupon was not created. Check the template coupon, then add the coupon from the affiliate\'s page in the admin.', 'woo-coupon-usage' ),
                    500,
                    array(
                        'registration_id' => (int) $registration_id,
                    )
                );
            }
            return array(
                'affiliate'       => wcusage_api_prepare_user_summary( $user->ID ),
                'new_user'        => $new_user,
                'coupon'          => wcusage_api_prepare_coupon_summary( $coupon_id ),
                'registration_id' => (int) $registration_id,
            );
        }

        /**
         * A free username for a new account made from an email address.
         *
         * Follows the registration setting that uses the email address itself
         * as the username; otherwise uses the part before the "@", numbered if
         * that is taken.
         *
         * @param string $email Email address.
         *
         * @return string
         */
        protected function username_for_email( $email ) {
            if ( wcusage_get_setting_value( 'wcusage_field_registration_emailusername', '0' ) && !username_exists( $email ) ) {
                return $email;
            }
            $base = sanitize_user( strstr( $email, '@', true ), true );
            if ( '' === $base ) {
                $base = 'affiliate';
            }
            $username = $base;
            for ($i = 2; username_exists( $username ); $i++) {
                $username = $base . $i;
            }
            return $username;
        }

        /**
         * The newest published coupon with a code, assigned to a user.
         *
         * Queried directly rather than through wc_get_coupon_id_by_code(),
         * whose cache can still hold the "no such coupon" answer from the
         * check made before the coupon was created.
         *
         * @param string $code    Coupon code.
         * @param int    $user_id User ID.
         *
         * @return int Coupon ID, or 0.
         */
        protected function find_assigned_coupon( $code, $user_id ) {
            $ids = get_posts( array(
                'post_type'        => 'shop_coupon',
                'post_status'      => 'publish',
                'title'            => $code,
                'meta_key'         => 'wcu_select_coupon_user',
                'meta_value'       => (string) $user_id,
                'fields'           => 'ids',
                'posts_per_page'   => 1,
                'orderby'          => 'ID',
                'order'            => 'DESC',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ) );
            return ( $ids ? (int) $ids[0] : 0 );
        }

        /**
         * Give an existing coupon to an affiliate.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function assign_coupon( $request ) {
            $coupon_id = $this->resolve_coupon( $request );
            if ( is_wp_error( $coupon_id ) ) {
                return $coupon_id;
            }
            $user = get_userdata( absint( $request['user_id'] ) );
            if ( !$user ) {
                return wcusage_abilities_error( 'user_not_found', __( 'User not found.', 'woo-coupon-usage' ), 404 );
            }
            $code = (string) get_post_field( 'post_title', $coupon_id, 'raw' );
            // New affiliate coupons are copies of the template, which is
            // there to be copied, not to earn commission for anyone.
            $template = (string) wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' );
            if ( '' !== $template && function_exists( 'wcusage_coupon_codes_match' ) && wcusage_coupon_codes_match( $template, $code ) ) {
                return wcusage_abilities_error( 'template_coupon', __( 'This is the template coupon new affiliate coupons are copied from, so it cannot be given to an affiliate.', 'woo-coupon-usage' ), 409 );
            }
            $previous_id = absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) );
            $previous = ( $previous_id ? get_userdata( $previous_id ) : false );
            if ( $previous_id === (int) $user->ID ) {
                return rest_ensure_response( $this->assignment_result(
                    $coupon_id,
                    $user->ID,
                    $previous_id,
                    false,
                    false
                ) );
            }
            if ( $previous && !$request['reassign'] ) {
                return wcusage_abilities_error( 
                    'already_assigned',
                    /* translators: 1: affiliate's display name, 2: their user ID */
                    sprintf( __( 'This coupon already belongs to %1$s (user %2$d). Set reassign to true to move it to the new affiliate - its unpaid commission balance moves with it.', 'woo-coupon-usage' ), $previous->display_name, $previous_id ),
                    409
                 );
            }
            update_post_meta( $coupon_id, 'wcu_select_coupon_user', (int) $user->ID );
            $this->clear_assignment_caches( $code, array($previous_id, (int) $user->ID) );
            $send_email = (bool) $request['send_email'];
            $message = (string) $request['message'];
            $this->count_emails( true );
            if ( $send_email && function_exists( 'wcusage_email_affiliate_coupon_assigned' ) ) {
                $first_name = get_user_meta( $user->ID, 'first_name', true );
                wcusage_email_affiliate_coupon_assigned(
                    $user->user_email,
                    $code,
                    ( $first_name ? $first_name : $user->display_name ),
                    $user->user_login,
                    $message
                );
            }
            $this->count_emails( false );
            do_action(
                'wcusage_hook_admin_coupon_assigned',
                $coupon_id,
                (int) $user->ID,
                $code,
                $message,
                ( $send_email ? 'coupon_assigned' : '' )
            );
            return rest_ensure_response( $this->assignment_result(
                $coupon_id,
                $user->ID,
                $previous_id,
                true,
                $this->emails_sent > 0
            ) );
        }

        /**
         * Take a coupon away from its affiliate.
         *
         * The same change as "Unassign" on the admin coupons list: the coupon,
         * its orders and its balances all stay as they are.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function unassign_coupon( $request ) {
            $coupon_id = $this->resolve_coupon( $request );
            if ( is_wp_error( $coupon_id ) ) {
                return $coupon_id;
            }
            $previous_id = absint( get_post_meta( $coupon_id, 'wcu_select_coupon_user', true ) );
            if ( $previous_id ) {
                update_post_meta( $coupon_id, 'wcu_select_coupon_user', '' );
                $this->clear_assignment_caches( (string) get_post_field( 'post_title', $coupon_id, 'raw' ), array($previous_id) );
            }
            return rest_ensure_response( $this->assignment_result(
                $coupon_id,
                0,
                $previous_id,
                (bool) $previous_id,
                false
            ) );
        }

        /**
         * The coupon named by coupon_id or coupon_code.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return int|WP_Error
         */
        protected function resolve_coupon( $request ) {
            $coupon_id = absint( $request['coupon_id'] );
            $code = trim( (string) $request['coupon_code'] );
            if ( $coupon_id && '' !== $code || !$coupon_id && '' === $code ) {
                return wcusage_abilities_error( 'invalid_parameters', __( 'Pass either coupon_id or coupon_code.', 'woo-coupon-usage' ), 400 );
            }
            if ( !$coupon_id ) {
                $coupon_id = ( function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( $code ) : 0 );
                // The lookup silently picks one of the coupons sharing a code,
                // which may not be the one the caller means.
                if ( $coupon_id && function_exists( 'wcusage_coupon_code_is_ambiguous' ) && wcusage_coupon_code_is_ambiguous( $coupon_id ) ) {
                    return wcusage_abilities_error( 'ambiguous_code', __( 'More than one coupon uses this code. Pass coupon_id instead.', 'woo-coupon-usage' ), 409 );
                }
            }
            $coupon_id = wcusage_api_get_valid_coupon_id( $coupon_id );
            if ( !$coupon_id ) {
                return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
            }
            return $coupon_id;
        }

        /**
         * Clear what the plugin caches about who owns a coupon.
         *
         * Changing the owner already flushes the affiliate lists, through the
         * plugin's post meta hooks; these per-user entries are the rest, as
         * the admin screens clear them.
         *
         * @param string $code     Coupon code.
         * @param int[]  $user_ids Users whose caches to clear.
         */
        protected function clear_assignment_caches( $code, $user_ids ) {
            foreach ( array_filter( array_unique( array_map( 'absint', $user_ids ) ) ) as $user_id ) {
                if ( function_exists( 'wcusage_clear_user_cache' ) ) {
                    wcusage_clear_user_cache( $user_id );
                }
                if ( function_exists( 'wcusage_cache_key' ) && '' !== $code ) {
                    delete_transient( wcusage_cache_key( 'user', 'wcusage_is_coupon_users_' . md5( $code . '_' . $user_id ) ) );
                }
            }
        }

        /**
         * Result of assigning or unassigning a coupon.
         *
         * @param int  $coupon_id   Coupon ID.
         * @param int  $user_id     New affiliate, or 0.
         * @param int  $previous_id Previous affiliate, or 0.
         * @param bool $changed     Whether anything changed.
         * @param bool $emailed     Whether the affiliate was emailed.
         *
         * @return array
         */
        protected function assignment_result(
            $coupon_id,
            $user_id,
            $previous_id,
            $changed,
            $emailed
        ) {
            return array(
                'coupon'             => wcusage_api_prepare_coupon_summary( $coupon_id ),
                'affiliate'          => ( $user_id ? wcusage_api_prepare_user_summary( $user_id ) : null ),
                'previous_affiliate' => ( $previous_id ? wcusage_api_prepare_user_summary( $previous_id ) : null ),
                'changed'            => (bool) $changed,
                'email_sent'         => (bool) $emailed,
            );
        }

    }

}