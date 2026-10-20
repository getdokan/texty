<?php
/**
 * Base class for all Texty DB migrations.
 *
 * @package Texty\Migrations
 * @since   2.0.0
 */

namespace Texty\Migrations;

use Texty\Dependencies\WeDevs\WPKit\Migration\BaseMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all Texty DB migrations.
 *
 * Sets the option key wp-kit reads/writes to track schema version. Schema
 * introspection helpers live on `Texty\Migrations\Schema` so they don't get
 * picked up by `BaseMigration::run()`'s public-static auto-discovery.
 */
abstract class TextyMigration extends BaseMigration {

    /**
     * The option key used to store the DB version.
     *
     * @var string
     */
    protected static string $db_version_key = 'texty_db_version';
}
