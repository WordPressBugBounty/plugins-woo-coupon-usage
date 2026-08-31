<?php

/**
 * Coupon Affiliates REST API v2 - API management (/keys, /webhooks).
 *
 * All routes require a plugin admin with the "manage" scope. Key tokens are
 * returned exactly once, at creation.
 *
 * @package WooCouponUsage\API
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WCUsage_API_Manage_Controller' ) ) {
    /**
     * /keys and /webhooks endpoints.
     */
    class WCUsage_API_Manage_Controller extends WCUsage_API_Controller {
        /**
         * Register routes.
         */
        public function register_routes() {
            register_rest_route( $this->namespace, '/keys', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_keys'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => $this->get_collection_params(),
            ), array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'create_key'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'user_id'     => array(
                        'description' => __( 'WP user the key acts as. Defaults to the current user.', 'woo-coupon-usage' ),
                        'type'        => 'integer',
                    ),
                    'description' => array(
                        'description'       => __( 'Label for the key.', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'scopes'      => array(
                        'description' => __( 'Scopes: read, write, manage.', 'woo-coupon-usage' ),
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'string',
                            'enum' => array('read', 'write', 'manage'),
                        ),
                        'default'     => array('read'),
                    ),
                    'expires'     => array(
                        'description'       => __( 'Optional expiry date (Y-m-d).', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'default'           => '',
                        'validate_callback' => 'wcusage_api_validate_date_arg',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )) );
            register_rest_route( $this->namespace, '/keys/(?P<id>[\\d]+)', array(array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array($this, 'revoke_key'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'id' => array(
                        'description' => __( 'API key ID.', 'woo-coupon-usage' ),
                        'type'        => 'integer',
                        'required'    => true,
                    ),
                ),
            )) );
        }

        /**
         * Register the webhook management routes.
         *
         * PRO only - see register_routes(). Protected on purpose: the method
         * body survives into the free build (only the call to it is stripped),
         * and the routes it registers would answer with handlers whose backing
         * functions do not exist there. Nothing outside this class should be
         * able to turn them on.
         */
        protected function register_webhook_routes() {
            register_rest_route( $this->namespace, '/webhooks', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_webhooks'),
                'permission_callback' => array($this, 'permission_admin_manage'),
            ), array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'create_webhook'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'name'   => array(
                        'description'       => __( 'Label for the webhook.', 'woo-coupon-usage' ),
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'url'    => array(
                        'description' => __( 'HTTPS delivery URL.', 'woo-coupon-usage' ),
                        'type'        => 'string',
                        'format'      => 'uri',
                        'required'    => true,
                    ),
                    'events' => array(
                        'description' => __( 'Webhook event names, or ["*"] for all events.', 'woo-coupon-usage' ),
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'string',
                        ),
                        'required'    => true,
                    ),
                ),
            )) );
            register_rest_route( $this->namespace, '/webhooks/(?P<id>[\\d]+)', array(array(
                'methods'             => 'PATCH',
                'callback'            => array($this, 'update_webhook'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'id'     => array(
                        'type'     => 'integer',
                        'required' => true,
                    ),
                    'status' => array(
                        'description' => __( 'Set to active or disabled. Re-activating resets the failure counter.', 'woo-coupon-usage' ),
                        'type'        => 'string',
                        'enum'        => array('active', 'disabled'),
                    ),
                    'events' => array(
                        'description' => __( 'Replace the subscribed events.', 'woo-coupon-usage' ),
                        'type'        => 'array',
                        'items'       => array(
                            'type' => 'string',
                        ),
                    ),
                ),
            ), array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array($this, 'delete_webhook'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'id' => array(
                        'type'     => 'integer',
                        'required' => true,
                    ),
                ),
            )) );
            register_rest_route( $this->namespace, '/webhooks/(?P<id>[\\d]+)/test', array(array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'test_webhook'),
                'permission_callback' => array($this, 'permission_admin_manage'),
                'args'                => array(
                    'id' => array(
                        'type'     => 'integer',
                        'required' => true,
                    ),
                ),
            )) );
            register_rest_route( $this->namespace, '/webhooks/events', array(array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_webhook_events'),
                'permission_callback' => array($this, 'permission_admin_read'),
            )) );
        }

        /**
         * GET /keys
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response
         */
        public function get_keys( $request ) {
            list( $limit, $offset ) = $this->get_limit_offset( $request );
            $total = wcusage_api_count_keys();
            if ( $this->offset_is_out_of_range( $offset, $total ) ) {
                return $this->paginated_response( array(), $total, $request );
            }
            return $this->paginated_response( wcusage_api_get_keys( $limit, $offset ), $total, $request );
        }

        /**
         * POST /keys
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function create_key( $request ) {
            $user_id = absint( $request['user_id'] );
            if ( !$user_id ) {
                $user_id = get_current_user_id();
            }
            if ( !wcusage_api_can_create_key_for_user( $user_id ) ) {
                return wcusage_api_error( 'cannot_create_for_user', __( 'You do not have permission to create an API key for this user.', 'woo-coupon-usage' ), 403 );
            }
            $result = wcusage_api_create_key(
                $user_id,
                (string) $request['description'],
                (array) $request['scopes'],
                (string) $request['expires']
            );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $response = rest_ensure_response( array(
                'id'      => $result['id'],
                'token'   => $result['token'],
                'notice'  => __( 'Store this token now - it cannot be shown again.', 'woo-coupon-usage' ),
                'user_id' => $user_id,
            ) );
            $response->set_status( 201 );
            return $response;
        }

        /**
         * DELETE /keys/{id}
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function revoke_key( $request ) {
            $key_id = absint( $request['id'] );
            if ( !wcusage_api_get_key_row( $key_id ) ) {
                return wcusage_api_error( 'not_found', __( 'API key not found.', 'woo-coupon-usage' ), 404 );
            }
            if ( !wcusage_api_can_manage_key( $key_id ) ) {
                return wcusage_api_error( 'cannot_manage_key', __( 'You do not have permission to manage this API key.', 'woo-coupon-usage' ), 403 );
            }
            if ( !wcusage_api_revoke_key( $key_id ) ) {
                return wcusage_api_error( 'not_found', __( 'API key not found.', 'woo-coupon-usage' ), 404 );
            }
            return rest_ensure_response( array(
                'revoked' => true,
            ) );
        }

        /**
         * GET /webhooks
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response
         */
        public function get_webhooks( $request ) {
            $items = array();
            foreach ( wcusage_api_get_webhooks() as $webhook ) {
                $items[] = $this->prepare_webhook( $webhook );
            }
            return rest_ensure_response( $items );
        }

        /**
         * POST /webhooks
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function create_webhook( $request ) {
            $webhook = wcusage_api_add_webhook( (string) $request['name'], (string) $request['url'], (array) $request['events'] );
            if ( is_wp_error( $webhook ) ) {
                return $webhook;
            }
            // The secret is included on creation so the receiver can verify
            // signatures; it stays visible to managing admins.
            $response = rest_ensure_response( $this->prepare_webhook( $webhook, true ) );
            $response->set_status( 201 );
            return $response;
        }

        /**
         * PATCH /webhooks/{id}
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function update_webhook( $request ) {
            $fields = array();
            if ( null !== $request['status'] ) {
                $fields['status'] = (string) $request['status'];
                if ( 'active' === $fields['status'] ) {
                    $fields['failures'] = 0;
                    $fields['last_error'] = '';
                }
            }
            if ( null !== $request['events'] ) {
                $valid_names = array_merge( array('*'), array_keys( wcusage_api_webhook_event_names() ) );
                $events = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $request['events'] ), $valid_names ) );
                if ( empty( $events ) ) {
                    return wcusage_api_error( 'no_events', __( 'Select at least one valid event.', 'woo-coupon-usage' ), 400 );
                }
                $fields['events'] = $events;
            }
            if ( empty( $fields ) ) {
                return wcusage_api_error( 'no_fields', __( 'Nothing to update.', 'woo-coupon-usage' ), 400 );
            }
            $webhook = wcusage_api_update_webhook( absint( $request['id'] ), $fields );
            if ( !$webhook ) {
                return wcusage_api_error( 'not_found', __( 'Webhook not found.', 'woo-coupon-usage' ), 404 );
            }
            return rest_ensure_response( $this->prepare_webhook( $webhook ) );
        }

        /**
         * DELETE /webhooks/{id}
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function delete_webhook( $request ) {
            if ( !wcusage_api_delete_webhook( absint( $request['id'] ) ) ) {
                return wcusage_api_error( 'not_found', __( 'Webhook not found.', 'woo-coupon-usage' ), 404 );
            }
            return rest_ensure_response( array(
                'deleted' => true,
            ) );
        }

        /**
         * POST /webhooks/{id}/test
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response|WP_Error
         */
        public function test_webhook( $request ) {
            $result = wcusage_api_webhook_send_test( absint( $request['id'] ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        /**
         * GET /webhooks/events - the event catalog.
         *
         * @param WP_REST_Request $request Request.
         *
         * @return WP_REST_Response
         */
        public function get_webhook_events( $request ) {
            $events = array();
            foreach ( wcusage_api_webhook_event_names() as $name => $description ) {
                $events[] = array(
                    'event'       => $name,
                    'description' => $description,
                );
            }
            return rest_ensure_response( $events );
        }

        /**
         * Prepare a webhook for output.
         *
         * @param array $webhook        Stored endpoint.
         * @param bool  $include_secret Include the signing secret.
         *
         * @return array
         */
        protected function prepare_webhook( $webhook, $include_secret = false ) {
            $data = array(
                'id'            => (int) $webhook['id'],
                'name'          => $webhook['name'],
                'url'           => $webhook['url'],
                'events'        => $webhook['events'],
                'status'        => $webhook['status'],
                'failures'      => (int) $webhook['failures'],
                'last_delivery' => wcusage_api_format_date( (string) $webhook['last_delivery'] ),
                'last_error'    => $webhook['last_error'],
                'date_created'  => wcusage_api_format_date( (string) $webhook['date_created'] ),
            );
            if ( $include_secret ) {
                $data['secret'] = $webhook['secret'];
            }
            return $data;
        }

    }

}