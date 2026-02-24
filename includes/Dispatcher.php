<?php

namespace Texty;

use Texty\Integrations\Dokan;
use Texty\Integrations\WooCommerce;

/**
 * Dispatcher Class
 */
class Dispatcher {

    /**
     * Initialize
     */
    public function __construct() {

        // WordPress Events
        add_action( 'user_register', [ $this, 'user_register' ] );
        add_action( 'comment_post', [ $this, 'new_comment' ] );

        // Load integrations
        $this->register_integrations();
    }

    /**
     * Register integrations via hook for extensibility.
     *
     * @return void
     */
    private function register_integrations() {
        /**
         * Fires to allow registration of custom integrations.
         *
         * Default handler loads WooCommerce and Dokan integrations
         * when their respective plugins are active.
         */
        do_action( 'texty_register_integrations' );

        // Load built-in integrations conditionally
        if ( class_exists( 'WooCommerce' ) ) {
            new WooCommerce();
        }

        if ( class_exists( 'WeDevs_Dokan' ) ) {
            new Dokan();
        }
    }

    /**
     * Send message upon user registration
     *
     * @param int $user_id
     *
     * @return void
     */
    public function user_register( $user_id ) {
        $class    = texty()->notifications()->get( 'registration' );
        $notifier = new $class();

        $notifier->set_user( $user_id );
        $notifier->send();
    }

    /**
     * Send message upon a new comment
     *
     * @param int $comment_id
     *
     * @return void
     */
    public function new_comment( $comment_id ) {
        $class    = texty()->notifications()->get( 'comment' );
        $notifier = new $class();

        $notifier->set_comment( $comment_id );
        $notifier->send();
    }
}
