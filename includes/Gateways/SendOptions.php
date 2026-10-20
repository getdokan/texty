<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Per-message send options
 *
 * Options travel alongside `$to` and `$message` through `Gateways::send()`
 * and the send hooks. Gateways that don't support an option ignore it.
 *
 * - `from`        string Sender ID / number override ('' = gateway default)
 * - `unicode`     bool   Force unicode encoding
 * - `template_id` string Provider template / DLT ID
 * - `variables`   array  Template variables, keyed by provider variable name
 *
 * @since 2.1.0
 */
class SendOptions {

    /**
     * Default option values
     *
     * @since 2.1.0
     *
     * @return array
     */
    public static function defaults() {
        return [
            'from'        => '',
            'unicode'     => false,
            'template_id' => '',
            'variables'   => [],
        ];
    }

    /**
     * Fill in defaults and coerce types
     *
     * Unknown keys are kept so extensions can pass their own options.
     *
     * @since 2.1.0
     *
     * @param mixed $options
     *
     * @return array
     */
    public static function normalize( $options ) {
        $options = wp_parse_args( is_array( $options ) ? $options : [], self::defaults() );

        $options['from']        = is_scalar( $options['from'] ) ? trim( (string) $options['from'] ) : '';
        $options['unicode']     = (bool) $options['unicode'];
        $options['template_id'] = is_scalar( $options['template_id'] ) ? trim( (string) $options['template_id'] ) : '';
        $options['variables']   = is_array( $options['variables'] ) ? $options['variables'] : [];

        return $options;
    }
}
