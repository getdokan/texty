<?php

namespace Texty\Models;

use Texty\Dependencies\WeDevs\WPKit\DataLayer\Model\BaseModel;
use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataLayerFactory;

/**
 * SMS Statistics Model
 *
 * Represents a single SMS message transaction record with metadata.
 *
 * @since 2.0.0
 */
class SmsStat extends BaseModel {

    /**
     * Object type identifier for hooks
     *
     * @var string
     */
    protected string $object_type = 'sms_stat';

    /**
     * Plugin hook prefix
     *
     * @var string
     */
    protected string $hook_prefix = 'texty_';

    /**
     * Cache group name
     *
     * @var string
     */
    protected string $cache_group = 'texty_sms_stats';

    /**
     * Default properties/schema
     *
     * @var array
     */
    protected array $data = [
        'receiver'           => '',
        'gateway'            => '',
        'status'             => 'pending',
        'notification_id'    => '',
        'notification_group' => '',
        'message'            => '',
        'response'           => '',
        'created_at'         => null,
        'updated_at'         => null,
        'reference_id'       => '',
    ];

    /**
     * Type casting configuration
     *
     * @var array
     */
    protected array $casts = [
        'receiver'           => 'string',
        'gateway'            => 'string',
        'status'             => 'string',
        'notification_id'    => 'string',
        'notification_group' => 'string',
        'message'            => 'string',
        'response'           => 'string',
        'created_at'         => 'date',
        'updated_at'         => 'date',
        'reference_id'       => 'string',
    ];

    // --- Getters ---

    /**
     * Get receiver phone number
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_receiver( string $context = 'view' ): string {
        return $this->get_prop( 'receiver', $context );
    }

    /**
     * Get SMS gateway name
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_gateway( string $context = 'view' ): string {
        return $this->get_prop( 'gateway', $context );
    }

    /**
     * Get message status
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_status( string $context = 'view' ): string {
        return $this->get_prop( 'status', $context );
    }

    /**
     * Get notification ID (e.g. 'registration', 'order_admin_processing')
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_notification_id( string $context = 'view' ): string {
        return $this->get_prop( 'notification_id', $context );
    }

    /**
     * Get notification group ('wp', 'wc', 'dokan', or custom)
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_notification_group( string $context = 'view' ): string {
        return $this->get_prop( 'notification_group', $context );
    }

    /**
     * Get the final, token-replaced message body that was sent
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_message( string $context = 'view' ): string {
        return $this->get_prop( 'message', $context );
    }

    /**
     * Get the gateway response payload (JSON-encoded). For failed sends this
     * holds the WP_Error message + data; for successful sends it holds the
     * raw gateway response array.
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
     * @since 2.0.0
     */
    public function get_response( string $context = 'view' ): string {
        return $this->get_prop( 'response', $context );
    }

    /**
     * Get creation timestamp
     *
     * @param string $context 'view' or 'edit'
     *
     * @return \DateTimeInterface|null
     * @since 2.0.0
     */
    public function get_created_at( string $context = 'view' ) {
        return $this->get_prop( 'created_at', $context );
    }

    /**
     * Get last updated timestamp
     *
     * @param string $context 'view' or 'edit'
     *
     * @return \DateTimeInterface|null
     * @since 2.0.0
     */
    public function get_updated_at( string $context = 'view' ) {
        return $this->get_prop( 'updated_at', $context );
    }

    /**
     * Get external gateway reference ID
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string|null
     * @since 2.0.0
     */
    public function get_reference_id( string $context = 'view' ): ?string {
        return $this->get_prop( 'reference_id', $context );
    }

    // --- Setters ---

    /**
     * Set receiver phone number
     *
     * @param string $receiver
     *
     * @return void
     * @since 2.0.0
     */
    public function set_receiver( string $receiver ): void {
        $this->set_prop( 'receiver', $receiver );
    }

    /**
     * Set SMS gateway name
     *
     * @param string $gateway
     *
     * @return void
     * @since 2.0.0
     */
    public function set_gateway( string $gateway ): void {
        $this->set_prop( 'gateway', $gateway );
    }

    /**
     * Set message status
     *
     * @param string $status
     *
     * @return void
     * @since 2.0.0
     */
    public function set_status( string $status ): void {
        $this->set_prop( 'status', $status );
    }

    /**
     * Set notification ID
     *
     * @param string|null $notification_id
     *
     * @return void
     * @since 2.0.0
     */
    public function set_notification_id( ?string $notification_id ): void {
        $this->set_prop( 'notification_id', null === $notification_id ? '' : $notification_id );
    }

