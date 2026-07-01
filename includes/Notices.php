<?php
/**
 * Texty admin-notice bootstrap.
 *
 * Owns the wp-kit `NoticeManager` and registers the REST controller that
 * powers plugin-ui's `<AdminNotice />` (`/texty/v1/notices/{admin,dismiss}`).
 *
 * Other modules surface notices by implementing `NoticeProviderInterface`
 * and registering with `texty()->notices()->register_provider(...)`.
 *
 * @package Texty
 */

namespace Texty;

use Texty\Dependencies\WeDevs\WPKit\AdminNotification\Contracts\NoticeProviderInterface;
use Texty\Dependencies\WeDevs\WPKit\AdminNotification\NoticeManager;
use Texty\Dependencies\WeDevs\WPKit\AdminNotification\NoticeRESTController;

defined( 'ABSPATH' ) || exit;

/**
 * Texty admin-notice bootstrap.
 */
class Notices {

    /**
     * Plugin prefix used for the notice option keys (dismissed list, etc.).
     */
    public const PREFIX = 'texty';

    /**
     * REST API namespace shared with the rest of the plugin.
     */
    public const REST_NAMESPACE = 'texty/v1';

    /**
     * Notice manager.
     *
     * @var NoticeManager|null
     */
    protected ?NoticeManager $manager = null;

    /**
     * Constructor — registers the REST routes lazily via rest_api_init.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Lazily build the notice manager.
     *
     * @return NoticeManager
     */
    public function get_manager(): NoticeManager {
        if ( null === $this->manager ) {
            $this->manager = new NoticeManager( self::PREFIX );
        }

        return $this->manager;
    }

    /**
     * Register a notice provider.
     *
     * @param NoticeProviderInterface $provider Provider implementation.
     *
     * @return self
     */
    public function register_provider( NoticeProviderInterface $provider ): self {
        $this->get_manager()->register_provider( $provider );

        return $this;
    }

    /**
     * Register the wp-kit notice REST controller.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        $notices = new NoticeRESTController( $this->get_manager(), self::REST_NAMESPACE );
        $notices->register_routes();
    }
}
