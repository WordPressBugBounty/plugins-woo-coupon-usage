<?php

/**
 * Coupon Affiliates - Abilities - Settings, dispatch and bookkeeping.
 *
 * @package WooCouponUsage\Abilities
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'wcusage_abilities_supported' ) ) {
    /**
     * Whether this WordPress has the Abilities API (core since 6.9).
     *
     * @return bool
     */
    function wcusage_abilities_supported() {
        return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
    }

}
if ( !function_exists( 'wcusage_abilities_is_pro_build' ) ) {
    /**
     * Whether this is the PRO build of the plugin.
     *
     * The build marker rather than the licence, for the same reason as the
     * API screen's endpoint list: the payouts controller is compiled out of
     * the free build, so the build is what decides whether the payout
     * abilities can exist at all.
     *
     * @return bool
     */
    function wcusage_abilities_is_pro_build() {
        return (bool) wcu_fs()->is__premium_only();
    }

}
/*
 * ------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------
 *
 * Kept in the same "wcusage_api_settings" option as the REST API switches,
 * since both are managed from the same screen, but with a switch of their
 * own: a store can let AI agents read its program without opening the REST
 * API to API keys, and the other way round.
 */
if ( !function_exists( 'wcusage_abilities_default_settings' ) ) {
    /**
     * Defaults for the abilities settings.
     *
     * Off until an administrator switches AI & MCP on, like the REST API, so
     * a site never starts offering tools to AI apps just because the plugin
     * was updated. Once on, reading is all that is allowed: anything that
     * changes data waits for "abilities_write", and affiliates are kept out
     * until "abilities_affiliates" is switched on.
     *
     * "disabled_abilities" stores the exceptions rather than the whole list,
     * so abilities added in a later release are on by default - the same
     * approach the endpoint switches take.
     *
     * @return array
     */
    function wcusage_abilities_default_settings() {
        return array(
            'abilities_enabled'    => '0',
            'abilities_write'      => '0',
            'abilities_affiliates' => '0',
            'disabled_abilities'   => array(),
        );
    }

}
if ( !function_exists( 'wcusage_abilities_normalise_settings' ) ) {
    /**
     * Bring stored abilities settings into a known shape.
     *
     * @param array $settings Raw settings.
     *
     * @return array
     */
    function wcusage_abilities_normalise_settings(  $settings  ) {
        $settings = array_merge( wcusage_abilities_default_settings(), (array) $settings );
        foreach ( array('abilities_enabled', 'abilities_write', 'abilities_affiliates') as $flag ) {
            $settings[$flag] = ( '1' === (string) $settings[$flag] ? '1' : '0' );
        }
        $disabled = ( is_array( $settings['disabled_abilities'] ) ? $settings['disabled_abilities'] : array() );
        $settings['disabled_abilities'] = array_values( array_unique( array_filter( array_map( 'wcusage_abilities_sanitize_name', $disabled ) ) ) );
        return $settings;
    }

}
if ( !function_exists( 'wcusage_abilities_sanitize_name' ) ) {
    /**
     * Sanitise an ability name ("namespace/ability-name").
     *
     * @param string $name Raw name.
     *
     * @return string
     */
    function wcusage_abilities_sanitize_name(  $name  ) {
        return preg_replace( '/[^a-z0-9\\-\\/]/', '', strtolower( (string) $name ) );
    }

}
if ( !function_exists( 'wcusage_abilities_get_settings' ) ) {
    /**
     * The abilities settings, normalised.
     *
     * @return array
     */
    function wcusage_abilities_get_settings() {
        $settings = ( function_exists( 'wcusage_api_get_settings' ) ? wcusage_api_get_settings() : array() );
        return wcusage_abilities_normalise_settings( array_intersect_key( $settings, wcusage_abilities_default_settings() ) );
    }

}
if ( !function_exists( 'wcusage_abilities_update_settings' ) ) {
    /**
     * Merge changes into the abilities settings.
     *
     * @param array $changes Settings to change.
     *
     * @return array The saved abilities settings.
     */
    function wcusage_abilities_update_settings(  $changes  ) {
        $settings = wcusage_abilities_normalise_settings( array_merge( wcusage_abilities_get_settings(), array_intersect_key( (array) $changes, wcusage_abilities_default_settings() ) ) );
        wcusage_api_update_settings( $settings );
        return $settings;
    }

}
if ( !function_exists( 'wcusage_abilities_enabled' ) ) {
    /**
     * Whether abilities are switched on at all.
     *
     * @return bool
     */
    function wcusage_abilities_enabled() {
        $settings = wcusage_abilities_get_settings();
        return '1' === $settings['abilities_enabled'];
    }

}
if ( !function_exists( 'wcusage_abilities_write_allowed' ) ) {
    /**
     * Whether abilities that change data may be used.
     *
     * @return bool
     */
    function wcusage_abilities_write_allowed() {
        $settings = wcusage_abilities_get_settings();
        return '1' === $settings['abilities_write'];
    }

}
if ( !function_exists( 'wcusage_abilities_affiliates_allowed' ) ) {
    /**
     * Whether affiliates may use abilities for their own data.
     *
     * @return bool
     */
    function wcusage_abilities_affiliates_allowed() {
        $settings = wcusage_abilities_get_settings();
        return '1' === $settings['abilities_affiliates'];
    }

}
/*
 * ------------------------------------------------------------------
 * Definitions and state
 * ------------------------------------------------------------------
 */
