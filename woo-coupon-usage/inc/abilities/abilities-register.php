<?php
/**
 * Coupon Affiliates - Abilities - Registration with the Abilities API.
 *
 * Only active abilities are registered. One that is switched off, a write
 * while writes are not allowed, or a PRO ability in the free build does not
 * exist as far as WordPress is concerned - so an agent is never offered a
 * tool it could not use. The API screen lists them all from the definitions
 * instead, which is how it can show and switch on the ones that are off.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_abilities_register_category' ) ) {
	/**
	 * Register the "coupon-affiliates" ability category.
	 */
	function wcusage_abilities_register_category() {

		if ( ! wcusage_abilities_enabled() ) {
			return;
		}

		wp_register_ability_category(
			'coupon-affiliates',
			array(
				'label'       => __( 'Coupon Affiliates', 'woo-coupon-usage' ),
				'description' => __( 'Read and manage the WooCommerce affiliate program run by Coupon Affiliates: affiliates, their coupons, referred orders, commission, registrations and payouts.', 'woo-coupon-usage' ),
			)
		);
	}
	add_action( 'wp_abilities_api_categories_init', 'wcusage_abilities_register_category' );
}

if ( ! function_exists( 'wcusage_abilities_register' ) ) {
	/**
	 * Register every active ability.
	 */
	function wcusage_abilities_register() {

		if ( ! wcusage_abilities_enabled() || ! wp_has_ability_category( 'coupon-affiliates' ) ) {
			return;
		}

		foreach ( wcusage_abilities_active_names() as $name ) {
			wcusage_abilities_register_one( $name, wcusage_abilities_get_definition( $name ) );
		}
	}
	add_action( 'wp_abilities_api_init', 'wcusage_abilities_register' );
}

if ( ! function_exists( 'wcusage_abilities_drop_empty_input' ) ) {
	/**
	 * Treat optional parameters sent as null or "" as not sent at all.
	 *
	 * Some AI apps send every parameter a tool has, with null or an empty
	 * string for the ones they are not using - OpenAI's strict mode does this
	 * by design. The schema would refuse those ("not of type integer"), so they
	 * are removed first and the defaults apply, as if the caller had left them
	 * out. A required parameter removed this way is still reported as missing.
	 *
	 * Hooked to the Abilities API's input filter, which WordPress 7.1 added;
	 * on 6.9 and 7.0 an empty string is still accepted for dates (see the
	 * date pattern), but a null is refused.
	 *
	 * @param mixed  $input        Ability input.
	 * @param string $ability_name Ability name.
	 *
	 * @return mixed
	 */
	function wcusage_abilities_drop_empty_input( $input, $ability_name ) {

		if ( ! is_array( $input ) || 0 !== strpos( (string) $ability_name, 'coupon-affiliates/' ) ) {
			return $input;
		}

		foreach ( $input as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $input[ $key ] );
			}
		}

		return $input;
	}
	add_filter( 'wp_ability_normalize_input', 'wcusage_abilities_drop_empty_input', 10, 2 );
}

if ( ! function_exists( 'wcusage_abilities_register_one' ) ) {
	/**
	 * Register a single ability from its definition.
	 *
	 * @param string $name       Ability name.
	 * @param array  $definition Ability definition.
	 *
	 * @return WP_Ability|null
	 */
	function wcusage_abilities_register_one( $name, $definition ) {

		$is_write = ( 'write' === $definition['type'] );

		return wp_register_ability(
			$name,
			array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => 'coupon-affiliates',
				'input_schema'        => wcusage_abilities_public_schema( $definition['input'] ),
				'output_schema'       => wcusage_abilities_public_schema( $definition['output'] ),
				'execute_callback'    => function ( $input = null ) use ( $name ) {
					return wcusage_abilities_execute( $name, $input );
				},
				'permission_callback' => function ( $input = null ) use ( $name ) {
					return wcusage_abilities_check_permission( $name, $input );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'        => ! $is_write,
						'destructive'     => (bool) $definition['destructive'],
						'idempotent'      => (bool) $definition['idempotent'],
						// The same hints under their MCP names, plus a display
						// title. Current MCP Adapter releases derive these from
						// the WordPress names above, but the older copy bundled
						// with WooCommerce passes annotations on as they are - so
						// without them its clients would never learn which tools
						// only read, and which need confirming first.
						'readOnlyHint'    => ! $is_write,
						'destructiveHint' => (bool) $definition['destructive'],
						'idempotentHint'  => (bool) $definition['idempotent'],
						'title'           => $definition['label'],
					),
					// Available to clients - MCP, AI agents, other plugins. The
					// REST and MCP flags are also set explicitly, because before
					// WordPress 7.1 core does not derive show_in_rest from
					// "public", and older MCP Adapter releases only read
					// mcp.public.
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}
}
