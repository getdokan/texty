<?php

namespace Texty\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * Secret credential handling
 *
 * Any field a gateway declares as `password` in `get_settings()` is a
 * secret: it is shown to the browser as a fixed mask, restored from storage
 * when the mask comes back unchanged on save, and scrubbed from logs.
 *
 * @since 2.1.0
 */
class Secrets {

    /**
     * Placeholder sent to the browser instead of a saved secret
     */
    const MASK = '••••••••';

    /**
     * Replacement used when scrubbing secrets from logged payloads
     */
    const REDACTED = '[REDACTED]';

    /**
     * Secrets shorter than this are not scrubbed, so a short value can't
     * wipe out unrelated text
     */
    const MIN_REDACT_LENGTH = 4;

    /**
     * Get the keys of a gateway's secret fields
     *
     * @since 2.1.0
     *
     * @param GatewayInterface $gateway
     *
     * @return string[]
     */
    public static function fields( GatewayInterface $gateway ) {
        $settings = $gateway->get_settings();
        $fields   = [];

        if ( ! is_array( $settings ) ) {
            return $fields;
        }

        foreach ( $settings as $key => $setting ) {
            if ( is_array( $setting ) && isset( $setting['type'] ) && 'password' === $setting['type'] ) {
                $fields[] = (string) $key;
            }
        }

        return $fields;
    }

    /**
     * Mask a single stored value
     *
     * @since 2.1.0
     *
     * @param mixed $value
     *
     * @return string Mask when a secret is saved, '' otherwise
     */
    public static function mask_value( $value ) {
        return is_scalar( $value ) && '' !== (string) $value ? self::MASK : '';
    }

    /**
     * Mask the secret fields of a stored credentials array
     *
     * @since 2.1.0
     *
     * @param GatewayInterface $gateway
     * @param mixed            $creds
     *
     * @return array
     */
    public static function mask( GatewayInterface $gateway, $creds ) {
        if ( ! is_array( $creds ) ) {
            return [];
        }

        foreach ( self::fields( $gateway ) as $field ) {
            if ( array_key_exists( $field, $creds ) ) {
                $creds[ $field ] = self::mask_value( $creds[ $field ] );
            }
        }

        return $creds;
    }

    /**
     * Mask the `value` of every secret field in a `get_settings()` array
     *
     * @since 2.1.0
     *
     * @param array $settings
     *
     * @return array
     */
    public static function mask_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            return [];
        }

        foreach ( $settings as $key => $setting ) {
            if ( is_array( $setting ) && isset( $setting['type'] ) && 'password' === $setting['type'] ) {
                $settings[ $key ]['value'] = self::mask_value( isset( $setting['value'] ) ? $setting['value'] : '' );
            }
        }

        return $settings;
    }

    /**
     * Swap any unchanged mask in submitted credentials back to the stored value
     *
     * An empty submitted value is kept as-is, so clearing a field removes
     * the stored secret.
     *
     * @since 2.1.0
     *
     * @param GatewayInterface $gateway
     * @param mixed            $submitted Credentials from the request
     * @param mixed            $stored    Credentials currently saved
     *
     * @return array
     */
    public static function restore( GatewayInterface $gateway, $submitted, $stored ) {
        $submitted = is_array( $submitted ) ? $submitted : [];
        $stored    = is_array( $stored ) ? $stored : [];

        foreach ( self::fields( $gateway ) as $field ) {
            if ( isset( $submitted[ $field ] ) && self::MASK === $submitted[ $field ] ) {
                $submitted[ $field ] = isset( $stored[ $field ] ) ? $stored[ $field ] : '';
            }
        }

        return $submitted;
    }

    /**
     * Get the saved secret values for a gateway
     *
     * @since 2.1.0
     *
     * @param GatewayInterface $gateway
     *
     * @return string[]
     */
    public static function values( GatewayInterface $gateway ) {
        $settings = $gateway->get_settings();
        $values   = [];

        if ( ! is_array( $settings ) ) {
            return $values;
        }

        foreach ( $settings as $setting ) {
            if ( ! is_array( $setting ) || ! isset( $setting['type'] ) || 'password' !== $setting['type'] ) {
                continue;
            }

            $value = isset( $setting['value'] ) && is_scalar( $setting['value'] ) ? (string) $setting['value'] : '';

            if ( strlen( $value ) >= self::MIN_REDACT_LENGTH ) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Replace every occurrence of the given secrets in a payload
     *
     * Walks arrays and objects recursively, so secrets echoed anywhere in a
     * provider response (or inside a WP_Error's data) are scrubbed.
     *
     * @since 2.1.0
     *
     * @param mixed    $payload
     * @param string[] $secrets
     *
     * @return mixed
     */
    public static function redact( $payload, array $secrets ) {
        if ( empty( $secrets ) ) {
            return $payload;
        }

        if ( is_string( $payload ) ) {
            return str_replace( $secrets, self::REDACTED, $payload );
        }

        if ( is_array( $payload ) ) {
            foreach ( $payload as $key => $value ) {
                $payload[ $key ] = self::redact( $value, $secrets );
            }

            return $payload;
        }

        if ( is_object( $payload ) ) {
            return self::redact( json_decode( wp_json_encode( $payload ), true ), $secrets );
        }

        return $payload;
    }
}