if ( !function_exists( 'wcusage_abilities_controller_files' ) ) {
    /**
     * The controller classes abilities can call, and their files.
     *
     * The REST API v2 controllers, plus WCUsage_Abilities_Controller for the
     * abilities that have no REST endpoint.
     *
     * The payouts controller ships in the PRO build only, so in the free build
     * it is simply absent from this map - which is what marks the payout
     * abilities as locked there.
     *
     * @return array Class name => file path.
     */
    function wcusage_abilities_controller_files() {
        $dir = WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/api/v2/controllers/';
        $files = array(
            'WCUsage_API_Affiliates_Controller'    => $dir . 'class-wcusage-api-affiliates-controller.php',
            'WCUsage_API_Coupons_Controller'       => $dir . 'class-wcusage-api-coupons-controller.php',
            'WCUsage_API_Registrations_Controller' => $dir . 'class-wcusage-api-registrations-controller.php',
            'WCUsage_API_Events_Controller'        => $dir . 'class-wcusage-api-events-controller.php',
            'WCUsage_API_Clicks_Controller'        => $dir . 'class-wcusage-api-clicks-controller.php',
            'WCUsage_API_Reports_Controller'       => $dir . 'class-wcusage-api-reports-controller.php',
            'WCUsage_API_Me_Controller'            => $dir . 'class-wcusage-api-me-controller.php',
            'WCUsage_Abilities_Controller'         => WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/abilities/class-wcusage-abilities-controller.php',
        );
        /**
         * Filter the controllers abilities may call, as class name => file.
         *
         * Lets an extension back abilities of its own, added through the
         * wcusage_abilities_definitions filter, with a controller that extends
         * WCUsage_API_Controller.
         *
         * @param array $files Class name => file path.
         */
        return (array) apply_filters( 'wcusage_abilities_controller_files', $files );
    }

}
if ( !function_exists( 'wcusage_abilities_controller' ) ) {
    /**
     * A shared instance of a controller abilities call.
     *
     * The controllers are only ever loaded by the REST API when it registers
     * its routes, and that does not happen while the API is switched off, so
     * they are loaded here on demand. Nothing in them needs the routes to
     * exist: abilities call the handler methods directly.
     *
     * @param string $class Controller class name.
     *
     * @return WCUsage_API_Controller|null Null when this build does not ship it.
     */
    function wcusage_abilities_controller(  $class  ) {
        static $instances = array();
        if ( isset( $instances[$class] ) ) {
            return $instances[$class];
        }
        $files = wcusage_abilities_controller_files();
        if ( !isset( $files[$class] ) || !file_exists( $files[$class] ) ) {
            return null;
        }
        include_once WCUSAGE_UNIQUE_PLUGIN_PATH . 'inc/api/v2/class-wcusage-api-controller.php';
        include_once $files[$class];
        if ( !class_exists( $class ) ) {
            return null;
        }
        $instances[$class] = new $class();
        return $instances[$class];
    }

}
if ( !function_exists( 'wcusage_abilities_get_definitions' ) ) {
    /**
     * Every ability this plugin knows about, whether or not it is active.
     *
     * @return array Ability name => definition.
     */
    function wcusage_abilities_get_definitions() {
        static $definitions = null;
        if ( null === $definitions ) {
            /**
             * Filter the abilities Coupon Affiliates registers.
             *
             * Each definition maps an ability onto a controller method - see
             * wcusage_abilities_definitions() for the keys.
             *
             * @param array $definitions Ability name => definition.
             */
            $filtered = (array) apply_filters( 'wcusage_abilities_definitions', wcusage_abilities_definitions() );
            // Fill in the optional keys, and drop anything without what it
            // needs to be registered and run - a malformed definition from the
            // filter should go missing, not raise notices on every request.
            $definitions = array();
            foreach ( $filtered as $name => $definition ) {
                if ( !is_string( $name ) || !is_array( $definition ) ) {
                    continue;
                }
                $definition = array_merge( array(
                    'group'              => '',
                    'type'               => 'read',
                    'destructive'        => false,
                    'idempotent'         => false,
                    'access'             => 'admin',
                    'affiliate_requires' => array(),
                    'pro'                => false,
                    'method'             => 'GET',
                    'route'              => '/',
                    'paginated'          => false,
                    'headers'            => array(),
                    'input'              => wcusage_abilities_input(),
                    'output'             => array(
                        'type' => 'object',
                    ),
                ), $definition );
                $complete = true;
                foreach ( array(
                    'label',
                    'description',
                    'controller',
                    'permission',
                    'callback'
                ) as $required ) {
                    if ( empty( $definition[$required] ) || !is_string( $definition[$required] ) ) {
                        $complete = false;
                    }
                }
                if ( $complete ) {
                    $definitions[$name] = $definition;
                }
            }
        }
        return $definitions;
    }

}
if ( !function_exists( 'wcusage_abilities_get_definition' ) ) {
    /**
     * One ability's definition.
     *
     * @param string $name Ability name.
     *
     * @return array|null
     */
    function wcusage_abilities_get_definition(  $name  ) {
        $definitions = wcusage_abilities_get_definitions();
        return ( isset( $definitions[$name] ) ? $definitions[$name] : null );
    }

}
if ( !function_exists( 'wcusage_abilities_is_available' ) ) {
    /**
     * Whether this build ships what an ability needs.
     *
     * @param array $definition Ability definition.
     *
     * @return bool False for PRO abilities in the free build.
     */
    function wcusage_abilities_is_available(  $definition  ) {
        $files = wcusage_abilities_controller_files();
        return isset( $definition['controller'], $files[$definition['controller']] );
    }

}
if ( !function_exists( 'wcusage_abilities_is_active' ) ) {
    /**
     * Whether an ability should be registered and may be run.
     *
     * @param string $name Ability name.
     *
     * @return bool
     */
    function wcusage_abilities_is_active(  $name  ) {
        $definition = wcusage_abilities_get_definition( $name );
        if ( !$definition || !wcusage_abilities_is_available( $definition ) ) {
            return false;
        }
        $settings = wcusage_abilities_get_settings();
        if ( '1' !== $settings['abilities_enabled'] ) {
            return false;
        }
        if ( 'write' === $definition['type'] && '1' !== $settings['abilities_write'] ) {
            return false;
        }
        return !in_array( $name, $settings['disabled_abilities'], true );
    }

}
if ( !function_exists( 'wcusage_abilities_active_names' ) ) {
    /**
     * Names of every ability that is currently active.
     *
     * @return string[]
     */
    function wcusage_abilities_active_names() {
        return array_values( array_filter( array_keys( wcusage_abilities_get_definitions() ), 'wcusage_abilities_is_active' ) );
    }

}
/*
 * ------------------------------------------------------------------
 * Schemas
 * ------------------------------------------------------------------
 */
