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
                error_log( 'Texty: SmsStat store is not initialized' );
                return new \WP_Error( 'store_error', 'SMS data store not available', [ 'status' => 503 ] );
            }

            $gateway_name   = texty()->settings()->gateway();
            $gateway_status = $gateway_name ? true : false;
            $current        = new \DateTimeImmutable( 'first day of this month', wp_timezone() );
            $current_month  = $current->format( 'Y-m' );
            $last_month     = $current->modify( '-1 month' )->format( 'Y-m' );

            // Today's date number (e.g., 10 if today is the 10th)
            // Used for fair comparison: this month's 10 days vs last month's same 10 days
            $current_day = (int) ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'd' );

            // Get monthly usage for current and last month
            $monthly_usage    = $this->get_monthly_usage( $store, $current_month, $current_day );
            $last_month_usage = $this->get_monthly_usage( $store, $last_month, $current_day );

            // Calculate usage change percentage
            $usage_change = 0;
            if ( $last_month_usage > 0 ) {
                $usage_change = round( ( ( $monthly_usage - $last_month_usage ) / $last_month_usage ) * 100, 1 );
            } elseif ( $monthly_usage > 0 ) {
                // Last month had 0, this month has data = 100% increase
                $usage_change = 100;
            }

            // Calculate delivery rate for last 30 days
            $delivery_rate = $this->get_delivery_rate( $store );

            // Build volume chart data for last 12 months
            $volume_chart = $this->get_volume_chart( $store );

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
            return new \WP_Error( 'metrics_error', 'Failed to load metrics data: ' . $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Get monthly usage for a specific month
     *
     * Uses DataLayer to count sent SMS for a specific month up to a given day.
     *
     * @param SmsStatStore $store Store instance
     * @param string       $month Month in 'Y-m' format
     * @param int          $day   Day of month for comparison
     *
     * @return int Total sent messages
     */
    private function get_monthly_usage( $store, $month, $day ) {
        // Calculate date range for this month up to the specified day
        $month_start = $month . '-01';
        $month_end   = $month . '-' . str_pad( $day, 2, '0', STR_PAD_LEFT );

        try {
            // Query using DataLayer: count sent SMS in date range
            $result = SmsStat::get_uses_stats_between_dates( $month_start, $month_end );

            return isset( $result['total'] ) ? (int) $result['total'] : 0;
        } catch ( \Exception $e ) {
            error_log( 'Texty: Error getting monthly usage - ' . $e->getMessage() );
            return 0;
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
            $thirty_days_ago = date( 'Y-m-d', strtotime( '-30 days' ) );

            // Query all SMS from last 30 days
            $result = $store->query( [
                'per_page'   => -1,
                'date_query' => [
                    'column' => 'created_at',
                    'after'  => $thirty_days_ago,
                ],
                'no_cache'   => true,
            ] );

            if ( empty( $result['total'] ) || (int) $result['total'] === 0 ) {
                return null;
            }

            // Count 'sent' status from fetched records
            $delivered = 0;
            if ( ! empty( $result['items'] ) ) {
                foreach ( $result['items'] as $row ) {
                    if ( isset( $row->status ) && 'sent' === $row->status ) {
                        $delivered++;
                    }
                }
            }

            $total   = (int) $result['total'];
            $rate    = ( $delivered / $total ) * 100;

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
     *
     * @param SmsStatStore $store Store instance
     *
     * @return array
     */
    private function get_volume_chart( $store ) {
        try {
            // Generate last 12 months list
            $current    = new \DateTimeImmutable( 'first day of this month', wp_timezone() );
            $months     = [];
            $months_map = [];

            for ( $i = 11; $i >= 0; $i-- ) {
                $month_key          = $current->modify( "-{$i} months" )->format( 'Y-m' );
                $months[]           = $month_key;
                $months_map[ $month_key ] = 0;
            }

            // Get the oldest month date
            $oldest_month = $months[0] . '-01';

            // Query using DataLayer: get all sent SMS for last 12 months
            $result = $store->query( [
                'per_page'   => -1,
                'status'     => 'sent',
                'date_query' => [
                    'column' => 'created_at',
                    'after'  => $oldest_month,
                ],
                'no_cache'   => true,
            ] );

            // Group records by month
            if ( ! empty( $result['items'] ) ) {
                foreach ( $result['items'] as $row ) {
                    if ( isset( $row->created_at ) ) {
                        // Extract Y-m from created_at string (format: YYYY-MM-DD HH:MM:SS)
                        $month_key = substr( $row->created_at, 0, 7 );
                        
                        if ( isset( $months_map[ $month_key ] ) ) {
                            $months_map[ $month_key ]++;
                        }
                    }
                }
            }

            // Build final chart data with short month names
            $chart_data = [];
            foreach ( $months_map as $month_key => $count ) {
                try {
                    $month_date = new \DateTimeImmutable( $month_key . '-01', wp_timezone() );
                    $chart_data[] = [
                        'month' => $month_date->format( 'M' ),
                        'count' => $count,
                    ];
                } catch ( \Exception $e ) {
                    // Skip invalid dates
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
