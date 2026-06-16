<?php

namespace Texty\Models;

use WeDevs\WPKit\DataLayer\DataStore\BaseDataStore;

/**
 * SMS Statistics DataStore
 *
 * Handles all database operations for SMS statistics.
 *
 * @since 2.0.0
 */
class SmsStatStore extends BaseDataStore {

    /**
     * Get the database table name (without prefix)
     *
     * @return string
     * @since 2.0.0
     */
    public function get_table_name(): string {
        return 'texty_sms_stat';
    }

    /**
     * Get field-to-format mapping for wpdb::prepare()
     *
     * @return array
     * @since 2.0.0
     */
    protected function get_fields_with_format(): array {
        return [
            'receiver'           => '%s',
            'gateway'            => '%s',
            'status'             => '%s',
            'notification_id'    => '%s',
            'notification_group' => '%s',
            'message'            => '%s',
            'response'           => '%s',
            'created_at'         => '%s',
            'updated_at'         => '%s',
            'reference_id'       => '%s',
        ];
    }

    /**
     * Get searchable fields for query operations
     *
     * @return array
     * @since 2.0.0
     */
    protected function get_searchable_fields(): array {
        return [
            'receiver',
            'gateway',
            'reference_id',
            'notification_id',
            'message',
        ];
    }

    /**
     * Get the primary key field name
     *
     * @return string
     * @since 2.0.0
     */
    public function get_id_field_name(): string {
        return 'id';
    }

    /**
     * Get the primary key field format
     *
     * @return string
     * @since 2.0.0
     */
    public function get_id_field_format(): string {
        return '%d';
    }

    /**
     * Get date format for specific fields
     *
     * @param string $field Field name
     *
     * @return string PHP date format
     * @since 2.0.0
     */
    public function get_date_format_for_field( string $field ): string {
        return 'Y-m-d H:i:s';
    }
}