if ( !function_exists( 'wcusage_abilities_public_schema' ) ) {
    /**
     * Strip this plugin's private "x-" keys from a schema.
     *
     * Definitions carry a couple of extra keys per property - which sanitiser
     * to run, whether it is a date - so that everything about a parameter
     * lives in one place. They mean nothing to a client, so they are removed
     * before the schema is handed to the Abilities API.
     *
     * @param array $schema Schema from a definition.
     *
     * @return array
     */
    function wcusage_abilities_public_schema(  $schema  ) {
        if ( !is_array( $schema ) ) {
            return $schema;
        }
        foreach ( array_keys( $schema ) as $key ) {
            if ( is_string( $key ) && 0 === strpos( $key, 'x-' ) ) {
                unset($schema[$key]);
            } elseif ( is_array( $schema[$key] ) ) {
                $schema[$key] = wcusage_abilities_public_schema( $schema[$key] );
            }
        }
        return $schema;
    }

}
if ( !function_exists( 'wcusage_abilities_input_properties' ) ) {
    /**
     * The input properties of a definition, private keys included.
     *
     * @param array $definition Ability definition.
     *
     * @return array
     */
    function wcusage_abilities_input_properties(  $definition  ) {
        return ( isset( $definition['input']['properties'] ) && is_array( $definition['input']['properties'] ) ? $definition['input']['properties'] : array() );
    }

}
/*
 * ------------------------------------------------------------------
 * Dispatch
 * ------------------------------------------------------------------
 */
