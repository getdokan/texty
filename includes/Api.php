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
            Api\Tools::class,
            Api\Status::class,
            Api\Send::class,
            Api\Metrics::class,
            Api\SettingsController::class,
            Api\Gateway::class,
            Api\Logs::class,
        ];

        add_action( 'rest_api_init', [ $this, 'init_api' ] );
    }

    /**
     * Register APIs
     *
     * @return void
     */
    public function init_api() {
        $classes = apply_filters( 'texty_rest_api_class_map', $this->classes );

        foreach ( $classes as $class ) {
            $object = new $class();
            // check if object is instance of WP_REST_Controller
            if ( ! is_a( $object, 'WP_REST_Controller' ) ) {
                continue;
            }
            $object->register_routes();
        }
    }
}
