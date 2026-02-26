<?php

namespace Texty\Api;

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
        global $wpdb;

        $table_name     = $wpdb->prefix . 'texty_sms_stat';
        $gateway_name   = texty()->settings()->gateway();
        $gateway_status = $gateway_name ? true : false;
        $current        = new \DateTimeImmutable( 'first day of this month', wp_timezone() );
        $current_month  = $current->format( 'Y-m' );
        $last_month     = $current->modify( '-1 month' )->format( 'Y-m' );

        // Today's date number (e.g., 10 if today is the 10th)
        // Used for fair comparison: this month's 10 days vs last month's same 10 days
        $current_day = (int) ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'd' );

        // OPTIMIZED: Single query to get both current and last month usage
        // Uses same date range (DAY <= current_day) for fair comparison
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, COUNT(*) as total
                 FROM {$table_name}
                 WHERE DATE_FORMAT(created_at, '%%Y-%%m') IN (%s, %s)
                 AND DAY(created_at) <= %d
                 AND status = 'sent'
                 GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')",
                $current_month,
                $last_month,
                $current_day
            )
        );

        // Parse results into variables
        $monthly_usage    = 0;
        $last_month_usage = 0;

        foreach ( $results as $row ) {
            if ( $row->month === $current_month ) {
                $monthly_usage = (int) $row->total;
            } else {
                $last_month_usage = (int) $row->total;
            }
        }

        // Calculate usage change percentage
        $usage_change = 0;
        if ( $last_month_usage > 0 ) {
            $usage_change = round( ( ( $monthly_usage - $last_month_usage ) / $last_month_usage ) * 100, 1 );
        } elseif ( $monthly_usage > 0 ) {
            // Last month had 0, this month has data = 100% increase
            $usage_change = 100;
        }

        // Calculate delivery rate for last 30 days
        $delivery_rate = $this->get_delivery_rate( $table_name );

        // Build volume chart data for last 12 months
        $volume_chart = $this->get_volume_chart( $table_name );

        $response = [
            'gateway_status' => $gateway_status,
            'gateway_name'   => $gateway_name,
            'monthly_usage'  => $monthly_usage,
            'usage_change'   => $usage_change,
            'delivery_rate'  => $delivery_rate,
            'volume_chart'   => $volume_chart,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Calculate delivery rate for last 30 days
     * OPTIMIZED: Single query using SUM+CASE instead of two separate queries
     *
     * @param string $table_name The table name
     *
     * @return float|null Delivery rate as percentage (e.g., 94.5) or null if no data
     */
    private function get_delivery_rate( $table_name ) {
        global $wpdb;

        $thirty_days_ago = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' );

        // OPTIMIZED: Get total and delivered in a single query
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as delivered
                 FROM {$table_name}
                 WHERE created_at >= %s",
                $thirty_days_ago
            )
        );

        if ( ! $row || (int) $row->total === 0 ) {
            return null;
        }

        $rate = ( (int) $row->delivered / (int) $row->total ) * 100;

        return round( $rate, 1 );
    }

    /**
     * Get volume chart data for last 12 months (sent messages only)
     * OPTIMIZED: Single query for all 12 months instead of 12 separate queries
     *
     * @param string $table_name The table name
     *
     * @return array
     */
    private function get_volume_chart( $table_name ) {
        global $wpdb;

        // Generate last 12 months list
        $current      = new \DateTimeImmutable( 'first day of this month', wp_timezone() );
        $months       = [];
        $months_map   = [];

        for ( $i = 11; $i >= 0; $i-- ) {
            $month_key          = $current->modify( "-{$i} months" )->format( 'Y-m' );
            $months[]           = $month_key;
            $months_map[ $month_key ] = 0; // default count = 0
        }

        // OPTIMIZED: Single query for all 12 months at once
        $oldest_month = $months[0] . '-01';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, COUNT(*) as total
                 FROM {$table_name}
                 WHERE created_at >= %s
                 AND status = 'sent'
                 GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')",
                $oldest_month
            )
        );

        // Fill results into the map
        foreach ( $results as $row ) {
            if ( isset( $months_map[ $row->month ] ) ) {
                $months_map[ $row->month ] = (int) $row->total;
            }
        }

        // Build final chart data with short month names
        $chart_data = [];
        foreach ( $months_map as $month_key => $count ) {
            $chart_data[] = [
                'month' => ( new \DateTimeImmutable( $month_key . '-01', wp_timezone() ) )->format( 'M' ),
                'count' => $count,
            ];
        }

        return $chart_data;
    }
}
