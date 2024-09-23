<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// fire it up!
add_action( 'plugins_loaded', 'wcusage_class_orders_filters_coupons' );

/**
 * Adds custom filtering to the orders screen to allow filtering by coupon used.
 */
 class wcusage_class_orders_filters_coupons {

	const VERSION = '1.1.0';

	/** @var wcusage_class_orders_filters_coupons single instance of this plugin */
	protected static $instance;

	/**
	 * WC_Filter_Orders constructor.
	 */
	public function __construct() {

		// load translations
		//add_action( 'init', array( $this, 'load_translation' ) );

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			// adds the coupon filtering dropdown to the orders page
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'filter_orders_by_coupon_used' ) );

			// makes coupons filterable
			add_filter( 'posts_join',  array( $this, 'add_order_items_join' ) );
			add_filter( 'posts_where', array( $this, 'add_filterable_where' ) );

		}

	}

	/**
	 * Adds the coupon filtering dropdown to the orders list
	 */
	public function filter_orders_by_coupon_used() {
	?>

		<input placeholder="Filter by coupon code..." type="text" name="wcu_coupons" id="dropdown_coupons_used"></input>

		<?php if( isset($_GET['wcu_coupons']) ) { ?>
			<p style="position: absolute; top: 10px; display: flex; color: green; font-weight: bold;">
			<?php if($_GET['wcu_coupons'] == "ALL") { ?>
			<span class="dashicons dashicons-info-outline"></span>&nbsp; Filter: Only showing orders that used ANY coupon code.
			<?php } elseif($_GET['wcu_coupons'] != "") { ?>
			<span class="dashicons dashicons-info-outline"></span>&nbsp; Filter: Only showing orders that used coupon code: <?php echo esc_html( $_GET['wcu_coupons'] ); ?>
			<?php } ?>
			</p>
		<?php } ?>

	<?php
	}

	/**
	 * Modify SQL JOIN for filtering the orders by any coupons used
	 *
	 * @param string $join JOIN part of the sql query
	 * @return string $join modified JOIN part of sql query
	 */
	public function add_order_items_join( $join ) {
		global $typenow, $wpdb;

		if ( 'shop_order' === $typenow && isset( $_GET['wcu_coupons'] ) && ! empty( $_GET['wcu_coupons'] ) ) {

			$join .= "LEFT JOIN {$wpdb->prefix}woocommerce_order_items woi ON {$wpdb->posts}.ID = woi.order_id";
		}

		return $join;
	}


	/**
	 * Modify SQL WHERE for filtering the orders by any coupons used
	 *
	 * @param string $where WHERE part of the sql query
	 * @return string $where modified WHERE part of sql query
	 */
	public function add_filterable_where( $where ) {
		global $typenow, $wpdb;

		if ( 'shop_order' === $typenow && isset( $_GET['wcu_coupons'] ) && ! empty( $_GET['wcu_coupons'] ) ) {

			// Main WHERE query part
      if($_GET['wcu_coupons'] == "ALL") {

          $where .= " AND woi.order_item_type='coupon' AND woi.order_item_name!='' ";

      } elseif($_GET['wcu_coupons'] == "NONE") {

          $where .= " AND woi.order_item_type='coupon' AND woi.order_item_name='' ";

      } else {

  		    $where .= $wpdb->prepare( " AND woi.order_item_type='coupon' AND woi.order_item_name='%s'", wc_clean( $_GET['wcu_coupons'] ) );

      }

		}

		return $where;
	}

	/**
	 * Main wcusage_class_orders_filters_coupons Instance, ensures only one instance is/can be loaded
	 *
	 * @see wcusage_class_orders_filters_coupons()
	 * @return wcusage_class_orders_filters_coupons
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
		 	self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 */
	public function __clone() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot clone instances of %s.', 'woo-coupon-usage' ), 'Filter WC Orders by Coupon' ), '1.1.0' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 */
	public function __wakeup() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot unserialize instances of %s.', 'woo-coupon-usage' ), 'Filter WC Orders by Coupon' ), '1.1.0' );
	}


}

/**
 * Returns the One True Instance of wcusage_class_orders_filters_coupons
 *
 * @return \wcusage_class_orders_filters_coupons
 */
function wcusage_class_orders_filters_coupons() {
	return wcusage_class_orders_filters_coupons::instance();
}
