<?php
/**
 * Coupon Affiliates - Abilities - Definitions.
 *
 * One entry per ability, each mapping an ability onto a controller method:
 * a REST API v2 one, or WCUsage_Abilities_Controller for the few actions
 * the REST API does not offer. The wording here is what an AI agent reads
 * to decide which tool to call and how, so descriptions say what comes
 * back, when to use it, and anything that would surprise a caller.
 *
 * The payout abilities are listed in every build, like the payout endpoints
 * on the API screen: in the free build their controller is absent, which
 * leaves them unregistered and shown locked, so it is clear what PRO adds.
 *
 * @package WooCouponUsage\Abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wcusage_abilities_prop' ) ) {
	/**
	 * A reusable input property.
	 *
	 * Keys beginning "x-" are private to this plugin and are stripped before
	 * the schema is published: "x-sanitize" names the sanitiser the REST route
	 * runs on the same parameter, "x-date" marks a Y-m-d date that also has to
	 * exist on the calendar, and "x-coupon" a coupon ID that has to exist -
	 * checked before the handler runs, for handlers that would otherwise
	 * answer a missing coupon with something other than "not found".
	 *
	 * @param string $key         Property name.
	 * @param string $description Description, when the default wording does not fit.
	 *
	 * @return array
	 */
	function wcusage_abilities_prop( $key, $description = '' ) {

		// Empty is allowed and means "no date", as on the REST routes - AI apps
		// often send an empty string for an optional parameter.
		$date_pattern = '^([0-9]{4}-[0-9]{2}-[0-9]{2})?$';

		switch ( $key ) {

			case 'page':
				$property = array(
					'type'        => 'integer',
					'description' => __( 'Page of results to return, starting at 1.', 'woo-coupon-usage' ),
					'minimum'     => 1,
					'default'     => 1,
				);
				break;

			case 'per_page':
				$property = array(
					'type'        => 'integer',
					'description' => __( 'How many results per page, from 1 to 100.', 'woo-coupon-usage' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				);
				break;

			case 'from':
				$property = array(
					'type'        => 'string',
					'description' => __( 'Start date, in the format YYYY-MM-DD.', 'woo-coupon-usage' ),
					'pattern'     => $date_pattern,
					'x-date'      => true,
					'x-sanitize'  => 'sanitize_text_field',
				);
				break;

			case 'to':
				$property = array(
					'type'        => 'string',
					'description' => __( 'End date, in the format YYYY-MM-DD.', 'woo-coupon-usage' ),
					'pattern'     => $date_pattern,
					'x-date'      => true,
					'x-sanitize'  => 'sanitize_text_field',
				);
				break;

			case 'search':
				$property = array(
					'type'        => 'string',
					'description' => '',
					'x-sanitize'  => 'sanitize_text_field',
				);
				break;

			default:
				$property = array(
					'type'        => 'integer',
					'description' => '',
					'minimum'     => 1,
				);
		}

		if ( '' !== $description ) {
			$property['description'] = $description;
		}

		return $property;
	}
}

if ( ! function_exists( 'wcusage_abilities_input' ) ) {
	/**
	 * Wrap properties into an ability input schema.
	 *
	 * An object schema always carries a default of an empty object, because
	 * the Abilities API validates a call made with no input at all against
	 * the schema: without the default, an ability whose every parameter is
	 * optional would refuse to run unless the caller sent "{}".
	 *
	 * Unknown properties are refused, so a misspelt parameter comes back as
	 * an error an agent can correct rather than being silently ignored.
	 *
	 * @param array $properties Properties.
	 * @param array $required   Required property names.
	 *
	 * @return array
	 */
	function wcusage_abilities_input( $properties = array(), $required = array() ) {

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => array(),
		);

		if ( ! empty( $properties ) ) {
			$schema['properties'] = $properties;
		}

		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}

		return $schema;
	}
}

