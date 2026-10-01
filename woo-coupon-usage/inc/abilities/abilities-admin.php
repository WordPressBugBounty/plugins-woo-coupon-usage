<?php
/**
 * Coupon Affiliates - Abilities - "AI Agents & MCP" card on the API screen.
 *
 * Rendered on the AI & MCP tab of the API screen (Coupon Affiliates > API,
 * Webhooks & AI) by wcusage_api_admin_page_render(), and saved through the
 * same form handler
 * as the rest of that screen - wcusage_api_admin_handle_post() checks the
 * nonce and capability, then passes any action it does not know to the
 * "wcusage_api_admin_handle_action" filter below.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_abilities_admin_handle_action' ) ) {
	/**
	 * Handle the card's form submissions.
	 *
	 * @param string $redirect Where the screen will redirect to.
	 * @param string $action   Submitted action.
	 *
	 * @return string Redirect target.
	 */
	function wcusage_abilities_admin_handle_action( $redirect, $action ) {

		// The nonce and capability were verified by wcusage_api_admin_handle_post()
		// before this filter runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		switch ( $action ) {

			case 'toggle_abilities':
				$enable = isset( $_POST['abilities_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['abilities_enabled'] ) );
				wcusage_abilities_update_settings( array( 'abilities_enabled' => $enable ? '1' : '0' ) );
				$redirect = add_query_arg( array( 'tab' => 'ai', 'wcusage_api_notice' => $enable ? 'abilities_enabled' : 'abilities_disabled' ), $redirect );
				break;

			case 'save_abilities':
				// As with the endpoint switches: only abilities this build can
				// run are recorded, and the list holds what is switched OFF, so
				// abilities added later start out on. Locked PRO rows never post
				// their checkbox, and recording them as off here would leave them
				// switched off after an upgrade.
				$known = array();
				foreach ( wcusage_abilities_get_definitions() as $name => $definition ) {
					if ( wcusage_abilities_is_available( $definition ) ) {
						$known[] = $name;
					}
				}

				$submitted = isset( $_POST['abilities'] ) ? array_map( 'wcusage_abilities_sanitize_name', (array) wp_unslash( $_POST['abilities'] ) ) : array();

				wcusage_abilities_update_settings(
					array(
						'abilities_write'      => empty( $_POST['abilities_write'] ) ? '0' : '1',
						'abilities_affiliates' => empty( $_POST['abilities_affiliates'] ) ? '0' : '1',
						'disabled_abilities'   => array_values( array_diff( $known, $submitted ) ),
					)
				);

				$redirect = add_query_arg( array( 'tab' => 'ai', 'wcusage_api_notice' => 'abilities_saved' ), $redirect );
				break;

			case 'clear_abilities_log':
				delete_option( 'wcusage_abilities_log' );
				$redirect = add_query_arg( array( 'tab' => 'ai', 'wcusage_api_notice' => 'abilities_log_cleared' ), $redirect );
				break;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $redirect;
	}
	add_filter( 'wcusage_api_admin_handle_action', 'wcusage_abilities_admin_handle_action', 10, 2 );
}

if ( ! function_exists( 'wcusage_abilities_admin_count_registered' ) ) {
	/**
	 * How many of this plugin's abilities WordPress actually has registered.
	 *
	 * Read from the registry rather than from the settings, so the number on
	 * the screen is what an agent would really find.
	 *
	 * @return int
	 */
	function wcusage_abilities_admin_count_registered() {

		if ( ! wcusage_abilities_supported() || ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		$count = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && 0 === strpos( $ability->get_name(), 'coupon-affiliates/' ) ) {
				++$count;
			}
		}

		return $count;
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_status_row' ) ) {
	/**
	 * One line of the connection checklist.
	 *
	 * @param string $state  "ok", "missing" or "pending".
	 * @param string $title  What is being checked.
	 * @param string $detail Explanation. May contain links - escape before passing.
	 */
	function wcusage_abilities_admin_status_row( $state, $title, $detail ) {

		$icons = array(
			'ok'      => 'fa-circle-check',
			'missing' => 'fa-circle-xmark',
			'pending' => 'fa-circle-minus',
		);
		?>
		<li class="wcu-api-ai-status-<?php echo esc_attr( $state ); ?>">
			<i class="fas <?php echo esc_attr( isset( $icons[ $state ] ) ? $icons[ $state ] : $icons['pending'] ); ?>" aria-hidden="true"></i>
			<div>
				<strong><?php echo esc_html( $title ); ?></strong>
				<span><?php echo wp_kses( $detail, array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ), 'code' => array() ) ); ?></span>
			</div>
		</li>
		<?php
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_client_config' ) ) {
	/**
	 * Example MCP client configuration for this site.
	 *
	 * Uses Automattic's mcp-wordpress-remote proxy, which is what the MCP
	 * Adapter documents for desktop clients such as Claude Desktop, with an
	 * application password for the current user.
	 *
	 * @param string $url MCP server URL.
	 *
	 * @return string Pretty-printed JSON.
	 */
	function wcusage_abilities_admin_client_config( $url ) {

		$user = wp_get_current_user();

		$config = array(
			'mcpServers' => array(
				'coupon-affiliates' => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => $url,
						'WP_API_USERNAME' => $user && $user->exists() ? $user->user_login : 'your-username',
						'WP_API_PASSWORD' => 'your-application-password',
						'OAUTH_ENABLED'   => 'false',
					),
				),
			),
		);

		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_connector_plugins' ) ) {
	/**
	 * MCP connector plugins to suggest for claude.ai, ChatGPT and the like.
	 *
	 * Those connectors reach a site from the AI company's servers and sign in
	 * with OAuth, which neither the MCP Adapter nor WooCommerce MCP provides,
	 * so they cannot use this plugin's MCP server directly. Each plugin listed
	 * here brings its own OAuth sign-in and exposes abilities registered by
	 * other plugins, which is what puts the Coupon Affiliates tools in front
	 * of those apps. All free, on WordPress.org.
	 *
	 * @return array WordPress.org slug => array( name, note ).
	 */
	function wcusage_abilities_admin_connector_plugins() {

		$plugins = array(
			'easy-mcp-ai'              => array(
				'name' => 'Easy MCP AI',
				'note' => __( 'OAuth sign-in for Claude and ChatGPT. Tick the Coupon Affiliates abilities under its "Abilities" screen - including any you allow here later, such as actions that change data.', 'woo-coupon-usage' ),
			),
			'royal-mcp'                => array(
				'name' => 'Royal MCP',
				'note' => __( 'OAuth sign-in for Claude and ChatGPT. Includes every ability registered on the site automatically.', 'woo-coupon-usage' ),
			),
			'enable-abilities-for-mcp' => array(
				'name' => 'Enable Abilities for MCP',
				'note' => __( 'OAuth sign-in for claude.ai, with ChatGPT in beta. Works on top of the MCP Adapter plugin; Coupon Affiliates is listed under its "Third-party" abilities.', 'woo-coupon-usage' ),
			),
		);

		/**
		 * Filter the MCP connector plugins suggested on the AI & MCP tab.
		 *
		 * @param array $plugins WordPress.org slug => array( name, note ).
		 */
		return (array) apply_filters( 'wcusage_abilities_connector_plugins', $plugins );
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_active_connector' ) ) {
	/**
	 * The suggested connector plugin active on this site, if any.
	 *
	 * @return string Its name, or an empty string.
	 */
	function wcusage_abilities_admin_active_connector() {

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( wcusage_abilities_admin_connector_plugins() as $slug => $plugin ) {
			foreach ( $active as $file ) {
				if ( 0 === strpos( (string) $file, $slug . '/' ) ) {
					return isset( $plugin['name'] ) ? (string) $plugin['name'] : (string) $slug;
				}
			}
		}

		return '';
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_connector_gaps' ) ) {
	/**
	 * Coupon Affiliates abilities a connector plugin is not sharing yet.
	 *
	 * Some connector plugins only turn the abilities ticked on their own
	 * settings screen into tools, so anything registered afterwards - such as
	 * the actions that change data, once they are allowed - is left out until
	 * it is ticked there too. Easy MCP AI keeps that list in an option, so it
	 * can be checked here; others can be added through the filter.
	 *
	 * Sets up the abilities registry, so call it after the MCP status.
	 *
	 * @return array Plugin name => array( 'url' => its abilities screen, 'missing' => ability names ).
	 */
	function wcusage_abilities_admin_connector_gaps() {

		$gaps = array();

		if ( defined( 'EASY_MCP_AI_VERSION' ) && function_exists( 'wp_has_ability' ) ) {
			$shared  = (array) get_option( 'easy_mcp_ai_enabled_abilities', array() );
			$missing = array();
			foreach ( wcusage_abilities_active_names() as $name ) {
				if ( wp_has_ability( $name ) && ! in_array( $name, $shared, true ) ) {
					$missing[] = $name;
				}
			}
			if ( $missing ) {
				$gaps['Easy MCP AI'] = array(
					'url'     => admin_url( 'admin.php?page=easy-mcp-ai-abilities' ),
					'missing' => $missing,
				);
			}
		}

		/**
		 * Filter the Coupon Affiliates abilities connector plugins are not sharing.
		 *
		 * @param array $gaps Plugin name => array( 'url' => its abilities screen, 'missing' => ability names ).
		 */
		return (array) apply_filters( 'wcusage_abilities_connector_gaps', $gaps );
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_connector_gap_text' ) ) {
	/**
	 * Explain which abilities a connector plugin is leaving out.
	 *
	 * @param array $gaps From wcusage_abilities_admin_connector_gaps().
	 *
	 * @return string Escaped HTML, or an empty string when nothing is missing.
	 */
	function wcusage_abilities_admin_connector_gap_text( $gaps ) {

		$definitions = wcusage_abilities_get_definitions();
		$total       = count( wcusage_abilities_active_names() );
		$parts       = array();

		foreach ( (array) $gaps as $plugin => $gap ) {
			$missing = empty( $gap['missing'] ) ? array() : array_values( (array) $gap['missing'] );
			if ( ! $missing ) {
				continue;
			}

			if ( count( $missing ) >= $total ) {
				$text = sprintf(
					/* translators: %s: name of an MCP connector plugin */
					__( '%s is active, but it only shares the abilities ticked on its own Abilities screen, and none of the Coupon Affiliates abilities are ticked there yet.', 'woo-coupon-usage' ),
					$plugin
				);
			} else {
				$labels = array();
				foreach ( $missing as $name ) {
					$labels[] = isset( $definitions[ $name ]['label'] ) ? $definitions[ $name ]['label'] : $name;
				}
				$text = sprintf(
					/* translators: 1: name of an MCP connector plugin, 2: comma-separated ability names */
					_n(
						'%1$s is active, but it only shares the abilities ticked on its own Abilities screen, and this Coupon Affiliates ability is not ticked there yet: %2$s.',
						'%1$s is active, but it only shares the abilities ticked on its own Abilities screen, and these Coupon Affiliates abilities are not ticked there yet: %2$s.',
						count( $missing ),
						'woo-coupon-usage'
					),
					$plugin,
					implode( ', ', $labels )
				);
			}

			$html = esc_html( $text );
			if ( ! empty( $gap['url'] ) ) {
				$link  = '<a href="' . esc_url( $gap['url'] ) . '">' . esc_html( $plugin . ' > ' . __( 'Abilities', 'woo-coupon-usage' ) ) . '</a>';
				$html .= ' ' . sprintf(
					/* translators: %s: link to the connector plugin's abilities screen */
					esc_html( _n( 'Tick it under %s.', 'Tick them under %s.', count( $missing ), 'woo-coupon-usage' ) ),
					$link
				);
			}
			$parts[] = $html;
		}

		if ( ! $parts ) {
			return '';
		}

		return implode( ' ', $parts ) . ' ' . esc_html__( 'Claude, ChatGPT and other AI apps load their tools when a chat starts, so start a new chat afterwards.', 'woo-coupon-usage' );
	}
}

if ( ! function_exists( 'wcusage_abilities_admin_render_card' ) ) {
	/**
	 * Render the "AI Agents & MCP" card.
	 */
	function wcusage_abilities_admin_render_card() {

		global $wp_version;

		$supported   = wcusage_abilities_supported();
		$settings    = wcusage_abilities_get_settings();
		$enabled     = ( '1' === $settings['abilities_enabled'] );
		$definitions = wcusage_abilities_get_definitions();
		$groups      = wcusage_abilities_groups();

		// MCP first: an adapter only hooks its own abilities into the registry
		// as it starts up, so counting abilities - which sets the registry up -
		// before it has started would leave the adapter's abilities out.
		$mcp        = wcusage_abilities_mcp_status();
		$registered = $supported ? wcusage_abilities_admin_count_registered() : 0;
		$log        = wcusage_abilities_get_log();

		$app_passwords = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

		$pro_url      = 'https://couponaffiliates.com/pricing?utm_campaign=plugin&utm_source=api-page&utm_medium=abilities-locked';
		$adapter_url  = 'https://github.com/WordPress/mcp-adapter/releases/latest';
		$abilities_on = $supported && $enabled;
		?>

		<div class="wcu-api-card wcu-api-ai <?php echo $abilities_on ? 'is-on' : 'is-off'; ?>" id="wcu-api-ai">

			<div class="wcu-api-card-head">
				<div>
					<h2>
						<i class="fas fa-robot" aria-hidden="true"></i><?php esc_html_e( 'AI Agents & MCP', 'woo-coupon-usage' ); ?>
						<span class="wcu-api-badge wcu-api-badge-<?php echo $abilities_on ? 'active' : 'inactive'; ?>">
							<?php echo $abilities_on ? esc_html__( 'Enabled', 'woo-coupon-usage' ) : esc_html__( 'Disabled', 'woo-coupon-usage' ); ?>
						</span>
					</h2>
					<p><?php esc_html_e( 'Let AI assistants (Claude, ChatGPT, Cursor and others), store agents and other plugins read your affiliate program - and, if you allow it, act on it - through the WordPress Abilities API.', 'woo-coupon-usage' ); ?></p>
				</div>
				<?php
				// Nothing to switch on without the Abilities API: the checklist
				// below says what is needed instead. A switch here would also
				// disagree with the badge, which reads "Disabled" regardless.
				if ( $supported ) :
					?>
				<form method="post" class="wcu-api-ai-toggle">
					<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
					<input type="hidden" name="abilities_enabled" value="<?php echo $enabled ? '0' : '1'; ?>"/>
					<button class="button <?php echo $enabled ? '' : 'button-primary'; ?>" name="wcusage_api_admin_action" value="toggle_abilities"
						<?php if ( $enabled ) : ?>
							onclick="return confirm('<?php echo esc_js( __( 'Switch off AI abilities? Any AI assistant or plugin using them will lose access straight away.', 'woo-coupon-usage' ) ); ?>');"
						<?php endif; ?>
					>
						<?php echo $enabled ? esc_html__( 'Disable AI abilities', 'woo-coupon-usage' ) : esc_html__( 'Enable AI abilities', 'woo-coupon-usage' ); ?>
					</button>
				</form>
				<?php endif; ?>
			</div>

			<div class="wcu-api-card-body">

				<ul class="wcu-api-ai-status">
					<?php
					// 1. The Abilities API itself.
					if ( ! $supported ) {
						wcusage_abilities_admin_status_row(
							'missing',
							__( 'WordPress Abilities API', 'woo-coupon-usage' ),
							esc_html(
								sprintf(
									/* translators: %s: installed WordPress version */
									__( 'Needs WordPress 6.9 or later. This site runs WordPress %s - update WordPress to use AI abilities.', 'woo-coupon-usage' ),
									$wp_version
								)
							)
						);
					} elseif ( ! $enabled ) {
						wcusage_abilities_admin_status_row(
							'pending',
							__( 'WordPress Abilities API', 'woo-coupon-usage' ),
							esc_html__( 'Available, but AI abilities are switched off, so none are registered.', 'woo-coupon-usage' )
						);
					} else {
						wcusage_abilities_admin_status_row(
							$registered ? 'ok' : 'pending',
							__( 'WordPress Abilities API', 'woo-coupon-usage' ),
							esc_html(
								sprintf(
									/* translators: 1: WordPress version, 2: number of abilities */
									_n( 'WordPress %1$s - %2$d Coupon Affiliates ability registered. Other plugins can use it now.', 'WordPress %1$s - %2$d Coupon Affiliates abilities registered. Other plugins can use them now.', $registered, 'woo-coupon-usage' ),
									$wp_version,
									$registered
								)
							)
						);
					}

					// 2. The MCP Adapter - the standalone plugin, or the copy inside
					// WooCommerce, which only runs when its MCP feature is on.
					$get_adapter = ' <a href="' . esc_url( $adapter_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get the MCP Adapter', 'woo-coupon-usage' ) . '</a>';

					if ( $mcp['running'] ) {
						switch ( $mcp['source'] ) {
							case 'woocommerce':
								/* translators: %s: MCP Adapter version */
								$adapter_detail = __( 'Running through WooCommerce\'s "WooCommerce MCP" feature (adapter version %s). AI apps can connect over MCP.', 'woo-coupon-usage' );
								break;
							case 'other':
								/* translators: %s: MCP Adapter version */
								$adapter_detail = __( 'Running (version %s), loaded by another plugin. AI apps can connect over MCP.', 'woo-coupon-usage' );
								break;
							default:
								/* translators: %s: MCP Adapter version */
								$adapter_detail = __( 'Active (version %s). AI apps can connect over MCP.', 'woo-coupon-usage' );
						}
						wcusage_abilities_admin_status_row(
							'ok',
							__( 'MCP Adapter', 'woo-coupon-usage' ),
							esc_html( sprintf( $adapter_detail, $mcp['version'] ? $mcp['version'] : __( 'unknown', 'woo-coupon-usage' ) ) )
						);
					} elseif ( $mcp['plugin_installed'] ) {
						$activate = current_user_can( 'activate_plugins' )
							? ' <a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=mcp-adapter/mcp-adapter.php' ), 'activate-plugin_mcp-adapter/mcp-adapter.php' ) ) . '">' . esc_html__( 'Activate it', 'woo-coupon-usage' ) . '</a>'
							: '';
						wcusage_abilities_admin_status_row(
							'missing',
							__( 'MCP Adapter', 'woo-coupon-usage' ),
							esc_html__( 'Installed but not active.', 'woo-coupon-usage' ) . $activate
						);
					} elseif ( $mcp['woo_bundled'] ) {
						wcusage_abilities_admin_status_row(
							'pending',
							__( 'MCP Adapter', 'woo-coupon-usage' ),
							esc_html__( 'Not running. Install the free WordPress MCP Adapter plugin, or switch on the experimental "WooCommerce MCP" feature, which includes it, so AI apps such as Claude, ChatGPT and Cursor can connect to this site.', 'woo-coupon-usage' )
								. $get_adapter
								. ' | <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ) . '">' . esc_html__( 'WooCommerce features', 'woo-coupon-usage' ) . '</a>'
						);
					} else {
						wcusage_abilities_admin_status_row(
							'pending',
							__( 'MCP Adapter', 'woo-coupon-usage' ),
							esc_html__( 'Not installed. Install the free WordPress MCP Adapter plugin so AI apps such as Claude, ChatGPT and Cursor can connect to this site.', 'woo-coupon-usage' ) . $get_adapter
						);
					}

					// 3. This plugin's MCP server.
					if ( $mcp['server_live'] ) {
						wcusage_abilities_admin_status_row(
							'ok',
							__( 'Coupon Affiliates MCP server', 'woo-coupon-usage' ),
							esc_html__( 'Live. Every enabled ability below is a tool on it.', 'woo-coupon-usage' )
						);
					} elseif ( $mcp['running'] && '' !== $mcp['server_error'] ) {
						wcusage_abilities_admin_status_row(
							'missing',
							__( 'Coupon Affiliates MCP server', 'woo-coupon-usage' ),
							esc_html(
								sprintf(
									/* translators: %s: error message from the MCP Adapter */
									__( 'The MCP Adapter could not create it: %s', 'woo-coupon-usage' ),
									$mcp['server_error']
								)
							)
						);
					} else {
						wcusage_abilities_admin_status_row(
							'pending',
							__( 'Coupon Affiliates MCP server', 'woo-coupon-usage' ),
							$mcp['running']
								? esc_html__( 'Starts once AI abilities are enabled and at least one ability is switched on.', 'woo-coupon-usage' )
								: esc_html__( 'Starts automatically once an MCP Adapter is running.', 'woo-coupon-usage' )
						);
					}

					// 4. How clients sign in.
					wcusage_abilities_admin_status_row(
						$app_passwords ? 'ok' : 'missing',
						__( 'Application passwords', 'woo-coupon-usage' ),
						$app_passwords
							? esc_html__( 'Available. AI apps sign in as a WordPress user with an application password, and get exactly that user\'s access.', 'woo-coupon-usage' )
								. ' <a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Create one', 'woo-coupon-usage' ) . '</a>'
							: esc_html__( 'Not available on this site. WordPress only offers application passwords over HTTPS, and a plugin or setting may have turned them off.', 'woo-coupon-usage' )
					);

					// 5. claude.ai, ChatGPT and other web connectors, which need
					// an OAuth sign-in this plugin's own server does not offer.
					// A connector plugin that picks abilities one by one may be
					// leaving some out, which is flagged instead of a tick.
					$connector = wcusage_abilities_admin_active_connector();
					$gap_text  = $abilities_on ? wcusage_abilities_admin_connector_gap_text( wcusage_abilities_admin_connector_gaps() ) : '';
					if ( '' !== $gap_text ) {
						$connector_state  = 'pending';
						$connector_detail = $gap_text;
					} elseif ( '' !== $connector ) {
						$connector_state  = 'ok';
						$connector_detail = esc_html(
							sprintf(
								/* translators: %s: name of an MCP connector plugin */
								__( '%s is active, so claude.ai, ChatGPT and other web connectors can sign in through it and reach these abilities.', 'woo-coupon-usage' ),
								$connector
							)
						);
					} else {
						$connector_state  = 'pending';
						$connector_detail = esc_html__( 'These sign in with OAuth, so they need an MCP connector plugin with its own sign-in - see "Connect from claude.ai or ChatGPT" below. Claude Desktop, Claude Code and Cursor do not.', 'woo-coupon-usage' );
					}
					wcusage_abilities_admin_status_row( $connector_state, __( 'claude.ai & ChatGPT connectors', 'woo-coupon-usage' ), $connector_detail );
					?>
				</ul>

				<?php
				if ( $mcp['server_live'] ) {
					wcusage_api_admin_copy_field( __( 'MCP server', 'woo-coupon-usage' ), $mcp['server_url'] );
				}
				?>
			</div>

			<details class="wcu-api-form">
				<summary><?php esc_html_e( 'Connect from claude.ai or ChatGPT', 'woo-coupon-usage' ); ?></summary>
				<div class="wcu-api-form-body wcu-api-ai-connect">
					<p>
						<?php esc_html_e( 'claude.ai, ChatGPT and Claude Desktop\'s "Add custom connector" reach your site from the AI company\'s servers and sign in with OAuth. The MCP Adapter and WooCommerce MCP do not provide OAuth, so pointing these at the MCP server on this page fails at the sign-in step. Use an MCP connector plugin that has its own sign-in and includes other plugins\' abilities instead, such as:', 'woo-coupon-usage' ); ?>
					</p>
					<ul class="wcu-api-ai-connectors">
						<?php foreach ( wcusage_abilities_admin_connector_plugins() as $slug => $plugin ) : ?>
							<?php
							if ( empty( $plugin['name'] ) ) {
								continue;
							}
							?>
							<li>
								<a href="<?php echo esc_url( 'https://wordpress.org/plugins/' . sanitize_title( $slug ) . '/' ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $plugin['name'] ); ?></strong></a>
								<?php if ( $plugin['name'] === $connector ) : ?>
									<span class="wcu-api-badge wcu-api-badge-active"><?php esc_html_e( 'Active on this site', 'woo-coupon-usage' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $plugin['note'] ) ) : ?>
									- <?php echo esc_html( $plugin['note'] ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<ol>
						<li><?php esc_html_e( 'Keep AI abilities enabled on this tab: a connector plugin can only offer the abilities that are switched on here.', 'woo-coupon-usage' ); ?></li>
						<li><?php esc_html_e( 'Install one of these plugins, then add the MCP server URL shown in its settings as a custom connector in claude.ai or ChatGPT, and sign in to WordPress when asked.', 'woo-coupon-usage' ); ?></li>
						<li><?php esc_html_e( 'The Coupon Affiliates tools appear alongside the plugin\'s own. Actions that change data only appear if you allow them below - and, with a plugin that lists abilities one by one, tick them there too. AI apps load their tools when a chat starts, so start a new chat after any change.', 'woo-coupon-usage' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'These are third-party plugins, not made by Coupon Affiliates. Your site also has to be reachable from the internet for these connectors to reach it.', 'woo-coupon-usage' ); ?></p>
				</div>
			</details>

			<details class="wcu-api-form">
				<summary><?php esc_html_e( 'Connect Claude Desktop, Claude Code or Cursor', 'woo-coupon-usage' ); ?></summary>
				<div class="wcu-api-form-body wcu-api-ai-connect">
					<ol>
						<li>
							<?php
							if ( $mcp['running'] ) {
								esc_html_e( 'An MCP Adapter is already running on this site - nothing to install.', 'woo-coupon-usage' );
							} else {
								printf(
									/* translators: %s: link to the MCP Adapter plugin */
									esc_html__( 'Install and activate the %s plugin on this site.', 'woo-coupon-usage' ),
									'<a href="' . esc_url( $adapter_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress MCP Adapter', 'woo-coupon-usage' ) . '</a>'
								);
								if ( $mcp['woo_bundled'] ) {
									echo ' ';
									esc_html_e( 'Or switch on WooCommerce\'s experimental "WooCommerce MCP" feature, which includes it.', 'woo-coupon-usage' );
								}
							}
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: link to the user's profile */
								esc_html__( 'Create an application password for the user the AI app should act as, under %s. Use an administrator for the whole program, or - with affiliate access switched on below - an affiliate\'s own account for just their data.', 'woo-coupon-usage' ),
								'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Users > Profile > Application Passwords', 'woo-coupon-usage' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Add this server to your AI app\'s MCP settings - for Claude Desktop, that is claude_desktop_config.json - with the password in place of "your-application-password":', 'woo-coupon-usage' ); ?></li>
					</ol>

					<?php $config = wcusage_abilities_admin_client_config( $mcp['server_url'] ); ?>
					<div class="wcu-api-ai-config">
						<pre><code><?php echo esc_html( $config ); ?></code></pre>
						<button type="button" class="button button-small wcu-api-copy-btn" data-copy="<?php echo esc_attr( $config ); ?>">
							<i class="fas fa-copy" aria-hidden="true"></i> <?php esc_html_e( 'Copy', 'woo-coupon-usage' ); ?>
						</button>
						<span class="wcu-api-copied"><?php esc_html_e( 'Copied', 'woo-coupon-usage' ); ?></span>
					</div>

					<p class="description">
						<?php
						printf(
							/* translators: %s: MCP server URL */
							esc_html__( 'Tools appear in the app as coupon-affiliates-list-affiliates, coupon-affiliates-get-program-summary and so on. The server lives at %s.', 'woo-coupon-usage' ),
							'<code>' . esc_html( $mcp['server_url'] ) . '</code>'
						);
						echo ' ';
						if ( $mcp['default_live'] ) {
							printf(
								/* translators: %s: the MCP Adapter's default server URL */
								esc_html__( 'The same abilities are also offered through the MCP Adapter\'s default server at %s.', 'woo-coupon-usage' ),
								'<code>' . esc_html( $mcp['default_url'] ) . '</code>'
							);
							echo ' ';
						}
						esc_html_e( 'Other plugins, such as store assistants, can use the abilities directly without MCP.', 'woo-coupon-usage' );
						?>
					</p>
				</div>
			</details>

			<form method="post">
				<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>

				<div class="wcu-api-form-body wcu-api-ai-options">
					<label>
						<input type="checkbox" name="abilities_write" value="1" <?php checked( '1', $settings['abilities_write'] ); ?> id="wcu-api-ai-write"/>
						<strong><?php esc_html_e( 'Allow actions that change data', 'woo-coupon-usage' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Adding affiliates, assigning and removing their coupons, approving or declining registrations, requesting payouts and updating payout statuses. Off by default: with it off, AI apps can only read.', 'woo-coupon-usage' ); ?></span>
					</label>
					<label>
						<input type="checkbox" name="abilities_affiliates" value="1" <?php checked( '1', $settings['abilities_affiliates'] ); ?>/>
						<strong><?php esc_html_e( 'Let affiliates connect their own AI app', 'woo-coupon-usage' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Affiliates signed in with their own application password can use the abilities marked "Admins + the affiliate", and see exactly what their own affiliate dashboard shows them: their own coupons, stats and payouts - plus, with Multi-Level Affiliates, the coupons of affiliates below them.', 'woo-coupon-usage' ); ?></span>
					</label>
				</div>

				<table class="wcu-api-table wcu-api-ability-table">
					<thead>
						<tr>
							<th class="wcu-api-enable-col"><?php esc_html_e( 'On', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Ability', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'What it does', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Who can use it', 'woo-coupon-usage' ); ?></th>
							<th><?php esc_html_e( 'Type', 'woo-coupon-usage' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$access_labels = array(
							'admin'     => __( 'Admins', 'woo-coupon-usage' ),
							'affiliate' => __( 'Admins + the affiliate', 'woo-coupon-usage' ),
						);

						foreach ( $groups as $group => $group_label ) :

							$in_group = array();
							foreach ( $definitions as $name => $definition ) {
								if ( isset( $definition['group'] ) && $group === $definition['group'] ) {
									$in_group[ $name ] = $definition;
								}
							}

							if ( empty( $in_group ) ) {
								continue;
							}
							?>
							<tr class="wcu-api-ai-group">
								<th colspan="5"><?php echo esc_html( $group_label ); ?></th>
							</tr>
							<?php
							foreach ( $in_group as $name => $definition ) :

								$locked   = ! wcusage_abilities_is_available( $definition );
								$switched = ! $locked && ! in_array( $name, $settings['disabled_abilities'], true );
								$is_write = ( 'write' === $definition['type'] );
								$blocked  = $is_write && '1' !== $settings['abilities_write'];

								$row_classes = array();
								if ( ! $switched || $blocked || ! $abilities_on ) {
									$row_classes[] = 'wcu-api-row-off';
								}
								if ( $locked ) {
									$row_classes[] = 'wcu-api-row-pro';
								}
								?>
								<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>">
									<td class="wcu-api-enable-col">
										<input type="checkbox" name="abilities[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $switched ); ?> <?php disabled( $locked ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ability name */ __( 'Enable %s', 'woo-coupon-usage' ), $definition['label'] ) ); ?>"/>
									</td>
									<td>
										<strong><?php echo esc_html( $definition['label'] ); ?></strong>
										<?php if ( $locked ) : ?>
											<span class="wcu-api-badge wcu-api-badge-pro"><i class="fas fa-lock" aria-hidden="true"></i> <?php esc_html_e( 'PRO', 'woo-coupon-usage' ); ?></span>
										<?php endif; ?>
										<br/><code><?php echo esc_html( $name ); ?></code>
									</td>
									<td class="wcu-api-desc">
										<?php echo esc_html( $definition['description'] ); ?>
										<?php if ( $blocked && ! $locked ) : ?>
											<br/><em class="wcu-api-ai-note"><?php esc_html_e( 'Needs "Allow actions that change data" above.', 'woo-coupon-usage' ); ?></em>
										<?php endif; ?>
									</td>
									<td>
										<span class="wcu-api-badge wcu-api-badge-<?php echo ( 'admin' === $definition['access'] ) ? 'inactive' : 'active'; ?>">
											<?php echo esc_html( isset( $access_labels[ $definition['access'] ] ) ? $access_labels[ $definition['access'] ] : $definition['access'] ); ?>
										</span>
									</td>
									<td>
										<?php if ( $is_write ) : ?>
											<span class="wcu-api-ai-type wcu-api-ai-type-write"><?php esc_html_e( 'Write', 'woo-coupon-usage' ); ?></span>
											<?php if ( $definition['destructive'] ) : ?>
												<span class="wcu-api-ai-type wcu-api-ai-type-destructive" title="<?php esc_attr_e( 'Can move money or change balances. AI apps are told to confirm with you first.', 'woo-coupon-usage' ); ?>"><?php esc_html_e( 'High impact', 'woo-coupon-usage' ); ?></span>
											<?php endif; ?>
										<?php else : ?>
											<span class="wcu-api-ai-type wcu-api-ai-type-read"><?php esc_html_e( 'Read', 'woo-coupon-usage' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<?php
							endforeach;
						endforeach;
						?>
					</tbody>
				</table>

				<div class="wcu-api-form-body wcu-api-endpoint-save">
					<button class="button button-primary" name="wcusage_api_admin_action" value="save_abilities"><?php esc_html_e( 'Save AI settings', 'woo-coupon-usage' ); ?></button>
					<button type="button" class="button" id="wcu-api-ai-all"><?php esc_html_e( 'Enable all', 'woo-coupon-usage' ); ?></button>
					<button type="button" class="button" id="wcu-api-ai-none"><?php esc_html_e( 'Disable all', 'woo-coupon-usage' ); ?></button>
					<?php if ( ! wcusage_abilities_is_pro_build() ) : ?>
						<a class="button" href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-star" aria-hidden="true"></i> <?php esc_html_e( 'Unlock payout abilities with PRO', 'woo-coupon-usage' ); ?></a>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Every ability checks the connected user\'s permissions on each call, exactly like the REST API. These switches are independent of the REST API tab - AI abilities work whether or not the REST API is enabled.', 'woo-coupon-usage' ); ?></p>
				</div>
			</form>

			<details class="wcu-api-form">
				<summary>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of recent calls */
							__( 'Recent AI activity (%d)', 'woo-coupon-usage' ),
							count( $log )
						)
					);
					?>
				</summary>

				<?php if ( empty( $log ) ) : ?>
					<div class="wcu-api-empty">
						<i class="fas fa-robot" aria-hidden="true"></i>
						<?php esc_html_e( 'No AI activity yet. Calls to these abilities will show up here.', 'woo-coupon-usage' ); ?>
					</div>
				<?php else : ?>
					<table class="wcu-api-table wcu-api-ai-log">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Ability', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'User', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Via', 'woo-coupon-usage' ); ?></th>
								<th><?php esc_html_e( 'Result', 'woo-coupon-usage' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$channels = array(
								'mcp'  => __( 'MCP', 'woo-coupon-usage' ),
								'rest' => __( 'REST API', 'woo-coupon-usage' ),
								'cli'  => __( 'WP-CLI', 'woo-coupon-usage' ),
								'php'  => __( 'Plugin', 'woo-coupon-usage' ),
							);

							foreach ( $log as $entry ) :
								$log_user   = $entry['user_id'] ? get_userdata( (int) $entry['user_id'] ) : false;
								$definition = wcusage_abilities_get_definition( $entry['ability'] );
								?>
								<tr>
									<td title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: human-readable time difference */
												__( '%s ago', 'woo-coupon-usage' ),
												human_time_diff( (int) $entry['time'] )
											)
										);
										?>
									</td>
									<td>
										<?php echo esc_html( $definition ? $definition['label'] : $entry['ability'] ); ?>
										<?php if ( $entry['target'] ) : ?>
											<code>#<?php echo (int) $entry['target']; ?></code>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $log_user ) : ?>
											<a href="<?php echo esc_url( get_edit_user_link( $log_user->ID ) ); ?>"><?php echo esc_html( $log_user->user_login ); ?></a>
										<?php else : ?>
											&mdash;
										<?php endif; ?>
									</td>
									<td><span class="wcu-api-scope"><?php echo esc_html( isset( $channels[ $entry['channel'] ] ) ? $channels[ $entry['channel'] ] : $entry['channel'] ); ?></span></td>
									<td>
										<?php if ( $entry['ok'] ) : ?>
											<span class="wcu-api-badge wcu-api-badge-active"><?php esc_html_e( 'OK', 'woo-coupon-usage' ); ?></span>
										<?php else : ?>
											<span class="wcu-api-badge wcu-api-badge-error"><?php esc_html_e( 'Failed', 'woo-coupon-usage' ); ?></span>
											<span class="wcu-api-ai-note"><?php echo esc_html( $entry['error'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="wcu-api-form-body wcu-api-endpoint-save">
						<form method="post">
							<?php wp_nonce_field( 'wcusage_api_admin', 'wcusage_api_admin_nonce' ); ?>
							<button class="button" name="wcusage_api_admin_action" value="clear_abilities_log"><?php esc_html_e( 'Clear activity', 'woo-coupon-usage' ); ?></button>
						</form>
					</div>
				<?php endif; ?>
			</details>
		</div>

		<script>
		( function () {
			var card = document.getElementById( 'wcu-api-ai' );
			if ( ! card ) { return; }

			var setAll = function ( state ) {
				card.querySelectorAll( 'input[name="abilities[]"]' ).forEach( function ( box ) {
					// Locked PRO rows have nothing behind them to switch on.
					if ( ! box.disabled ) { box.checked = state; }
				} );
			};
			var all  = document.getElementById( 'wcu-api-ai-all' );
			var none = document.getElementById( 'wcu-api-ai-none' );
			if ( all ) { all.addEventListener( 'click', function () { setAll( true ); } ); }
			if ( none ) { none.addEventListener( 'click', function () { setAll( false ); } ); }

			// Switching writes on lets an AI app change real data, so make that
			// a deliberate choice rather than a stray click.
			var write = document.getElementById( 'wcu-api-ai-write' );
			if ( write ) {
				write.addEventListener( 'change', function () {
					if ( write.checked && ! window.confirm( <?php echo wp_json_encode( __( 'Allow AI apps to add affiliates, assign coupons, approve registrations and manage payouts? They act with the permissions of the user they sign in as, and are told to confirm with you before each change.', 'woo-coupon-usage' ) ); ?> ) ) {
						write.checked = false;
					}
				} );
			}
		}() );
		</script>
		<?php
	}
}
