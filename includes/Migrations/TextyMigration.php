<?php
/**
 * Base class for all Texty DB migrations.
 *
 * @package Texty\Migrations
 */

namespace Texty\Migrations;

use WeDevs\WPKit\Migration\BaseMigration;

/**
 * Base class for all Texty DB migrations.
 *
 * Sets the option key wp-kit reads/writes to track schema version.
 */
abstract class TextyMigration extends BaseMigration {

    /**
     * The option key used to store the DB version.
     *
     * @var string
     */
    protected static string $db_version_key = 'texty_db_version';
}
