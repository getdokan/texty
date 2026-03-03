<?php
/**
 * Integration Guide: Using SmsStat DataLayer in Send.php
 *
 * This file shows how to integrate SmsStat DataLayer logging
 * in your existing Send.php gateway implementation.
 */

namespace Texty\Api;

use Texty\Models\SmsStat;
use WeDevs\WPKit\DataLayer\DataLayerFactory;

/**
 * Example: Send SMS via Twilio and log with DataLayer
 */
class TwilioIntegration {

    /**
     * Send SMS and log to database
     *
     * @param string $to Phone number
     * @param string $message Message text
     *
     * @return array Response with status
     */
    public function send( $to, $message ) {
        $store = DataLayerFactory::make_store( SmsStat::class );

        try {
            // Your existing Twilio send logic
            $response = $this->twilio_client->messages->create(
                $to,
                [ 'from' => $this->from_number, 'body' => $message ]
            );

            // Log successful send with DataLayer
            $sms = new SmsStat();
            $sms->set_props( [
                'receiver'      => $to,
                'gateway'       => 'Twilio',
                'status'        => 'sent',
                'reference_id'  => $response->sid,
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ] );

            $store->create( $sms );

            return [
                'success' => true,
                'id'      => $sms->get_id(),
                'ref'     => $response->sid,
            ];
        } catch ( \Exception $e ) {
            // Log failed send
            $sms = new SmsStat();
            $sms->set_props( [
                'receiver'      => $to,
                'gateway'       => 'Twilio',
                'status'        => 'failed',
                'reference_id'  => $e->getMessage(),
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ] );

            $store->create( $sms );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Update SMS status when delivery confirmation received
     *
     * @param string $ref Twilio message SID
     * @param string $status New status (delivered, failed)
     *
     * @return bool
     */
    public function update_delivery_status( $ref, $status ) {
        global $wpdb;
        $store = DataLayerFactory::make_store( SmsStat::class );
        $table = $wpdb->prefix . 'texty_sms_stat';

        // Find the SMS by reference_id
        $sms_record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE reference_id = %s LIMIT 1",
                $ref
            )
        );

        if ( ! $sms_record ) {
            return false;
        }

        // Load the SMS model and update status
        $sms = DataLayerFactory::make_model( SmsStat::class, $sms_record->id );
        if ( $sms->get_id() ) {
            $sms->set_status( $status );
            $sms->set_updated_at( current_time( 'mysql' ) );
            $store->update( $sms );
            return true;
        }

        return false;
    }

    /**
     * Get recent SMS statistics
     *
     * @return array
     */
    public function get_statistics() {
        $store = DataLayerFactory::make_store( SmsStat::class );

        // Get last 30 days stats
        $all_sms = $store->query( [
            'date_query' => [
                'column' => 'created_at',
                'after'  => date( 'Y-m-d', strtotime( '-30 days' ) ),
            ],
            'per_page' => -1,
        ] );

        // Count status distribution
        $stats = [
            'total'     => 0,
            'sent'      => 0,
            'delivered' => 0,
            'failed'    => 0,
        ];

        foreach ( $all_sms['items'] as $row ) {
            $stats['total']++;
            if ( isset( $stats[ $row->status ] ) ) {
                $stats[ $row->status ]++;
            }
        }

        $stats['delivery_rate'] = $stats['total'] > 0
            ? round( ( $stats['delivered'] / $stats['total'] ) * 100, 1 )
            : null;

        return $stats;
    }

    /**
     * Cleanup old SMS logs (older than 90 days)
     *
     * @return int Number of deleted records
     */
    public function cleanup_old_logs() {
        $store = DataLayerFactory::make_store( SmsStat::class );

        // Delete SMS older than 90 days
        $cutoff = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'texty_sms_stat';

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            )
        );

        // Flush cache group
        wp_cache_flush_group( 'texty_sms_stats' );

        return (int) $deleted;
    }
}

// ============================================================================
// MINIMAL EXAMPLE: Direct SMS Logging
// ============================================================================

/**
 * Log an SMS record to the database
 *
 * @param string $receiver Phone number
 * @param string $gateway Gateway name (Twilio, Vonage, etc)
 * @param string $status Status (sent, failed, pending)
 * @param string $reference_id Optional: Gateway message ID
 *
 * @return int SMS record ID, or 0 on failure
 */
function texty_log_sms( $receiver, $gateway, $status, $reference_id = '' ) {
    $store = DataLayerFactory::make_store( SmsStat::class );

    $sms = new SmsStat();
    $sms->set_props( [
        'receiver'      => $receiver,
        'gateway'       => $gateway,
        'status'        => $status,
        'reference_id'  => $reference_id,
        'created_at'    => current_time( 'mysql' ),
        'updated_at'    => current_time( 'mysql' ),
    ] );

    $store->create( $sms );

    return $sms->get_id();
}

// ============================================================================
// USAGE IN SEND.PHP
// ============================================================================

/*
// In your Send.php send_sms() method:

public function send_sms( $to, $message ) {
    // ... your existing send logic ...

    // Log the result
    texty_log_sms(
        $to,
        'Twilio',           // Gateway name
        'sent',             // Status
        $twilio_message_id  // Reference ID
    );

    return true;
}

// On delivery webhook callback:
public function handle_webhook( $data ) {
    $store = DataLayerFactory::make_store( SmsStat::class );

    // Find SMS by reference_id and update status
    global $wpdb;
    $table = $wpdb->prefix . 'texty_sms_stat';
    $sms_record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE reference_id = %s",
            $data['messageId']
        )
    );

    if ( $sms_record ) {
        $sms = DataLayerFactory::make_model( SmsStat::class, $sms_record->id );
        $sms->set_status( $data['status'] ); // 'delivered', 'failed'
        $sms->set_updated_at( current_time( 'mysql' ) );
        $store->update( $sms );
    }
}
*/

// ============================================================================
// HOOKS FOR CUSTOM LOGGING
// ============================================================================

/*
// Add custom data on SMS save
add_filter( 'texty_texty_sms_stat_insert_data', function ( $data ) {
    // Can modify $data before insert
    // e.g., add campaign ID, user ID, etc
    return $data;
}, 10, 1 );

// After SMS is created
add_action( 'texty_after_sms_stat_save', function () {
    // Send to analytics, webhook service, etc
}, 10, 0 );

// Fire hooks from within Send.php
do_action( 'texty_sms_sent', $to, $message, $gateway );
*/
