<?php

namespace Texty\Gateways;

interface GatewayInterface {

    /**
     * Send a text
     *
     * Since 2.1.0 `Gateways::send()` passes per-message options (see
     * `SendOptions`) as a third argument. It can't be part of this
     * signature without breaking existing implementations, so declare
     * `$options = []` in your class to receive it. Return an array on
     * success (include `reference_id`) or a WP_Error; extending
     * `AbstractGateway` handles this for you.
     *
     * @param string $to
     * @param string $message
     *
     * @return mixed
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
}
