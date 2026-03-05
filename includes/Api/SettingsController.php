<?php
/**
 * Settings REST Controller.
 *
 * Extends BaseSettingsRESTController to serve the Texty gateway settings
 * via a schema-driven REST API compatible with the plugin-ui <Settings> component.
 *
 * Option key: texty_settings (option_prefix = 'texty', page_id = 'settings').
 *
 * Backward Compatibility:
 * The existing texty_settings option stores data as:
 *   [ 'gateway' => 'twilio', 'twilio' => [...], 'vonage' => [...], ... ]
 *
 * The BaseSettingsRESTController would normally nest the gateway field under
 * its section_id (gateway_selection.gateway), but existing data has 'gateway'
 * at the root level. We override get_field_path() to handle this mapping.
 *
 * For gateway credential fields (twilio.sid, vonage.key, etc.), section_id
 * already matches the existing storage key, so they map correctly by default.
 *
 * @package Texty\Api
 */

namespace Texty\Api;

use WeDevs\WPKit\Settings\BaseSettingsRESTController;

/**
 * SettingsController Class
 */
class SettingsController extends BaseSettingsRESTController {

	/**
	 * Fields that should be stored at the root level of the option,
	 * bypassing the section_id nesting.
	 *
	 * @var string[]
	 */
	private $root_level_fields = [ 'gateway' ];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'texty/v1', 'settings/schema', 'texty' );
	}

	/**
	 * Build the nested path array for a field element.
	 *
	 * Overrides the parent to handle backward compatibility with the existing
	 * texty_settings option structure. Fields listed in $root_level_fields
	 * are stored at the option root level (no section nesting).
	 *
	 * @param array $element Field element.
	 *
	 * @return string[]
	 */
	protected function get_field_path( array $element ): array {
		// Root-level fields bypass section nesting.
		if ( in_array( $element['id'], $this->root_level_fields, true ) ) {
			return [ $element['id'] ];
		}

		return parent::get_field_path( $element );
	}

	/**
	 * POST handler — converts flat dot-keyed values from the frontend
	 * into the nested structure expected by the parent controller.
	 *
	 * The plugin-ui <Settings> component sends values keyed by dependency_key
	 * (e.g. "gateway_selection.gateway", "twilio.sid"), but BaseSettingsRESTController
	 * expects nested arrays (e.g. { gateway: "twilio", twilio: { sid: "..." } }).
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function create_item( $request ) {
		$values   = $request->get_param( 'values' );
		$scope_id = sanitize_key( $request->get_param( 'scopeId' ) ?? '' );

		if ( is_array( $values ) && ! empty( $scope_id ) ) {
			$schema = $this->get_settings_schema();
			$fields = $this->get_fields_for_page( $schema, $scope_id );
			$nested = [];

			foreach ( $fields as $field ) {
				$dep_key = $this->get_dependency_key( $field );

				if ( array_key_exists( $dep_key, $values ) ) {
					$path = $this->get_field_path( $field );
					$this->set_nested_value( $nested, $path, $values[ $dep_key ] );
				}
			}

			$request->set_param( 'values', $nested );

			// Validate gateway credentials using the gateway's own validate() method.
			$gateway_error = $this->validate_gateway_credentials( $nested );

			if ( is_wp_error( $gateway_error ) ) {
				return new \WP_REST_Response(
					[
						'errors' => [
							'gateway' => $gateway_error->get_error_message(),
						],
					],
					400
				);
			}
		}

		return parent::create_item( $request );
	}

	/**
	 * Validate gateway credentials using the gateway's own validate() method.
	 *
	 * Replicates the old Api\Settings behavior where each gateway class
	 * validates its own credentials before they are saved.
	 *
	 * @param array $nested Nested values converted from flat dot-key format.
	 *
	 * @return \WP_Error|true WP_Error on validation failure, true on success.
	 */
	private function validate_gateway_credentials( array $nested ) {
		$gateway_key = $nested['gateway'] ?? '';

		if ( empty( $gateway_key ) ) {
			return true;
		}

		$registered = texty()->gateways()->all();

		if ( ! isset( $registered[ $gateway_key ] ) ) {
			return true;
		}

		$gateway_class = $registered[ $gateway_key ];
		$gateway       = new $gateway_class();

		// Build a mock request with the gateway credentials as the gateway expects.
		// Each gateway's validate() reads $request->get_param('gateway_name').
		$mock_request = new \WP_REST_Request( 'POST' );
		$mock_request->set_param( $gateway_key, $nested[ $gateway_key ] ?? [] );

		$result = $gateway->validate( $mock_request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Build the dependency_key for a field as the frontend would compute it.
	 *
	 * The plugin-ui <Settings> component builds dependency_key by joining
	 * parent IDs with dots: "section_id.field_id" (e.g. "twilio.sid").
	 *
	 * @param array $field Field element from schema.
	 *
	 * @return string
	 */
	private function get_dependency_key( array $field ): string {
		$parts       = [];
		$parent_keys = [ 'subpage_id', 'tab_id', 'section_id', 'subsection_id', 'field_group_id' ];

		foreach ( $parent_keys as $pk ) {
			if ( ! empty( $field[ $pk ] ) ) {
				$parts[] = $field[ $pk ];
			}
		}

		$parts[] = $field['id'];

		return implode( '.', $parts );
	}

	/**
	 * Return the flat settings schema array.
	 *
	 * The schema defines a single page 'settings' which maps to wp_options key 'texty_settings'.
	 * Gateway credentials are stored as nested arrays under section IDs that match
	 * the gateway key (twilio, vonage, clickatell, plivo).
	 *
	 * @return array[] Flat array of settings elements.
	 */
	protected function get_settings_schema(): array {
		$gateway_options = $this->get_gateway_options();

		$schema = [
			// Page -- maps to option key 'texty_settings'.
			[
				'type'  => 'page',
				'id'    => 'settings',
				'title' => __( 'Settings', 'texty' ),
				'icon'  => 'settings',
			],

			// Section: Gateway selection.
			[
				'type'        => 'section',
				'id'          => 'gateway_selection',
				'page_id'     => 'settings',
				'title'       => __( 'SMS Gateway', 'texty' ),
				'description' => __( 'Select and configure your SMS gateway provider.', 'texty' ),
			],

			// Field: Gateway selector (stored at root: texty_settings['gateway']).
			[
				'type'       => 'field',
				'id'         => 'gateway',
				'variant'    => 'select',
				'page_id'    => 'settings',
				'section_id' => 'gateway_selection',
				'title'      => __( 'Gateway', 'texty' ),
				'label'      => __( 'Gateway', 'texty' ),
				'default'    => '',
				'options'    => $gateway_options,
			],

			// =====================
			// Twilio section
			// =====================
			[
				'type'         => 'section',
				'id'           => 'twilio',
				'page_id'      => 'settings',
				'title'        => __( 'Twilio Settings', 'texty' ),
				'description'  => __( 'Configure your Twilio SMS gateway credentials.', 'texty' ),
				'dependencies' => [
					[
						'key'        => 'gateway_selection.gateway',
						'value'      => 'twilio',
						'comparison' => '===',
					],
				],
			],
			[
				'type'       => 'field',
				'id'         => 'sid',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'twilio',
				'title'      => __( 'Account SID', 'texty' ),
				'label'      => __( 'Account SID', 'texty' ),
				'default'    => '',
			],
			[
				'type'       => 'field',
				'id'         => 'token',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'twilio',
				'title'      => __( 'Auth Token', 'texty' ),
				'label'      => __( 'Auth Token', 'texty' ),
				'default'    => '',
			],
			[
				'type'        => 'field',
				'id'          => 'from',
				'variant'     => 'text',
				'page_id'     => 'settings',
				'section_id'  => 'twilio',
				'title'       => __( 'From Number', 'texty' ),
				'label'       => __( 'From Number', 'texty' ),
				'default'     => '',
				'helper_text' => __( 'Must be a valid number associated with your Twilio account', 'texty' ),
			],

			// =====================
			// Vonage section
			// =====================
			[
				'type'         => 'section',
				'id'           => 'vonage',
				'page_id'      => 'settings',
				'title'        => __( 'Vonage Settings', 'texty' ),
				'description'  => __( 'Configure your Vonage (Nexmo) SMS gateway credentials.', 'texty' ),
				'dependencies' => [
					[
						'key'        => 'gateway_selection.gateway',
						'value'      => 'vonage',
						'comparison' => '===',
					],
				],
			],
			[
				'type'       => 'field',
				'id'         => 'key',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'vonage',
				'title'      => __( 'API Key', 'texty' ),
				'label'      => __( 'API Key', 'texty' ),
				'default'    => '',
			],
			[
				'type'       => 'field',
				'id'         => 'secret',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'vonage',
				'title'      => __( 'API Secret', 'texty' ),
				'label'      => __( 'API Secret', 'texty' ),
				'default'    => '',
			],
			[
				'type'       => 'field',
				'id'         => 'from',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'vonage',
				'title'      => __( 'From Number', 'texty' ),
				'label'      => __( 'From Number', 'texty' ),
				'default'    => '',
			],

			// =====================
			// Clickatell section
			// =====================
			[
				'type'         => 'section',
				'id'           => 'clickatell',
				'page_id'      => 'settings',
				'title'        => __( 'Clickatell Settings', 'texty' ),
				'description'  => __( 'Configure your Clickatell SMS gateway credentials.', 'texty' ),
				'dependencies' => [
					[
						'key'        => 'gateway_selection.gateway',
						'value'      => 'clickatell',
						'comparison' => '===',
					],
				],
			],
			[
				'type'       => 'field',
				'id'         => 'key',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'clickatell',
				'title'      => __( 'API Key', 'texty' ),
				'label'      => __( 'API Key', 'texty' ),
				'default'    => '',
			],

			// =====================
			// Plivo section
			// =====================
			[
				'type'         => 'section',
				'id'           => 'plivo',
				'page_id'      => 'settings',
				'title'        => __( 'Plivo Settings', 'texty' ),
				'description'  => __( 'Configure your Plivo SMS gateway credentials.', 'texty' ),
				'dependencies' => [
					[
						'key'        => 'gateway_selection.gateway',
						'value'      => 'plivo',
						'comparison' => '===',
					],
				],
			],
			[
				'type'       => 'field',
				'id'         => 'auth_id',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'plivo',
				'title'      => __( 'Auth ID', 'texty' ),
				'label'      => __( 'Auth ID', 'texty' ),
				'default'    => '',
			],
			[
				'type'       => 'field',
				'id'         => 'token',
				'variant'    => 'text',
				'page_id'    => 'settings',
				'section_id' => 'plivo',
				'title'      => __( 'Auth Token', 'texty' ),
				'label'      => __( 'Auth Token', 'texty' ),
				'default'    => '',
			],
			[
				'type'        => 'field',
				'id'          => 'from',
				'variant'     => 'text',
				'page_id'     => 'settings',
				'section_id'  => 'plivo',
				'title'       => __( 'From Number', 'texty' ),
				'label'       => __( 'From Number', 'texty' ),
				'default'     => '',
				'helper_text' => __( 'Must be a valid number associated with your Plivo account', 'texty' ),
			],
		];

		// Add Fake gateway section if in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$schema[] = [
				'type'         => 'section',
				'id'           => 'fake',
				'page_id'      => 'settings',
				'title'        => __( 'Fake Gateway', 'texty' ),
				'description'  => __( 'This is a fake gateway that logs messages to debug.log without sending actual SMS.', 'texty' ),
				'dependencies' => [
					[
						'key'        => 'gateway_selection.gateway',
						'value'      => 'fake',
						'comparison' => '===',
					],
				],
			];
		}

		return $schema;
	}

	/**
	 * Get gateway options for the select field.
	 *
	 * @return array
	 */
	private function get_gateway_options(): array {
		$gateways = texty()->gateways()->all();
		$options  = [
			[
				'label' => __( 'Select a Gateway', 'texty' ),
				'value' => '',
			],
		];

		foreach ( $gateways as $key => $class ) {
			$obj       = new $class();
			$options[] = [
				'label' => $obj->name(),
				'value' => $key,
			];
		}

		return $options;
	}

	/**
	 * Get translated validation messages.
	 *
	 * @return array<string, string>
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
	 * Get permission error message.
	 *
	 * @param string $context 'read' or 'write'.
	 *
	 * @return string
	 */
	protected function get_permission_error_message( string $context ): string {
		if ( 'write' === $context ) {
			return __( 'You do not have permission to update settings.', 'texty' );
		}

		return __( 'You do not have permission to view settings.', 'texty' );
	}
}
