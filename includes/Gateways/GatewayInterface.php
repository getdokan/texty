<?php

namespace Texty\Gateways;

interface GatewayInterface {

    /**
     * Send a text
     *
     * @param string $to
     * @param string $message
     *
     * @return void
     */
    public function send( $to, $message );

    /**
     * Returns URL to the logo
     *
     * @return string
     */
    public function logo();

    /**
     * Get the gateway name
     *
     * @return string
     */
    public function name();

    /**
     * Get the gateway description
     *
     * @return string
     */
    public function description();

    /**
     * Get gateway credential
     *
     * @return array
     */
    public function get_settings();

    /**
     * Validate a REST API request
     *
     * @param WP_REST_Request $request
     *
     * @return WP_Error|true
     */
    public function validate( $request );

    /**
     * Get the display order for this gateway.
     *
     * Lower values appear first in the gateway dropdown.
     * Default is 10. Use higher values (e.g. 99) to push gateways to the end.
     *
     * @return int
     */
    public function order();
}
