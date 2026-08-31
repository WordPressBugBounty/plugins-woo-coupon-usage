<?php
/**
 * Coupon Affiliates REST API v2 - OpenAPI 3.1 document.
 *
 * Generates the spec dynamically from the registered v2 routes and their
 * args, so it never drifts from the implementation. Intended for API
 * clients, custom GPTs and AI agents that consume OpenAPI.
 *
 * @package WooCouponUsage\API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCUsage_API_OpenAPI_Controller' ) ) {

	/**
	 * /openapi endpoint.
	 */
	class WCUsage_API_OpenAPI_Controller extends WCUsage_API_Controller {

		/**
		 * Register routes.
		 */
		public function register_routes() {

			register_rest_route(
				$this->namespace,
				'/openapi',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_spec' ),
						'permission_callback' => array( $this, 'permission_spec' ),
					),
				)
			);
		}

		/**
		 * Permission: public by default (routes are already enumerable via
		 * the REST index), restrictable via filter.
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return true|WP_Error
		 */
		public function permission_spec( $request ) {

			/**
			 * Filter whether the OpenAPI document is publicly readable.
			 *
			 * @param bool $public Default true.
			 */
			if ( apply_filters( 'wcusage_api_openapi_public', true ) ) {
				return true;
			}

			return wcusage_api_is_admin_user() ? true : wcusage_api_auth_required_error();
		}

		/**
		 * GET /openapi
		 *
		 * @param WP_REST_Request $request Request.
		 *
		 * @return WP_REST_Response
		 */
		public function get_spec( $request ) {

			$server = rest_get_server();
			$routes = $server->get_routes();

			$paths = array();

			foreach ( $routes as $route => $handlers ) {

				if ( 0 !== strpos( $route, '/wcusage/v2' ) ) {
					continue;
				}

				// /wcusage/v2/coupons/(?P<id>[\d]+) -> /coupons/{id}.
				$path = preg_replace( '/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', $route );
				$path = substr( $path, strlen( '/wcusage/v2' ) );
				if ( '' === $path ) {
					$path = '/';
				}

				preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $path, $path_param_matches );
				$path_params = $path_param_matches[1];

				foreach ( $handlers as $handler ) {

					if ( empty( $handler['methods'] ) || ! is_array( $handler['methods'] ) ) {
						continue;
					}

					foreach ( array_keys( $handler['methods'] ) as $method ) {

						$method_lower = strtolower( $method );
						if ( ! in_array( $method_lower, array( 'get', 'post', 'put', 'patch', 'delete' ), true ) ) {
							continue;
						}

						$operation = array(
							'operationId' => $method_lower . str_replace( array( '/', '{', '}' ), array( '_', '', '' ), $path ),
							'parameters'  => array(),
							'security'    => array(
								array( 'bearerApiKey' => array() ),
								array( 'basicAppPassword' => array() ),
							),
							'responses'   => array(
								'200' => array( 'description' => 'Success.' ),
								'401' => array( 'description' => 'Authentication required or invalid.' ),
								'403' => array( 'description' => 'Insufficient permissions or scope.' ),
							),
						);

						$body_properties = array();
						$body_required   = array();

						$args = isset( $handler['args'] ) && is_array( $handler['args'] ) ? $handler['args'] : array();

						foreach ( $args as $arg_name => $arg ) {

							$schema = $this->arg_to_schema( $arg );

							if ( in_array( $arg_name, $path_params, true ) ) {
								$operation['parameters'][] = array(
									'name'        => $arg_name,
									'in'          => 'path',
									'required'    => true,
									'description' => isset( $arg['description'] ) ? $arg['description'] : '',
									'schema'      => $schema,
								);
								continue;
							}

							if ( 'get' === $method_lower || 'delete' === $method_lower ) {
								$operation['parameters'][] = array(
									'name'        => $arg_name,
									'in'          => 'query',
									'required'    => ! empty( $arg['required'] ),
									'description' => isset( $arg['description'] ) ? $arg['description'] : '',
									'schema'      => $schema,
								);
							} else {
								if ( isset( $arg['description'] ) ) {
									$schema['description'] = $arg['description'];
								}
								$body_properties[ $arg_name ] = $schema;
								if ( ! empty( $arg['required'] ) ) {
									$body_required[] = $arg_name;
								}
							}
						}

						if ( $body_properties ) {
							$body_schema = array(
								'type'       => 'object',
								'properties' => $body_properties,
							);
							if ( $body_required ) {
								$body_schema['required'] = $body_required;
							}
							$operation['requestBody'] = array(
								'content' => array(
									'application/json' => array( 'schema' => $body_schema ),
								),
							);
						}

						if ( ! isset( $paths[ $path ] ) ) {
							$paths[ $path ] = array();
						}
						$paths[ $path ][ $method_lower ] = $operation;
					}
				}
			}

			$spec = array(
				'openapi'    => '3.1.0',
				'info'       => array(
					'title'       => 'Coupon Affiliates REST API',
					'description' => 'Affiliate data for WooCommerce: affiliates, coupons, referred orders, commission, payouts, registrations, clicks, events and reports. Authenticate with a WordPress application password (HTTP Basic) or a plugin API key (Bearer wcus_...).',
					'version'     => defined( 'WCUSAGE_VERSION' ) ? WCUSAGE_VERSION : '1.0.0',
				),
				'servers'    => array(
					array( 'url' => untrailingslashit( rest_url( 'wcusage/v2' ) ) ),
				),
				'paths'      => $paths,
				'components' => array(
					'securitySchemes' => array(
						'bearerApiKey'     => array(
							'type'        => 'http',
							'scheme'      => 'bearer',
							'description' => 'Plugin API key (wcus_...). Created under Coupon Affiliates > Admin Tools > API.',
						),
						'basicAppPassword' => array(
							'type'        => 'http',
							'scheme'      => 'basic',
							'description' => 'WordPress application password.',
						),
					),
				),
			);

			/**
			 * Filter the generated OpenAPI document.
			 *
			 * @param array $spec OpenAPI document.
			 */
			$spec = apply_filters( 'wcusage_api_openapi_spec', $spec );

			return rest_ensure_response( $spec );
		}

		/**
		 * Convert a REST arg definition to an OpenAPI schema.
		 *
		 * @param array $arg Arg definition.
		 *
		 * @return array
		 */
		protected function arg_to_schema( $arg ) {

			$schema = array();

			$type = isset( $arg['type'] ) ? $arg['type'] : 'string';
			if ( is_array( $type ) ) {
				$type = reset( $type );
			}
			$schema['type'] = in_array( $type, array( 'integer', 'number', 'boolean', 'array', 'object', 'string' ), true ) ? $type : 'string';

			if ( 'array' === $schema['type'] ) {
				$schema['items'] = isset( $arg['items']['type'] ) ? array( 'type' => $arg['items']['type'] ) : array( 'type' => 'string' );
			}

			foreach ( array( 'enum', 'default', 'minimum', 'maximum', 'format' ) as $copy ) {
				if ( isset( $arg[ $copy ] ) ) {
					$schema[ $copy ] = $arg[ $copy ];
				}
			}

			return $schema;
		}
	}
}
