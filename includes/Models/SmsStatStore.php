<?php

namespace Texty\Models;

use WeDevs\WPKit\DataLayer\DataStore\BaseDataStore;

/**
 * SMS Statistics DataStore
 *
 * Handles all database operations for SMS statistics.
 */
class SmsStatStore extends BaseDataStore {

    /**
     * Get the database table name (without prefix)
     *
     * @return string
     */
    public function get_table_name(): string {
        return 'texty_sms_stat';
    }

    /**
     * Get field-to-format mapping for wpdb::prepare()
     *
     * @return array
     */
    protected function get_fields_with_format(): array {
        return [
            'receiver'      => '%s',
            'gateway'       => '%s',
            'status'        => '%s',
            'created_at'    => '%s',
            'updated_at'    => '%s',
            'reference_id'  => '%s',
        ];
    }

    /**
     * Get searchable fields for query operations
     *
     * @return array
     */
    protected function get_searchable_fields(): array {
        return [
            'receiver',
            'gateway',
            'reference_id',
        ];
    }

    /**
     * Get the primary key field name
     *
     * @return string
     */
    public function get_id_field_name(): string {
        return 'id';
    }

    /**
     * Get the primary key field format
     *
     * @return string
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
     */
    public function get_date_format_for_field( string $field ): string {
        return 'Y-m-d H:i:s';
    }
}
