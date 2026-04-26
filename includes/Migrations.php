<?php
/**
 * Texty migrations bootstrap.
 *
 * Registers all schema migrations with wp-kit and runs pending ones on boot.
 *
 * @package Texty
 */

namespace Texty;

use Texty\Migrations\TextyMigration;
use Texty\Migrations\V_2_0_0;
use WeDevs\WPKit\Migration\MigrationManager;
use WeDevs\WPKit\Migration\MigrationRegistry;

/**
 * Texty migrations bootstrap.
 */
class Migrations {

    /**
     * Plugin prefix used for the migration log/lock options.
     */
    const PREFIX = 'texty';

    /**
     * Migration manager.
     *
     * @var MigrationManager|null
     */
    protected ?MigrationManager $manager = null;

    /**
     * Constructor — defers actual upgrade until plugins_loaded so the
     * datastore (and any third-party gateway/notification registrations)
     * are wired in first.
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'maybe_upgrade' ], 5 );
    }

    /**
     * Build the registry and run any pending migrations.
     *
     * Safe to call repeatedly — wp-kit's MigrationRegistry skips migrations
     * whose version is <= the stored DB version.
     *
     * @return void
     */
    public function maybe_upgrade(): void {
        $manager = $this->get_manager();

        if ( ! $manager->is_upgrade_required() ) {
            return;
        }

        try {
            $manager->do_upgrade();
            // After all migrations finish, sync the stored DB version up to
            // the plugin version so the gap doesn't reopen on the next boot.
            $manager->get_registry()->update_db_version_to_current();
        } catch ( \Throwable $e ) {
            error_log( sprintf( 'Texty migration failed: %s', $e->getMessage() ) );
        }
    }

    /**
     * Lazily build the manager.
     *
     * @return MigrationManager
     */
    public function get_manager(): MigrationManager {
        if ( null === $this->manager ) {
            $registry = new MigrationRegistry( TextyMigration::get_db_version_key(), TEXTY_VERSION );
            $registry->register_many( $this->get_migrations() );

            $this->manager = new MigrationManager( $registry, self::PREFIX );
        }

        return $this->manager;
    }

    /**
     * Map of version → migration class. Add new entries in ascending order
     * as the schema evolves; wp-kit sorts by version_compare before running.
     *
     * @return array<string, string>
     */
    protected function get_migrations(): array {
        return [
            '2.0.0' => V_2_0_0::class,
        ];
    }
}