if ( !function_exists( 'wcusage_abilities_build_request' ) ) {
    /**
     * Turn ability input into the request its controller method expects.
     *
     * The Abilities API has already validated the input against the schema by
     * the time this runs, so this only does what the REST server would do
     * after validation: coerce types, run each parameter's sanitiser and fill
     * in defaults. Defaults matter - the controllers read an absent
     * "send_email", for example, as false where the route's default is true.
     *
     * @param array $definition Ability definition.
     * @param mixed $input      Validated ability input.
     *
     * @return WP_REST_Request
     */
    function wcusage_abilities_build_request(  $definition, $input  ) {
        $input = ( is_array( $input ) ? $input : array() );
        $route = ( isset( $definition['route'] ) ? (string) $definition['route'] : '/' );
        if ( isset( $input['id'] ) ) {
            $route = str_replace( '{id}', (string) absint( $input['id'] ), $route );
        }
        $request = new WP_REST_Request(( isset( $definition['method'] ) ? $definition['method'] : 'GET' ), '/wcusage/v2' . $route);
        foreach ( wcusage_abilities_input_properties( $definition ) as $key => $property ) {
            if ( array_key_exists( $key, $input ) && null !== $input[$key] ) {
                $value = rest_sanitize_value_from_schema( $input[$key], wcusage_abilities_public_schema( $property ), $key );
                if ( is_wp_error( $value ) ) {
                    continue;
                }
                if ( !empty( $property['x-sanitize'] ) && is_callable( $property['x-sanitize'] ) ) {
                    $value = call_user_func( $property['x-sanitize'], $value );
                }
            } elseif ( array_key_exists( 'default', $property ) ) {
                $value = $property['default'];
            } else {
                continue;
            }
            $request->set_param( $key, $value );
        }
        return $request;
    }

}
if ( !function_exists( 'wcusage_abilities_missing_affiliate_param' ) ) {
    /**
     * A parameter a non-admin must pass that the input leaves out.
     *
     * @param array $definition Ability definition.
     * @param mixed $input      Ability input.
     *
     * @return string The first missing parameter, or an empty string.
     */
    function wcusage_abilities_missing_affiliate_param(  $definition, $input  ) {
        if ( empty( $definition['affiliate_requires'] ) || wcusage_api_is_admin_user() ) {
            return '';
        }
        foreach ( (array) $definition['affiliate_requires'] as $key ) {
            if ( !is_array( $input ) || empty( $input[$key] ) ) {
                return (string) $key;
            }
        }
        return '';
    }

}
if ( !function_exists( 'wcusage_abilities_check_permission' ) ) {
    /**
     * Permission callback shared by every ability.
     *
     * Deliberately returns a plain boolean. The Abilities API turns a WP_Error
     * from a permission callback into a generic refusal and raises a
     * _doing_it_wrong() notice about it, so the detail would never reach the
     * caller anyway.
     *
     * Layered, most general first:
     *  1. the ability is switched on, and writes are allowed if it writes;
     *  2. the caller is logged in;
     *  3. a non-admin caller is an affiliate, affiliates are allowed in, and
     *     the ability is one an affiliate may use at all;
     *  4. the REST API controller's own permission callback, which is what
     *     limits an affiliate to their own coupons, stats and payouts.
     *
     * @param string $name  Ability name.
     * @param mixed  $input Ability input.
     *
     * @return bool
     */
    function wcusage_abilities_check_permission(  $name, $input = null  ) {
        $definition = wcusage_abilities_get_definition( $name );
        if ( !$definition || !wcusage_abilities_is_active( $name ) || !is_user_logged_in() ) {
            return false;
        }
        if ( !wcusage_api_is_admin_user() ) {
            if ( 'admin' === $definition['access'] || !wcusage_abilities_affiliates_allowed() ) {
                return false;
            }
            if ( !function_exists( 'wcusage_is_user_affiliate' ) || !wcusage_is_user_affiliate( get_current_user_id() ) ) {
                return false;
            }
            // An affiliate left out a parameter only admins may leave out, such
            // as the coupon for click stats. The controller would refuse that
            // too, but a refusal here reaches the agent only as a generic "no
            // permission", with nothing to correct - so let it through to
            // wcusage_abilities_run(), which refuses it before any handler
            // runs, with a message naming the parameter.
            if ( '' !== wcusage_abilities_missing_affiliate_param( $definition, $input ) ) {
                return true;
            }
        }
        $controller = wcusage_abilities_controller( $definition['controller'] );
        if ( !$controller || !is_callable( array($controller, $definition['permission']) ) ) {
            return false;
        }
        $allowed = call_user_func( array($controller, $definition['permission']), wcusage_abilities_build_request( $definition, $input ) );
        // The coupon and payout checks answer "not found" for an object that
        // does not exist. Refused here, that would reach an admin as a generic
        // "no permission" - wrong, and unhelpful to an agent that only mistyped
        // an ID. An admin may read everything anyway, so let it through: the
        // handler, or for handlers that would not say so the "x-coupon" check
        // in wcusage_abilities_run(), then reports "Coupon not found". For
        // anybody else the refusal stands, which keeps the REST API's rule
        // that someone else's coupon and a missing one look exactly alike.
        if ( is_wp_error( $allowed ) && wcusage_api_is_admin_user() ) {
            $data = $allowed->get_error_data();
            if ( is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'] ) {
                return true;
            }
        }
        return true === $allowed;
    }

}
if ( !function_exists( 'wcusage_abilities_execute' ) ) {
    /**
     * Execute callback shared by every ability.
     *
     * The permission callback has already passed by the time this runs - the
     * Abilities API checks it on every execute() - so this goes straight to
     * the controller method and reshapes what it returns.
     *
     * @param string $name  Ability name.
     * @param mixed  $input Ability input.
     *
     * @return array|WP_Error
     */
    function wcusage_abilities_execute(  $name, $input = null  ) {
        $result = wcusage_abilities_run( $name, $input );
        // The Abilities API checks the result against the output schema after
        // this returns, and turns a mismatch into an error. Check it here too,
        // so the activity log records that as the failure the caller will
        // see, rather than as a success.
        $logged = $result;
        $definition = wcusage_abilities_get_definition( $name );
        if ( $definition && !is_wp_error( $result ) ) {
            $valid = rest_validate_value_from_schema( $result, wcusage_abilities_public_schema( $definition['output'] ), 'output' );
            if ( is_wp_error( $valid ) ) {
                $logged = new WP_Error('ability_invalid_output', $valid->get_error_message());
            }
        }
        // A caller over the rate limit is not recorded: logging costs a write,
        // and the log would fill with the same refusal.
        if ( !is_wp_error( $result ) || 'wcusage_abilities_rate_limited' !== $result->get_error_code() ) {
            wcusage_abilities_log_call( $name, $input, $logged );
        }
        return $result;
    }

}
if ( !function_exists( 'wcusage_abilities_run' ) ) {
    /**
     * Do the work behind wcusage_abilities_execute().
     *
     * @param string $name  Ability name.
     * @param mixed  $input Ability input.
     *
     * @return array|WP_Error
     */
    function wcusage_abilities_run(  $name, $input  ) {
        $definition = wcusage_abilities_get_definition( $name );
        $controller = ( $definition ? wcusage_abilities_controller( $definition['controller'] ) : null );
        if ( !$controller || !is_callable( array($controller, $definition['callback']) ) ) {
            return wcusage_abilities_error( 'unavailable', __( 'This ability is not available on this site.', 'woo-coupon-usage' ), 501 );
        }
        // First, before anything else can run: the permission check lets an
        // affiliate through without a parameter only admins may omit, so that
        // the refusal can say what is missing. This is that refusal.
        $missing = wcusage_abilities_missing_affiliate_param( $definition, $input );
        if ( '' !== $missing ) {
            return wcusage_abilities_error( 
                'missing_parameter',
                /* translators: %s: parameter name */
                sprintf( __( '"%s" is required: pass one of the coupons on your own affiliate dashboard. Figures for the whole store are for admins only.', 'woo-coupon-usage' ), $missing ),
                400
             );
        }
        if ( !wcusage_abilities_rate_limit_ok( $definition['type'] ) ) {
            return wcusage_abilities_error(
                'rate_limited',
                __( 'Too many requests. Please wait a minute before trying again.', 'woo-coupon-usage' ),
                429,
                array(
                    'retry_after' => 60,
                )
            );
        }
        $input = ( is_array( $input ) ? $input : array() );
        // The schema pattern already guarantees YYYY-MM-DD; this catches dates
        // with that shape that do not exist, such as 2026-02-30, the same way
        // the REST route's validate_callback does.
        foreach ( wcusage_abilities_input_properties( $definition ) as $key => $property ) {
            if ( !empty( $property['x-date'] ) && isset( $input[$key] ) && !wcusage_api_validate_date_arg( $input[$key] ) ) {
                return wcusage_abilities_error( 
                    'invalid_date',
                    /* translators: %s: parameter name */
                    sprintf( __( '"%s" must be a real date in the format YYYY-MM-DD.', 'woo-coupon-usage' ), $key ),
                    400
                 );
            }
            // Only an admin reaches this with a coupon that does not exist - the
            // permission check refuses anybody else - so saying so leaks nothing.
            if ( !empty( $property['x-coupon'] ) && !empty( $input[$key] ) && !wcusage_api_get_valid_coupon_id( $input[$key] ) ) {
                return wcusage_api_error( 'not_found', __( 'Coupon not found.', 'woo-coupon-usage' ), 404 );
            }
        }
        $request = wcusage_abilities_build_request( $definition, $input );
        // Anything a handler prints - an admin notice from a payment gateway, a
        // notice from an email template - would otherwise end up in front of
        // an MCP or REST response body and break it. The controllers already
        // buffer their own gateway calls; this covers everything else.
        ob_start();
        try {
            $result = call_user_func( array($controller, $definition['callback']), $request );
        } catch ( Throwable $e ) {
            // The caller may be an affiliate, and an exception message can hold
            // server paths, so they get a plain message; the detail goes to the
            // PHP error log for the site owner.
            error_log( sprintf(
                'Coupon Affiliates: ability %s failed: %s in %s:%d',
                $name,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ) );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            $result = wcusage_abilities_error( 'failed', __( 'Something went wrong on the site while running this. The details have been logged for the site owner.', 'woo-coupon-usage' ), 500 );
        }
        ob_end_clean();
        return wcusage_abilities_format_result( $definition, $request, $result );
    }

}
if ( !function_exists( 'wcusage_abilities_format_result' ) ) {
    /**
     * Reshape a controller response into an ability result.
     *
     * The REST API carries paging, and a few other facts, in HTTP headers.
     * An ability result is only a value, so those are moved into it:
     * collections come back as { items, total, total_pages, page, per_page }
     * and any headers the definition names become fields of their own.
     *
     * @param array                          $definition Ability definition.
     * @param WP_REST_Request                $request    The request the controller saw.
     * @param WP_REST_Response|WP_Error|mixed $result     What the controller returned.
     *
     * @return array|WP_Error
     */
    function wcusage_abilities_format_result(  $definition, $request, $result  ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $response = rest_ensure_response( $result );
        $data = $response->get_data();
        $headers = $response->get_headers();
        if ( !empty( $definition['paginated'] ) ) {
            $items = array_values( (array) $data );
            $data = array(
                'items'       => $items,
                'total'       => ( isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $items ) ),
                'total_pages' => ( isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1 ),
                'page'        => max( 1, (int) $request['page'] ),
                'per_page'    => max( 1, (int) $request['per_page'] ),
            );
        }
        if ( !is_array( $data ) ) {
            $data = array(
                'result' => $data,
            );
        }
        if ( !empty( $definition['headers'] ) ) {
            foreach ( $definition['headers'] as $header => $field ) {
                $value = ( isset( $headers[$header] ) ? $headers[$header] : null );
                switch ( $field[1] ) {
                    case 'bool':
                        $data[$field[0]] = !empty( $value );
                        break;
                    case 'int':
                        $data[$field[0]] = (int) $value;
                        break;
                }
            }
        }
        return $data;
    }

}
if ( !function_exists( 'wcusage_abilities_error' ) ) {
    /**
     * Build an ability error in the same shape as the REST API's errors.
     *
     * @param string $code    Error code, without prefix.
     * @param string $message Human readable message.
     * @param int    $status  HTTP status for REST callers.
     * @param array  $data    Extra fields.
     *
     * @return WP_Error
     */
    function wcusage_abilities_error(
        $code,
        $message,
        $status,
        $data = array()
    ) {
        return new WP_Error('wcusage_abilities_' . $code, $message, array_merge( (array) $data, array(
            'status' => (int) $status,
        ) ));
    }

}
if ( !function_exists( 'wcusage_abilities_rate_limit_ok' ) ) {
    /**
     * Per-user, per-minute limit on ability calls.
     *
     * Abilities do not pass through the REST API's own limiter - that one is
     * keyed to the plugin's REST routes, and an ability can be called from
     * an MCP route, core's abilities routes or plain PHP - so they carry one
     * of their own. Reads and writes are counted separately, and writes get
     * a much smaller budget: an agent approving applications in a loop should
     * be slowed down long before it could do real damage.
     *
     * @param string $type "read" or "write".
     *
     * @return bool True when the call may go ahead.
     */
    function wcusage_abilities_rate_limit_ok(  $type  ) {
        $type = ( 'write' === $type ? 'write' : 'read' );
        $user_id = get_current_user_id();
        /**
         * Filter how many ability calls a user may make per minute.
         *
         * @param int    $limit   Calls per minute. Zero or less disables the limit.
         * @param string $type    "read" or "write".
         * @param int    $user_id Calling user.
         */
        $limit = (int) apply_filters(
            'wcusage_abilities_rate_limit',
            ( 'write' === $type ? 20 : 120 ),
            $type,
            $user_id
        );
        if ( $limit <= 0 ) {
            return true;
        }
        $bucket = 'wcusage_ab_rl_' . md5( $type . '|' . $user_id ) . '_' . gmdate( 'YmdHi' );
        // With a persistent object cache the count is kept there, where
        // incrementing it is atomic, so parallel calls cannot slip past the
        // limit between reading the count and writing it back.
        if ( wp_using_ext_object_cache() ) {
            wp_cache_add(
                $bucket,
                0,
                'wcusage_abilities',
                2 * MINUTE_IN_SECONDS
            );
            $count = wp_cache_incr( $bucket, 1, 'wcusage_abilities' );
            return false === $count || (int) $count <= $limit;
        }
        // Otherwise a transient, which is not atomic: parallel calls can
        // undercount a little, which a per-minute budget can live with. Once
        // over the limit, calls are refused without writing anything, so a
        // client that keeps going costs a read per call rather than a write.
        $count = (int) get_transient( $bucket );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );
        return true;
    }

}
/*
 * ------------------------------------------------------------------
 * Recent activity
 * ------------------------------------------------------------------
 *
 * A short record of what agents have done, shown on the API screen so a
 * store owner can see what is being asked of their program. Changes an
 * ability makes also land in the plugin's activity log through the flows
 * they run (registration_accept, payout_paid and so on); this is the view
 * from the agent's side, including the calls that failed. A call refused by
 * the permission check never reaches the execute callback, so it is not
 * recorded here.
 */
