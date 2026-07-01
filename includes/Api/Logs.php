<?php
/**
 * SMS Logs REST controller.
 *
 * Routes:
 *   GET /texty/v1/logs           — paged listing for the SMS Logs UI.
 *   GET /texty/v1/logs/export    — CSV download of the (filtered) log set.
 *   GET /texty/v1/logs/{id}      — single row drill-in for the "View Log" panel.
 *
 * Storage: reads from `wp_texty_sms_stat` via the SmsStatStore data layer.
 *
 * @package Texty\Api
 * @since   2.0.0
 */

namespace Texty\Api;

use Texty\Models\SmsStat;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Logs Class
 */
class Logs extends Base {

    const DEFAULT_PER_PAGE = 10;
    const MAX_PER_PAGE     = 100;

    /**
     * Constructor.
     *
     * @since 2.0.0
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'logs';
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
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'page'     => [
                            'description' => __( 'Current page number.', 'texty' ),
                            'type'        => 'integer',
                            'default'     => 1,
                        ],
                        'per_page' => [
                            'description' => __( 'Items per page.', 'texty' ),
                            'type'        => 'integer',
                            'default'     => self::DEFAULT_PER_PAGE,
                        ],
                        'status'   => [
                            'description' => __( 'Filter by status.', 'texty' ),
                            'type'        => 'string',
                            'enum'        => [ '', 'sent', 'failed', 'pending' ],
                            'default'     => '',
                        ],
                        'type'     => [
                            'description' => __( 'Filter by notification id.', 'texty' ),
                            'type'        => 'string',
                            'default'     => '',
                        ],
                        'search'   => [
                            'description' => __( 'Free-text search.', 'texty' ),
                            'type'        => 'string',
                            'default'     => '',
                        ],
                        'orderby'  => [
                            'description' => __( 'Sort field.', 'texty' ),
                            'type'        => 'string',
                            'default'     => 'created_at',
                        ],
                        'order'    => [
                            'description' => __( 'Sort direction.', 'texty' ),
                            'type'        => 'string',
                            'enum'        => [ 'asc', 'desc' ],
                            'default'     => 'desc',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/export',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'export' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'status' => [
                            'description' => __( 'Filter by status.', 'texty' ),
                            'type'        => 'string',
                            'enum'        => [ '', 'sent', 'failed', 'pending' ],
                            'default'     => '',
                        ],
                        'type'   => [
                            'description' => __( 'Filter by notification id.', 'texty' ),
                            'type'        => 'string',
                            'default'     => '',
                        ],
                        'search' => [
                            'description' => __( 'Free-text search.', 'texty' ),
                            'type'        => 'string',
                            'default'     => '',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\d+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_item' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'id' => [
                            'description' => __( 'Log entry ID.', 'texty' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * GET /logs — paged listing.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     * @since 2.0.0
     */
    public function get_items( $request ) {
        $store = DataLayerFactory::make_store( SmsStat::class );
        if ( ! $store ) {
            return rest_ensure_response(
                [
                    'items'        => [],
                    'total'        => 0,
                    'per_page'     => self::DEFAULT_PER_PAGE,
                    'current_page' => 1,
                    'total_pages'  => 0,
                ]
            );
        }

        $per_page = max( 1, min( (int) $request->get_param( 'per_page' ), self::MAX_PER_PAGE ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $status   = sanitize_key( (string) $request->get_param( 'status' ) );
        $type     = sanitize_key( (string) $request->get_param( 'type' ) );
        $search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
        $orderby  = sanitize_key( (string) $request->get_param( 'orderby' ) );
        $order    = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

        // BaseDataStore::query falls back to the id column when orderby
        // isn't a known field, so unknown values silently degrade to the
        // closest analogue (id DESC ≈ created_at DESC) instead of erroring.
        $args = [
            'per_page' => $per_page,
            'page'     => $page,
            'orderby'  => '' !== $orderby ? $orderby : 'created_at',
            'order'    => $order,
        ];
        if ( '' !== $status ) {
            $args['status'] = $status;
        }
        if ( '' !== $type ) {
            $args['notification_id'] = $type;
        }
        if ( '' !== $search ) {
            $args['search'] = $search;
        }

        $result = $store->query( $args );
        $items  = is_array( $result['items'] ?? null ) ? $result['items'] : [];

        $payload = [
            'items'        => array_map( [ $this, 'present_row' ], $items ),
            'total'        => (int) ( $result['total'] ?? 0 ),
            'per_page'     => (int) ( $result['per_page'] ?? $per_page ),
            'current_page' => (int) ( $result['current_page'] ?? $page ),
            'total_pages'  => (int) ( $result['total_pages'] ?? 0 ),
        ];

        return rest_ensure_response( $payload );
    }

    /**
     * GET /logs/{id} — single row.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     * @since 2.0.0
     */
    public function get_item( $request ) {
        $id    = (int) $request->get_param( 'id' );
        $store = DataLayerFactory::make_store( SmsStat::class );
        if ( ! $store ) {
            return new WP_Error( 'texty_no_store', __( 'Logs store unavailable.', 'texty' ), [ 'status' => 500 ] );
        }

        // BaseDataStore::read() takes a hydrated model by reference and throws
        // when the row is missing, so it can't be fed a bare id. Query by the
        // id column instead — it returns the same raw row shape present_row()
        // already consumes for the listing, and an empty set on not-found.
        $result = $store->query(
            [
                'id'          => $id,
                'per_page'    => 1,
                'count_total' => false,
            ]
        );
        $items  = is_array( $result['items'] ?? null ) ? $result['items'] : [];

        if ( empty( $items ) ) {
            return new WP_Error( 'texty_log_not_found', __( 'Log entry not found.', 'texty' ), [ 'status' => 404 ] );
        }

        return rest_ensure_response( $this->present_row( $items[0] ) );
    }

    /**
     * GET /logs/export — stream the (optionally filtered) log set as a CSV
     * download. Honours the same `status` / `type` / `search` filters as the
     * listing so the export matches what the user sees.
     *
     * Opened directly in a browser tab (not via apiFetch), so the frontend must
     * pass `_wpnonce` on the query string for REST cookie auth to succeed.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_Error|void Streams CSV and exits on success.
     * @since 2.0.0
     */
    public function export( $request ) {
        $store = DataLayerFactory::make_store( SmsStat::class );
        if ( ! $store ) {
            return new WP_Error( 'texty_no_store', __( 'Logs store unavailable.', 'texty' ), [ 'status' => 500 ] );
        }

        $status = sanitize_key( (string) $request->get_param( 'status' ) );
        $type   = sanitize_key( (string) $request->get_param( 'type' ) );
        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );

        $args = [
            'per_page' => -1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];
        if ( '' !== $status ) {
            $args['status'] = $status;
        }
        if ( '' !== $type ) {
            $args['notification_id'] = $type;
        }
        if ( '' !== $search ) {
            $args['search'] = $search;
        }

        $result = $store->query( $args );
        $items  = is_array( $result['items'] ?? null ) ? $result['items'] : [];

        $filename = 'texty-sms-logs-' . gmdate( 'Y-m-d-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // UTF-8 BOM so Excel renders multibyte (e.g. Arabic) message bodies.
        fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        fputcsv(
            $output,
            [
                __( 'ID', 'texty' ),
                __( 'Date', 'texty' ),
                __( 'Type', 'texty' ),
                __( 'Gateway', 'texty' ),
                __( 'Recipient', 'texty' ),
                __( 'Message', 'texty' ),
                __( 'Status', 'texty' ),
                __( 'Reference ID', 'texty' ),
                __( 'Response', 'texty' ),
            ]
        );

        foreach ( $items as $row ) {
            $data = $this->present_row( $row );
            fputcsv(
                $output,
                [
                    $data['id'],
                    $data['created_at'],
                    $data['type_label'],
                    $data['gateway'],
                    $data['receiver'],
                    $data['message'],
                    $data['status'],
                    $data['reference_id'],
                    $data['response'],
                ]
            );
        }

        fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Shape a raw DB row for the API. Adds derived `type_label` so the frontend
     * doesn't need to look up the notification registry.
     *
     * @param mixed $row Raw row (object or array).
     *
     * @return array
     * @since 2.0.0
     */
    private function present_row( $row ): array {
        $row = is_object( $row ) ? (array) $row : (array) $row;

        $notification_id    = (string) ( $row['notification_id'] ?? '' );
        $notification_group = (string) ( $row['notification_group'] ?? '' );

        return [
            'id'                 => (int) ( $row['id'] ?? 0 ),
            'receiver'           => (string) ( $row['receiver'] ?? '' ),
            'gateway'            => (string) ( $row['gateway'] ?? '' ),
            'status'             => (string) ( $row['status'] ?? '' ),
            'notification_id'    => $notification_id,
            'notification_group' => $notification_group,
            'type_label'         => $this->build_type_label( $notification_id, $notification_group ),
            'message'            => (string) ( $row['message'] ?? '' ),
            'response'           => (string) ( $row['response'] ?? '' ),
            'reference_id'       => (string) ( $row['reference_id'] ?? '' ),
            'created_at'         => (string) ( $row['created_at'] ?? '' ),
            'created_at_formatted' => $this->format_datetime( (string) ( $row['created_at'] ?? '' ) ),
            'updated_at'         => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /**
     * Format a stored MySQL datetime using the site's date & time settings
     * (Settings → General).
     *
     * Rows are written with current_time( 'mysql' ) — already site-local —
     * so mysql2date is the right tool: it localizes month/day names without
     * applying a second timezone shift.
     *
     * @param string $datetime MySQL datetime string (site-local).
     *
     * @return string
     * @since 2.0.0
     */
    private function format_datetime( string $datetime ): string {
        if ( '' === $datetime ) {
            return '';
        }

        $format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $formatted = mysql2date( $format, $datetime );

        return $formatted ? $formatted : $datetime;
    }

    /**
     * Build the human "Type" string shown in the table — e.g. "Dokan -
     * Vendor User Registration" or just the notification's title for the
     * `wp` group. Falls back to the raw notification id when the
     * notification class isn't registered (legacy rows).
     *
     * @param string $notification_id    Stored notification id.
     * @param string $notification_group Stored group key.
     *
     * @return string
     * @since 2.0.0
     */
    private function build_type_label( string $notification_id, string $notification_group ): string {
        if ( '' === $notification_id ) {
            return '';
        }

        $notifications = texty()->notifications()->all();
        $title         = '';

        if ( isset( $notifications[ $notification_id ] ) ) {
            $class = $notifications[ $notification_id ];
            $obj   = new $class();
            if ( method_exists( $obj, 'get_title' ) ) {
                $title = (string) $obj->get_title();
            }
        }

        if ( '' === $title ) {
            $title = $notification_id;
        }

        $groups = texty()->notifications()->get_groups();
        if ( 'wp' !== $notification_group && isset( $groups[ $notification_group ]['title'] ) ) {
            return sprintf( '%s - %s', (string) $groups[ $notification_group ]['title'], $title );
        }

        return $title;
    }
}
