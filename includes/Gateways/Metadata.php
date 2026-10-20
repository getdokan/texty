<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway metadata
 *
 * Describes a gateway for the UI and the send pipeline without any
 * per-gateway frontend code.
 *
 * @since 2.1.0
 */
class Metadata {

    /**
     * Gateway is verified against a live account
     */
    const STATUS_STABLE = 'stable';

    /**
     * Gateway is built from provider docs and not yet verified live
     */
    const STATUS_BETA = 'beta';

    /**
     * Default metadata
     *
     * - `status`            stable|beta
     * - `docs_url`          Provider API docs
     * - `signup_url`        Provider sign-up page
     * - `regions`           ISO country codes or region names; empty = global
     * - `features`          unicode, sender_id, template, balance_check (bool each)
     * - `validation`        live|format_only — whether validate() calls the provider
     * - `template_required` bool — sends fail without a template ID
     * - `sandbox`           bool — gateway offers a sandbox/test mode
     *
     * @since 2.1.0
     *
     * @return array
     */
    public static function defaults() {
        return [
            'status'            => self::STATUS_STABLE,
            'docs_url'          => '',
            'signup_url'        => '',
            'regions'           => [],
            'features'          => [
                'unicode'       => false,
                'sender_id'     => false,
                'template'      => false,
                'balance_check' => false,
            ],
            'validation'        => 'live',
            'template_required' => false,
            'sandbox'           => false,
        ];
    }

    /**
     * Merge metadata over the defaults and coerce types
     *
     * @since 2.1.0
     *
     * @param mixed $meta
     *
     * @return array
     */
    public static function normalize( $meta ) {
        $defaults = self::defaults();
        $meta     = is_array( $meta ) ? $meta : [];
        $features = isset( $meta['features'] ) && is_array( $meta['features'] ) ? $meta['features'] : [];

        $meta             = array_merge( $defaults, $meta );
        $meta['features'] = array_map( 'boolval', array_merge( $defaults['features'], $features ) );

        $meta['status']            = self::STATUS_BETA === $meta['status'] ? self::STATUS_BETA : self::STATUS_STABLE;
        $meta['docs_url']          = esc_url_raw( (string) $meta['docs_url'] );
        $meta['signup_url']        = esc_url_raw( (string) $meta['signup_url'] );
        $meta['regions']           = array_values( array_map( 'strval', (array) $meta['regions'] ) );
        $meta['validation']        = 'format_only' === $meta['validation'] ? 'format_only' : 'live';
        $meta['template_required'] = (bool) $meta['template_required'];
        $meta['sandbox']           = (bool) $meta['sandbox'];

        return $meta;
    }

    /**
     * Get metadata for any gateway, including ones that don't extend
     * `AbstractGateway`
     *
     * @since 2.1.0
     *
     * @param GatewayInterface $gateway
     *
     * @return array
     */
    public static function for_gateway( GatewayInterface $gateway ) {
        $meta = method_exists( $gateway, 'metadata' ) ? $gateway->metadata() : [];

        /**
         * Filter a gateway's metadata.
         *
         * @since 2.1.0
         *
         * @param array            $meta    Normalized metadata
         * @param GatewayInterface $gateway Gateway instance
         */
        return self::normalize( apply_filters( 'texty_gateway_metadata', self::normalize( $meta ), $gateway ) );
    }
}
