<?php

namespace Texty;

/**
 * Manager Class
 */
class Api {

    /**
     * All API Classes
     *
     * @var array
     */
    protected $classes;

    /**
     * Initialize
     */
    public function __construct() {
        $this->classes = [
            Api\Settings::class,
            Api\Notifications::class,
            Api\NotificationSettings::class,
            Api\Inbound::class,
            Api\Tools::class,
            Api\Status::class,
            Api\Send::class,
            Api\Metrics::class,
            Api\SettingsController::class,
            Api\Gateway::class,
        ];

        add_action( 'rest_api_init', [ $this, 'init_api' ] );
    }

    /**
     * Register APIs
     *
     * @return void
     */
    public function init_api() {
        foreach ( $this->classes as $class ) {
            $object = new $class();
            $object->register_routes();
        }
    }
}
