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
 * @since   2.0.0
 */

namespace Texty\Api;

use Texty\Gateways\GatewayInterface;
use Texty\Dependencies\WeDevs\WPKit\Settings\BaseSettingsRESTController;
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
	 * @since 2.0.0
	 */
	public function __construct() {
		parent::__construct( 'texty/v1', 'settings/schema', 'texty' );
	}

	/**
	 * Build the page-per-gateway schema from the gateway registry.
	 *
	 * Every gateway returned by texty()->gateways()->all() — including third
	 * parties registered via `texty_register_gateways` — becomes a page whose
	 * label, description, logo and credential fields are pulled from the
	 * gateway class itself (name(), description(), logo(), get_settings()).
	 * get_settings() also supplies each field's saved value, so the frontend's
	 * SettingsProvider can extract initial values from the field defaults.
	 *
	 * @return array[]
	 * @since 2.0.0
	 */
	protected function get_settings_schema(): array {
		$schema   = [];
		$priority = 10;

		foreach ( texty()->gateways()->all() as $key => $classname ) {
			$gateway = is_object( $classname ) ? $classname : new $classname();

			if ( ! $gateway instanceof GatewayInterface ) {
				continue;
			}

			$schema    = array_merge( $schema, $this->build_gateway_schema( (string) $key, $gateway, $priority ) );
			$priority += 10;
		}

		/**
		 * Filter the gateway settings schema. Gateways registered via
		 * `texty_register_gateways` are included automatically; use this filter
		 * to adjust generated elements or add extra ones.
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * The plugin-ui SettingsProvider keys its flat values map by the raw field
	 * element `id` (see `collectKeys` in settings-context), so field ids must
	 * be globally unique — hence the `<gateway>_<field>` naming in the schema.
	 *
	 * @param array $field Field element.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private function build_dependency_key( array $field ): string {
		return $field['id'];
	}

	/**
	 * Read the backing option safely.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	private function load_stored_settings(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Build a `validations` array marking a field as required.
	 *
	 * @param string $label Human-readable field label, used in the message.
	 *
	 * @return array
	 * @since 2.0.0
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
	 * Build the schema elements for one gateway from its own class.
	 *
	 * Page label/description/logo come from the gateway's name(), description()
	 * and logo() methods; credential fields come from get_settings(), which
	 * also supplies the saved value for each field. Field ids are prefixed
	 * with the gateway key to stay globally unique for the frontend (see
	 * build_dependency_key()).
	 *
	 * @param string           $key      Gateway registry key.
	 * @param GatewayInterface $gateway  Gateway instance.
	 * @param int              $priority Page priority (registry order).
	 *
	 * @return array[]
	 * @since 2.0.0
	 */
	private function build_gateway_schema( string $key, GatewayInterface $gateway, int $priority ): array {
		$doc_links = $this->doc_links();

		$page = [
			'type'        => 'page',
			'id'          => $key,
			'label'       => $gateway->name(),
			'description' => $gateway->description(),
			'image_url'   => $gateway->logo(),
			'priority'    => $priority,
		];

		if ( isset( $doc_links[ $key ] ) ) {
			$page['doc_link']      = $doc_links[ $key ];
			$page['doc_link_text'] = __( 'Get your account', 'texty' );
		}

		$elements = [ $page ];
		$settings = $gateway->get_settings();

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return $elements;
		}

		$section_id = $key . '_credentials';

		$elements[] = [
			'type'     => 'section',
			'id'       => $section_id,
			'page_id'  => $key,
			'priority' => 10,
		];

		$field_priority = 10;

		foreach ( $settings as $cred_key => $setting ) {
			$label = isset( $setting['name'] ) ? $setting['name'] : $cred_key;
			$type  = isset( $setting['type'] ) ? $setting['type'] : 'text';

			$field = [
				'type'        => 'field',
				'id'          => $key . '_' . $cred_key,
				'page_id'     => $key,
				'section_id'  => $section_id,
				'variant'     => $this->field_variant( (string) $cred_key, $type ),
				'label'       => $label,
				/* translators: %s: credential field label, e.g. "Account SID" */
				'placeholder' => sprintf( __( 'Enter %s', 'texty' ), $label ),
				'layout'      => 'full-width',
				'priority'    => $field_priority,
				'default'     => isset( $setting['value'] ) ? $setting['value'] : '',
				'validations' => $this->required_validation( $label ),
			];

			if ( ! empty( $setting['help'] ) ) {
				$field['description'] = $setting['help'];
			}

			$elements[]      = $field;
			$field_priority += 10;
		}

		return $elements;
	}

	/**
	 * Map a gateway settings field to a plugin-ui field variant.
	 *
	 * @param string $cred_key Credential key within the gateway (e.g. 'from').
	 * @param string $type     Field type declared by the gateway's get_settings().
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private function field_variant( string $cred_key, string $type ): string {
		if ( 'password' === $type ) {
			return 'show_hide';
		}

		if ( 'phone' === $type || 'from' === $cred_key ) {
			return 'phone';
		}

		return 'text';
	}

	/**
	 * Sign-up links for the built-in gateways, shown as the page's doc link.
	 *
	 * @return array<string, string>
	 * @since 2.0.0
	 */
	private function doc_links(): array {
		return [
			'twilio'     => 'https://www.twilio.com/try-twilio',
			'vonage'     => 'https://dashboard.nexmo.com/sign-up',
			'plivo'      => 'https://console.plivo.com/accounts/register/',
			'clickatell' => 'https://www.clickatell.com/sign-up/',
		];
	}

	/**
	 * Translatable validation messages for built-in field types.
	 *
	 * @return array<string, string>
	 * @since 2.0.0
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
	 * @since 2.0.0
	 */
	protected function get_permission_error_message( string $context ): string {
		return 'write' === $context
			? __( 'You do not have permission to update settings.', 'texty' )
			: __( 'You do not have permission to view settings.', 'texty' );
	}
}
