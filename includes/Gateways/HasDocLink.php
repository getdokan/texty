<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract for gateways that link to a sign-up / docs page.
 *
 * Kept separate from GatewayInterface so existing third-party gateways keep
 * working unchanged; implement it to show a "Get your account" link on the
 * gateway's settings page.
 *
 * @since 2.0.3
 */
interface HasDocLink {

    /**
     * URL where the user can create an account or find their credentials.
     *
     * @return string
     */
    public function doc_link();
}
