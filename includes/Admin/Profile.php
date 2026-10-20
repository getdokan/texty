<?php

namespace Texty\Admin;

/**
 * Profile Class
 */
class Profile {

    /**
     * Initialize
     */
    public function __construct() {
        add_filter( 'user_contactmethods', [ $this, 'add_contact_methods' ], 8 );
    }

    /**
     * Add phone number as contact method
     *
     * @param array $methods
     *
     * @return array
     */
    public function add_contact_methods( $methods ) {
        $methods['texty_phone'] = __( 'Phone Number (Texty)', 'texty' );

        return $methods;
    }
}
