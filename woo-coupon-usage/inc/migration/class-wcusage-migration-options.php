<?php
/**
 * The choices an admin makes before migrating.
 *
 * One schema drives the options form, the defaults and the sanitising of what
 * is posted back, so the three cannot drift apart. Each source can add options
 * of its own (WCUsage_Migration_Source::get_source_options()) and change the
 * defaults of these (get_option_defaults()).
 *
 * Option entries:
 *   type     'checkbox' | 'select' | 'text' | 'number' | 'date'
 *   section  Which part of the form it sits in.
 *   label, desc
 *   default
 *   choices  For selects: value => label.
 *   requires A kind of data (see WCUsage_Migration_Source::get_supported())
 *            the source must provide for the option to apply.
 *   pro      Only available with PRO.
 *   parent   Only shown while this checkbox is ticked.
 *
 * @package Coupon Affiliates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCUsage_Migration_Options {

	/**
	 * Form sections, in order.
	 *
	 * @return array key => title
	 */
	public static function sections() {
		return array(
			'data'      => __( 'What to migrate', 'woo-coupon-usage' ),
			'affiliates' => __( 'Affiliates', 'woo-coupon-usage' ),
			'coupons'   => __( 'Coupons', 'woo-coupon-usage' ),
			'referrals' => __( 'Referral history & commission', 'woo-coupon-usage' ),
			'balances'  => __( 'Balances & payouts', 'woo-coupon-usage' ),
			'visits'    => __( 'Referral visits', 'woo-coupon-usage' ),
			'settings'  => __( 'Program settings', 'woo-coupon-usage' ),
			'links'     => __( 'Old referral links', 'woo-coupon-usage' ),
			'source'    => __( 'Source-specific options', 'woo-coupon-usage' ),
			'advanced'  => __( 'Advanced', 'woo-coupon-usage' ),
		);
	}

	/**
	 * The general options.
	 *
	 * @param string $source_name Name of the plugin being migrated from, for labels.
	 * @return array
	 */
	public static function general_schema( $source_name = '' ) {

		$role      = function_exists( 'wcusage_get_setting_value' ) ? wcusage_get_setting_value( 'wcusage_field_registration_accepted_role', 'coupon_affiliate' ) : 'coupon_affiliate';
		$template  = function_exists( 'wcusage_get_setting_value' ) ? (string) wcusage_get_setting_value( 'wcusage_field_registration_coupon_template', '' ) : '';

		return array(

			// What to migrate.
			'migrate_affiliates'     => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Affiliates and their coupons', 'woo-coupon-usage' ),
				'desc'     => __( 'Required. Every other kind of data belongs to an affiliate.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'affiliates',
				'locked'   => true,
			),
			'migrate_registrations'  => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Pending and rejected applications', 'woo-coupon-usage' ),
				'desc'     => __( 'Added to the Registrations screen, so pending applications can be accepted or declined here.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'registrations',
			),
			'migrate_rates'          => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Custom commission rates per affiliate', 'woo-coupon-usage' ),
				'desc'     => __( 'Copied onto each of the affiliate\'s coupons.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'rates',
				'pro'      => true,
			),
			'migrate_referrals'      => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Referral history', 'woo-coupon-usage' ),
				'desc'     => __( 'Links each past referred order to the affiliate, so it appears in their statistics. Off: orders are left untouched, and commission is only carried over as balances - except orders whose commission is still pending, which are linked either way (with balances migrated), so they earn what the old plugin recorded when they complete.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'referrals',
			),
			'migrate_balances'       => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Unpaid & pending commission balances', 'woo-coupon-usage' ),
				'desc'     => __( 'What each affiliate is still owed, and commission on orders not yet completed.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'referrals',
				'pro'      => true,
			),
			'migrate_payouts'        => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Payout history', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'payouts',
				'pro'      => true,
			),
			'migrate_payout_details' => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Payout details', 'woo-coupon-usage' ),
				'desc'     => __( 'Each affiliate\'s PayPal / payment email, plus their payout method and bank details where the plugin keeps them.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'payout_details',
				'pro'      => true,
			),
			'migrate_visits'         => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Referral visits (clicks)', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'visits',
			),
			'migrate_groups'         => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Affiliate groups', 'woo-coupon-usage' ),
				'desc'     => __( 'Created as affiliate groups, with their commission rates.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'groups',
				'pro'      => true,
			),
			'migrate_creatives'      => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Creatives (banners)', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'creatives',
				'pro'      => true,
			),
			'migrate_product_rates'  => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Products with commission switched off', 'woo-coupon-usage' ),
				'desc'     => __( 'They earn no commission here either.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'product_rates',
				'pro'      => true,
			),
			'migrate_store_credit'   => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Store credit balances', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'store_credit',
				'pro'      => true,
			),
			'migrate_settings'       => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				'label'    => __( 'Program settings', 'woo-coupon-usage' ),
				'desc'     => __( 'Default commission rate, cookie length and so on - choose which below.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'settings',
			),
			'legacy_links'           => array(
				'type'     => 'checkbox',
				'section'  => 'data',
				/* translators: %s: plugin name */
				'label'    => sprintf( __( 'Keep %s referral links working', 'woo-coupon-usage' ), $source_name ? $source_name : __( 'old', 'woo-coupon-usage' ) ),
				'desc'     => __( 'Links affiliates have already shared keep crediting them, through their new coupon.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'legacy_links',
			),

			// Affiliates.
			'inactive_affiliates'    => array(
				'type'    => 'select',
				'section' => 'affiliates',
				'label'   => __( 'Inactive, blocked or banned affiliates', 'woo-coupon-usage' ),
				'default' => 'suspend',
				'choices' => array(
					'suspend' => __( 'Import them, suspended', 'woo-coupon-usage' ),
					'active'  => __( 'Import them as active affiliates', 'woo-coupon-usage' ),
					'skip'    => __( 'Don\'t import them', 'woo-coupon-usage' ),
				),
			),
			'missing_users'          => array(
				'type'     => 'select',
				'section'  => 'affiliates',
				'label'    => __( 'Affiliates with no WordPress user account', 'woo-coupon-usage' ),
				'desc'     => __( 'Coupon Affiliates needs a user account for each affiliate. An existing user with the same email is always used first.', 'woo-coupon-usage' ),
				'default'  => 'create',
				'choices'  => array(
					'create' => __( 'Create a user account for them (no email is sent)', 'woo-coupon-usage' ),
					'skip'   => __( 'Skip them', 'woo-coupon-usage' ),
				),
				'requires' => 'users_optional',
			),
			'add_role'               => array(
				'type'    => 'checkbox',
				'section' => 'affiliates',
				/* translators: %s: role name */
				'label'   => sprintf( __( 'Give imported affiliates the "%s" user role', 'woo-coupon-usage' ), $role ),
				'default' => 1,
			),
			'registration_records'   => array(
				'type'    => 'checkbox',
				'section' => 'affiliates',
				'label'   => __( 'Add an accepted registration for each imported affiliate', 'woo-coupon-usage' ),
				'desc'    => __( 'Keeps their original sign-up date, website and application answers on the Registrations screen.', 'woo-coupon-usage' ),
				'default' => 1,
			),

			// Coupons.
			'coupon_mode'            => array(
				'type'     => 'select',
				'section'  => 'coupons',
				// Only for plugins whose coupon links are read: with none, every
				// affiliate simply gets a coupon (or keeps the one they have here).
				'requires' => 'coupons',
				'label'   => __( 'Coupons', 'woo-coupon-usage' ),
				'desc'    => __( 'Coupon Affiliates tracks each affiliate through a coupon: their referral link is built from its code. Someone who is already an affiliate here keeps the coupons they have.', 'woo-coupon-usage' ),
				'default' => 'existing_or_create',
				'choices' => array(
					'existing_or_create' => __( 'Assign the coupons they already have, and create one for affiliates with none', 'woo-coupon-usage' ),
					'always_create'      => __( 'Create a new coupon for every affiliate (and still assign the ones they have)', 'woo-coupon-usage' ),
					'existing_only'      => __( 'Only assign the coupons they already have (affiliates with none are skipped)', 'woo-coupon-usage' ),
				),
			),
			'coupon_code_format'     => array(
				'type'    => 'text',
				'section' => 'coupons',
				'label'   => __( 'Code for new coupons', 'woo-coupon-usage' ),
				'desc'    => __( 'Tags: {username} {first_name} {last_name} {affiliate_id} {user_id} {identifier} {random}. Taken codes get a number added.', 'woo-coupon-usage' ),
				'default' => '{username}',
			),
			'coupon_settings'        => array(
				'type'    => 'select',
				'section' => 'coupons',
				'label'   => __( 'Settings for new coupons', 'woo-coupon-usage' ),
				'default' => $template ? 'template' : 'discount',
				'choices' => array(
					'template' => __( 'Copy them from a template coupon', 'woo-coupon-usage' ),
					'discount' => __( 'Use the discount below', 'woo-coupon-usage' ),
				),
			),
			'coupon_template'        => array(
				'type'    => 'text',
				'section' => 'coupons',
				'label'   => __( 'Template coupon code', 'woo-coupon-usage' ),
				'desc'    => __( 'Defaults to the template used for new registrations.', 'woo-coupon-usage' ),
				'default' => $template,
			),
			'coupon_discount_type'   => array(
				'type'    => 'select',
				'section' => 'coupons',
				'label'   => __( 'Discount type', 'woo-coupon-usage' ),
				'default' => 'percent',
				'choices' => array(
					'percent'       => __( 'Percentage discount', 'woo-coupon-usage' ),
					'fixed_cart'    => __( 'Fixed cart discount', 'woo-coupon-usage' ),
					'fixed_product' => __( 'Fixed product discount', 'woo-coupon-usage' ),
				),
			),
			'coupon_discount_amount' => array(
				'type'    => 'number',
				'section' => 'coupons',
				'label'   => __( 'Discount amount', 'woo-coupon-usage' ),
				'desc'    => __( '0 gives customers no discount: the coupon only tracks the referral.', 'woo-coupon-usage' ),
				'default' => 0,
			),
			'coupon_conflict'        => array(
				'type'    => 'select',
				'section' => 'coupons',
				'label'   => __( 'When the code is already taken', 'woo-coupon-usage' ),
				'default' => 'use_unassigned',
				'choices' => array(
					'use_unassigned' => __( 'Use that coupon if nobody owns it yet, otherwise add a number', 'woo-coupon-usage' ),
					'suffix'         => __( 'Always add a number (e.g. jane2)', 'woo-coupon-usage' ),
				),
			),

			// Referral history.
			'referrals_since'        => array(
				'type'     => 'date',
				'section'  => 'referrals',
				'label'    => __( 'Only referrals from this date', 'woo-coupon-usage' ),
				'desc'     => __( 'Leave empty for all of them. Balances still include everything owed.', 'woo-coupon-usage' ),
				'default'  => '',
				'requires' => 'referrals',
			),
			'unapproved_commission'  => array(
				'type'     => 'select',
				'section'  => 'referrals',
				'label'    => __( 'Commission the old plugin still had awaiting approval, on orders that have since completed', 'woo-coupon-usage' ),
				'desc'     => __( 'Some plugins hold commission until an admin approves it. Commission on orders not yet completed stays pending here either way.', 'woo-coupon-usage' ),
				'default'  => 'owed',
				'choices'  => array(
					'owed' => __( 'Treat it as owed to the affiliate', 'woo-coupon-usage' ),
					'skip' => __( 'Don\'t import it', 'woo-coupon-usage' ),
				),
				'requires' => 'referrals',
			),

			// Balances & payouts.
			'balancing_payouts'      => array(
				'type'     => 'checkbox',
				'section'  => 'balances',
				'label'    => __( 'Record paid commission that has no payout record as a historical payout', 'woo-coupon-usage' ),
				'desc'     => __( 'So each affiliate\'s "paid" total matches the old plugin.', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'referrals',
				'pro'      => true,
			),

			// Visits.
			'visits_since'           => array(
				'type'     => 'date',
				'section'  => 'visits',
				'label'    => __( 'Only visits from this date', 'woo-coupon-usage' ),
				'desc'     => __( 'Visit tables can be very large. Leave empty for all of them.', 'woo-coupon-usage' ),
				'default'  => '',
				'requires' => 'visits',
			),
			'visits_converted_only'  => array(
				'type'     => 'checkbox',
				'section'  => 'visits',
				'label'    => __( 'Only visits that led to an order', 'woo-coupon-usage' ),
				'default'  => 0,
				'requires' => 'visits',
			),
			'migrate_campaigns'      => array(
				'type'     => 'checkbox',
				'section'  => 'visits',
				'label'    => __( 'Create campaigns from the campaign names on visits', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'campaigns',
				'pro'      => true,
			),

			// Settings.
			'settings_rate'          => array(
				'type'     => 'checkbox',
				'section'  => 'settings',
				'label'    => __( 'Default commission rate', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'settings',
				'setting'  => 'rate',
			),
			'settings_cookie'        => array(
				'type'     => 'checkbox',
				'section'  => 'settings',
				'label'    => __( 'Referral cookie length', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'settings',
				'setting'  => 'cookie_days',
			),
			'settings_attribution'   => array(
				'type'     => 'checkbox',
				'section'  => 'settings',
				'label'    => __( 'First- or last-click attribution', 'woo-coupon-usage' ),
				'default'  => 1,
				'requires' => 'settings',
				'setting'  => 'credit_first',
			),

			// Old links.
			'legacy_cookie_days'     => array(
				'type'     => 'number',
				'section'  => 'links',
				/* translators: %s: plugin name */
				'label'    => sprintf( __( 'Keep crediting visitors with a %s referral cookie for (days)', 'woo-coupon-usage' ), $source_name ? $source_name : __( 'old', 'woo-coupon-usage' ) ),
				'desc'     => __( 'Visitors referred shortly before the switch still earn the affiliate commission. 0 turns this off.', 'woo-coupon-usage' ),
				'default'  => 60,
				'requires' => 'legacy_links',
				'parent'   => 'legacy_links',
			),

			// Advanced.
			'batch_size'             => array(
				'type'    => 'number',
				'section' => 'advanced',
				'label'   => __( 'Records per batch', 'woo-coupon-usage' ),
				'desc'    => __( 'Lower this if the migration times out on your server.', 'woo-coupon-usage' ),
				'default' => 100,
			),
		);
	}

	/**
	 * The full schema for a source: general options that apply to it, plus its own.
	 *
	 * @param WCUsage_Migration_Source $source
	 * @return array
	 */
	public static function schema( $source ) {

		$supported = $source->get_supported();
		$schema    = array();

		foreach ( self::general_schema( $source->get_name() ) as $key => $option ) {
			if ( ! empty( $option['requires'] ) && ! in_array( $option['requires'], $supported, true ) ) {
				continue;
			}
			$schema[ $key ] = $option;
		}

		foreach ( $source->get_source_options() as $key => $option ) {
			if ( ! empty( $option['requires'] ) && ! in_array( $option['requires'], $supported, true ) ) {
				continue;
			}
			$option['section'] = isset( $option['section'] ) ? $option['section'] : 'source';
			$schema[ $key ]    = $option;
		}

		// The source's suggested code for new coupons (usually one of its link
		// identifiers, so the code matches what affiliates already share).
		if ( isset( $schema['coupon_code_format'] ) ) {
			$schema['coupon_code_format']['default'] = $source->get_default_coupon_format();
		}

		foreach ( $source->get_option_defaults() as $key => $default ) {
			if ( isset( $schema[ $key ] ) ) {
				$schema[ $key ]['default'] = $default;
			}
		}

		return $schema;
	}

	/**
	 * Default values for a source.
	 *
	 * @param WCUsage_Migration_Source $source
	 * @return array
	 */
	public static function defaults( $source ) {
		$values = array();
		foreach ( self::schema( $source ) as $key => $option ) {
			$values[ $key ] = $option['default'];
		}
		return $values;
	}

	/**
	 * Sanitise posted options against the schema, filling in defaults.
	 *
	 * A checkbox missing from the input is off - except when no input was given
	 * at all, which means "use the defaults" (the CLI and the tests).
	 *
	 * @param WCUsage_Migration_Source $source
	 * @param array|null               $input
	 * @return array
	 */
	public static function sanitize( $source, $input ) {

		$schema = self::schema( $source );
		$pro    = wcusage_migration_is_pro();
		$values = array();
		$input  = is_array( $input ) ? $input : null;

		foreach ( $schema as $key => $option ) {

			if ( null === $input || ! array_key_exists( $key, $input ) ) {
				$value = ( null === $input || 'checkbox' !== $option['type'] ) ? $option['default'] : 0;
			} else {
				$value = $input[ $key ];
			}

			switch ( $option['type'] ) {
				case 'checkbox':
					$value = ( $value && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
					if ( ! empty( $option['locked'] ) ) {
						$value = 1;
					}
					break;
				case 'select':
					$value = (string) $value;
					if ( ! isset( $option['choices'][ $value ] ) ) {
						$value = $option['default'];
					}
					break;
				case 'number':
					$value = is_numeric( $value ) ? 0 + $value : $option['default'];
					if ( $value < 0 ) {
						$value = 0;
					}
					break;
				case 'date':
					$value = (string) $value;
					if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						$value = '';
					}
					break;
				default:
					$value = sanitize_text_field( (string) $value );
			}

			// PRO-only parts are switched off where PRO is not available.
			if ( ! empty( $option['pro'] ) && ! $pro && 'checkbox' === $option['type'] ) {
				$value = 0;
			}

			$values[ $key ] = $value;
		}

		$values['batch_size'] = isset( $values['batch_size'] ) ? max( 10, min( 1000, (int) $values['batch_size'] ) ) : 100;

		return $values;
	}
}
