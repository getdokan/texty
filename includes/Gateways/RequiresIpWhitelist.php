<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Optional contract for gateways that only accept API requests from
 * whitelisted server IPs (e.g. SSL Wireless iSMS Plus).
 *
 * Implementing it adds an "IP whitelist" section to the gateway's settings
 * page listing this site's outgoing IPs with copy buttons. The gateway should
 * also report each send's outcome through `texty()->egress_ip()->report()`
 * so blocked servers behind a load balancer show up in that list.
 *
 * @since 2.0.3
 */
interface RequiresIpWhitelist {

    /**
     * URL of the provider page where IPs are whitelisted (or its portal).
     *
     * @return string
     */
    public function ip_whitelist_url();
}
