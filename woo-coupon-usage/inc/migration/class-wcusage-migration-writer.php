<?php
/**
 * Writes migrated data into Coupon Affiliates.
 *
 * The one place that knows how Coupon Affiliates stores affiliates, coupons,
 * registrations, attributed orders, clicks and settings. Sources hand over
 * plain records; this turns them into the plugin's own data, and records
 * every change in the journal (with what it replaced) so it can be undone.
 *
 * It deliberately writes the plugin's meta and tables directly instead of
 * calling the functions behind its screens: those send emails, fire webhooks,
 * log activity and grant commission as if each record were happening now.
 *
 * The PRO half (balances, payouts, groups, rates...) is
 * WCUsage_Migration_Writer_Pro, in premium-files/.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Writer {

	/**
	 * @var WCUsage_Migration_Journal
	 */
	protected $journal;

	/**
	 * Source ID.
	 *
	 * @var string
	 */
	protected $source_id;

	/**
	 * Source display name.
	 *
	 * @var string
	 */
	protected $source_name;

	/**
	 * The run's options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Whether the last create_coupon() used an existing unassigned coupon
	 * rather than creating one.
	 *
	 * @var bool
	 */
	public $reused_coupon = false;

	/**
	 * Receives log messages: function( $level, $message ).
	 *
	 * @var callable|null
	 */
	protected $logger;

	/**
	 * Coupon codes handed out in this request, so two affiliates generated
	 * from the same name in one batch do not both get "jane".
	 *
	 * @var array
	 */
	protected $claimed_codes = array();

	/**
	 * Codes the old plugin gave affiliates as virtual coupons: never made up
	 * for anyone (see free_code()), null until reserve_codes() is called.
	 *
	 * @var array|null
	 */
	protected $reserved_codes = null;

	/**
	 * Keep codes out of the ones free_code() makes up.
	 *
	 * @param string[] $codes
	 */
	public function reserve_codes( $codes ) {
		$this->reserved_codes = array();
		foreach ( (array) $codes as $code ) {
			$this->reserved_codes[ wc_format_coupon_code( (string) $code ) ] = true;
		}
	}

	/**
	 * Whether reserve_codes() has been called.
	 *
	 * @return bool
	 */
	public function has_reserved_codes() {
		return null !== $this->reserved_codes;
	}

	/**
	 * @param WCUsage_Migration_Journal $journal
	 * @param string                    $source_id
	 * @param string                    $source_name
	 * @param array                     $options
	 * @param callable|null             $logger
	 */
	public function __construct( $journal, $source_id, $source_name, $options, $logger = null ) {
		$this->journal     = $journal;
		$this->source_id   = $source_id;
		$this->source_name = $source_name;
		$this->options     = $options;
		$this->logger      = $logger;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function option( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	/**
	 * @param string $level   'info' | 'warning' | 'error'
	 * @param string $message
	 */
	protected function log( $level, $message ) {
		if ( $this->logger ) {
			call_user_func( $this->logger, $level, $message );
		}
	}

	/**
	 * @return WCUsage_Migration_Journal
	 */
	public function journal() {
		return $this->journal;
	}

	/* ---------------------------------------------------------------------
	 * Meta helpers that remember what they replaced
	 * ------------------------------------------------------------------ */

	/**
	 * Update post meta, noting the first previous value in $changes.
	 *
	 * @param int    $post_id
	 * @param string $key
	 * @param mixed  $value
	 * @param array  $changes key => array( 'e' => existed, 'v' => previous, 'n' => new )
	 */
	public function set_post_meta( $post_id, $key, $value, &$changes ) {
		if ( ! isset( $changes[ $key ] ) ) {
			$exists          = metadata_exists( 'post', $post_id, $key );
			$changes[ $key ] = array(
				'e' => $exists,
				'v' => $exists ? get_post_meta( $post_id, $key, true ) : null,
			);
		}
		update_post_meta( $post_id, $key, $value );
		$changes[ $key ]['n'] = $value;
	}

	/**
	 * Update user meta, noting the first previous value in $changes.
	 *
	 * @param int    $user_id
	 * @param string $key
	 * @param mixed  $value
	 * @param array  $changes
	 */
	public function set_user_meta( $user_id, $key, $value, &$changes ) {
		if ( ! isset( $changes[ $key ] ) ) {
			$exists          = metadata_exists( 'user', $user_id, $key );
			$changes[ $key ] = array(
				'e' => $exists,
				'v' => $exists ? get_user_meta( $user_id, $key, true ) : null,
			);
		}
		update_user_meta( $user_id, $key, $value );
		$changes[ $key ]['n'] = $value;
	}

	/**
	 * Add to a numeric coupon meta value (a balance).
	 *
	 * @param int    $coupon_id
	 * @param string $key
	 * @param float  $amount
	 */
	public function add_to_coupon_meta( $coupon_id, $key, $amount ) {
		if ( ! $coupon_id || ! $amount ) {
			return;
		}
		$current = (float) get_post_meta( $coupon_id, $key, true );
		update_post_meta( $coupon_id, $key, round( $current + (float) $amount, 2 ) );
	}

	/* ---------------------------------------------------------------------
	 * Users
	 * ------------------------------------------------------------------ */

	/**
	 * The existing WordPress user for an affiliate: the user the old plugin
	 * linked them to, or failing that a user with their email address.
	 *
	 * @param array $affiliate
	 * @return int 0 when there is none.
	 */
	public function find_user( $affiliate ) {

		if ( $affiliate['user_id'] && get_userdata( $affiliate['user_id'] ) ) {
			return (int) $affiliate['user_id'];
		}

		if ( $affiliate['email'] && is_email( $affiliate['email'] ) ) {
			$user = get_user_by( 'email', $affiliate['email'] );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/**
	 * Create a WordPress user for an affiliate the old plugin kept without one.
	 *
	 * No email is sent: the affiliate can reset their password when they first
	 * need to log in.
	 *
	 * @param array  $affiliate
	 * @param string $role
	 * @return int|WP_Error
	 */
	public function create_user( $affiliate, $role ) {

		if ( ! $affiliate['email'] || ! is_email( $affiliate['email'] ) ) {
			return new WP_Error( 'wcusage_migration_no_email', __( 'No valid email address to create a user account with.', 'woo-coupon-usage' ) );
		}

		$login = sanitize_user( $affiliate['username'] ? $affiliate['username'] : strtok( $affiliate['email'], '@' ), true );
		if ( '' === $login ) {
			$login = 'affiliate';
		}
		$base = substr( $login, 0, 50 );
		$i    = 1;
		while ( username_exists( $login ) ) {
			$i++;
			$login = $base . $i;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'      => $login,
				'user_email'      => $affiliate['email'],
				'user_pass'       => wp_generate_password( 24, true, true ),
				'first_name'      => $affiliate['first_name'],
				'last_name'       => $affiliate['last_name'],
				'display_name'    => trim( $affiliate['first_name'] . ' ' . $affiliate['last_name'] ) ? trim( $affiliate['first_name'] . ' ' . $affiliate['last_name'] ) : $login,
				'user_url'        => $affiliate['website'],
				'role'            => $role,
				'user_registered' => $affiliate['date'] ? get_gmt_from_date( $affiliate['date'] ) : current_time( 'mysql', true ),
			)
		);

		return $user_id;
	}

	/**
	 * Give a user a role, noting it was added (for undoing).
	 *
	 * @param int    $user_id
	 * @param string $role
	 * @param array  $added Roles added so far.
	 */
	public function add_role( $user_id, $role, &$added ) {
		if ( ! $role || ! get_role( $role ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || in_array( $role, (array) $user->roles, true ) ) {
			return;
		}
		$user->add_role( $role );
		$added[] = $role;
	}

	/* ---------------------------------------------------------------------
	 * Coupons
	 * ------------------------------------------------------------------ */

	/**
	 * The user a coupon is assigned to, 0 for none.
	 *
	 * Older versions stored a username rather than an ID.
	 *
	 * @param int $coupon_id
	 * @return int
	 */
	public function coupon_owner( $coupon_id ) {
		$owner = get_post_meta( $coupon_id, 'wcu_select_coupon_user', true );
		if ( '' === $owner || null === $owner ) {
			return 0;
		}
		if ( is_numeric( $owner ) ) {
			return (int) $owner;
		}
		$user = get_user_by( 'login', $owner );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * The published coupon with a code. WooCommerce remembers a code's coupon
	 * ID, and with a persistent object cache can hand back one since deleted.
	 *
	 * @param string $code
	 * @return int Coupon ID, 0 if none.
	 */
	public static function live_coupon_id( $code ) {
		$coupon_id = (int) wc_get_coupon_id_by_code( $code );
		return $coupon_id && 'publish' === get_post_status( $coupon_id ) ? $coupon_id : 0;
	}

	/**
	 * Make the plugin forget the coupon details it remembered for this request,
	 * after a coupon was created, reassigned or given a new rate.
	 */
	public static function flush_coupon_cache() {
		if ( function_exists( 'wcusage_flush_coupon_info_cache' ) ) {
			wcusage_flush_coupon_info_cache();
		}
	}

	/**
	 * A coupon's code, as WooCommerce formats it.
	 *
	 * @param int $coupon_id
	 * @return string
	 */
	public function coupon_code( $coupon_id ) {
		$post = get_post( $coupon_id );
		return $post ? wc_format_coupon_code( $post->post_title ) : '';
	}

	/**
	 * The coupons a user already has as an affiliate here.
	 *
	 * @param int $user_id
	 * @return int[] Coupon IDs, oldest first.
	 */
	public function user_coupons( $user_id ) {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wcu_select_coupon_user' WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND pm.meta_value = %s ORDER BY p.ID ASC",
					(string) (int) $user_id
				)
			)
		);
	}

	/**
	 * Assign an existing coupon to an affiliate.
	 *
	 * @param int    $coupon_id
	 * @param int    $user_id
	 * @param string $affiliate_source_id
	 * @return string 'assigned' | 'already' | 'conflict' | 'missing'
	 */
	public function assign_coupon( $coupon_id, $user_id, $affiliate_source_id ) {

		$post = get_post( $coupon_id );
		if ( ! $post || 'shop_coupon' !== $post->post_type || 'trash' === $post->post_status ) {
			return 'missing';
		}

		$owner = $this->coupon_owner( $coupon_id );
		if ( $owner === (int) $user_id ) {
			return 'already';
		}
		// Another user's coupon here stays theirs.
		if ( $owner && get_userdata( $owner ) ) {
			return 'conflict';
		}

		$changes = array();
		$this->set_post_meta( $coupon_id, 'wcu_select_coupon_user', (string) (int) $user_id, $changes );
		// Statistics are rebuilt for the new owner when their dashboard next loads.
		delete_post_meta( $coupon_id, 'wcu_last_refreshed' );

		$this->journal->add(
			'coupon',
			$affiliate_source_id,
			'coupon',
			$coupon_id,
			array(
				'created' => false,
				'meta'    => $changes,
			)
		);

		$this->claimed_codes[ $this->coupon_code( $coupon_id ) ] = true;
		self::flush_coupon_cache();

		return 'assigned';
	}

	/**
	 * Fill in a coupon code format for an affiliate.
	 *
	 * @param string $format
	 * @param array  $affiliate
	 * @param int    $user_id
	 * @return string
	 */
	public function format_code( $format, $affiliate, $user_id ) {

		$user     = $user_id ? get_userdata( $user_id ) : false;
		$username = $user ? $user->user_login : $affiliate['username'];
		$first    = $affiliate['first_name'] ? $affiliate['first_name'] : ( $user ? $user->first_name : '' );
		$last     = $affiliate['last_name'] ? $affiliate['last_name'] : ( $user ? $user->last_name : '' );

		// The first identifier that is not just the numeric ID - a custom slug
		// or token reads better as a code - falling back to the ID.
		$identifier = '';
		foreach ( $affiliate['identifiers'] as $candidate ) {
			if ( ! is_numeric( $candidate ) && strlen( $candidate ) <= 40 && ! preg_match( '/^[a-f0-9]{32}$/', $candidate ) ) {
				$identifier = $candidate;
				break;
			}
		}
		if ( '' === $identifier ) {
			$identifier = $username ? $username : $affiliate['source_id'];
		}

		$code = strtr(
			(string) $format,
			array(
				'{username}'     => $username,
				'{first_name}'   => $first,
				'{last_name}'    => $last,
				'{affiliate_id}' => $affiliate['source_id'],
				'{user_id}'      => $user_id ? (string) $user_id : '',
				'{identifier}'   => $identifier,
				'{random}'       => strtolower( wp_generate_password( 6, false, false ) ),
			)
		);

		// Coupon codes go in URLs and are typed by customers: keep them plain.
		$code = remove_accents( $code );
		$code = preg_replace( '/[^A-Za-z0-9_\-]/', '', $code );
		$code = wc_format_coupon_code( $code );

		if ( '' === $code ) {
			$code = 'affiliate' . ( $user_id ? $user_id : $affiliate['source_id'] );
		}

		return substr( $code, 0, 60 );
	}

	/**
	 * A free coupon code, starting from the one asked for.
	 *
	 * @param string $code
	 * @param int    $user_id   When given, an unassigned coupon with this code
	 *                          may be returned for the affiliate to take over.
	 * @return array array( code, existing coupon ID or 0 )
	 */
	public function free_code( $code, $user_id = 0 ) {

		$base = $code;
		$i    = 1;

		while ( true ) {
			$existing = isset( $this->claimed_codes[ $code ] ) || isset( $this->reserved_codes[ $code ] ) ? -1 : self::live_coupon_id( $code );

			if ( ! $existing ) {
				return array( $code, 0 );
			}

			if ( $existing > 0 && 1 === $i && $user_id && 'use_unassigned' === $this->option( 'coupon_conflict', 'use_unassigned' ) ) {
				$owner = $this->coupon_owner( $existing );
				if ( ! $owner || $owner === (int) $user_id || ! get_userdata( $owner ) ) {
					return array( $code, $existing );
				}
			}

			$i++;
			$code = $base . $i;
		}
	}

	/**
	 * Create a coupon for an affiliate who has none.
	 *
	 * @param array $affiliate
	 * @param int   $user_id
	 * @return int Coupon ID, 0 on failure.
	 */
	public function create_coupon( $affiliate, $user_id ) {

		$this->reused_coupon = false;

		$format = (string) $this->option( 'coupon_code_format', '{username}' );
		$code   = $this->format_code( $format ? $format : '{username}', $affiliate, $user_id );

		list( $code, $existing ) = $this->free_code( $code, $user_id );

		if ( $existing ) {
			$result = $this->assign_coupon( $existing, $user_id, $affiliate['source_id'] );
			if ( in_array( $result, array( 'assigned', 'already' ), true ) ) {
				/* translators: %s: coupon code */
				$this->log( 'info', sprintf( __( 'Used the existing unassigned coupon "%s".', 'woo-coupon-usage' ), $code ) );
				$this->reused_coupon = true;
				return $existing;
			}
		}

		return $this->insert_coupon( $code, $affiliate, $user_id );
	}

	/**
	 * Create a coupon with a code the old plugin gave the affiliate but kept to
	 * itself (AffiliateWP's dynamic coupons, which exist only as codes it hands
	 * WooCommerce at checkout), so customers who have it can keep using it.
	 *
	 * @param string     $code
	 * @param array      $affiliate
	 * @param int        $user_id
	 * @param array|null $template See WCUsage_Migration_Source::get_virtual_coupon_template().
	 * @return int Coupon ID, 0 on failure.
	 */
	public function create_coupon_with_code( $code, $affiliate, $user_id, $template = null ) {
		$this->reused_coupon = false;
		$code                = wc_format_coupon_code( $code );
		if ( '' === $code || self::live_coupon_id( $code ) ) {
			return 0;
		}
		return $this->insert_coupon( $code, $affiliate, $user_id, $template, true );
	}

	/**
	 * Insert a coupon for an affiliate: settings copied from the old plugin's
	 * template where given, otherwise from the chosen template coupon or discount.
	 *
	 * @param string     $code
	 * @param array      $affiliate
	 * @param int        $user_id
	 * @param array|null $source_template array( 'id' => coupon ID, 'fields' => WC_Coupon props ), or null.
	 * @param bool       $from_code       The code is one the old plugin gave the affiliate (it has orders).
	 * @return int Coupon ID, 0 on failure.
	 */
	protected function insert_coupon( $code, $affiliate, $user_id, $source_template = null, $from_code = false ) {

		$template_id = 0;
		if ( $source_template ) {
			// Settings come from the old plugin's template below.
		} elseif ( 'template' === $this->option( 'coupon_settings', 'discount' ) ) {
			$template_code = (string) $this->option( 'coupon_template', '' );
			$template_id   = $template_code ? (int) wc_get_coupon_id_by_code( $template_code ) : 0;
			if ( ! $template_id ) {
				/* translators: %s: coupon code */
				$this->log( 'warning', sprintf( __( 'Template coupon "%s" was not found, so new coupons use the discount settings instead.', 'woo-coupon-usage' ), $template_code ) );
			}
		}

		$coupon_id = wp_insert_post(
			array(
				'post_title'   => $code,
				'post_status'  => 'publish',
				'post_type'    => 'shop_coupon',
				'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
				/* translators: %s: plugin name */
				'post_excerpt' => $template_id ? (string) get_post_field( 'post_excerpt', $template_id ) : sprintf( __( 'Affiliate coupon, migrated from %s.', 'woo-coupon-usage' ), $this->source_name ),
			),
			true
		);

		if ( is_wp_error( $coupon_id ) || ! $coupon_id ) {
			return 0;
		}

		if ( $source_template ) {
			// The fields the old plugin took from its template coupon at checkout;
			// everything else (usage limits, spend limits...) stays unset, as it did.
			$coupon   = new WC_Coupon( $coupon_id );
			$template = new WC_Coupon( (int) $source_template['id'] );
			foreach ( (array) $source_template['fields'] as $field ) {
				if ( method_exists( $template, 'get_' . $field ) && method_exists( $coupon, 'set_' . $field ) ) {
					call_user_func( array( $coupon, 'set_' . $field ), call_user_func( array( $template, 'get_' . $field ), 'edit' ) );
				}
			}
			$coupon->save();
		} elseif ( $template_id ) {
			// As when a registration is accepted: copy everything from the template,
			// then reset what belongs to one coupon only.
			$skip = array( '_edit_lock', '_edit_last', 'usage_count', '_used_by', 'wcu_select_coupon_user', 'wcu_text_unpaid_commission', 'wcu_text_pending_payment_commission', 'wcu_text_pending_order_commission', 'wcu_alltime_stats', 'wcu_alltime_stats_progress', 'wcu_last_refreshed', 'wcu_last_fast_refresh', 'wcu_commission_message', WCUSAGE_MIGRATED_ADJUSTMENT_META );
			foreach ( (array) get_post_custom( $template_id ) as $key => $values ) {
				if ( in_array( $key, $skip, true ) || 0 === strpos( $key, 'wcusage_monthly_' ) || 0 === strpos( $key, 'wcusage_yearly_' ) ) {
					continue;
				}
				foreach ( (array) $values as $value ) {
					add_post_meta( $coupon_id, $key, maybe_unserialize( $value ) );
				}
			}
		} else {
			$type   = (string) $this->option( 'coupon_discount_type', 'percent' );
			$amount = (float) $this->option( 'coupon_discount_amount', 0 );
			update_post_meta( $coupon_id, 'discount_type', in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ? $type : 'percent' );
			update_post_meta( $coupon_id, 'coupon_amount', wc_format_decimal( $amount ) );
			update_post_meta( $coupon_id, 'individual_use', 'no' );
			update_post_meta( $coupon_id, 'usage_limit', '' );
			update_post_meta( $coupon_id, 'usage_limit_per_user', '' );
			update_post_meta( $coupon_id, 'free_shipping', 'no' );
		}

		update_post_meta( $coupon_id, 'usage_count', '0' );
		update_post_meta( $coupon_id, 'wcu_select_coupon_user', (string) (int) $user_id );
		update_post_meta( $coupon_id, 'wcu_text_unpaid_commission', '0' );
		update_post_meta( $coupon_id, 'wcu_text_pending_payment_commission', '0' );

		$this->claimed_codes[ $code ] = true;
		self::flush_coupon_cache();

		$this->journal->add(
			'coupon',
			$affiliate['source_id'],
			'coupon',
			$coupon_id,
			$from_code ? array( 'created' => true, 'from_code' => true ) : array( 'created' => true )
		);

		return (int) $coupon_id;
	}

	/* ---------------------------------------------------------------------
	 * Registrations
	 * ------------------------------------------------------------------ */

	/**
	 * Add a row to the Registrations screen.
	 *
	 * @param int    $user_id
	 * @param array  $affiliate
	 * @param string $status      'accepted' | 'pending' | 'declined'
	 * @param string $coupon_code The coupon they were given, or would be.
	 * @return int Row ID.
	 */
	public function add_registration( $user_id, $affiliate, $status, $coupon_code ) {
		global $wpdb;

		if ( function_exists( 'wcusage_install_register_tables' ) && ! wcusage_migration_table_columns( $wpdb->prefix . 'wcusage_register' ) ) {
			wcusage_install_register_tables();
		}

		$info = array();
		foreach ( (array) $affiliate['fields'] as $label => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( '' !== (string) $value ) {
				$info[ sanitize_text_field( (string) $label ) ] = sanitize_textarea_field( (string) $value );
			}
		}
		if ( 'declined' === $status && $affiliate['reject_reason'] ) {
			$info[ __( 'Reason declined', 'woo-coupon-usage' ) ] = sanitize_textarea_field( $affiliate['reject_reason'] );
		}

		$date = $affiliate['date'] ? $affiliate['date'] : current_time( 'mysql' );

		$wpdb->insert(
			$wpdb->prefix . 'wcusage_register',
			array(
				'userid'       => (int) $user_id,
				'couponcode'   => (string) $coupon_code,
				'promote'      => sanitize_textarea_field( (string) $affiliate['promote'] ),
				'referrer'     => '',
				'website'      => esc_url_raw( (string) $affiliate['website'] ),
				'status'       => $status,
				'type'         => '',
				'info'         => $info ? wp_json_encode( $info ) : '',
				'date'         => $date,
				'dateaccepted' => 'pending' === $status ? null : $date,
			)
		);

		$row_id = (int) $wpdb->insert_id;

		if ( $row_id ) {
			$this->journal->add( 'registration', $affiliate['source_id'], 'register', $row_id );
		}

		return $row_id;
	}

	/**
	 * Store the affiliate's application answers on their profile, as the
	 * registration form does.
	 *
	 * @param int   $user_id
	 * @param array $affiliate
	 * @param array $changes
	 */
	public function save_profile_fields( $user_id, $affiliate, &$changes ) {

		$info = array();
		foreach ( (array) $affiliate['fields'] as $label => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( '' !== (string) $value ) {
				$info[ sanitize_text_field( (string) $label ) ] = sanitize_textarea_field( (string) $value );
			}
		}

		if ( $info && ! get_user_meta( $user_id, 'wcu_info', true ) ) {
			$this->set_user_meta( $user_id, 'wcu_info', wp_json_encode( $info ), $changes );
		}
		if ( $affiliate['website'] && ! get_user_meta( $user_id, 'wcu_website', true ) ) {
			$this->set_user_meta( $user_id, 'wcu_website', esc_url_raw( $affiliate['website'] ), $changes );
		}
		if ( $affiliate['promote'] && ! get_user_meta( $user_id, 'wcu_promote', true ) ) {
			$this->set_user_meta( $user_id, 'wcu_promote', sanitize_textarea_field( $affiliate['promote'] ), $changes );
		}
	}

	/**
	 * Suspend an affiliate, as the suspend button does but without the
	 * activity entry and hooks (nothing is happening to them now).
	 *
	 * @param int   $user_id
	 * @param array $changes
	 */
	public function suspend( $user_id, &$changes ) {
		$meta = defined( 'WCUSAGE_SUSPENDED_META' ) ? WCUSAGE_SUSPENDED_META : 'wcusage_affiliate_suspended';
		if ( get_user_meta( $user_id, $meta, true ) ) {
			return;
		}
		$this->set_user_meta( $user_id, $meta, '1', $changes );
		if ( defined( 'WCUSAGE_SUSPENDED_DATE_META' ) ) {
			$this->set_user_meta( $user_id, WCUSAGE_SUSPENDED_DATE_META, current_time( 'mysql' ), $changes );
		}
		if ( defined( 'WCUSAGE_SUSPENDED_BY_META' ) ) {
			$this->set_user_meta( $user_id, WCUSAGE_SUSPENDED_BY_META, get_current_user_id(), $changes );
		}
	}

	/**
	 * Remember which coupon each of the affiliate's old link identifiers
	 * stands for, for the old-link handler.
	 *
	 * @param array $affiliate
	 * @param int   $coupon_id
	 */
	public function record_identifiers( $affiliate, $coupon_id ) {

		// The plugins took a number in a link for an ID. So a numeric username or
		// slug never worked as one, and recorded here it would take over the
		// links of the affiliate whose ID it matches.
		$link_id     = strtolower( trim( (string) ( $affiliate['link_id'] ?? '' ) ) );
		$identifiers = (array) $affiliate['identifiers'];
		if ( '' !== $link_id ) {
			$identifiers[] = $link_id;
		}

		$recorded = array();
		foreach ( $identifiers as $identifier ) {
			$identifier = strtolower( trim( (string) $identifier ) );
			if ( '' === $identifier || strlen( $identifier ) > 191 || isset( $recorded[ $identifier ] ) ) {
				continue;
			}
			if ( '' !== $link_id && ctype_digit( $identifier ) && $identifier !== $link_id ) {
				continue;
			}
			$recorded[ $identifier ] = true;
			$this->journal->add( 'ref_id', $identifier, 'coupon', $coupon_id, array( 'affiliate' => $affiliate['source_id'] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * The status of each of a batch of orders, in one query.
	 *
	 * @param int[] $order_ids
	 * @return array order ID => status without the "wc-" prefix (missing orders left out)
	 */
	public static function order_statuses( $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) ) );
		if ( ! $order_ids ) {
			return array();
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$statuses = array();
		foreach ( array_chunk( $order_ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			if ( $hpos ) {
				$rows = $wpdb->get_results( "SELECT id, status FROM {$wpdb->prefix}wc_orders WHERE id IN ({$in}) AND type = 'shop_order'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$rows = $wpdb->get_results( "SELECT ID AS id, post_status AS status FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'shop_order'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			foreach ( (array) $rows as $row ) {
				$status = (string) $row['status'];
				if ( 'trash' === $status || 'auto-draft' === $status ) {
					continue;
				}
				$statuses[ (int) $row['id'] ] = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			}
		}

		return $statuses;
	}

	/**
	 * The coupon Coupon Affiliates already credits an order to, if any.
	 *
	 * @param WC_Order $order
	 * @return string Coupon code, or ''.
	 */
	/**
	 * The coupons that could credit an order to an affiliate here - its lifetime
	 * and referrer coupons, then the coupons used on it - that have one.
	 *
	 * @param WC_Order $order
	 * @return array coupon code => owner user ID, in that order.
	 */
	public function order_attributions( $order ) {
		$codes = array();
		foreach ( array( 'lifetime_affiliate_coupon_referrer', 'wcusage_referrer_coupon' ) as $key ) {
			$value = $order->get_meta( $key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$codes[] = wc_format_coupon_code( (string) $value );
			}
		}
		foreach ( $order->get_coupon_codes() as $code ) {
			$codes[] = wc_format_coupon_code( $code );
		}
		$owned = array();
		foreach ( array_unique( $codes ) as $code ) {
			$coupon_id = (int) wc_get_coupon_id_by_code( $code );
			$owner     = $coupon_id ? $this->coupon_owner( $coupon_id ) : 0;
			if ( $owner && get_userdata( $owner ) ) {
				$owned[ $code ] = $owner;
			}
		}
		return $owned;
	}

	/**
	 * Credit an order to an affiliate's coupon.
	 *
	 * The coupon is always recorded as the order's referrer, even when it is
	 * the coupon the customer used: that is what decides which coupon owns an
	 * order's commission, and it keeps the choice the old plugin made.
	 *
	 * @param WC_Order   $order
	 * @param string     $coupon_code
	 * @param int        $user_id
	 * @param string     $referral_source_id
	 * @param float|null $locked_amount Commission to keep, or null to let Coupon Affiliates work it out.
	 * @param array      $changes       Receives the order meta changed.
	 */
	public function attribute_order( $order, $coupon_code, $user_id, $referral_source_id, $locked_amount, &$changes ) {

		$meta = array(
			'wcusage_referrer_coupon'   => wc_format_coupon_code( $coupon_code ),
			'wcusage_affiliate_user'    => (string) (int) $user_id,
			WCUSAGE_MIGRATED_FROM_META  => $this->source_id . ':' . $referral_source_id,
		);

		if ( null !== $locked_amount ) {
			$amount = round( (float) $locked_amount, 2 );

			$meta[ WCUSAGE_MIGRATED_COMMISSION_META ] = wc_format_decimal( $amount, 2 );
			$meta[ WCUSAGE_MIGRATED_REFUNDS_META ]    = (string) count( $order->get_refunds() );
			$meta[ WCUSAGE_MIGRATED_NET_META ]        = wc_format_decimal( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );

			// The saved breakdown, as the plugin itself would save it, so screens
			// that read these keys directly show the imported amount.
			$meta['wcusage_total_commission']       = wc_format_decimal( $amount, 2 );
			$meta['wcusage_fixed_order_commission'] = '0';
			$meta['wcusage_product_commission']     = '0';
		}

		$this->set_order_meta( $order, $meta, $changes );

		// Let the plugin work out the saved order figures again, with the
		// new owner (and the imported amount, where one is kept).
		$this->delete_order_meta( $order, array( 'wcusage_stats', 'wcusage_commission_summary' ), $changes );

		// ...including later in this same request (WP-CLI runs it all in one).
		if ( function_exists( 'wcusage_forget_order_calculation' ) ) {
			wcusage_forget_order_calculation( $order->get_id() );
		}
	}

	/**
	 * Set several order meta keys with one save, noting what they replaced.
	 *
	 * @param WC_Order $order
	 * @param array    $meta
	 * @param array    $changes
	 */
	public function set_order_meta( $order, $meta, &$changes ) {
		foreach ( $meta as $key => $value ) {
			if ( ! isset( $changes[ $key ] ) ) {
				$exists          = $order->meta_exists( $key );
				$changes[ $key ] = array(
					'e' => $exists,
					'v' => $exists ? $order->get_meta( $key, true ) : null,
				);
			}
			$order->update_meta_data( $key, $value );
			$changes[ $key ]['n'] = $value;
		}
		$order->save_meta_data();
	}

	/**
	 * Delete order meta keys, noting what they held.
	 *
	 * @param WC_Order $order
	 * @param string[] $keys
	 * @param array    $changes
	 */
	public function delete_order_meta( $order, $keys, &$changes ) {
		$changed = false;
		foreach ( $keys as $key ) {
			if ( ! $order->meta_exists( $key ) ) {
				continue;
			}
			if ( ! isset( $changes[ $key ] ) ) {
				$changes[ $key ] = array(
					'e' => true,
					'v' => $order->get_meta( $key, true ),
				);
			}
			$order->delete_meta_data( $key );
			$changes[ $key ]['n'] = null;
			$changed              = true;
		}
		if ( $changed ) {
			$order->save_meta_data();
		}
	}

	/* ---------------------------------------------------------------------
	 * Clicks
	 * ------------------------------------------------------------------ */

	/**
	 * Insert referral visits into the clicks table, in one query.
	 *
	 * @param array[] $rows Each: couponid, campaign, page, referrer, ipaddress, orderid, converted, date.
	 * @return array array( first ID, last ID ) of the rows inserted, or empty.
	 */
	public function insert_clicks( $rows ) {
		global $wpdb;

		if ( ! $rows ) {
			return array();
		}

		$table = $wpdb->prefix . 'wcusage_clicks';
		if ( function_exists( 'wcusage_install_clicks_tables' ) && ! wcusage_migration_table_columns( $table ) ) {
			wcusage_install_clicks_tables();
		}

		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s, %s, %s, %d, %d, %s)',
				(int) $row['couponid'],
				substr( (string) $row['campaign'], 0, 250 ),
				(string) $row['page'],
				substr( (string) $row['referrer'], 0, 250 ),
				substr( (string) $row['ipaddress'], 0, 250 ),
				(int) $row['orderid'],
				(int) (bool) $row['converted'],
				(string) $row['date']
			);
		}

		$wpdb->query( "INSERT INTO {$table} (couponid, campaign, page, referrer, ipaddress, orderid, converted, date) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$first = (int) $wpdb->insert_id;
		if ( ! $first ) {
			return array();
		}

		return array( $first, $first + count( $rows ) - 1 );
	}

	/**
	 * The post ID behind a URL on this site (0 for the home page or unknown),
	 * as the clicks table stores the landing page.
	 *
	 * @param string $url
	 * @return int
	 */
	public function page_id_for_url( $url ) {
		static $cache = array();

		$url = (string) $url;
		if ( '' === $url ) {
			return 0;
		}

		$path = strtok( $url, '?#' );
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}

		$home = untrailingslashit( home_url() );
		$id   = 0;
		if ( 0 === stripos( $path, $home ) && untrailingslashit( $path ) !== $home ) {
			$id = (int) url_to_postid( $path );
		}

		if ( count( $cache ) > 2000 ) {
			$cache = array();
		}
		$cache[ $path ] = $id;

		return $id;
	}

	/**
	 * Reduce a referrer URL to the bare domain the clicks table stores.
	 *
	 * @param string $referrer
	 * @return string
	 */
	public function referrer_domain( $referrer ) {
		$referrer = trim( (string) $referrer );
		if ( '' === $referrer ) {
			return '';
		}
		if ( false !== strpos( $referrer, '//' ) ) {
			$referrer = (string) wp_parse_url( $referrer, PHP_URL_HOST );
		}
		$referrer = (string) strtok( $referrer, '/' );
		$referrer = (string) strtok( $referrer, '?' );
		// From the visitor's browser, so anything at all.
		return sanitize_text_field( preg_replace( '/^www\./i', '', $referrer ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Copy the old plugin's program settings across, as chosen.
	 *
	 * @param array $settings See WCUsage_Migration_Source::get_settings().
	 * @return array The settings changed, key => new value.
	 */
	public function apply_settings( $settings ) {

		$new = array();

		if ( $this->option( 'settings_rate' ) && isset( $settings['rate'] ) && is_numeric( $settings['rate'] ) ) {
			$rate = round( (float) $settings['rate'], 2 );
			switch ( isset( $settings['rate_type'] ) ? $settings['rate_type'] : 'percent' ) {
				case 'flat_order':
					$new['wcusage_field_affiliate']               = '0';
					$new['wcusage_field_affiliate_fixed_order']   = (string) $rate;
					$new['wcusage_field_affiliate_fixed_product'] = '0';
					break;
				case 'flat_product':
					$new['wcusage_field_affiliate']               = '0';
					$new['wcusage_field_affiliate_fixed_order']   = '0';
					$new['wcusage_field_affiliate_fixed_product'] = (string) $rate;
					break;
				default:
					$new['wcusage_field_affiliate']               = (string) $rate;
					$new['wcusage_field_affiliate_fixed_order']   = '0';
					$new['wcusage_field_affiliate_fixed_product'] = '0';
			}
		}

		if ( $this->option( 'settings_cookie' ) && ! empty( $settings['cookie_days'] ) && (int) $settings['cookie_days'] > 0 ) {
			$new['wcusage_urls_cookie_days'] = (string) (int) $settings['cookie_days'];
		}

		if ( $this->option( 'settings_attribution' ) && isset( $settings['credit_first'] ) ) {
			$new['wcusage_field_click_attribution_first'] = $settings['credit_first'] ? '1' : '0';
		}

		if ( ! $new ) {
			return array();
		}

		$current = get_option( 'wcusage_options', array() );
		$current = is_array( $current ) ? $current : array();

		// A setting an earlier migration from this source already set, and the
		// admin has changed since, is theirs now: running the migration again
		// does not put it back.
		$set_before = array();
		foreach ( $this->journal->all( 'settings' ) as $row ) {
			foreach ( (array) ( $row['data']['meta'] ?? array() ) as $key => $change ) {
				if ( is_array( $change ) && array_key_exists( 'n', $change ) ) {
					$set_before[ $key ] = $change['n'];
				}
			}
		}
		foreach ( array_keys( $new ) as $key ) {
			if ( array_key_exists( $key, $set_before ) && (string) ( $current[ $key ] ?? '' ) !== (string) $set_before[ $key ] ) {
				unset( $new[ $key ] );
			}
		}
		if ( ! $new ) {
			return array();
		}

		$changes = array();
		foreach ( $new as $key => $value ) {
			$changes[ $key ] = array(
				'e' => array_key_exists( $key, $current ),
				'v' => array_key_exists( $key, $current ) ? $current[ $key ] : null,
				'n' => $value,
			);
		}

		$this->update_settings( $new );

		$this->journal->add( 'settings', 'settings', 'settings', 0, array( 'meta' => $changes ) );

		return $new;
	}

	/**
	 * Update plugin settings (keys inside wcusage_options).
	 *
	 * @param array $values
	 */
	public function update_settings( $values ) {
		if ( function_exists( 'wcusage_update_options_merge' ) ) {
			wcusage_update_options_merge( $values );
		} else {
			$options = get_option( 'wcusage_options', array() );
			update_option( 'wcusage_options', array_merge( is_array( $options ) ? $options : array(), $values ) );
		}
		if ( function_exists( 'wcusage_options_memo' ) ) {
			wcusage_options_memo( false );
		}
	}

	/**
	 * Save how the old plugin's referral links and cookies looked, so the
	 * old-link handler can translate them.
	 *
	 * @param array $links       See WCUsage_Migration_Source::get_legacy_links().
	 * @param int   $cookie_days How long to honour the old cookies, 0 for not at all.
	 */
	public function save_legacy_links( $links, $cookie_days ) {

		$config = get_option( 'wcusage_migration_legacy_links', array() );
		$config = is_array( $config ) ? $config : array();

		$previous = isset( $config[ $this->source_id ] ) ? $config[ $this->source_id ] : null;

		$entry = array(
			'vars'     => array_values( array_filter( array_map( 'strval', (array) ( $links['vars'] ?? array() ) ) ) ),
			'paths'    => array_values( array_filter( array_map( 'strval', (array) ( $links['paths'] ?? array() ) ) ) ),
			'campaign' => (string) ( $links['campaign'] ?? '' ),
			'cookies'  => (int) $cookie_days > 0 ? array_values( array_filter( array_map( 'strval', (array) ( $links['cookies'] ?? array() ) ) ) ) : array(),
		);
		if ( ! empty( $links['cookie_strip_suffix'] ) ) {
			$entry['cookie_strip_suffix'] = 1;
		}
		if ( (int) $cookie_days > 0 ) {
			$entry['cookies_until'] = time() + (int) $cookie_days * DAY_IN_SECONDS;
		}

		$config[ $this->source_id ] = $entry;
		update_option( 'wcusage_migration_legacy_links', $config, true );

		$this->journal->add(
			'legacy_links',
			$this->source_id,
			'option',
			0,
			array(
				'option'   => 'wcusage_migration_legacy_links',
				'key'      => $this->source_id,
				'previous' => $previous,
			)
		);
	}
}
