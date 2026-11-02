<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract for gateways that link to their provider's API docs.
 *
 * Kept separate from GatewayInterface so existing third-party gateways keep
 * working unchanged; implement it to show a "View documentation" link on the
 * gateway's settings page.
 *
 * @since 2.0.3
 */
interface HasDocLink {

    /**
     * URL of the provider's API documentation.
     *
     * @return string
     */
    public function doc_link();
}
