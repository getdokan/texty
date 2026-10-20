<?php
/**
 * Schema introspection helpers shared by Texty migrations.
 *
 * Lives outside the migration class hierarchy on purpose — wp-kit's
 * `BaseMigration::run()` auto-invokes every public/protected static on the
 * migration class as a step, so helpers attached to that hierarchy would be
 * called with no arguments and crash. Put them here, call as
 * `Schema::column_exists(...)` from any migration.
 *
 * @package Texty\Migrations
 * @since   2.0.0
 */

namespace Texty\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Schema introspection helpers.
 */
final class Schema {

    /**
     * Whether a table exists in the current database.
     *
     * @param string $table Fully-prefixed table name.
     *
     * @return bool
     * @since 2.0.0
     */
    public static function table_exists( string $table ): bool {
        global $wpdb;

        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );

        return $found === $table;
    }

    /**
     * Whether a column exists on a table.
     *
     * @param string $table  Fully-prefixed table name.
     * @param string $column Column name.
     *
     * @return bool
     * @since 2.0.0
     */
    public static function column_exists( string $table, string $column ): bool {
        global $wpdb;

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            )
        );

        return $found === $column;
    }

    /**
     * Whether an index exists on a table.
     *
     * @param string $table Fully-prefixed table name.
     * @param string $index Index name.
     *
     * @return bool
     * @since 2.0.0
     */
    public static function index_exists( string $table, string $index ): bool {
        global $wpdb;

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
                 LIMIT 1',
                $table,
                $index
            )
        );

        return $found === $index;
    }
}
