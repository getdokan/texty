<?php

namespace Texty;

/**
 * Manager Class
 */
class Admin {

    /**
     * Initialize
     */
    public function __construct() {
        new Admin\Menu();
        new Admin\Profile();

        if ( class_exists( 'WooCommerce' ) ) {
            new Admin\OrderSmsBox();
        }
    }
}
