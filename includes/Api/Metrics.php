<?php

namespace Texty\Api;

use DateTimeImmutable;
use Exception;
use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Metrics REST Controller.
 *
 * @since 2.0.0
 */
class Metrics extends Base {

    /**
     * Initialize
     *
     * @return void
     * @since 2.0.0
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'metrics';
    }

    /**
     * Registers the routes for the objects of the controller.
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
                    'callback'            => [ $this, 'get_metrics' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [
                        'period' => [
                            'description' => __( 'The time range for metrics.', 'texty' ),
                            'type'        => 'string',
                            'enum'        => [ 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'this_year' ],
                            'default'     => 'this_month',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Get metrics data
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     * @since 2.0.0
     */
    public function get_metrics( $request ) {
        try {
            $store = DataLayerFactory::make_store( SmsStat::class );

            if ( ! $store instanceof SmsStatStore ) {
                return new WP_Error( 'store_error', __( 'SMS data store not available.', 'texty' ), [ 'status' => 503 ] );
            }

            $period = $request->get_param( 'period' );
            $range  = $this->resolve_range( $period );

            $counts = $store->count_by_bucket_and_status(
                $range['start']->format( 'Y-m-d H:i:s' ),
                $range['end']->format( 'Y-m-d H:i:s' ),
                $range['granularity']
            );

            // "SMS Sent" counts every attempt; delivered/failed split it by status.
            $sent      = 0;
            $delivered = 0;
            $failed    = 0;
            foreach ( $counts as $row ) {
                $sent += $row['total'];

                if ( 'sent' === $row['status'] ) {
                    $delivered += $row['total'];
                } elseif ( 'failed' === $row['status'] ) {
                    $failed += $row['total'];
                }
            }

            $delivery_rate = $sent > 0 ? round( ( $delivered / $sent ) * 100, 1 ) : 0;
            $volume_chart  = $this->build_volume_chart( $counts, $range );

            $gateway_name = texty()->settings()->gateway();

            // `gateway_name` stays the registry key (e.g. `sslwireless`) for
            // existing consumers; `gateway_label` is the gateway's display
            // name (e.g. "SSL Wireless") for the UI.
            $active_gateway = $gateway_name ? texty()->gateways()->active_gateway() : false;
            $gateway_label  = $active_gateway ? (string) $active_gateway->name() : '';

            $response = [
                'period'         => $period,
                'gateway_status' => $gateway_name ? true : false,
                'gateway_name'   => $gateway_name ? $gateway_name : '',
                'gateway_label'  => '' !== $gateway_label ? $gateway_label : ( $gateway_name ? $gateway_name : '' ),
                'sms_sent'       => $sent,
                'delivered'      => $delivered,
                'failed'         => $failed,
                'delivery_rate'  => $delivery_rate,
                'volume_chart'   => $volume_chart,
            ];

            return rest_ensure_response( $response );
        } catch ( Exception $e ) {
            error_log( 'Texty Metrics Error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() );
            return new WP_Error( 'metrics_error', __( 'Failed to load metrics data.', 'texty' ), [ 'status' => 500 ] );
        }
    }

    /**
     * Translate a period key into a date range and bucket granularity.
     *
     * @param string $period
     *
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,granularity:string,label:string}
     * @since 2.0.0
     */
    private function resolve_range( $period ) {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );

        switch ( $period ) {
            case 'last_month':
                $start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                $end   = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
                $label = __( 'Last Month', 'texty' );
                $gran  = 'day';
                break;

            case 'last_7_days':
                $start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
                $end   = $now->setTime( 23, 59, 59 );
                $label = __( 'Last 7 Days', 'texty' );
                $gran  = 'day';
                break;

            case 'last_30_days':
                $start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
                $end   = $now->setTime( 23, 59, 59 );
                $label = __( 'Last 30 Days', 'texty' );
                $gran  = 'day';
                break;

            case 'this_year':
                $start = $now->modify( 'first day of January' )->setTime( 0, 0, 0 );
                $end   = $now->setTime( 23, 59, 59 );
                $label = __( 'This Year', 'texty' );
                $gran  = 'month';
                break;

            case 'this_month':
            default:
                $start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                $end   = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
                $label = __( 'This Month', 'texty' );
                $gran  = 'day';
                break;
        }

        return [
            'start'       => $start,
            'end'         => $end,
            'granularity' => $gran,
            'label'       => $label,
        ];
    }

    /**
     * Build a series of buckets across the resolved range.
     *
     * Each bucket has:
     *  - `key`    machine ID (Y-m-d for day, Y-m for month) — used by the chart's x-axis dataKey
     *  - `label`  short label shown on the axis (Jan 1, Mar)
     *  - `date`   long label shown in the tooltip (16 January 2025)
     *  - `count`  number of SMS dispatched in that bucket
     *
     * @param array $counts Rows from SmsStatStore::count_by_bucket_and_status().
     * @param array $range
     *
     * @return array
     * @since 2.0.0
     */
    private function build_volume_chart( $counts, $range ) {
        $start    = $range['start'];
        $end      = $range['end'];
        $is_month = 'month' === $range['granularity'];

        $buckets = [];
        $cursor  = $start;
        while ( $cursor <= $end ) {
            $key = $is_month ? $cursor->format( 'Y-m' ) : $cursor->format( 'Y-m-d' );

            $buckets[ $key ] = [
                'key'   => $key,
                'label' => $is_month ? $cursor->format( 'M' ) : $cursor->format( 'M j' ),
                'date'  => $is_month ? $cursor->format( 'F Y' ) : $cursor->format( 'j F Y' ),
                'count' => 0,
            ];

            $cursor = $cursor->modify( $is_month ? '+1 month' : '+1 day' );
        }

        // Buckets use the same Y-m / Y-m-d keys the SQL DATE_FORMAT produces.
        foreach ( $counts as $row ) {
            if ( 'sent' === $row['status'] && isset( $buckets[ $row['bucket'] ] ) ) {
                $buckets[ $row['bucket'] ]['count'] += $row['total'];
            }
        }

        return array_values( $buckets );
    }
}
