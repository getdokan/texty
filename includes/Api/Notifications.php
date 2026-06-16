<?php

namespace Texty\Api;

use Texty\Notifications as TextyNotifications;
use WP_Error;
use WP_REST_Server;
use WP_Rest_Response;
use WP_REST_Request;

class Notifications extends Base {

    /**
     * Initialize
     *
     * @return void
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'notifications';
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

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/schema',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_schema' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'group' => [
                            'description' => __( 'Notification group key.', 'texty' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Build a plugin-ui Settings schema (and values) for one notification group.
     *
     * One `page` → one `section` → a `collapsible_switch` field per notification,
     * with its recipients / message / variables attached as children via
     * `field_group_id`. The frontend renders this verbatim — no schema building
     * lives on the client.
     *
     * @since 2.0.0
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_schema( $request ) {
        $group_id = sanitize_key( (string) $request->get_param( 'group' ) );
        $groups   = texty()->notifications()->get_groups();

        if ( '' === $group_id || ! isset( $groups[ $group_id ] ) ) {
            return new WP_Error(
                'texty_invalid_group',
                __( 'Invalid notification group.', 'texty' ),
                [ 'status' => 400 ]
            );
        }

        $group      = $groups[ $group_id ];
        $roles      = $this->get_roles();
        $section_id = $group_id . '_section';

        $schema = [
            [
                // Heading (icon + title + description) is rendered by the frontend,
                // since plugin-ui's built-in heading has no icon slot. Suppress its
                // heading; label/description/icon are still sent for the frontend.
                'id'           => $group_id,
                'type'         => 'page',
                'label'        => $group['title'],
                'description'  => isset( $group['description'] ) ? $group['description'] : '',
                'icon'         => $this->group_icon( $group_id ),
                'hide_heading' => true,
                'priority'     => 10,
            ],
            [
                // No header — the section is just the card wrapper for the rows;
                // title + description live on the page heading above.
                'id'       => $section_id,
                'type'     => 'section',
                'label'    => '',
                'page_id'  => $group_id,
                'priority' => 10,
            ],
        ];

        $values = [];

        foreach ( texty()->notifications()->all() as $class ) {
            $obj = new $class();

            if ( $obj->get_group() !== $group_id ) {
                continue;
            }

            $id      = $obj->get_id();
            $message = (string) $obj->get_message_raw();

            $schema[] = [
                'id'          => $id,
                'type'        => 'field',
                'variant'     => 'collapsible_switch',
                'section_id'  => $section_id,
                'title'       => $obj->get_title(),
                'description' => $message,
                'collapsed'   => true,
            ];
            $values[ $id ] = (bool) $obj->enabled();

            if ( $obj->get_type() === 'role' ) {
                $recipients = $obj->get_recipients_raw();

                $schema[] = [
                    'id'             => $id . '_recipients',
                    'type'           => 'field',
                    'variant'        => 'multicheck',
                    'field_group_id' => $id,
                    'title'          => __( 'Recipients', 'texty' ),
                    'options'        => $roles,
                ];
                $values[ $id . '_recipients' ] = is_array( $recipients ) ? array_values( $recipients ) : [];
            }

            $schema[] = [
                'id'             => $id . '_message',
                'type'           => 'field',
                'variant'        => 'textarea',
                'field_group_id' => $id,
                'title'          => __( 'Message Content', 'texty' ),
                'rows'           => 4,
            ];
            $values[ $id . '_message' ] = $message;

            $replacements = array_merge(
                array_keys( $obj->replacement_keys() ),
                array_keys( $obj->global_replacement_keys() )
            );

            if ( ! empty( $replacements ) ) {
                $tokens = array_map(
                    function ( $token ) {
                        return '{' . $token . '}';
                    },
                    $replacements
                );

                $schema[] = [
                    'id'             => $id . '_vars',
                    'type'           => 'field',
                    'variant'        => 'info',
                    'field_group_id' => $id,
                    'title'          => __( 'Available variables', 'texty' ),
                    'description'    => implode( ', ', $tokens ),
                ];
            }
        }

        return rest_ensure_response(
            [
                'schema' => $schema,
                'values' => $values,
            ]
        );
    }

    /**
     * Map a notification group to a plugin-ui (lucide) icon name.
     *
     * @since 2.0.0
     *
     * @param string $group_id Group key.
     *
     * @return string
     */
    private function group_icon( $group_id ) {
        $icons = [
            'wp'    => 'Globe',
            'wc'    => 'ShoppingCart',
            'dokan' => 'Store',
        ];

        return isset( $icons[ $group_id ] ) ? $icons[ $group_id ] : 'Bell';
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
            $notifications = texty()->notifications()->all();

            $response = [
                'groups'        => texty()->notifications()->get_groups(),
                'roles'         => $this->get_roles(),
                'notifications' => array_map( function ( $notifier ) { // phpcs:ignore
                    $obj = new $notifier();

                    return [
                        'id'           => $obj->get_id(),
                        'enabled'      => $obj->enabled(),
                        'title'        => $obj->get_title(),
                        'type'         => $obj->get_type(),
                        'message'      => $obj->get_message_raw(),
                        'route'        => 'sms',
                        'group'        => $obj->get_group(),
                        'recipients'   => $obj->get_recipients_raw(),
                        'replacements' => array_merge(
                            array_keys( $obj->replacement_keys() ),
                            array_keys( $obj->global_replacement_keys() )
                        ),
                    ];
                }, $notifications ), // phpcs:ignore
            ];
        } else {
            $response = texty()->notifications()->settings();
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
        // Merge over the stored option so a partial (single-group) save does not
        // wipe the notifications that weren't included in this request.
        $settings = get_option( TextyNotifications::OPTION_KEY, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        $notifications = texty()->notifications()->all();

        foreach ( $notifications as $class ) {
            $obj = new $class();

            if ( $request->has_param( $obj->get_id() ) ) {
                $settings[ $obj->get_id() ] = $request->get_param( $obj->get_id() );
            }
        }

        update_option( TextyNotifications::OPTION_KEY, $settings, false );

        $request->set_param( 'context', 'edit' );

        return $this->get_items( $request );
    }

    /**
     * Get user roles
     *
     * @return array
     */
    public function get_roles() {
        $roles = [];

        foreach ( wp_roles()->get_names() as $value => $label ) {
            $roles[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $roles;
    }
}
