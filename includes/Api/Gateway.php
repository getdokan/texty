<?php
/**
 * Gateway Lifecycle REST Controller.
 *
 * Splits the "save credentials" step (handled by Api\SettingsController) from
 * the activation lifecycle so the UI can offer Activate / Deactivate /
 * Disconnect actions independent of credential edits.
 *
 * Routes:
 *   POST /texty/v1/gateway/activate     { gateway: 'twilio' }
 *   POST /texty/v1/gateway/deactivate
 *   POST /texty/v1/gateway/disconnect   { gateway: 'twilio' }
 *
 * Storage: all three mutate the existing `texty_settings` option in place.
 *
 * @package Texty\Api
 * @since   2.0.0
 */

namespace Texty\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Gateway Class
 */
class Gateway extends Base {

	/**
	 * Single backing option (mirrors Api\SettingsController::OPTION_KEY).
	 */
	const OPTION_KEY = 'texty_settings';

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->namespace = 'texty/v1';
		$this->rest_base = 'gateway';
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/activate',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'activate' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'gateway' => [
							'description' => __( 'Gateway key to activate.', 'texty' ),
							'type'        => 'string',
							'required'    => true,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deactivate',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'deactivate' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/disconnect',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'disconnect' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => [
						'gateway' => [
							'description' => __( 'Gateway key to disconnect (clears credentials).', 'texty' ),
							'type'        => 'string',
							'required'    => true,
						],
					],
				],
			]
		);
	}

	/**
	 * Activate a gateway. Sets `texty_settings.gateway` to the given key.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 2.0.0
	 */
	public function activate( $request ) {
		$gateway_key = sanitize_key( (string) $request->get_param( 'gateway' ) );

		if ( '' === $gateway_key ) {
			return new WP_Error(
				'texty_invalid_gateway',
				__( 'A gateway key is required.', 'texty' ),
				[ 'status' => 400 ]
			);
		}

		$registered = texty()->gateways()->all();
		if ( ! isset( $registered[ $gateway_key ] ) ) {
			return new WP_Error(
				'texty_unknown_gateway',
				__( 'Unknown gateway.', 'texty' ),
				[ 'status' => 400 ]
			);
		}

		$stored = $this->load_stored();

		// Gateways that declare no settings (e.g. Fake) need no credentials.
		$gateway_class = $registered[ $gateway_key ];
		$gateway_obj   = is_object( $gateway_class ) ? $gateway_class : new $gateway_class();
		$needs_creds   = method_exists( $gateway_obj, 'get_settings' )
			? ! empty( $gateway_obj->get_settings() )
			: true;

		if ( $needs_creds && ( empty( $stored[ $gateway_key ] ) || ! is_array( $stored[ $gateway_key ] ) ) ) {
			return new WP_Error(
				'texty_no_credentials',
				__( 'Cannot activate a gateway that has no saved credentials. Save credentials first.', 'texty' ),
				[ 'status' => 400 ]
			);
		}

		$stored['gateway'] = $gateway_key;
		update_option( self::OPTION_KEY, $stored );

		/**
		 * Fires after a gateway is activated.
		 *
		 * @param string $gateway_key The gateway that was activated.
		 */
		do_action( 'texty_gateway_activated', $gateway_key );

		return rest_ensure_response(
			[
				'success'        => true,
				'active_gateway' => $gateway_key,
			]
		);
	}

	/**
	 * Deactivate the currently active gateway. Clears `texty_settings.gateway`.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 * @since 2.0.0
	 */
	public function deactivate( $request ) {
		$stored                 = $this->load_stored();
		$previous               = isset( $stored['gateway'] ) ? (string) $stored['gateway'] : '';
		$stored['gateway']      = '';

		update_option( self::OPTION_KEY, $stored );

		/**
		 * Fires after a gateway is deactivated.
		 *
		 * @param string $previous The gateway that was previously active.
		 */
		do_action( 'texty_gateway_deactivated', $previous );

		return rest_ensure_response(
			[
				'success'        => true,
				'active_gateway' => '',
			]
		);
	}

	/**
	 * Disconnect a gateway — clears its saved credentials. If it was the active
	 * gateway, also deactivates it.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 2.0.0
	 */
	public function disconnect( $request ) {
		$gateway_key = sanitize_key( (string) $request->get_param( 'gateway' ) );

		if ( '' === $gateway_key ) {
			return new WP_Error(
				'texty_invalid_gateway',
				__( 'A gateway key is required.', 'texty' ),
				[ 'status' => 400 ]
			);
		}

		$stored = $this->load_stored();

		unset( $stored[ $gateway_key ] );

		if ( isset( $stored['gateway'] ) && $stored['gateway'] === $gateway_key ) {
			$stored['gateway'] = '';
		}

		update_option( self::OPTION_KEY, $stored );

		/**
		 * Fires after a gateway's credentials are cleared.
		 *
		 * @param string $gateway_key The gateway that was disconnected.
		 */
		do_action( 'texty_gateway_disconnected', $gateway_key );

		return rest_ensure_response(
			[
				'success'        => true,
				'active_gateway' => isset( $stored['gateway'] ) ? (string) $stored['gateway'] : '',
				'disconnected'   => $gateway_key,
			]
		);
	}

	/**
	 * Read the backing option safely.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	private function load_stored(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}
}
