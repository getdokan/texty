<?php
/**
 * Texty migrations bootstrap.
 *
 * Wires wp-kit's MigrationManager and the migration REST controller, and
 * registers the migration NoticeProvider into the central notice bootstrap
 * (`Texty\Notices`). The "database update required" notice surfaces through
 * `/texty/v1/notices/admin` like any other notice — migrations don't own
 * the notice plumbing.
 *
 * Migrations are NOT auto-applied on boot — the user has to click "Update
 * database" on the admin notice, which POSTs to MigrationRESTController.
 *
 * @package Texty
 */

namespace Texty;

use Texty\Migrations\NoticeProvider;
use Texty\Migrations\TextyMigration;
use Texty\Migrations\V_2_0_0;
use WeDevs\WPKit\Migration\MigrationHooks;
use WeDevs\WPKit\Migration\MigrationManager;
use WeDevs\WPKit\Migration\MigrationRegistry;
use WeDevs\WPKit\Migration\MigrationRESTController;

defined( 'ABSPATH' ) || exit;

/**
 * Texty migrations bootstrap.
 */
class Migrations {

    /**
     * Plugin prefix used for the migration log/lock options.
     */
    public const PREFIX = 'texty';

    /**
     * REST API namespace.
     */
    public const REST_NAMESPACE = 'texty/v1';

    /**
     * Migration manager.
     *
     * @var MigrationManager|null
     */
    protected ?MigrationManager $manager = null;

    /**
     * Constructor — registers the migration notice provider, the post-upgrade
     * hooks, and the REST routes.
     */
    public function __construct() {
        texty()->notices()->register_provider( new NoticeProvider() );

        // wp-kit syncs db_version_to_current after a successful upgrade via
        // this hook subscription; without it the option may lag behind a
        // plugin-version bump that ships no new migrations.
        $hooks = new MigrationHooks( $this->get_manager(), self::PREFIX );
        $hooks->register();

        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Lazily build the migration manager.
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
     * Whether any migration is pending.
     *
     * @return bool
     */
    public function is_upgrade_required(): bool {
        return $this->get_manager()->is_upgrade_required();
    }

    /**
     * Register the wp-kit migration REST controller.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        $migrations = new MigrationRESTController( $this->get_manager(), self::REST_NAMESPACE );
        $migrations->register_routes();
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
