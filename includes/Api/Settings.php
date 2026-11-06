<?php

namespace Texty\Api;

use Texty\Gateways\GatewayInterface;
use Texty\Gateways\Secrets;
use Texty\Settings as TextySettings;
use WP_Error;
use WP_REST_Server;

class Settings extends Base {

    /**
     * Initialize
     *
     * @return void
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'settings';
    }

    /**
     * Registers the routes for the objects of the controller.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_items' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    /**
     * Retrieves a list of items.
     *
     * @param WP_Rest_Request $request
     *
     * @return WP_Rest_Response|WP_Error
     */
    public function get_items( $request ) {
        if ( isset( $request['context'] ) && $request['context'] === 'edit' ) {
            $settings = texty()->settings()->all();
            $gateways = texty()->gateways()->all();

            $response = [
                'gateway'  => $settings['gateway'],
                'gateways' => array_map( function ( $gateway ) { // phpcs:ignore
                    $obj = new $gateway();

                    return [
                        'name'        => $obj->name(),
                        'logo'        => $obj->logo(),
                        'description' => $obj->description(),
                    ];
                }, $gateways ), // phpcs:ignore
            ];

            foreach ( $gateways as $key => $gateway ) {
                $response[ $key ] = Secrets::mask_settings( ( new $gateway() )->get_settings() );
            }
        } else {
            $response = texty()->settings()->all();

            // Saved secrets never leave the server.
            foreach ( texty()->gateways()->all() as $key => $classname ) {
                $gateway = new $classname();

                if ( $gateway instanceof GatewayInterface && isset( $response[ $key ] ) ) {
                    $response[ $key ] = Secrets::mask( $gateway, $response[ $key ] );
                }
            }
        }

        return rest_ensure_response( $response );
    }

    /**
     * Updates item from the collection.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_items( $request ) {
        $settings   = texty()->settings()->all();
        $registered = texty()->gateways()->all();
        $gateway    = $request->get_param( 'gateway' );

        if ( ! is_string( $gateway ) || ! isset( $registered[ $gateway ] ) ) {
            return new WP_Error( 'texty_invalid_gateway', __( 'Unknown gateway.', 'texty' ), [ 'status' => 400 ] );
        }

        $value = [
            'gateway' => $gateway,
        ];

        // create a new gateway instance and validate from the gateway
        $object = new $registered[ $gateway ]();

        // An unchanged mask means "keep the saved secret".
        $request->set_param(
            $gateway,
            Secrets::restore( $object, $request->get_param( $gateway ), texty()->settings()->get( $gateway ) )
        );

        $response = $object->validate( $request );

        // check if the credentials are correct
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // set the credentials with the gateway key
        $value[ $gateway ] = $response;

        $settings = array_merge( $settings, $value );

        update_option( TextySettings::OPTION_KEY, $settings, false );

        $request->set_param( 'context', 'edit' );

        return $this->get_items( $request );
    }

    /**
     * Get registered items
     *
     * @return array
     */
    public function get_registered_items() {
        return [
            'gateway' => [
                'description' => __( 'The selected gateway', 'texty' ),
                'type'        => 'enum',
                'enum'        => array_keys( texty()->gateways()->all() ),
                'required'    => true,
            ],
        ];
    }

    /**
     * Retrieves the settings schema, conforming to JSON Schema.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $schema = [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'settings',
            'type'       => 'object',
            'properties' => [],
        ];

        foreach ( $this->get_registered_items() as $option => $args ) {
            $schema['properties'][ $option ] = $args;
        }

        $this->schema = $schema;

        return $this->add_additional_fields_schema( $this->schema );
    }
}
