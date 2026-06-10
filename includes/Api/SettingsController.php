<?php
/**
 * Settings REST Controller.
 *
 * Serves the Texty gateway settings via a schema-driven REST API compatible
 * with the plugin-ui <Settings> component. Every registered gateway becomes
 * its own page in the schema; saving a page activates that gateway and writes
 * its credentials into the existing `texty_settings` option (BC-preserved).
 *
 * Storage shape (unchanged):
 *   texty_settings = [
 *     'gateway' => 'twilio',          // active gateway key (root)
 *     'twilio'  => [ 'sid' => ..., 'token' => ..., 'from' => ... ],
 *     'vonage'  => [ 'key' => ..., 'secret' => ..., 'from' => ... ],
 *     ...
 *   ]
 *
 * Routes:
 *   GET  /texty/v1/settings/schema  — schema + values + active_gateway
 *   POST /texty/v1/settings/schema  — save one gateway scope (also activates it)
 *
 * @package Texty\Api
 * @since   TEXTY_VERSION
 */

namespace Texty\Api;

use WeDevs\WPKit\Settings\BaseSettingsRESTController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SettingsController Class
 */
class SettingsController extends BaseSettingsRESTController {

	/**
	 * Single backing option that stores all gateway credentials + the active key.
	 */
	const OPTION_KEY = 'texty_settings';

	/**
	 * Constructor.
	 *
	 * @since TEXTY_VERSION
	 */
	public function __construct() {
		parent::__construct( 'texty/v1', 'settings/schema', 'texty' );
	}