    /**
     * Set notification group
     *
     * @param string|null $notification_group
     *
     * @return void
     * @since 2.0.0
     */
    public function set_notification_group( ?string $notification_group ): void {
        $this->set_prop( 'notification_group', null === $notification_group ? '' : $notification_group );
    }

    /**
     * Set the final, token-replaced message body
     *
     * @param string|null $message
     *
     * @return void
     * @since 2.0.0
     */
    public function set_message( ?string $message ): void {
        $this->set_prop( 'message', null === $message ? '' : $message );
    }

    /**
     * Set the gateway response payload. Arrays/objects are JSON-encoded;
     * strings are stored as-is.
     *
     * @param mixed $response
     *
     * @return void
     * @since 2.0.0
     */
    public function set_response( $response ): void {
        if ( null === $response ) {
            $this->set_prop( 'response', '' );
            return;
        }
        if ( is_string( $response ) ) {
            $this->set_prop( 'response', $response );
            return;
        }
        $this->set_prop( 'response', wp_json_encode( $response ) ?? '' );
    }

    /**
     * Set creation timestamp
     *
     * @param string|int|\DateTimeInterface|null $date
     *
     * @return void
     * @since 2.0.0
     */
    public function set_created_at( $date ): void {
        $this->set_date_prop( 'created_at', $date );
    }

    /**
     * Set last updated timestamp
     *
     * @param string|int|\DateTimeInterface|null $date
     *
     * @return void
     * @since 2.0.0
     */
    public function set_updated_at( $date ): void {
        $this->set_date_prop( 'updated_at', $date );
    }

    /**
     * Set external gateway reference ID
     *
     * Safety net: converts null to empty string to prevent type errors
     *
     * @param string|null $reference_id
     *
     * @return void
     * @since 2.0.0
     */
    public function set_reference_id( ?string $reference_id ): void {
        // Safety net: convert null to empty string
        if ( null === $reference_id ) {
            $reference_id = '';
        }
        $this->set_prop( 'reference_id', $reference_id );
    }

    /**
     * Bulk set properties
     *
     * @param array $props
     *
     * @return void
     * @since 2.0.0
     */
    public function set_props( array $props ): void {
        foreach ( $props as $key => $value ) {
            $method = 'set_' . $key;
            if ( method_exists( $this, $method ) ) {
                $this->$method( $value );
            }
        }
    }

    /**
     * Get sent SMS records between two dates (for volume chart)
     *
     * @param string $start_date Date in 'Y-m-d' format
     * @param string $end_date   Date in 'Y-m-d' format
     *
     * @return array Array with 'total' and 'items' keys
     * @since 2.0.0
     */
    public static function get_sent_sms_between_dates( string $start_date, string $end_date ): array {
        $store = DataLayerFactory::make_store( self::class );
        if ( ! $store ) {
            error_log( sprintf( 'Texty SmsStat error: Failed to create data store for %s.', self::class ) );

            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime   = $end_date . ' 23:59:59';

        $result = $store->query(
            [
				'per_page'   => -1,
				'date_query' => [
					'column' => 'created_at',
					'after'  => $start_datetime,
					'before' => $end_datetime,
				],
			]
        );

        // Ensure result is an array with proper structure
        if ( ! is_array( $result ) ) {
            return [
                'total' => 0,
                'items' => [],
            ];
        }

        return $result;
    }
        /**
     * Get sent SMS records between two dates (for volume chart)
     *
     * @param string $start_date Date in 'Y-m-d' format
     * @param string $end_date   Date in 'Y-m-d' format
     *
     * @return array Array with 'total' and 'items' keys
     * @since 2.0.0
     */
    public static function get_successful_sent_sms_between_dates( string $start_date, string $end_date ): array {
        $store = DataLayerFactory::make_store( self::class );
        if ( ! $store ) {
            error_log( sprintf( 'Texty SmsStat error: Failed to create data store for %s.', self::class ) );

            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime   = $end_date . ' 23:59:59';

        $result = $store->query(
            [
				'per_page'   => -1,
				'date_query' => [
					'column' => 'created_at',
					'status' => 'sent',
					'after'  => $start_datetime,
					'before' => $end_datetime,
				],
			]
        );

        // Ensure result is an array with proper structure
        if ( ! is_array( $result ) ) {
            return [
                'total' => 0,
                'items' => [],
            ];
        }

        return $result;
    }
}
