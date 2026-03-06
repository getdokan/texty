<?php

namespace Texty\Models;

use WeDevs\WPKit\DataLayer\Model\BaseModel;
use WeDevs\WPKit\DataLayer\DataLayerFactory;

/**
 * SMS Statistics Model
 *
 * Represents a single SMS message transaction record with metadata.
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
        'receiver'      => '',
        'gateway'       => '',
        'status'        => 'pending',
        'created_at'    => null,
        'updated_at'    => null,
        'reference_id'  => '',
    ];

    /**
     * Type casting configuration
     *
     * @var array
     */
    protected array $casts = [
        'receiver'      => 'string',
        'gateway'       => 'string',
        'status'        => 'string',
        'created_at'    => 'date',
        'updated_at'    => 'date',
        'reference_id'  => 'string',
    ];

    // --- Getters ---

    /**
     * Get receiver phone number
     *
     * @param string $context 'view' or 'edit'
     *
     * @return string
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
     */
    public function get_status( string $context = 'view' ): string {
        return $this->get_prop( 'status', $context );
    }

    /**
     * Get creation timestamp
     *
     * @param string $context 'view' or 'edit'
     *
     * @return \DateTimeInterface|null
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
     */
    public function set_status( string $status ): void {
        $this->set_prop( 'status', $status );
    }

    /**
     * Set creation timestamp
     *
     * @param string|int|\DateTimeInterface|null $date
     *
     * @return void
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
     */
    public static function get_sent_sms_between_dates( string $start_date, string $end_date ): array {
        $store = DataLayerFactory::make_store( SmsStat::class );
        if ( ! $store ) {
            error_log( sprintf( 'Texty SmsStat error: Failed to create data store for %s.', SmsStat::class ) );

            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime   = $end_date   . ' 23:59:59'; 

        $result = $store->query( [
            'per_page'   => -1,
            'date_query' => [
                'column' => 'created_at',
                'after'  => $start_datetime,
                'before' => $end_datetime,
            ],
        ] );

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
     */
    public static function get_successful_sent_sms_between_dates( string $start_date, string $end_date ): array {
        $store = DataLayerFactory::make_store( SmsStat::class );
        if ( ! $store ) {
            error_log( sprintf( 'Texty SmsStat error: Failed to create data store for %s.', SmsStat::class ) );

            return [
                'total' => 0,
                'items' => [],
            ];
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime   = $end_date   . ' 23:59:59'; 

        $result = $store->query( [
            'per_page'   => -1,
            'status'     => 'sent',
            'date_query' => [
                'column' => 'created_at',
                'after'  => $start_datetime,
                'before' => $end_datetime,
            ],
        ] );

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
