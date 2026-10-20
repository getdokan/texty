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

            if ( null === $store ) {
                return new WP_Error( 'store_error', __( 'SMS data store not available.', 'texty' ), [ 'status' => 503 ] );
            }

            $period = $request->get_param( 'period' );
            $range  = $this->resolve_range( $period );

            $items = $this->fetch_items( $range['start'], $range['end'] );

            $sent      = 0;
            $delivered = 0;
            $failed    = 0;
            foreach ( $items as $row ) {
                ++$sent;
                if ( isset( $row->status ) ) {
                    if ( 'sent' === $row->status ) {
                        ++$delivered;
                    } elseif ( 'failed' === $row->status ) {
                        ++$failed;
                    }
                }
            }

            $delivery_rate = $sent > 0 ? round( ( $delivered / $sent ) * 100, 1 ) : 0;
            $volume_chart  = $this->build_volume_chart( $items, $range );

            $gateway_name = texty()->settings()->gateway();

            $response = [
                'period'         => $period,
                'gateway_status' => $gateway_name ? true : false,
                'gateway_name'   => $gateway_name ? $gateway_name : '',
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
     * Fetch SMS records in the given range.
     *
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     *
     * @return array
     * @since 2.0.0
     */
    private function fetch_items( $start, $end ) {
        $result = SmsStat::get_sent_sms_between_dates(
            $start->format( 'Y-m-d' ),
            $end->format( 'Y-m-d' )
        );

        if ( empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
            return [];
        }

        return $result['items'];
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
     * @param array $items
     * @param array $range
     *
     * @return array
     * @since 2.0.0
     */
    private function build_volume_chart( $items, $range ) {
        $tz       = wp_timezone();
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

        foreach ( $items as $row ) {
            if ( empty( $row->created_at ) ) {
                continue;
            }

            try {
                $created = new DateTimeImmutable( $row->created_at, $tz );
            } catch ( Exception $e ) {
                continue;
            }

            $key = $is_month ? $created->format( 'Y-m' ) : $created->format( 'Y-m-d' );

            if ( isset( $buckets[ $key ] ) && isset( $row->status ) && 'sent' === $row->status ) {
                ++$buckets[ $key ]['count'];
            }
        }

        return array_values( $buckets );
    }
}
