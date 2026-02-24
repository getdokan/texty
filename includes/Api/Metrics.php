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
        $gateway_status  = texty()->settings()->gateway() ? true : false;
        $current_month   = current_time( 'Y-m' );
        $last_month      = gmdate( 'Y-m', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

        // Get current month usage
        $monthly_usage = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(timestamp, %s) = %s",
                '%Y-%m',
                $current_month
            )
        );

        // Get last month usage for comparison
        $last_month_usage = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(timestamp, %s) = %s",
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

        // Build volume chart data for last 12 months
        $volume_chart = $this->get_volume_chart( $table_name );

        $response = [
            'gateway_status' => $gateway_status,
            'monthly_usage'  => $monthly_usage,
            'usage_change'   => $usage_change,
            'delivery_rate'  => null,
            'volume_chart'   => $volume_chart,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Get volume chart data for last 12 months
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
                    "SELECT COUNT(*) FROM {$table_name} WHERE DATE_FORMAT(timestamp, %s) = %s",
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
