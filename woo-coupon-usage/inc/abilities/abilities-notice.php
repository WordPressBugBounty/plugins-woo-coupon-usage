<?php
/**
 * Coupon Affiliates - Abilities - "Enable AI tools" notice.
 *
 * AI & MCP is off until an administrator switches it on. Most stores will
 * never need it, so it is not advertised to them - but a store that is
 * already set up for AI apps, by running the MCP Adapter or WooCommerce's
 * own MCP feature, very likely wants the affiliate program in there too. For
 * those stores only, a one-time notice on the plugin's own admin pages offers
 * to switch it on.
 *
 * Never shown once anyone has made a choice about AI & MCP, and each admin
 * can dismiss it for good.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_abilities_notice_on_plugin_page' ) ) {
	/**
	 * Whether the current admin screen is one of the plugin's own.
	 *
	 * Narrower than wcusage_is_plugin_admin_screen(), which also takes in the
	 * WooCommerce coupon and order screens, the users list and the dashboard
	 * for styling purposes: this notice belongs on the Coupon Affiliates pages
	 * alone. The API screen is left out too - it has the AI controls on it
	 * already - as is the setup wizard.
	 *
	 * @return bool
	 */
	function wcusage_abilities_notice_on_plugin_page() {

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $page ) {
			return 0 === strpos( $page, 'wcusage' ) && ! in_array( $page, array( 'wcusage_api', 'wcusage_setup' ), true );
		}

		// The plugin's own post types (creatives, bonuses, statements and so
		// on) are listed in its menu too.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && is_string( $screen->post_type ) && 0 === strpos( $screen->post_type, 'wcu-' );
	}
}

if ( ! function_exists( 'wcusage_abilities_notice_reason' ) ) {
	/**
	 * Why this site looks ready for AI tools, or an empty string when it does not.
	 *
	 * @return string "woocommerce", "plugin" or "".
	 */
	function wcusage_abilities_notice_reason() {

		$signals = wcusage_abilities_mcp_signals();

		if ( $signals['woo_enabled'] ) {
			return 'woocommerce';
		}

		return $signals['plugin_active'] ? 'plugin' : '';
	}
}

if ( ! function_exists( 'wcusage_abilities_notice_should_show' ) ) {
	/**
	 * Whether to offer the notice to the current user on this screen.
	 *
	 * Cheapest checks first: this runs on every admin page load.
	 *
	 * @return bool
	 */
	function wcusage_abilities_notice_should_show() {

		if ( ! wcusage_abilities_supported() || wcusage_abilities_enabled() ) {
			return false;
		}

		if ( ! wcusage_abilities_notice_on_plugin_page() ) {
			return false;
		}

		if ( ! function_exists( 'wcusage_check_admin_access' ) || ! wcusage_check_admin_access() ) {
			return false;
		}

		// Somebody has already decided about AI & MCP - including deciding to
		// leave it off, which the notice must not second-guess. The setting is
		// only ever stored by that decision.
		$stored = get_option( 'wcusage_api_settings', array() );
		if ( is_array( $stored ) && array_key_exists( 'abilities_enabled', $stored ) ) {
			return false;
		}

		if ( get_user_meta( get_current_user_id(), 'wcusage_ai_notice_dismissed', true ) ) {
			return false;
		}

		/**
		 * Filter whether to offer the "Enable AI tools" notice.
		 *
		 * @param bool $show Whether this site looks ready for AI tools.
		 */
		return (bool) apply_filters( 'wcusage_abilities_show_notice', '' !== wcusage_abilities_notice_reason() );
	}
}

if ( ! function_exists( 'wcusage_abilities_notice_dismiss_url' ) ) {
	/**
	 * Link that dismisses the notice for the current user, and comes back here.
	 *
	 * @return string
	 */
	function wcusage_abilities_notice_dismiss_url() {
		return add_query_arg(
			array(
				'wcusage_ai_notice' => 'dismiss',
				'_wcu_ai_nonce'     => wp_create_nonce( 'wcusage_ai_notice_dismiss' ),
			)
		);
	}
}

if ( ! function_exists( 'wcusage_abilities_notice_handle_dismiss' ) ) {
	/**
	 * Record a dismissal, then return to the page without the query args.
	 */
	function wcusage_abilities_notice_handle_dismiss() {

		if ( ! isset( $_GET['wcusage_ai_notice'] ) || 'dismiss' !== $_GET['wcusage_ai_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$nonce = isset( $_GET['_wcu_ai_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wcu_ai_nonce'] ) ) : '';

		// A stale or forged link just does nothing: the notice stays until a
		// real click dismisses it.
		if ( wp_verify_nonce( $nonce, 'wcusage_ai_notice_dismiss' ) && get_current_user_id() ) {
			update_user_meta( get_current_user_id(), 'wcusage_ai_notice_dismissed', 1 );
		}

		wp_safe_redirect( remove_query_arg( array( 'wcusage_ai_notice', '_wcu_ai_nonce' ) ) );
		exit;
	}
	add_action( 'admin_init', 'wcusage_abilities_notice_handle_dismiss' );
}

if ( ! function_exists( 'wcusage_abilities_notice_render' ) ) {
	/**
	 * Render the notice.
	 */
	function wcusage_abilities_notice_render() {

		if ( ! wcusage_abilities_notice_should_show() ) {
			return;
		}

		$ai_tab = function_exists( 'wcusage_api_admin_url' ) ? wcusage_api_admin_url( 'ai' ) : admin_url( 'admin.php?page=wcusage_api&tab=ai' );

		$reason = ( 'woocommerce' === wcusage_abilities_notice_reason() )
			? __( 'WooCommerce MCP is switched on for this store, so AI apps can already connect to it.', 'woo-coupon-usage' )
			: __( 'The MCP Adapter is active on this site, so AI apps can already connect to it.', 'woo-coupon-usage' );
		?>
		<div class="notice notice-info wcusage-ai-notice">
			<p><strong><?php esc_html_e( 'Coupon Affiliates can work with your AI apps', 'woo-coupon-usage' ); ?></strong></p>
			<p>
				<?php echo esc_html( $reason ); ?>
				<?php esc_html_e( 'Switch on AI & MCP to let Claude, ChatGPT, Cursor and other AI apps answer questions about your affiliate program - affiliates, coupons, commission and registrations. It starts read-only and for admins only; you decide whether to allow anything more.', 'woo-coupon-usage' ); ?>
			</p>
			<?php
			// A div, not a p: a form inside a paragraph is invalid, and browsers
			// close the paragraph early. The form posts to the API screen's own
			// handler, which checks the capability and nonce, switches AI & MCP
			// on and lands on its tab.
			?>
			<div class="wcusage-ai-notice-actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 12px 0;">
				<form method="post" action="<?php echo esc_url( $ai_tab ); ?>" style="margin:0;">
					<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
					<input type="hidden" name="abilities_enabled" value="1"/>
					<button class="button button-primary" name="wcusage_api_admin_action" value="toggle_abilities"><?php esc_html_e( 'Enable AI tools', 'woo-coupon-usage' ); ?></button>
				</form>
				<a class="button" href="<?php echo esc_url( $ai_tab ); ?>"><?php esc_html_e( 'Learn more', 'woo-coupon-usage' ); ?></a>
				<a href="<?php echo esc_url( wcusage_abilities_notice_dismiss_url() ); ?>"><?php esc_html_e( 'No thanks', 'woo-coupon-usage' ); ?></a>
			</div>
		</div>
		<?php
	}
	add_action( 'admin_notices', 'wcusage_abilities_notice_render' );
}