if ( ! function_exists( 'wcusage_abilities_output' ) ) {
	/**
	 * An output schema.
	 *
	 * Only the fields every response is certain to carry, with the types it is
	 * certain to have, are described: the Abilities API validates results
	 * against this and refuses any that do not match, so describing a field
	 * too tightly would turn a harmless variation into a failed call.
	 *
	 * @param array $properties Properties.
	 *
	 * @return array
	 */
	function wcusage_abilities_output( $properties ) {
		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}

if ( ! function_exists( 'wcusage_abilities_collection_output' ) ) {
	/**
	 * Output schema for a paginated collection.
	 *
	 * @param string $items_description What each item is.
	 * @param array  $extra             Extra top-level properties.
	 *
	 * @return array
	 */
	function wcusage_abilities_collection_output( $items_description, $extra = array() ) {
		return wcusage_abilities_output(
			array_merge(
				array(
					'items'       => array(
						'type'        => 'array',
						'description' => $items_description,
						'items'       => array( 'type' => 'object' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of matching results across all pages.', 'woo-coupon-usage' ),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of pages.', 'woo-coupon-usage' ),
					),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				$extra
			)
		);
	}
}

if ( ! function_exists( 'wcusage_abilities_definitions' ) ) {
	/**
	 * Build the ability definitions.
	 *
	 * Keys:
	 *  - label, description: shown to agents and on the API screen.
	 *  - group:       heading the ability is listed under on the API screen.
	 *  - type:        "read", or "write" for anything that changes data.
	 *  - destructive: whether it can do something that cannot simply be undone.
	 *  - idempotent:  whether repeating the same call has no further effect.
	 *  - access:      "admin", or "affiliate" when an affiliate may call it for
	 *                 their own data (admins can always call everything).
	 *  - affiliate_requires: parameters an affiliate must pass, though an admin
	 *                 need not. Checked with its own error message, which a
	 *                 refusal from the permission check could not carry.
	 *  - controller, permission, callback: the controller class - a REST API
	 *                 v2 one, or WCUsage_Abilities_Controller - its
	 *                 permission method and its handler method.
	 *  - method, route: the REST route the handler serves, for reference
	 *                 (none for WCUsage_Abilities_Controller).
	 *  - paginated:   whether the handler returns a paginated collection.
	 *  - headers:     response headers to carry into the result, as
	 *                 header => array( field, "bool"|"int" ).
	 *  - input, output: JSON schemas.
	 *
	 * @return array Ability name => definition.
	 */
	function wcusage_abilities_definitions() {

		$money = __( 'Amounts are in the store currency.', 'woo-coupon-usage' );

		$user_output = array(
			'type'        => array( 'object', 'null' ),
			'description' => __( 'The WordPress user: id and display name, plus login and email for admins.', 'woo-coupon-usage' ),
		);

		$date_output = array( 'type' => array( 'string', 'null' ) );

		$definitions = array();

		/*
		 * Program
		 */

		$definitions['coupon-affiliates/get-my-account'] = array(
			'label'       => __( 'Get my affiliate account', 'woo-coupon-usage' ),
			'description' => __( 'Identify the connected WordPress user: whether they are a Coupon Affiliates admin, whether they are an affiliate, and - for an affiliate - their coupons with commission balances. Call this first to find out what the current user can access.', 'woo-coupon-usage' ),
			'group'       => 'program',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Me_Controller',
			'permission'  => 'permission_authenticated',
			'callback'    => 'get_item',
			'method'      => 'GET',
			'route'       => '/me',
			'input'       => wcusage_abilities_input(),
			'output'      => wcusage_abilities_output(
				array(
					'user'         => $user_output,
					'is_admin'     => array( 'type' => 'boolean' ),
					'is_affiliate' => array( 'type' => 'boolean' ),
					'coupons'      => array(
						'type'        => 'array',
						'description' => __( 'The affiliate\'s coupons, with commission balances.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		$definitions['coupon-affiliates/get-program-summary'] = array(
			'label'       => __( 'Get affiliate program summary', 'woo-coupon-usage' ),
			'description' => __( 'Store-wide totals for the affiliate program: number of affiliates and coupons, referred orders, sales, discounts, commission earned, unpaid commission, commission waiting in payout requests, pending affiliate registrations, and the top affiliates by commission earned. Figures come from each coupon\'s stored all-time stats and the summary is cached for 5 minutes; set refresh to true to rebuild it. The store currency is included in the result. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'program',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Reports_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_summary',
			'method'      => 'GET',
			'route'       => '/reports/summary',
			'input'       => wcusage_abilities_input(
				array(
					'top'     => array(
						'type'        => 'integer',
						'description' => __( 'How many top affiliates to include, from 0 to 50.', 'woo-coupon-usage' ),
						'minimum'     => 0,
						'maximum'     => 50,
						'default'     => 10,
					),
					'refresh' => array(
						'type'        => 'boolean',
						'description' => __( 'Rebuild the summary instead of using the 5-minute cache.', 'woo-coupon-usage' ),
						'default'     => false,
					),
				)
			),
			'output'      => wcusage_abilities_output(
				array(
					'currency'              => array(
						'type'        => 'string',
						'description' => __( 'Store currency code, e.g. USD.', 'woo-coupon-usage' ),
					),
					'totals'                => array(
						'type'        => 'object',
						'description' => __( 'affiliates, coupons, orders_count, total_sales, total_discount, total_commission, unpaid_commission and pending_payout_commission.', 'woo-coupon-usage' ),
					),
					'pending_registrations' => array(
						'type'        => 'integer',
						'description' => __( 'Affiliate applications waiting for review.', 'woo-coupon-usage' ),
					),
					'top_affiliates'        => array(
						'type'        => 'array',
						'description' => __( 'Affiliates with the most commission, highest first.', 'woo-coupon-usage' ),
					),
					'cached'                => array( 'type' => 'boolean' ),
				)
			),
		);

		$definitions['coupon-affiliates/list-activity'] = array(
			'label'       => __( 'List recent program activity', 'woo-coupon-usage' ),
			'description' => __( 'List affiliate program activity from the activity log, newest first: new referrals, commission added or removed, registrations, payout requests and payments, rewards earned and more. Filter by event type (for example referral, commission_added, payout_request, payout_paid, registration or registration_accept) or by the user who acted. To read only what is new since last time, pass the highest event id already seen as "after" - results then come back oldest first. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'program',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Events_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_items',
			'method'      => 'GET',
			'route'       => '/events',
			'paginated'   => true,
			'headers'     => array( 'X-WCUsage-Last-Event' => array( 'last_event_id', 'int' ) ),
			'input'       => wcusage_abilities_input(
				array(
					'page'     => wcusage_abilities_prop( 'page' ),
					'per_page' => wcusage_abilities_prop( 'per_page' ),
					'event'    => array(
						'type'        => 'string',
						'description' => __( 'Only this event type, e.g. referral or payout_paid.', 'woo-coupon-usage' ),
						'x-sanitize'  => 'sanitize_key',
					),
					'user_id'  => wcusage_abilities_prop( 'user_id', __( 'Only events by this WordPress user ID.', 'woo-coupon-usage' ) ),
					'after'    => array(
						'type'        => 'integer',
						'description' => __( 'Only events with an id greater than this.', 'woo-coupon-usage' ),
						'minimum'     => 0,
					),
				)
			),
			'output'      => wcusage_abilities_collection_output(
				__( 'Events, each with id, event, event_id (the related order, payout or registration), user_id, info and date.', 'woo-coupon-usage' ),
				array(
					'last_event_id' => array(
						'type'        => 'integer',
						'description' => __( 'Highest event id returned - pass it as "after" next time.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		/*
		 * Affiliates
		 */

		$definitions['coupon-affiliates/list-affiliates'] = array(
			'label'       => __( 'List affiliates', 'woo-coupon-usage' ),
			'description' => __( 'List affiliates - users with at least one affiliate coupon assigned - with each affiliate\'s coupons and their unpaid and pending-payout commission. Use search to match a username, email address or display name. Results are paginated. Admin only.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'affiliates',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Affiliates_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_items',
			'method'      => 'GET',
			'route'       => '/affiliates',
			'paginated'   => true,
			'input'       => wcusage_abilities_input(
				array(
					'page'     => wcusage_abilities_prop( 'page' ),
					'per_page' => wcusage_abilities_prop( 'per_page' ),
					'search'   => wcusage_abilities_prop( 'search', __( 'Match against username, email address or display name.', 'woo-coupon-usage' ) ),
				)
			),
			'output'      => wcusage_abilities_collection_output( __( 'Affiliates, each with user, coupons, unpaid_commission and pending_payout_commission.', 'woo-coupon-usage' ) ),
		);

		$definitions['coupon-affiliates/get-affiliate'] = array(
			'label'       => __( 'Get affiliate', 'woo-coupon-usage' ),
			'description' => __( 'Get one affiliate by their WordPress user ID: their coupons with commission balances and cached all-time stats, when they registered, the profile details from their application (phone, website, how they promote, who referred them) and their affiliate groups. An affiliate can only look up themselves.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'affiliates',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Affiliates_Controller',
			'permission'  => 'permission_self_or_admin',
			'callback'    => 'get_item',
			'method'      => 'GET',
			'route'       => '/affiliates/{id}',
			'input'       => wcusage_abilities_input(
				array( 'id' => wcusage_abilities_prop( 'id', __( 'The affiliate\'s WordPress user ID.', 'woo-coupon-usage' ) ) ),
				array( 'id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'user'                      => $user_output,
					'coupons'                   => array( 'type' => 'array' ),
					'unpaid_commission'         => array( 'type' => 'number' ),
					'pending_payout_commission' => array( 'type' => 'number' ),
				)
			),
		);

		$definitions['coupon-affiliates/get-affiliate-stats'] = array(
			'label'       => __( 'Get affiliate stats', 'woo-coupon-usage' ),
			'description' => __( 'Referred orders, sales, discounts and commission across all of an affiliate\'s coupons, with a breakdown per coupon and their unpaid and pending-payout commission. Without dates the stored all-time figures are used. With a from/to date range they are calculated from the orders, which is limited to one new range per affiliate per minute. An affiliate can only read their own stats.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'affiliates',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Affiliates_Controller',
			'permission'  => 'permission_self_or_admin',
			'callback'    => 'get_item_stats',
			'method'      => 'GET',
			'route'       => '/affiliates/{id}/stats',
			'input'       => wcusage_abilities_input(
				array(
					'id'   => wcusage_abilities_prop( 'id', __( 'The affiliate\'s WordPress user ID.', 'woo-coupon-usage' ) ),
					'from' => wcusage_abilities_prop( 'from' ),
					'to'   => wcusage_abilities_prop( 'to', __( 'End date, in the format YYYY-MM-DD. Defaults to today when only a start date is given.', 'woo-coupon-usage' ) ),
				),
				array( 'id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'user_id' => array( 'type' => 'integer' ),
					'totals'  => array(
						'type'        => 'object',
						'description' => __( 'orders_count, total_sales, total_discount, total_commission, unpaid_commission and pending_payout_commission.', 'woo-coupon-usage' ),
					),
					'coupons' => array(
						'type'        => 'array',
						'description' => __( 'The same figures for each of the affiliate\'s coupons.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		$coupon_output = array(
			'type'        => array( 'object', 'null' ),
			'description' => __( 'The coupon: id, code, the affiliate\'s user_id, date_created and commission balances.', 'woo-coupon-usage' ),
		);

		// Either one names the coupon: an unassigned coupon is not in the
		// list-coupons results, so its ID may not be to hand.
		$coupon_by = array(
			'coupon_id'   => array_merge( wcusage_abilities_prop( 'coupon_id', __( 'Coupon ID. Pass this or coupon_code.', 'woo-coupon-usage' ) ), array( 'x-coupon' => true ) ),
			'coupon_code' => array(
				'type'        => 'string',
				'description' => __( 'Coupon code. Pass this or coupon_id.', 'woo-coupon-usage' ),
				'maxLength'   => 100,
				'x-sanitize'  => 'sanitize_text_field',
			),
		);

		$assignment_output = wcusage_abilities_output(
			array(
				'coupon'             => $coupon_output,
				'affiliate'          => $user_output,
				'previous_affiliate' => $user_output,
				'changed'            => array(
					'type'        => 'boolean',
					'description' => __( 'False when the coupon was already as asked, so nothing was done.', 'woo-coupon-usage' ),
				),
				'email_sent'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the affiliate was emailed. False when send_email is false, or when the store has that email switched off.', 'woo-coupon-usage' ),
				),
			)
		);

		$definitions['coupon-affiliates/create-affiliate'] = array(
			'label'       => __( 'Create an affiliate', 'woo-coupon-usage' ),
			'description' => __( 'Add a new affiliate, the same way "Add New Affiliate" in the admin does: creates their WordPress user account if the email address does not have one yet, creates their affiliate coupon from the template coupon with the code you choose, gives them the affiliate role and emails them. Set send_email to false to skip the emails - a new user then gets no login details and has to use "Lost your password?". If the email address already has an account, that user becomes an affiliate (or gets another coupon) and their account is otherwise left alone. To give an existing coupon to someone, use assign-coupon instead. Confirm with the user before calling it. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'affiliates',
			'type'        => 'write',
			'destructive' => false,
			'idempotent'  => false,
			'access'      => 'admin',
			'controller'  => 'WCUsage_Abilities_Controller',
			'permission'  => 'permission_admin_write',
			'callback'    => 'create_affiliate',
			'method'      => 'POST',
			'input'       => wcusage_abilities_input(
				array(
					'email'       => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The affiliate\'s email address.', 'woo-coupon-usage' ),
					),
					'coupon_code' => array(
						'type'        => 'string',
						'description' => __( 'Code for their new coupon: what customers enter at checkout, and what their referral links carry. Must not be in use already.', 'woo-coupon-usage' ),
						'minLength'   => 1,
						'maxLength'   => 100,
						'x-sanitize'  => 'sanitize_text_field',
					),
					'username'    => array(
						'type'        => 'string',
						'description' => __( 'Username for a new account. Leave it out to have one made from the email address. Ignored when the email address already has an account.', 'woo-coupon-usage' ),
						'maxLength'   => 60,
						'x-sanitize'  => 'sanitize_text_field',
					),
					'first_name'  => array(
						'type'        => 'string',
						'description' => __( 'First name, for a new account.', 'woo-coupon-usage' ),
						'maxLength'   => 100,
						'x-sanitize'  => 'sanitize_text_field',
					),
					'last_name'   => array(
						'type'        => 'string',
						'description' => __( 'Last name, for a new account.', 'woo-coupon-usage' ),
						'maxLength'   => 100,
						'x-sanitize'  => 'sanitize_text_field',
					),
					'message'     => array(
						'type'        => 'string',
						'description' => __( 'Optional message to include in the welcome email.', 'woo-coupon-usage' ),
						'default'     => '',
						'x-sanitize'  => 'sanitize_textarea_field',
					),
					'send_email'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the affiliate: the welcome email with their coupon, and their account details if a new account is created.', 'woo-coupon-usage' ),
						'default'     => true,
					),
				),
				array( 'email', 'coupon_code' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'affiliate'       => $user_output,
					'new_user'        => array(
						'type'        => 'boolean',
						'description' => __( 'True when a new WordPress account was created.', 'woo-coupon-usage' ),
					),
					'coupon'          => $coupon_output,
					'registration_id' => array( 'type' => 'integer' ),
					'emails_sent'     => array(
						'type'        => 'integer',
						'description' => __( 'How many emails were sent to the affiliate: none when send_email is false, or when the store has those emails switched off.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		$definitions['coupon-affiliates/assign-coupon'] = array(
			'label'       => __( 'Assign a coupon to an affiliate', 'woo-coupon-usage' ),
			'description' => __( 'Give an existing WooCommerce coupon to an affiliate as their affiliate coupon: from then on they earn commission on orders that use it, and see it on their affiliate dashboard. Name the coupon with coupon_id or coupon_code. If it already belongs to another affiliate it is only moved when reassign is true, and its unpaid commission balance moves with it. Emails the affiliate unless send_email is false. To create a new coupon for someone, use create-affiliate. Confirm with the user before calling it. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'affiliates',
			'type'        => 'write',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_Abilities_Controller',
			'permission'  => 'permission_admin_write',
			'callback'    => 'assign_coupon',
			'method'      => 'POST',
			'input'       => wcusage_abilities_input(
				array_merge(
					$coupon_by,
					array(
						'user_id'    => wcusage_abilities_prop( 'user_id', __( 'The affiliate\'s WordPress user ID.', 'woo-coupon-usage' ) ),
						'reassign'   => array(
							'type'        => 'boolean',
							'description' => __( 'Move the coupon even if it already belongs to another affiliate.', 'woo-coupon-usage' ),
							'default'     => false,
						),
						'message'    => array(
							'type'        => 'string',
							'description' => __( 'Optional message to include in the email to the affiliate.', 'woo-coupon-usage' ),
							'default'     => '',
							'x-sanitize'  => 'sanitize_textarea_field',
						),
						'send_email' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to send the affiliate the "New Coupon Assigned" email.', 'woo-coupon-usage' ),
							'default'     => true,
						),
					)
				),
				array( 'user_id' )
			),
			'output'      => $assignment_output,
		);

		$definitions['coupon-affiliates/unassign-coupon'] = array(
			'label'       => __( 'Remove a coupon from its affiliate', 'woo-coupon-usage' ),
			'description' => __( 'Take a coupon away from its affiliate, so it is an ordinary coupon again: it stops earning commission for anyone and leaves the affiliate\'s dashboard. The coupon, its past orders and its commission balances are kept, and assign-coupon can give it back. Check its unpaid commission first, because the affiliate can no longer request a payout of it. Nobody is emailed. Name the coupon with coupon_id or coupon_code. Confirm with the user before calling it. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'affiliates',
			'type'        => 'write',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_Abilities_Controller',
			'permission'  => 'permission_admin_write',
			'callback'    => 'unassign_coupon',
			'method'      => 'POST',
			'input'       => wcusage_abilities_input( $coupon_by ),
			'output'      => $assignment_output,
		);

		/*
		 * Coupons
		 */

		$definitions['coupon-affiliates/list-coupons'] = array(
			'label'       => __( 'List affiliate coupons', 'woo-coupon-usage' ),
			'description' => __( 'List affiliate coupons (coupons assigned to an affiliate), newest first, with the affiliate\'s user ID and the coupon\'s unpaid, pending and pending-payout commission. Filter by affiliate with user_id, or search by coupon code. Admin only.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Coupons_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_items',
			'method'      => 'GET',
			'route'       => '/coupons',
			'paginated'   => true,
			'input'       => wcusage_abilities_input(
				array(
					'page'     => wcusage_abilities_prop( 'page' ),
					'per_page' => wcusage_abilities_prop( 'per_page' ),
					'user_id'  => wcusage_abilities_prop( 'user_id', __( 'Only coupons assigned to this affiliate user ID.', 'woo-coupon-usage' ) ),
					'search'   => wcusage_abilities_prop( 'search', __( 'Match against the coupon code.', 'woo-coupon-usage' ) ),
				)
			),
			'output'      => wcusage_abilities_collection_output( __( 'Coupons, each with id, code, user_id, date_created and commission balances.', 'woo-coupon-usage' ) ),
		);

		$definitions['coupon-affiliates/get-coupon'] = array(
			'label'       => __( 'Get affiliate coupon', 'woo-coupon-usage' ),
			'description' => __( 'Get one affiliate coupon by its ID: code, assigned affiliate, commission balances, cached all-time stats, commission rate settings and the affiliate\'s referral URL. An affiliate can only read the coupons their own affiliate dashboard shows them: their own and, with multi-level affiliates, those of affiliates below them.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Coupons_Controller',
			'permission'  => 'permission_coupon_read',
			'callback'    => 'get_item',
			'method'      => 'GET',
			'route'       => '/coupons/{id}',
			'input'       => wcusage_abilities_input(
				array( 'id' => wcusage_abilities_prop( 'id', __( 'Coupon ID.', 'woo-coupon-usage' ) ) ),
				array( 'id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'id'                => array( 'type' => 'integer' ),
					'code'              => array( 'type' => 'string' ),
					'user_id'           => array( 'type' => 'integer' ),
					'unpaid_commission' => array( 'type' => 'number' ),
					'commission'        => array(
						'type'        => 'object',
						'description' => __( 'Commission rate settings: percent, and any per-coupon overrides.', 'woo-coupon-usage' ),
					),
					'referral_url'      => array( 'type' => 'string' ),
				)
			),
		);

		$definitions['coupon-affiliates/get-coupon-stats'] = array(
			'label'       => __( 'Get coupon stats', 'woo-coupon-usage' ),
			'description' => __( 'Referred orders, sales, discounts, shipping and commission for one affiliate coupon, with a count per order status and its unpaid, pending and pending-payout commission. Without dates the stored all-time figures are returned; "source" says whether they came from the stored snapshot ("cache") or were just calculated. Set refresh to true to recalculate them from the orders; the result is saved as the coupon\'s cached statistics, which is all that changes. With a from/to date range the figures are calculated for that range. Calculating is limited to once per coupon per minute. An affiliate can only read the coupons their own affiliate dashboard shows them: their own and, with multi-level affiliates, those of affiliates below them.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Coupons_Controller',
			'permission'  => 'permission_coupon_read',
			'callback'    => 'get_item_stats',
			'method'      => 'GET',
			'route'       => '/coupons/{id}/stats',
			'input'       => wcusage_abilities_input(
				array(
					'id'      => wcusage_abilities_prop( 'id', __( 'Coupon ID.', 'woo-coupon-usage' ) ),
					'from'    => wcusage_abilities_prop( 'from' ),
					'to'      => wcusage_abilities_prop( 'to', __( 'End date, in the format YYYY-MM-DD. Defaults to today when only a start date is given.', 'woo-coupon-usage' ) ),
					'refresh' => array(
						'type'        => 'boolean',
						'description' => __( 'Recalculate the all-time figures from the orders instead of using the stored snapshot. Ignored when a date range is given.', 'woo-coupon-usage' ),
						'default'     => false,
					),
				),
				array( 'id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'coupon_id'        => array( 'type' => 'integer' ),
					'code'             => array( 'type' => 'string' ),
					'source'           => array(
						'type'        => 'string',
						'description' => __( 'cache, live or throttled.', 'woo-coupon-usage' ),
					),
					'orders_count'     => array( 'type' => 'integer' ),
					'total_sales'      => array( 'type' => 'number' ),
					'total_commission' => array( 'type' => 'number' ),
					'last_refreshed'   => $date_output,
				)
			),
		);

		$definitions['coupon-affiliates/list-coupon-orders'] = array(
			'label'       => __( 'List coupon orders', 'woo-coupon-usage' ),
			'description' => __( 'List the orders an affiliate coupon referred, newest first, with each order\'s date, status, total, discount and commission. No customer details are included. Filter by date range or order status. When "truncated" is true the coupon has more orders than one request reads, so the oldest are left out - narrow the date range. An affiliate can only read the coupons their own affiliate dashboard shows them: their own and, with multi-level affiliates, those of affiliates below them.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Coupons_Controller',
			'permission'  => 'permission_coupon_read',
			'callback'    => 'get_item_orders',
			'method'      => 'GET',
			'route'       => '/coupons/{id}/orders',
			'paginated'   => true,
			'headers'     => array( 'X-WCUsage-Truncated' => array( 'truncated', 'bool' ) ),
			'input'       => wcusage_abilities_input(
				array(
					'id'       => wcusage_abilities_prop( 'id', __( 'Coupon ID.', 'woo-coupon-usage' ) ),
					'page'     => wcusage_abilities_prop( 'page' ),
					'per_page' => wcusage_abilities_prop( 'per_page' ),
					'from'     => wcusage_abilities_prop( 'from' ),
					'to'       => wcusage_abilities_prop( 'to' ),
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'Only orders with this status, without the "wc-" prefix - e.g. completed, processing or refunded.', 'woo-coupon-usage' ),
						'x-sanitize'  => 'sanitize_key',
					),
				),
				array( 'id' )
			),
			'output'      => wcusage_abilities_collection_output(
				__( 'Orders, each with order_id, date, status, total, discount and commission.', 'woo-coupon-usage' ),
				array( 'truncated' => array( 'type' => 'boolean' ) )
			),
		);

		$definitions['coupon-affiliates/get-click-stats'] = array(
			'label'       => __( 'Get referral click stats', 'woo-coupon-usage' ),
			'description' => __( 'Referral link click statistics: how many clicks there were, how many turned into orders, and the conversion rate as a percentage. Filter by coupon_id, campaign name and date range. Without coupon_id the figures cover the whole store, which only admins can see; an affiliate must pass a coupon their affiliate dashboard shows them.', 'woo-coupon-usage' ),
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Clicks_Controller',
			'permission'  => 'permission_stats',
			'callback'    => 'get_stats',
			'method'      => 'GET',
			'route'       => '/clicks/stats',
			// Store-wide figures are for admins: an affiliate must name a coupon.
			'affiliate_requires' => array( 'coupon_id' ),
			'input'       => wcusage_abilities_input(
				array(
					'coupon_id' => array_merge( wcusage_abilities_prop( 'coupon_id', __( 'Only clicks on this coupon\'s referral links. Required for affiliates.', 'woo-coupon-usage' ) ), array( 'x-coupon' => true ) ),
					'campaign'  => array(
						'type'        => 'string',
						'description' => __( 'Only clicks tagged with this campaign name.', 'woo-coupon-usage' ),
						'x-sanitize'  => 'sanitize_text_field',
					),
					'from'      => wcusage_abilities_prop( 'from' ),
					'to'        => wcusage_abilities_prop( 'to' ),
				)
			),
			'output'      => wcusage_abilities_output(
				array(
					'clicks'          => array( 'type' => 'integer' ),
					'conversions'     => array( 'type' => 'integer' ),
					'conversion_rate' => array(
						'type'        => 'number',
						'description' => __( 'Conversions as a percentage of clicks.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		$definitions['coupon-affiliates/get-referral-link'] = array(
			'label'       => __( 'Get a referral link', 'woo-coupon-usage' ),
			'description' => __( 'Build a referral link for an affiliate coupon - the same link the referral URL generator on the affiliate dashboard gives. It points at the store\'s usual referral landing page unless you pass page_url (another page on this store) or product_id (a product page). Add a campaign name to tell traffic sources apart in the click stats. Nothing is saved. An affiliate can only use the coupons their own affiliate dashboard shows them: their own and, with multi-level affiliates, those of affiliates below them.', 'woo-coupon-usage' ),
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_Abilities_Controller',
			'permission'  => 'permission_coupon_read',
			'callback'    => 'get_referral_link',
			'input'       => wcusage_abilities_input(
				array(
					'coupon_id'  => array_merge( wcusage_abilities_prop( 'coupon_id', __( 'The affiliate coupon the link is for.', 'woo-coupon-usage' ) ), array( 'x-coupon' => true ) ),
					'page_url'   => array(
						'type'        => 'string',
						'description' => __( 'A page on this store to link to instead of the usual landing page: a full URL, or a path such as /shop/.', 'woo-coupon-usage' ),
						'maxLength'   => 2000,
						'x-sanitize'  => 'trim',
					),
					'product_id' => wcusage_abilities_prop( 'product_id', __( 'Link straight to this product\'s page instead.', 'woo-coupon-usage' ) ),
					'campaign'   => array(
						'type'        => 'string',
						'description' => __( 'Campaign name to tag the link with, such as "instagram" or "newsletter", so its clicks and the orders they lead to can be counted separately in the click stats.', 'woo-coupon-usage' ),
						'maxLength'   => 100,
						'x-sanitize'  => 'sanitize_text_field',
					),
				),
				array( 'coupon_id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'coupon_id'    => array( 'type' => 'integer' ),
					'code'         => array( 'type' => 'string' ),
					'url'          => array(
						'type'        => 'string',
						'description' => __( 'The referral link to share.', 'woo-coupon-usage' ),
					),
					'landing_page' => array( 'type' => 'string' ),
					'campaign'     => array( 'type' => array( 'string', 'null' ) ),
				)
			),
		);

		$definitions['coupon-affiliates/get-order-referral'] = array(
			'label'       => __( 'Get an order\'s referral', 'woo-coupon-usage' ),
			'description' => __( 'Look up whether a WooCommerce order was referred by an affiliate: the coupon and affiliate it is credited to, how it was credited, and the commission it earned. credited_via is "lifetime" (a returning customer tied to that affiliate), "referrer" (a referral link, or an affiliate set on the order by an admin) or "coupon" (the coupon was used at checkout). When credited_via is set but referred is false, the coupon involved has no affiliate. No customer details are included. Admin only.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'coupons',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_Abilities_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_order_referral',
			'input'       => wcusage_abilities_input(
				array( 'order_id' => wcusage_abilities_prop( 'order_id', __( 'WooCommerce order ID (the order number, unless a plugin changes order numbers).', 'woo-coupon-usage' ) ) ),
				array( 'order_id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'order_id'           => array( 'type' => 'integer' ),
					'status'             => array( 'type' => 'string' ),
					'referred'           => array(
						'type'        => 'boolean',
						'description' => __( 'True when the order is credited to an affiliate.', 'woo-coupon-usage' ),
					),
					'credited_via'       => array( 'type' => array( 'string', 'null' ) ),
					'coupon'             => array(
						'type'        => array( 'object', 'null' ),
						'description' => __( 'The coupon the order is credited to: id, code and the affiliate\'s user_id.', 'woo-coupon-usage' ),
					),
					'affiliate'          => $user_output,
					'commission'         => array( 'type' => 'number' ),
					'commission_granted' => array(
						'type'        => array( 'boolean', 'null' ),
						'description' => __( 'Whether the commission has been added to the affiliate\'s unpaid commission, ready for a payout request. Null in the free version, which does not track this.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		/*
		 * Registrations
		 */

		$definitions['coupon-affiliates/list-registrations'] = array(
			'label'       => __( 'List affiliate registrations', 'woo-coupon-usage' ),
			'description' => __( 'List affiliate registrations - applications to join the program - newest first. Use status "pending" to find applications waiting for review. Each one includes the applicant, the coupon code they asked for, how they plan to promote, their website and any custom registration fields. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'registrations',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Registrations_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_items',
			'method'      => 'GET',
			'route'       => '/registrations',
			'paginated'   => true,
			'input'       => wcusage_abilities_input(
				array(
					'page'     => wcusage_abilities_prop( 'page' ),
					'per_page' => wcusage_abilities_prop( 'per_page' ),
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'Only registrations with this status.', 'woo-coupon-usage' ),
						'enum'        => array( 'pending', 'accepted', 'declined' ),
					),
					'user_id'  => wcusage_abilities_prop( 'user_id', __( 'Only registrations by this WordPress user ID.', 'woo-coupon-usage' ) ),
				)
			),
			'output'      => wcusage_abilities_collection_output( __( 'Registrations, each with id, user, coupon_code, status, type, promote, referrer, website, custom_fields, date and date_accepted.', 'woo-coupon-usage' ) ),
		);

		$definitions['coupon-affiliates/get-registration'] = array(
			'label'       => __( 'Get affiliate registration', 'woo-coupon-usage' ),
			'description' => __( 'Get one affiliate registration (application) by its ID, with everything the applicant submitted. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'registrations',
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Registrations_Controller',
			'permission'  => 'permission_admin_read',
			'callback'    => 'get_item',
			'method'      => 'GET',
			'route'       => '/registrations/{id}',
			'input'       => wcusage_abilities_input(
				array( 'id' => wcusage_abilities_prop( 'id', __( 'Registration ID.', 'woo-coupon-usage' ) ) ),
				array( 'id' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'id'          => array( 'type' => 'integer' ),
					'user'        => $user_output,
					'coupon_code' => array( 'type' => 'string' ),
					'status'      => array( 'type' => 'string' ),
				)
			),
		);

		$definitions['coupon-affiliates/update-registration-status'] = array(
			'label'       => __( 'Approve or decline a registration', 'woo-coupon-usage' ),
			'description' => __( 'Approve ("accepted") or decline ("declined") an affiliate registration. Accepting runs the full approval flow: it creates the affiliate\'s coupon from the template coupon, gives them the affiliate role and emails them - set send_email to false to skip the email. An accepted registration cannot be reversed with this ability. Confirm with the user before calling it. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'registrations',
			'type'        => 'write',
			'destructive' => false,
			'idempotent'  => false,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Registrations_Controller',
			'permission'  => 'permission_admin_write',
			'callback'    => 'update_item_status',
			'method'      => 'POST',
			'route'       => '/registrations/{id}/status',
			'input'       => wcusage_abilities_input(
				array(
					'id'         => wcusage_abilities_prop( 'id', __( 'Registration ID.', 'woo-coupon-usage' ) ),
					'status'     => array(
						'type'        => 'string',
						'description' => __( '"accepted" to approve the application, "declined" to turn it down.', 'woo-coupon-usage' ),
						'enum'        => array( 'accepted', 'declined' ),
					),
					'message'    => array(
						'type'        => 'string',
						'description' => __( 'Optional message to include in the email to the applicant.', 'woo-coupon-usage' ),
						'default'     => '',
						'x-sanitize'  => 'sanitize_textarea_field',
					),
					'send_email' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the applicant.', 'woo-coupon-usage' ),
						'default'     => true,
					),
				),
				array( 'id', 'status' )
			),
			'output'      => wcusage_abilities_output(
				array(
					'id'          => array( 'type' => 'integer' ),
					'coupon_code' => array( 'type' => 'string' ),
					'status'      => array(
						'type'        => 'string',
						'description' => __( 'The registration\'s status after the change.', 'woo-coupon-usage' ),
					),
				)
			),
		);

		/*
		 * Payouts (PRO)
		 */

		$payout_output = wcusage_abilities_output(
			array(
				'id'          => array( 'type' => 'integer' ),
				'user'        => $user_output,
				'coupon_id'   => array( 'type' => 'integer' ),
				'coupon_code' => array( 'type' => 'string' ),
				'amount'      => array( 'type' => 'number' ),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'pending (requested), created (approved), paid or cancel (cancelled).', 'woo-coupon-usage' ),
				),
				'date'        => $date_output,
				'date_paid'   => $date_output,
			)
		);

		$definitions['coupon-affiliates/list-payouts'] = array(
			'label'       => __( 'List payouts', 'woo-coupon-usage' ),
			'description' => __( 'List affiliate commission payouts, newest first, with amount, payout method, status - pending (requested), created (approved), paid or cancel (cancelled) - transaction ID and dates. Payment destination details are never included. Filter by affiliate, coupon, status or date requested. An affiliate only ever sees their own payouts.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'payouts',
			'pro'         => true,
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Payouts_Controller',
			'permission'  => 'permission_list',
			'callback'    => 'get_items',
			'method'      => 'GET',
			'route'       => '/payouts',
			'paginated'   => true,
			'input'       => wcusage_abilities_input(
				array(
					'page'      => wcusage_abilities_prop( 'page' ),
					'per_page'  => wcusage_abilities_prop( 'per_page' ),
					'user_id'   => wcusage_abilities_prop( 'user_id', __( 'Only payouts for this affiliate user ID.', 'woo-coupon-usage' ) ),
					'coupon_id' => wcusage_abilities_prop( 'coupon_id', __( 'Only payouts for this coupon ID.', 'woo-coupon-usage' ) ),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'Only payouts with this status.', 'woo-coupon-usage' ),
						'enum'        => array( 'pending', 'created', 'paid', 'cancel' ),
					),
					'from'      => wcusage_abilities_prop( 'from', __( 'Only payouts requested on or after this date, in the format YYYY-MM-DD.', 'woo-coupon-usage' ) ),
					'to'        => wcusage_abilities_prop( 'to', __( 'Only payouts requested on or before this date, in the format YYYY-MM-DD.', 'woo-coupon-usage' ) ),
				)
			),
			'output'      => wcusage_abilities_collection_output( __( 'Payouts, each with id, user, coupon_id, coupon_code, amount, method, status, transaction_id, date and date_paid.', 'woo-coupon-usage' ) ),
		);

		$definitions['coupon-affiliates/get-payout'] = array(
			'label'       => __( 'Get payout', 'woo-coupon-usage' ),
			'description' => __( 'Get one affiliate payout by its ID. Payment destination details are never included. An affiliate can only read their own payouts.', 'woo-coupon-usage' ) . ' ' . $money,
			'group'       => 'payouts',
			'pro'         => true,
			'type'        => 'read',
			'destructive' => false,
			'idempotent'  => true,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Payouts_Controller',
			'permission'  => 'permission_item_read',
			'callback'    => 'get_item',
			'method'      => 'GET',
			'route'       => '/payouts/{id}',
			'input'       => wcusage_abilities_input(
				array( 'id' => wcusage_abilities_prop( 'id', __( 'Payout ID.', 'woo-coupon-usage' ) ) ),
				array( 'id' )
			),
			'output'      => $payout_output,
		);

		$definitions['coupon-affiliates/request-payout'] = array(
			'label'       => __( 'Request a payout', 'woo-coupon-usage' ),
			'description' => __( 'Request a payout of a coupon\'s whole unpaid commission, exactly as the affiliate\'s "Request Payout" button does, with the store\'s rules applied: minimum payout amount, saved payment details and invoices. If the store pays automatically, this can send money straight away through PayPal, Stripe or Wise - "gateway_triggered" in the result says so. If the coupon already has an open request, that request is returned instead ("existing_request" is true). Confirm with the user before calling it.', 'woo-coupon-usage' ),
			'group'       => 'payouts',
			'pro'         => true,
			'type'        => 'write',
			'destructive' => true,
			'idempotent'  => false,
			'access'      => 'affiliate',
			'controller'  => 'WCUsage_API_Payouts_Controller',
			'permission'  => 'permission_create',
			'callback'    => 'create_item',
			'method'      => 'POST',
			'route'       => '/payouts',
			'headers'     => array( 'X-WCUsage-Existing' => array( 'existing_request', 'bool' ) ),
			'input'       => wcusage_abilities_input(
				array( 'coupon_id' => array_merge( wcusage_abilities_prop( 'coupon_id', __( 'The coupon whose unpaid commission should be paid out.', 'woo-coupon-usage' ) ), array( 'x-coupon' => true ) ) ),
				array( 'coupon_id' )
			),
			'output'      => wcusage_abilities_output(
				array_merge(
					$payout_output['properties'],
					array(
						'existing_request'  => array(
							'type'        => 'boolean',
							'description' => __( 'True when an open request already existed and was returned instead of a new one.', 'woo-coupon-usage' ),
						),
						'gateway_triggered' => array(
							'type'        => 'boolean',
							'description' => __( 'True when the new request was sent to a payment gateway straight away.', 'woo-coupon-usage' ),
						),
					)
				)
			),
		);

		$definitions['coupon-affiliates/update-payout-status'] = array(
			'label'       => __( 'Update payout status', 'woo-coupon-usage' ),
			'description' => __( 'Change a payout\'s status to record what has happened to it: "paid", "cancel" (cancels it and returns the amount to the affiliate\'s unpaid commission), "created" (approved) or "pending". This is bookkeeping only - no money is sent through any payment gateway. Only sensible changes are allowed, and cancelled payouts can only be re-opened while the amount is still unpaid. Confirm with the user before calling it. Admin only.', 'woo-coupon-usage' ),
			'group'       => 'payouts',
			'pro'         => true,
			'type'        => 'write',
			'destructive' => true,
			'idempotent'  => false,
			'access'      => 'admin',
			'controller'  => 'WCUsage_API_Payouts_Controller',
			'permission'  => 'permission_admin_write',
			'callback'    => 'update_item_status',
			'method'      => 'POST',
			'route'       => '/payouts/{id}/status',
			'input'       => wcusage_abilities_input(
				array(
					'id'     => wcusage_abilities_prop( 'id', __( 'Payout ID.', 'woo-coupon-usage' ) ),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'The new status.', 'woo-coupon-usage' ),
						'enum'        => array( 'pending', 'created', 'paid', 'cancel' ),
					),
				),
				array( 'id', 'status' )
			),
			'output'      => $payout_output,
		);

		// Optional keys are filled in by wcusage_abilities_get_definitions(),
		// after the filter, so definitions added there get them too.
		return $definitions;
	}
}

if ( ! function_exists( 'wcusage_abilities_groups' ) ) {
	/**
	 * Headings the abilities are grouped under on the API screen.
	 *
	 * @return array Group key => label.
	 */
	function wcusage_abilities_groups() {
		return array(
			'program'       => __( 'Program', 'woo-coupon-usage' ),
			'affiliates'    => __( 'Affiliates', 'woo-coupon-usage' ),
			'coupons'       => __( 'Coupons & referrals', 'woo-coupon-usage' ),
			'registrations' => __( 'Registrations', 'woo-coupon-usage' ),
			'payouts'       => __( 'Payouts', 'woo-coupon-usage' ),
		);
	}
}
