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

        $table_name      = $wpdb->prefix . 'texty_sms_stat';
        $gateway_name    = texty()->settings()->gateway();
        $gateway_status  = $gateway_name ? true : false;
        $current_month   = current_time( 'Y-m' );
        $last_month      = gmdate( 'Y-m', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

        // Get current month usage (sent messages only)
        $monthly_usage = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(created_at, %s) = %s AND status = 'sent'",
                '%Y-%m',
                $current_month
            )
        );

        // Get last month usage for comparison (sent messages only)
        $last_month_usage = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(created_at, %s) = %s AND status = 'sent'",
                '%Y-%m',
                $last_month
            )
        );

        // Calculate usage change percentage
        $usage_change = 0;
        if ( $last_month_usage > 0 ) {
            $usage_change = round( ( ( $monthly_usage - $last_month_usage ) / $last_month_usage ) * 100, 1 );
        } elseif ( $monthly_usage > 0 ) {
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
     *
     * @param string $table_name The table name
     *
     * @return float|null Delivery rate as percentage (e.g., 94.5) or null if no data
     */
    private function get_delivery_rate( $table_name ) {
        global $wpdb;

        $thirty_days_ago = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

        // Total SMS attempts in last 30 days
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
                $thirty_days_ago
            )
        );

        if ( $total === 0 ) {
            return null;
        }

        // Delivered SMS in last 30 days
        $delivered = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s AND status = 'sent'",
                $thirty_days_ago
            )
        );

        $rate = ( $delivered / $total ) * 100;

        return round( $rate, 1 );
    }

    /**
     * Get volume chart data for last 12 months (sent messages only)
     *
     * @param string $table_name The table name
     *
     * @return array
     */
    private function get_volume_chart( $table_name ) {
        global $wpdb;

        $months     = [];
        $chart_data = [];

        // Generate last 12 months
        for ( $i = 11; $i >= 0; $i-- ) {
            $timestamp = current_time( 'timestamp' ) - ( $i * 30 * DAY_IN_SECONDS );
            $months[]  = gmdate( 'Y-m', $timestamp );
        }

        foreach ( $months as $month ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(created_at, %s) = %s AND status = 'sent'",
                    '%Y-%m',
                    $month
                )
            );

            $chart_data[] = [
                'month' => gmdate( 'M', strtotime( $month . '-01' ) ),
                'count' => $count,
            ];
        }

        return $chart_data;
    }
}