if ( !function_exists( 'wcusage_abilities_log_size' ) ) {
    /**
     * How many recent calls are kept.
     *
     * @return int
     */
    function wcusage_abilities_log_size() {
        /**
         * Filter how many recent ability calls are kept for the API screen.
         *
         * @param int $size Entries kept. Zero switches the record off.
         */
        return max( 0, (int) apply_filters( 'wcusage_abilities_log_size', 30 ) );
    }

}
if ( !function_exists( 'wcusage_abilities_request_channel' ) ) {
    /**
     * How the current ability call reached the site.
     *
     * @return string "mcp", "rest", "cli" or "php".
     */
    function wcusage_abilities_request_channel() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return 'cli';
        }
        $route = ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && !empty( $GLOBALS['wp']->query_vars['rest_route'] ) ? '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' ) : '' );
        if ( '' === $route ) {
            $channel = 'php';
        } elseif ( 0 === strpos( $route, '/wp-abilities/' ) ) {
            $channel = 'rest';
        } elseif ( false !== stripos( $route, 'mcp' ) ) {
            // The MCP Adapter's routes, this plugin's own server, and the MCP
            // connector plugins suggested on the AI & MCP tab, which serve MCP
            // from REST routes of their own - all carry "mcp" in the route.
            $channel = 'mcp';
        } else {
            $channel = 'php';
        }
        /**
         * Filter how an ability call is recorded as having arrived.
         *
         * For an MCP server whose route does not say "mcp", say.
         *
         * @param string $channel "mcp", "rest", "cli" or "php".
         * @param string $route   The REST route being served, or "".
         */
        return (string) apply_filters( 'wcusage_abilities_request_channel', $channel, $route );
    }

}
if ( !function_exists( 'wcusage_abilities_log_call' ) ) {
    /**
     * Record one ability call.
     *
     * Only the ability, who called it, how, and the object it was about are
     * kept - never the rest of the input, which can hold free text such as a
     * message to an applicant.
     *
     * @param string         $name   Ability name.
     * @param mixed          $input  Ability input.
     * @param array|WP_Error $result Result.
     */
    function wcusage_abilities_log_call(  $name, $input, $result  ) {
        $size = wcusage_abilities_log_size();
        if ( $size <= 0 ) {
            return;
        }
        $target = 0;
        if ( is_array( $input ) ) {
            foreach ( array('id', 'coupon_id', 'user_id') as $key ) {
                if ( !empty( $input[$key] ) ) {
                    $target = absint( $input[$key] );
                    break;
                }
            }
        }
        $log = get_option( 'wcusage_abilities_log', array() );
        if ( !is_array( $log ) ) {
            $log = array();
        }
        array_unshift( $log, array(
            'time'    => time(),
            'ability' => (string) $name,
            'user_id' => get_current_user_id(),
            'channel' => wcusage_abilities_request_channel(),
            'target'  => $target,
            'ok'      => !is_wp_error( $result ),
            'error'   => ( is_wp_error( $result ) ? wp_html_excerpt( wp_strip_all_tags( $result->get_error_message() ), 200, '…' ) : '' ),
        ) );
        update_option( 'wcusage_abilities_log', array_slice( $log, 0, $size ), false );
    }

}
if ( !function_exists( 'wcusage_abilities_get_log' ) ) {
    /**
     * Recent ability calls, newest first.
     *
     * @return array[]
     */
    function wcusage_abilities_get_log() {
        $log = get_option( 'wcusage_abilities_log', array() );
        if ( !is_array( $log ) ) {
            return array();
        }
        $clean = array();
        foreach ( $log as $entry ) {
            if ( is_array( $entry ) && isset( $entry['time'], $entry['ability'] ) ) {
                $clean[] = array_merge( array(
                    'user_id' => 0,
                    'channel' => 'php',
                    'target'  => 0,
                    'ok'      => true,
                    'error'   => '',
                ), $entry );
            }
        }
        return $clean;
    }

}
/*
 * ------------------------------------------------------------------
 * MCP
 * ------------------------------------------------------------------
 */
