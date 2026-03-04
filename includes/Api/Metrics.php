<?php

namespace Texty\Api;

use Texty\Models\SmsStat;
use Texty\Models\SmsStatStore;
use WeDevs\WPKit\DataLayer\DataLayerFactory;
use WP_REST_Server;

class Metrics extends Base {

    /**
     * Initialize
     *
     * @return void
     */
    public function __construct() {
        $this->namespace = 'texty/v1';
        $this->rest_base = 'metrics';
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
                    'callback'            => [ $this, 'get_metrics' ],
                    'permission_callback' => [ $this, 'admin_permissions_check' ],
                    'args'                => [],
                ],
            ]
        );
    }

    /**
     * Get metrics data
     *
     * @param WP_Rest_Request $request
     *
     * @return WP_Rest_Response|WP_Error
     */
    public function get_metrics( $request ) {
        try {
            $store = DataLayerFactory::make_store( SmsStat::class );

            if ( null === $store ) {
                return new \WP_Error( 'store_error', 'SMS data store not available', [ 'status' => 503 ] );
            }

            $gateway_name   = texty()->settings()->gateway();
            $gateway_status = $gateway_name ? true : false;

            // Build volume chart data for last 12 months first.
            // The last item in the chart is always the current month,
            // so we reuse that count instead of running a separate query.
            $volume_chart  = $this->get_volume_chart( $store );
            $monthly_usage = ! empty( $volume_chart ) ? end( $volume_chart )['count'] : 0;

            // Calculate usage change vs previous month
            $usage_change = 0;
            // Calculate delivery rate for last 30 days
            $delivery_rate = $this->get_delivery_rate( $store );

            $response = [
                'gateway_status' => $gateway_status,
                'gateway_name'   => $gateway_name,
                'monthly_usage'  => $monthly_usage,
                'usage_change'   => $usage_change,
                'delivery_rate'  => $delivery_rate,
                'volume_chart'   => $volume_chart,
            ];

            return rest_ensure_response( $response );
        } catch ( \Exception $e ) {
            error_log( 'Texty Metrics Error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine() );
            return new \WP_Error( 'metrics_error', __( 'Failed to load metrics data.', 'texty' ), [ 'status' => 500 ] );
        }
    }

    /**
     * Calculate delivery rate for last 30 days
     *
     * Uses DataLayer to fetch sent and failed SMS, then calculates rate.
     *
     * @param SmsStatStore $store Store instance
     *
     * @return float|null Delivery rate as percentage (e.g., 94.5) or null if no data
     */
    private function get_delivery_rate( $store ) {
        try {
            $today           = new \DateTimeImmutable( 'now', wp_timezone() );
            $thirty_days_ago = $today->modify( '-30 days' )->format( 'Y-m-d' );
            $today_date      = $today->format( 'Y-m-d' );

            // Query all SMS from last 30 days using SmsStat static method
            $result = SmsStat::get_successful_sent_sms_between_dates( $thirty_days_ago, $today_date );

            if ( empty( $result['total'] ) || (int) $result['total'] === 0 ) {
                return null;
            }

            // Count 'sent' status from fetched records
            $delivered = 0;
            if ( ! empty( $result['items'] ) ) {
                foreach ( $result['items'] as $row ) {
                    if ( isset( $row->status ) && 'sent' === $row->status ) {
                        ++$delivered;
                    }
                }
            }

            $total = (int) $result['total'];
            $rate  = ( $delivered / $total ) * 100;

            return round( $rate, 1 );
        } catch ( \Exception $e ) {
            error_log( 'Texty: Error calculating delivery rate - ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Get volume chart data for last 12 months (sent messages only)
     *
     * Uses DataLayer to fetch sent SMS for 12 months, groups by month in PHP.
     * The last element of the returned array always represents the current month,
     * and is reused by get_metrics() as monthly_usage — no extra query needed.
     *
     * @param SmsStatStore $store Store instance
     *
     * @return array  e.g. [ ['month' => 'Apr', 'count' => 42], ... ]
     */
    private function get_volume_chart( $store ) {
        try {
            // Generate last 12 months list
            $current    = new \DateTimeImmutable( 'first day of this month', wp_timezone() );
            $months     = [];
            $months_map = [];

            for ( $i = 11; $i >= 0; $i-- ) {
                $month_key                = $current->modify( "-{$i} months" )->format( 'Y-m' );
                $months[]                 = $month_key;
                $months_map[ $month_key ] = 0;
            }

            // Get the oldest month date and today
            $oldest_month = $months[0] . '-01';
            $today        = new \DateTimeImmutable( 'now', wp_timezone() );
            $today_date   = $today->format( 'Y-m-d' );

            // Query using SmsStat static method: get all sent SMS for last 12 months
            $result = SmsStat::get_sent_sms_between_dates( $oldest_month, $today_date );

            // Group records by month
            if ( ! empty( $result['items'] ) ) {
                foreach ( $result['items'] as $row ) {
                    if ( isset( $row->created_at ) ) {
                        // Extract Y-m from created_at string (format: YYYY-MM-DD HH:MM:SS)
                        $month_key = substr( $row->created_at, 0, 7 );

                        if ( isset( $months_map[ $month_key ] ) ) {
                            ++$months_map[ $month_key ];
                        }
                    }
                }
            }

            // Build final chart data with short month names
            $chart_data = [];
            foreach ( $months_map as $month_key => $count ) {
                try {
                    $month_date   = new \DateTimeImmutable( $month_key . '-01', wp_timezone() );
                    $chart_data[] = [
                        'month' => $month_date->format( 'M' ),
                        'count' => $count,
                    ];
                } catch ( \Exception $e ) {
                    error_log( 'Texty: Invalid date format - ' . $month_key );
                }
            }

            return $chart_data;
        } catch ( \Exception $e ) {
            error_log( 'Texty: Error getting volume chart - ' . $e->getMessage() );
            return [];
        }
    }
}