	/**
	 * Build the page-per-gateway schema.
	 *
	 * Each gateway's page, section, and fields are declared explicitly here —
	 * not generated from `texty()->gateways()->all()`. Easier to read, modify,
	 * and override per-gateway than a generic `foreach`. Third-party gateways
	 * register their own pages via the `texty_settings_schema` filter.
	 *
	 * Defaults for each field are pulled from the saved `texty_settings` option
	 * so the frontend's SettingsProvider can extract initial values.
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	protected function get_settings_schema(): array {
		$stored = $this->load_stored_settings();

		$schema = array_merge(
			$this->build_twilio_schema( $this->credentials_for( $stored, 'twilio' ) ),
			$this->build_vonage_schema( $this->credentials_for( $stored, 'vonage' ) ),
			$this->build_plivo_schema( $this->credentials_for( $stored, 'plivo' ) ),
			$this->build_clickatell_schema( $this->credentials_for( $stored, 'clickatell' ) )
		);

		// Fake gateway is dev-only — only registered when WP_DEBUG is true,
		// matching the gating in Texty\Gateways::all().
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$schema = array_merge( $schema, $this->build_fake_schema() );
		}

		/**
		 * Filter the gateway settings schema. Third-party gateways added via
		 * `texty_register_gateways` should append their own page + section + field
		 * elements through this filter (the static declarations above only cover
		 * the gateways that ship with Texty).
		 *
		 * @param array[] $schema Flat array of settings elements.
		 */
		return apply_filters( 'texty_settings_schema', $schema );
	}

	/**
	 * Storage path override.
	 *
	 * Each credential field stores at texty_settings[<gateway_key>][<cred_key>].
	 * Schema field ids carry a `<gateway>_` prefix to stay globally unique for
	 * the frontend; strip it here so the stored option keeps the legacy shape
	 * (e.g. field `twilio_sid` → texty_settings['twilio']['sid']).
	 *
	 * @param array $element Field element.
	 *
	 * @return string[]
	 * @since TEXTY_VERSION
	 */
	protected function get_field_path( array $element ): array {
		if ( ! empty( $element['page_id'] ) ) {
			$page_id  = $element['page_id'];
			$field_id = $element['id'];
			$prefix   = $page_id . '_';

			if ( 0 === strpos( $field_id, $prefix ) ) {
				$field_id = substr( $field_id, strlen( $prefix ) );
			}

			return [ $page_id, $field_id ];
		}
		return parent::get_field_path( $element );
	}

	/**
	 * Load values for the schema from a single backing option (`texty_settings`).
	 *
	 * BaseSettingsRESTController defaults to one option per page; we override to
	 * keep all gateway credentials in one option (BC).
	 *
	 * @param array $schema Settings schema elements.
	 *
	 * @return array<string, array>
	 * @since TEXTY_VERSION
	 */
	protected function load_values( array $schema ): array {
		$stored = $this->load_stored_settings();
		$values = [];

		foreach ( $schema as $element ) {
			if ( 'page' !== $element['type'] ) {
				continue;
			}
			$values[ $element['id'] ] = isset( $stored[ $element['id'] ] ) && is_array( $stored[ $element['id'] ] )
				? $stored[ $element['id'] ]
				: [];
		}

		return $values;
	}

	/**
	 * GET handler — wraps the parent response with two extra fields:
	 *   - active_gateway     : the gateway currently set as active for sending
	 *   - connected_gateways : keys of all gateways with saved (non-empty) credentials
	 *
	 * The two are independent — a gateway can be "connected" (has credentials)
	 * without being "active" (chosen for sending).
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 * @since TEXTY_VERSION
	 */
	public function get_items( $request ) {
		$response = parent::get_items( $request );
		$data     = $response->get_data();
		$stored   = $this->load_stored_settings();

		$active                 = texty()->settings()->gateway();
		$data['active_gateway'] = $active ? (string) $active : '';

		$schema     = isset( $data['schema'] ) && is_array( $data['schema'] ) ? $data['schema'] : [];
		$connected  = [];
		$page_ids   = [];
		foreach ( $schema as $element ) {
			if ( 'page' === $element['type'] ) {
				$page_ids[] = $element['id'];
			}
		}

		foreach ( $page_ids as $page_id ) {
			$creds = isset( $stored[ $page_id ] ) && is_array( $stored[ $page_id ] ) ? $stored[ $page_id ] : [];
			foreach ( $creds as $value ) {
				if ( '' !== (string) $value ) {
					$connected[] = $page_id;
					break;
				}
			}
		}

		$data['connected_gateways'] = $connected;

		$response->set_data( $data );
		return $response;
	}

	/**
	 * POST handler — saves credentials for one gateway page.
	 *
	 * Saving does NOT activate the gateway. Activation is a separate action
	 * (POST /texty/v1/gateway/activate) so the UI can offer a Connect → Activate
	 * two-step flow. The active gateway field is left untouched.
	 *
	 * Steps:
	 * 1. Convert flat dot-keyed values from the frontend (keyed by dependency_key)
	 *    into a nested structure keyed by storage path.
	 * 2. Validate the credentials via the gateway's own validate() method.
	 * 3. Merge into the existing `texty_settings` option (preserving root gateway).
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 * @since TEXTY_VERSION
	 */
	public function create_item( $request ) {
		$scope_id = sanitize_key( (string) ( $request->get_param( 'scopeId' ) ?? '' ) );
		$values   = $request->get_param( 'values' );

		if ( ! is_array( $values ) || empty( $scope_id ) ) {
			return new WP_REST_Response(
				[ 'errors' => [ 'values' => __( 'Invalid values format.', 'texty' ) ] ],
				400
			);
		}

		$schema   = $this->get_settings_schema();
		$page_ids = $this->get_page_ids( $schema );

		if ( ! in_array( $scope_id, $page_ids, true ) ) {
			return new WP_REST_Response(
				[ 'errors' => [ 'scopeId' => __( 'Invalid scope ID.', 'texty' ) ] ],
				400
			);
		}

		// Convert flat values (keyed by field id, e.g. twilio_sid) → nested ([gw][cred]).
		$fields = $this->get_fields_for_page( $schema, $scope_id );
		$nested = [];
		foreach ( $fields as $field ) {
			$dep_key = $this->build_dependency_key( $field );
			if ( array_key_exists( $dep_key, $values ) ) {
				$path = $this->get_field_path( $field );
				$this->set_nested_value( $nested, $path, $values[ $dep_key ] );
			}
		}

		$creds = isset( $nested[ $scope_id ] ) && is_array( $nested[ $scope_id ] ) ? $nested[ $scope_id ] : [];

		// Validate credentials via the gateway's own validate() method.
		$validation = $this->validate_gateway_credentials( $scope_id, $creds );
		if ( is_wp_error( $validation ) ) {
			return new WP_REST_Response(
				[
					'errors' => [
						'gateway' => $validation->get_error_message(),
					],
				],
				400
			);
		}

		// Merge credentials into existing settings — preserve the active gateway.
		$existing = $this->load_stored_settings();
		$merged   = $this->array_merge_deep( $existing, $nested );

		update_option( self::OPTION_KEY, $merged );

		/**
		 * Fires after Texty gateway credentials have been saved.
		 *
		 * @param array  $merged   Final merged settings persisted to the option.
		 * @param array  $nested   Nested values for this scope only.
		 * @param string $scope_id The gateway key that was saved.
		 */
		do_action( 'texty_settings_after_save', $merged, $nested, $scope_id );

		// Build the per-page values response.
		$values_response = [];
		foreach ( $schema as $element ) {
			if ( 'page' !== $element['type'] ) {
				continue;
			}
			$values_response[ $element['id'] ] = isset( $merged[ $element['id'] ] ) && is_array( $merged[ $element['id'] ] )
				? $merged[ $element['id'] ]
				: [];
		}

		return new WP_REST_Response(
			[
				'success'        => true,
				'values'         => $values_response,
				'active_gateway' => isset( $merged['gateway'] ) ? (string) $merged['gateway'] : '',
				'connected'      => $scope_id,
			]
		);
	}

	/**
	 * Validate gateway credentials by delegating to the gateway's validate().
	 *
	 * @param string $gateway_key Gateway registry key.
	 * @param array  $creds       Submitted credentials for that gateway.
	 *
	 * @return WP_Error|true
	 * @since TEXTY_VERSION
	 */
	private function validate_gateway_credentials( string $gateway_key, array $creds ) {
		$registered = texty()->gateways()->all();
		if ( ! isset( $registered[ $gateway_key ] ) ) {
			return true;
		}

		$gateway_class = $registered[ $gateway_key ];
		$gateway       = new $gateway_class();

		$mock_request = new WP_REST_Request( 'POST' );
		$mock_request->set_param( $gateway_key, $creds );

		$result = $gateway->validate( $mock_request );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * The key the frontend uses for a field's value.
	 *
	 * plugin-ui's SettingsProvider keys its flat values map by the raw field
	 * element `id` (see `collectKeys` in settings-context), so field ids must
	 * be globally unique — hence the `<gateway>_<field>` naming in the schema.
	 *
	 * @param array $field Field element.
	 *
	 * @return string
	 * @since TEXTY_VERSION
	 */
	private function build_dependency_key( array $field ): string {
		return $field['id'];
	}

	/**
	 * Read the backing option safely.
	 *
	 * @return array
	 * @since TEXTY_VERSION
	 */
	private function load_stored_settings(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Pluck a specific gateway's saved credentials from the option.
	 *
	 * @param array  $stored      Full texty_settings option.
	 * @param string $gateway_key Gateway registry key.
	 *
	 * @return array
	 * @since TEXTY_VERSION
	 */
	private function credentials_for( array $stored, string $gateway_key ): array {
		return isset( $stored[ $gateway_key ] ) && is_array( $stored[ $gateway_key ] )
			? $stored[ $gateway_key ]
			: [];
	}

	/**
	 * Build a `validations` array marking a field as required.
	 *
	 * @param string $label Human-readable field label, used in the message.
	 *
	 * @return array
	 * @since TEXTY_VERSION
	 */
	private function required_validation( string $label ): array {
		return [
			[
				'rules'   => 'required',
				/* translators: %s: field label, e.g. "Account SID" */
				'message' => sprintf( __( '%s is required.', 'texty' ), $label ),
			],
		];
	}

	/**
	 * Twilio gateway page + credentials section + fields.
	 *
	 * @param array $creds Saved credentials for this gateway.
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	private function build_twilio_schema( array $creds ): array {
		return [
			[
				'type'          => 'page',
				'id'            => 'twilio',
				'label'         => __( 'Twilio', 'texty' ),
				'description'   => sprintf(
					/* translators: 1: URL to Twilio account settings, 2: URL to Texty wiki for Twilio setup */
					__(
						'Send SMS with Twilio. Follow <a href="%1$s" target="_blank" rel="noopener noreferrer">this link</a> to get the Account SID and Token from Twilio. Follow <a href="%2$s" target="_blank" rel="noopener noreferrer">these instructions</a> to configure the gateway.',
						'texty'
					),
					'https://www.twilio.com/console/project/settings',
					'https://github.com/weDevsOfficial/texty/wiki/Twilio'
				),
				'image_url'     => TEXTY_URL . '/assets/images/twilio-logo.png',
				'doc_link'      => 'https://www.twilio.com/try-twilio',
				'doc_link_text' => __( 'Get your account', 'texty' ),
				'priority'      => 10,
			],
			[
				'type'     => 'section',
				'id'       => 'twilio_credentials',
				'page_id'  => 'twilio',
				'priority' => 10,
			],
			[
				'type'        => 'field',
				'id'          => 'twilio_sid',
				'page_id'     => 'twilio',
				'section_id'  => 'twilio_credentials',
				'variant'     => 'text',
				'label'       => __( 'Account SID', 'texty' ),
				'placeholder' => __( 'Enter Account SID', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 10,
				'default'     => isset( $creds['sid'] ) ? $creds['sid'] : '',
				'validations' => $this->required_validation( __( 'Account SID', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'twilio_token',
				'page_id'     => 'twilio',
				'section_id'  => 'twilio_credentials',
				'variant'     => 'show_hide',
				'label'       => __( 'Auth Token', 'texty' ),
				'placeholder' => __( 'Enter Auth Token', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 20,
				'default'     => isset( $creds['token'] ) ? $creds['token'] : '',
				'validations' => $this->required_validation( __( 'Auth Token', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'twilio_from',
				'page_id'     => 'twilio',
				'section_id'  => 'twilio_credentials',
				'variant'     => 'phone',
				'label'       => __( 'From Number', 'texty' ),
				'description' => __( 'Must be a valid number associated with your Twilio account', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 30,
				'default'     => isset( $creds['from'] ) ? $creds['from'] : '',
				'validations' => $this->required_validation( __( 'From Number', 'texty' ) ),
			],
		];
	}

	/**
	 * Vonage (formerly Nexmo) gateway page + credentials section + fields.
	 *
	 * @param array $creds Saved credentials for this gateway.
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	private function build_vonage_schema( array $creds ): array {
		return [
			[
				'type'          => 'page',
				'id'            => 'vonage',
				'label'         => __( 'Vonage', 'texty' ),
				'description'   => sprintf(
					/* translators: 1: URL to Vonage dashboard settings, 2: URL to Texty wiki for Vonage setup */
					__(
						'Send SMS with Vonage (formerly Nexmo). Follow <a href="%1$s" target="_blank" rel="noopener noreferrer">this link</a> to get the API Key and Secret from Vonage. Follow <a href="%2$s" target="_blank" rel="noopener noreferrer">these instructions</a> to configure the gateway.',
						'texty'
					),
					'https://dashboard.nexmo.com/settings',
					'https://github.com/weDevsOfficial/texty/wiki/Vonage'
				),
				'image_url'     => TEXTY_URL . '/assets/images/vonage.svg',
				'doc_link'      => 'https://dashboard.nexmo.com/sign-up',
				'doc_link_text' => __( 'Get your account', 'texty' ),
				'priority'      => 20,
			],
			[
				'type'     => 'section',
				'id'       => 'vonage_credentials',
				'page_id'  => 'vonage',
				'priority' => 10,
			],
			[
				'type'        => 'field',
				'id'          => 'vonage_key',
				'page_id'     => 'vonage',
				'section_id'  => 'vonage_credentials',
				'variant'     => 'text',
				'label'       => __( 'API Key', 'texty' ),
				'placeholder' => __( 'Enter API Key', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 10,
				'default'     => isset( $creds['key'] ) ? $creds['key'] : '',
				'validations' => $this->required_validation( __( 'API Key', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'vonage_secret',
				'page_id'     => 'vonage',
				'section_id'  => 'vonage_credentials',
				'variant'     => 'show_hide',
				'label'       => __( 'API Secret', 'texty' ),
				'placeholder' => __( 'Enter API Secret', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 20,
				'default'     => isset( $creds['secret'] ) ? $creds['secret'] : '',
				'validations' => $this->required_validation( __( 'API Secret', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'vonage_from',
				'page_id'     => 'vonage',
				'section_id'  => 'vonage_credentials',
				'variant'     => 'phone',
				'label'       => __( 'From Number', 'texty' ),
				'description' => __( 'Must be a valid number associated with your Vonage account', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 30,
				'default'     => isset( $creds['from'] ) ? $creds['from'] : '',
				'validations' => $this->required_validation( __( 'From Number', 'texty' ) ),
			],
		];
	}

	/**
	 * Plivo gateway page + credentials section + fields.
	 *
	 * @param array $creds Saved credentials for this gateway.
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	private function build_plivo_schema( array $creds ): array {
		return [
			[
				'type'          => 'page',
				'id'            => 'plivo',
				'label'         => __( 'Plivo', 'texty' ),
				'description'   => sprintf(
					/* translators: 1: URL to Plivo console reporting, 2: URL to Texty wiki for Plivo setup */
					__(
						'Send SMS with Plivo. Follow <a href="%1$s" target="_blank" rel="noopener noreferrer">this link</a> to get the Auth ID and Token from Plivo. Follow <a href="%2$s" target="_blank" rel="noopener noreferrer">these instructions</a> to configure the gateway.',
						'texty'
					),
					'https://console.plivo.com/sms/reporting/',
					'https://github.com/weDevsOfficial/texty/wiki/Plivo'
				),
				'image_url'     => TEXTY_URL . '/assets/images/plivo.svg',
				'doc_link'      => 'https://console.plivo.com/accounts/register/',
				'doc_link_text' => __( 'Get your account', 'texty' ),
				'priority'      => 30,
			],
			[
				'type'     => 'section',
				'id'       => 'plivo_credentials',
				'page_id'  => 'plivo',
				'priority' => 10,
			],
			[
				'type'        => 'field',
				'id'          => 'plivo_auth_id',
				'page_id'     => 'plivo',
				'section_id'  => 'plivo_credentials',
				'variant'     => 'text',
				'label'       => __( 'Auth ID', 'texty' ),
				'placeholder' => __( 'Enter Auth ID', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 10,
				'default'     => isset( $creds['auth_id'] ) ? $creds['auth_id'] : '',
				'validations' => $this->required_validation( __( 'Auth ID', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'plivo_token',
				'page_id'     => 'plivo',
				'section_id'  => 'plivo_credentials',
				'variant'     => 'show_hide',
				'label'       => __( 'Auth Token', 'texty' ),
				'placeholder' => __( 'Enter Auth Token', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 20,
				'default'     => isset( $creds['token'] ) ? $creds['token'] : '',
				'validations' => $this->required_validation( __( 'Auth Token', 'texty' ) ),
			],
			[
				'type'        => 'field',
				'id'          => 'plivo_from',
				'page_id'     => 'plivo',
				'section_id'  => 'plivo_credentials',
				'variant'     => 'phone',
				'label'       => __( 'From Number', 'texty' ),
				'description' => __( 'Must be a valid number associated with your Plivo account', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 30,
				'default'     => isset( $creds['from'] ) ? $creds['from'] : '',
				'validations' => $this->required_validation( __( 'From Number', 'texty' ) ),
			],
		];
	}

	/**
	 * Clickatell gateway page + credentials section + fields.
	 *
	 * @param array $creds Saved credentials for this gateway.
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	private function build_clickatell_schema( array $creds ): array {
		return [
			[
				'type'          => 'page',
				'id'            => 'clickatell',
				'label'         => __( 'Clickatell', 'texty' ),
				'description'   => sprintf(
					/* translators: 1: URL to Clickatell workspace, 2: URL to Texty wiki for Clickatell setup */
					__(
						'Send SMS with Clickatell. Follow <a href="%1$s" target="_blank" rel="noopener noreferrer">this link</a> to get the API. Follow <a href="%2$s" target="_blank" rel="noopener noreferrer">these instructions</a> to configure the gateway.',
						'texty'
					),
					'https://app.clickatell.com/my-workspace',
					'https://github.com/weDevsOfficial/texty/wiki/Clickatell'
				),
				'image_url'     => TEXTY_URL . '/assets/images/clickatell.svg',
				'doc_link'      => 'https://www.clickatell.com/sign-up/',
				'doc_link_text' => __( 'Get your account', 'texty' ),
				'priority'      => 40,
			],
			[
				'type'     => 'section',
				'id'       => 'clickatell_credentials',
				'page_id'  => 'clickatell',
				'priority' => 10,
			],
			[
				'type'        => 'field',
				'id'          => 'clickatell_key',
				'page_id'     => 'clickatell',
				'section_id'  => 'clickatell_credentials',
				'variant'     => 'show_hide',
				'label'       => __( 'API Key', 'texty' ),
				'placeholder' => __( 'Enter API Key', 'texty' ),
				'layout'      => 'full-width',
				'priority'    => 10,
				'default'     => isset( $creds['key'] ) ? $creds['key'] : '',
				'validations' => $this->required_validation( __( 'API Key', 'texty' ) ),
			],
		];
	}

	/**
	 * Fake gateway page (no fields). Only included when WP_DEBUG is enabled,
	 * matching the gating in Texty\Gateways::all().
	 *
	 * @return array[]
	 * @since TEXTY_VERSION
	 */
	private function build_fake_schema(): array {
		return [
			[
				'type'        => 'page',
				'id'          => 'fake',
				'label'       => __( 'Fake Gateway', 'texty' ),
				'description' => __( 'This is a fake gateway that logs the messages to debug.log file without sending the actual SMS.', 'texty' ),
				'image_url'   => TEXTY_URL . '/assets/images/logo.svg',
				'priority'    => 99,
			],
			[
				'type'     => 'section',
				'id'       => 'fake_credentials',
				'page_id'  => 'fake',
				'priority' => 10,
			],
		];
	}

	/**
	 * Translatable validation messages for built-in field types.
	 *
	 * @return array<string, string>
	 * @since TEXTY_VERSION
	 */
	protected function get_validation_messages(): array {
		return [
			'number'          => __( 'Must be a numeric value.', 'texty' ),
			'switch'          => __( 'Must be "on" or "off".', 'texty' ),
			'invalid_option'  => __( 'Invalid option selected.', 'texty' ),
			'must_be_array'   => __( 'Must be an array.', 'texty' ),
			'invalid_options' => __( 'Contains invalid options.', 'texty' ),
			'color_picker'    => __( 'Must be a valid hex color (e.g. #ff0000).', 'texty' ),
			'must_be_object'  => __( 'Must be an object.', 'texty' ),
		];
	}

	/**
	 * Permission-error message for read/write contexts.
	 *
	 * @param string $context 'read' or 'write'.
	 *
	 * @return string
	 * @since TEXTY_VERSION
	 */
	protected function get_permission_error_message( string $context ): string {
		return 'write' === $context
			? __( 'You do not have permission to update settings.', 'texty' )
			: __( 'You do not have permission to view settings.', 'texty' );
	}
}