if ( !function_exists( 'wcusage_abilities_mcp_server_id' ) ) {
    /**
     * ID, REST namespace and route of this plugin's MCP server.
     *
     * @return array array( id, namespace, route ).
     */
    function wcusage_abilities_mcp_server_id() {
        return array('coupon-affiliates', 'coupon-affiliates', 'mcp');
    }

}
if ( !function_exists( 'wcusage_abilities_mcp_adapter' ) ) {
    /**
     * Remember, or read, the MCP adapter that started up this request.
     *
     * Hooked to the adapter's own "mcp_adapter_init" action, which fires only
     * when an adapter has really been switched on. Whether its class can be
     * loaded says nothing about that: WooCommerce ships a copy of the adapter
     * that its autoloader can always find, but only starts it when its
     * "WooCommerce MCP" feature is enabled. Nor is McpAdapter::instance() ever
     * called from here to find out - that would start WooCommerce's dormant
     * copy as a side effect.
     *
     * @param object|null $adapter Adapter to remember, or null to read.
     *
     * @return object|null
     */
    function wcusage_abilities_mcp_adapter(  $adapter = null  ) {
        static $current = null;
        if ( is_object( $adapter ) ) {
            $current = $adapter;
        }
        return $current;
    }

}
if ( !function_exists( 'wcusage_abilities_mcp_signals' ) ) {
    /**
     * Whether this site is set up to run an MCP adapter, from cheap checks only.
     *
     * Nothing here starts the adapter or the REST server, so it is safe to
     * call on any admin page: an adapter only actually starts on rest_api_init,
     * and wcusage_abilities_mcp_status() is what finds out whether it did.
     *
     * @return array {
     *     @type bool $plugin_active The MCP Adapter plugin is active.
     *     @type bool $woo_bundled   WooCommerce ships its own copy of the adapter.
     *     @type bool $woo_enabled   ...and its "WooCommerce MCP" feature, which runs it, is on.
     * }
     */
    function wcusage_abilities_mcp_signals() {
        $plugin_file = 'mcp-adapter/mcp-adapter.php';
        $plugin_active = defined( 'WP_MCP_VERSION' ) || in_array( $plugin_file, (array) get_option( 'active_plugins', array() ), true ) || is_multisite() && array_key_exists( $plugin_file, (array) get_site_option( 'active_sitewide_plugins', array() ) );
        // WooCommerce 10.3+ bundles the adapter behind its experimental
        // "WooCommerce MCP" feature.
        $woo_bundled = class_exists( 'Automattic\\WooCommerce\\Internal\\MCP\\MCPAdapterProvider' );
        $woo_enabled = $woo_bundled && class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) && call_user_func( array('Automattic\\WooCommerce\\Utilities\\FeaturesUtil', 'feature_is_enabled'), 'mcp_integration' );
        return array(
            'plugin_active' => $plugin_active,
            'woo_bundled'   => $woo_bundled,
            'woo_enabled'   => (bool) $woo_enabled,
        );
    }

}
if ( !function_exists( 'wcusage_abilities_mcp_status' ) ) {
    /**
     * Where MCP support stands on this site, for the API screen.
     *
     * @return array
     */
    function wcusage_abilities_mcp_status() {
        list( $server_id, $namespace, $route ) = wcusage_abilities_mcp_server_id();
        $signals = wcusage_abilities_mcp_signals();
        $plugin_file = 'mcp-adapter/mcp-adapter.php';
        $plugin_active = $signals['plugin_active'];
        $woo_bundled = $signals['woo_bundled'];
        $woo_enabled = $signals['woo_enabled'];
        // Adapters start up on rest_api_init, which on an admin screen only
        // fires once something asks for the REST server.
        if ( $plugin_active || $woo_enabled || did_action( 'wp_mcp_init' ) ) {
            rest_get_server();
        }
        $adapter = wcusage_abilities_mcp_adapter();
        $running = is_object( $adapter ) || did_action( 'mcp_adapter_init' ) > 0;
        $source = '';
        if ( $running ) {
            if ( $plugin_active ) {
                $source = 'plugin';
            } elseif ( $woo_enabled ) {
                $source = 'woocommerce';
            } else {
                $source = 'other';
            }
        }
        $version = ( is_object( $adapter ) && defined( get_class( $adapter ) . '::VERSION' ) ? (string) constant( get_class( $adapter ) . '::VERSION' ) : '' );
        $can_list = is_object( $adapter ) && method_exists( $adapter, 'get_server' );
        return array(
            'running'          => $running,
            'source'           => $source,
            'version'          => $version,
            'plugin_installed' => file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ),
            'woo_bundled'      => $woo_bundled,
            'server_url'       => rest_url( $namespace . '/' . $route ),
            'server_live'      => $can_list && null !== $adapter->get_server( $server_id ),
            'server_error'     => wcusage_abilities_mcp_server_error(),
            'default_url'      => rest_url( 'mcp/mcp-adapter-default-server' ),
            'default_live'     => $can_list && null !== $adapter->get_server( 'mcp-adapter-default-server' ),
        );
    }

}
if ( !function_exists( 'wcusage_abilities_mcp_server_error' ) ) {
    /**
     * Remember or read why the MCP server could not be created this request.
     *
     * @param string|null $message Message to store, or null to read.
     *
     * @return string
     */
    function wcusage_abilities_mcp_server_error(  $message = null  ) {
        static $error = '';
        if ( null !== $message ) {
            $error = (string) $message;
        }
        return $error;
    }

}