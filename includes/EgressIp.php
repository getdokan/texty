<?php

namespace Texty;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Detects and tracks this site's outgoing (egress) IP addresses.
 *
 * Gateways that whitelist API callers by IP (see
 * Gateways\RequiresIpWhitelist) see the address a request *leaves* from —
 * not `$_SERVER['SERVER_ADDR']` (often private or the load balancer's
 * internal address) and not the site's DNS address (the inbound side). The
 * only reliable way to learn it is to ask an IP-echo service from PHP.
 *
 * Behind a load balancer every web node may leave from a different IP, so a
 * single detection only describes the node that served that request. This
 * class therefore keeps a site-wide list of observed IPs: each node adds its
 * own address (tagged with its hostname) when the admin panel is opened and
 * whenever a send succeeds or is rejected, so a node that isn't whitelisted
 * reports itself the first time it fails.
 *
 * Lookups are IPv4-only (the providers whitelist IPv4) and cached per node,
 * so the echo service is contacted at most once per node per cache period.
 *
 * @since 2.0.3
 */
class EgressIp {

    /**
     * Option holding the observed IP list (not autoloaded).
     */
    const OPTION = 'texty_egress_ips';

    /**
     * Per-node transient prefix for the detected IP.
     */
    const CACHE_PREFIX = 'texty_egress_ip_';

    /**
     * How long a node's detected IP is trusted before re-checking.
     */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * How long a failed lookup is remembered, so an offline node doesn't
     * retry the echo services on every send.
     */
    const FAILURE_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Detected (non-manual) entries unseen for this long are dropped.
     */
    const STALE_AFTER = 30 * DAY_IN_SECONDS;

    /**
     * Upper bound on stored entries.
     */
    const MAX_ENTRIES = 20;

    /**
     * Skip rewriting an unchanged entry seen within this window, so every
     * SMS doesn't cost an option write.
     */
    const TOUCH_INTERVAL = HOUR_IN_SECONDS;

    /**
     * Result values.
     */
    const RESULT_DETECTED = 'detected';
    const RESULT_ALLOWED  = 'allowed';
    const RESULT_BLOCKED  = 'blocked';

    /**
     * Name of the server (node) handling this request.
     *
     * @return string
     */
    public function host() {
        $host = function_exists( 'gethostname' ) ? gethostname() : '';

        if ( ! $host ) {
            $host = php_uname( 'n' );
        }

        return (string) $host;
    }

    /**
     * This node's outgoing IPv4 address.
     *
     * @param bool $refresh Bypass the per-node cache.
     *
     * @return string IPv4 address, or '' when it couldn't be determined.
     */
    public function detect( $refresh = false ) {
        $key = self::CACHE_PREFIX . md5( $this->host() );

        if ( ! $refresh ) {
            $cached = get_transient( $key );

            if ( 'none' === $cached ) {
                return '';
            }

            if ( is_string( $cached ) && $this->is_ipv4( $cached ) ) {
                return $cached;
            }
        }

        $ip = $this->lookup();

        set_transient( $key, '' !== $ip ? $ip : 'none', '' !== $ip ? self::CACHE_TTL : self::FAILURE_TTL );

        return $ip;
    }

    /**
     * Record a send outcome for this node's IP.
     *
     * Called by IP-whitelisted gateways after each send. Detection is cached,
     * so this costs one echo-service request per node per cache period.
     *
     * @param string $result  One of the RESULT_* constants.
     * @param string $gateway Gateway registry key.
     *
     * @return string The node's IP, or '' when unknown.
     */
    public function report( $result, $gateway = '' ) {
        try {
            $ip = $this->detect();

            if ( '' !== $ip ) {
                $this->record( $ip, $result, $gateway );
            }

            return $ip;
        } catch ( Throwable $e ) {
            return '';
        }
    }

    /**
     * Add or refresh an entry in the observed list.
     *
     * @param string $ip      IPv4 address.
     * @param string $result  One of the RESULT_* constants.
     * @param string $gateway Gateway registry key, if any.
     * @param bool   $manual  Whether an admin added it by hand.
     *
     * @return bool False for an invalid IP.
     */
    public function record( $ip, $result = self::RESULT_DETECTED, $gateway = '', $manual = false ) {
        if ( ! $this->is_ipv4( $ip ) ) {
            return false;
        }

        $list     = $this->load();
        $now      = time();
        $existing = isset( $list[ $ip ] ) ? $list[ $ip ] : null;

        // A plain re-detection must not hide an earlier allowed/blocked result.
        if ( $existing && self::RESULT_DETECTED === $result && self::RESULT_DETECTED !== $existing['result'] ) {
            $result = $existing['result'];
        }

        $unchanged = $existing
            && $existing['result'] === $result
            && $existing['host'] === ( $manual ? $existing['host'] : $this->host() )
            && ( $now - (int) $existing['last_seen'] ) < self::TOUCH_INTERVAL;

        if ( $unchanged ) {
            return true;
        }

        $list[ $ip ] = [
            'ip'        => $ip,
            'host'      => $manual ? ( $existing['host'] ?? '' ) : $this->host(),
            'result'    => $result,
            'gateway'   => '' !== $gateway ? $gateway : ( $existing['gateway'] ?? '' ),
            'manual'    => $manual || ! empty( $existing['manual'] ),
            'last_seen' => $now,
        ];

        $this->save( $list );

        return true;
    }

