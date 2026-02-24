<?php

namespace Texty;

/**
 * Notification Class
 */
class Notifications {

    /**
     * Option key to hold the notifications
     */
    const OPTION_KEY = 'texty_notifications';

    /**
     * Notifications
     *
     * @var array
     */
    private $notifications = [];

    /**
     * Whether built-in notifications have been registered
     *
     * @var bool
     */
    private $registered = false;

    /**
     * Register a notification
     *
     * @param string $key   Notification identifier
     * @param string $class Fully qualified class name
     *
     * @return void
     */
    public function register( $key, $class ) {
        $this->notifications[ $key ] = $class;
    }

    /**
     * Get a notification class
     *
     * @param string $key
     *
     * @return false|string
     */
    public function get( $key ) {
        $notifications = $this->all();

        if ( array_key_exists( $key, $notifications ) ) {
            return $notifications[ $key ];
        }

        return false;
    }

    /**
     * Get available notification classes
     *
     * @return array
     */
    public function all() {
        if ( ! $this->registered ) {
            $this->notifications = [
                'registration' => __NAMESPACE__ . '\Notifications\WP\Registration',
                'comment'      => __NAMESPACE__ . '\Notifications\WP\Comment',
            ];

            /**
             * Fires to allow registration of custom notifications.
             *
             * @param Notifications $manager The notifications manager instance
             */
            do_action( 'texty_register_notifications', $this );

            $this->registered = true;
        }

        return apply_filters( 'texty_available_notifications', $this->notifications );
    }

    /**
     * Get the name of the groups
     *
     * @return void
     */
    public function get_groups() {
        return apply_filters( 'texty_notification_groups', [ // phpcs:ignore
            'wp' => [
                'title'       => __( 'WordPress', 'texty' ),
                'description' => '',
                'available'   => true,
            ],
            'wc' => [
                'title'       => __( 'WooCommerce', 'texty' ),
                'description' => '',
                'available'   => class_exists( 'WooCommerce' ) ? true : false,
            ],
            'dokan' => [
                'title'       => __( 'Dokan', 'texty' ),
                'description' => '',
                'available'   => class_exists( 'WeDevs_Dokan' ) ? true : false,
            ],
        ] ); // phpcs:ignore
    }

    /**
     * Retreive all the settings
     *
     * @return array
     */
    public function settings() {
        return get_option( self::OPTION_KEY, [] );
    }
}
