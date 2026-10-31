<?php

namespace Texty\Models;

use Texty\Dependencies\WeDevs\WPKit\DataLayer\DataStore\BaseDataStore;

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

    /**
     * Count rows per time bucket and status within a date range.
     *
     * Aggregates in SQL (served by `created_at_status_idx`) instead of
     * loading every row, message and response included, into PHP.
     *
     * @param string $start       Range start, `Y-m-d H:i:s` in site time.
     * @param string $end         Range end, `Y-m-d H:i:s` in site time.
     * @param string $granularity `day` or `month`.
     *
     * @return array<int, array{bucket:string,status:string,total:int}>
     * @since 2.0.3
     */
    public function count_by_bucket_and_status( string $start, string $end, string $granularity = 'day' ): array {
        global $wpdb;

        $table  = $this->get_table_name_with_prefix();
        $format = 'month' === $granularity ? '%Y-%m' : '%Y-%m-%d';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the store's own table name.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(created_at, %s) AS bucket, status, COUNT(*) AS total
                FROM {$table}
                WHERE created_at BETWEEN %s AND %s
                GROUP BY bucket, status",
                $format,
                $start,
                $end
            ),
            ARRAY_A
        );
        // phpcs:enable

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map(
            static function ( $row ) {
                return [
                    'bucket' => (string) $row['bucket'],
                    'status' => (string) $row['status'],
                    'total'  => (int) $row['total'],
                ];
            },
            $rows
        );
    }
}