    /**
     * Observed IPs, most recently seen first.
     *
     * @return array<int, array{ip:string,host:string,result:string,gateway:string,manual:bool,last_seen:int}>
     */
    public function observed() {
        $list = array_values( $this->load() );

        usort(
            $list,
            static function ( $a, $b ) {
                return (int) $b['last_seen'] - (int) $a['last_seen'];
            }
        );

        return $list;
    }

    /**
     * Remove one IP from the list.
     *
     * @param string $ip IPv4 address.
     *
     * @return bool Whether it was present.
     */
    public function remove( $ip ) {
        $list = $this->load();

        if ( ! isset( $list[ $ip ] ) ) {
            return false;
        }

        unset( $list[ $ip ] );
        $this->save( $list );

        return true;
    }

    /**
     * Forget every observed IP and this node's cached detection.
     *
     * @return void
     */
    public function clear() {
        delete_option( self::OPTION );
        delete_transient( self::CACHE_PREFIX . md5( $this->host() ) );
    }

    /**
     * Run a request with cURL restricted to IPv4 for URLs under $url_prefix.
     *
     * Providers whitelist IPv4 addresses; on a dual-stack server the request
     * could otherwise leave over IPv6 and be rejected even though the
     * server's IPv4 address is whitelisted.
     *
     * @param string   $url_prefix Only requests to URLs starting with this are affected.
     * @param callable $callback   Performs the request and returns its result.
     *
     * @return mixed The callback's return value.
     */
    public function with_ipv4( $url_prefix, callable $callback ) {
        $force = static function ( $handle, $args, $url ) use ( $url_prefix ) {
            unset( $args );

            if ( 0 === strpos( (string) $url, $url_prefix ) && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
                curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            }
        };

        add_action( 'http_api_curl', $force, 10, 3 );

        try {
            return $callback();
        } finally {
            remove_action( 'http_api_curl', $force, 10 );
        }
    }

    /**
     * Ask the IP-echo services for this node's IPv4 address.
     *
     * @return string
     */
    protected function lookup() {
        /**
         * Filter the IP-echo services used to detect the outgoing IPv4 address.
         *
         * Each must answer a GET with the caller's IPv4 address as plain text.
         * Return an empty array to disable detection (admins can still add
         * IPs by hand).
         *
         * @since 2.0.3
         *
         * @param string[] $services Service URLs, tried in order.
         */
        $services = (array) apply_filters(
            'texty_egress_ip_services',
            [
                'https://api.ipify.org',
                'https://checkip.amazonaws.com',
                'https://ipv4.icanhazip.com',
            ]
        );

        foreach ( $services as $service ) {
            $service = (string) $service;

            $response = $this->with_ipv4(
                $service,
                static function () use ( $service ) {
                    return wp_remote_get( $service, [ 'timeout' => 3 ] );
                }
            );

            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                continue;
            }

            $ip = trim( (string) wp_remote_retrieve_body( $response ) );

            if ( $this->is_ipv4( $ip ) ) {
                return $ip;
            }
        }

        return '';
    }

    /**
     * Whether a string is a valid IPv4 address.
     *
     * @param mixed $ip Candidate.
     *
     * @return bool
     */
    public function is_ipv4( $ip ) {
        return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
    }

    /**
     * Load the list, dropping stale detected entries.
     *
     * @return array<string, array>
     */
    protected function load() {
        $list = get_option( self::OPTION, [] );

        if ( ! is_array( $list ) ) {
            return [];
        }

        $cutoff = time() - self::STALE_AFTER;

        return array_filter(
            $list,
            static function ( $entry ) use ( $cutoff ) {
                return is_array( $entry )
                    && isset( $entry['ip'], $entry['last_seen'], $entry['result'] )
                    && ( ! empty( $entry['manual'] ) || (int) $entry['last_seen'] >= $cutoff );
            }
        );
    }

    /**
     * Persist the list, keeping only the newest MAX_ENTRIES.
     *
     * @param array<string, array> $entries Entries keyed by IP.
     *
     * @return void
     */
    protected function save( array $entries ) {
        uasort(
            $entries,
            static function ( $a, $b ) {
                return (int) $b['last_seen'] - (int) $a['last_seen'];
            }
        );

        update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES, true ), false );
    }
}
