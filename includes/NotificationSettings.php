<?php

namespace Texty;

defined( 'ABSPATH' ) || exit;

/**
 * Global notification settings.
 *
 * Stores compliance + global SMS toggles in a single option (`texty_notification_settings`).
 *
 * @since 2.0.0
 */
class NotificationSettings {

    /**
     * Option key for notification compliance settings.
     */
    const OPTION_KEY = 'texty_notification_settings';

    /**
     * Default settings.
     *
     * @return array
     */
    public function defaults() {
        return [
            'admin_phone'          => '',
            'global_sender_id'     => '',
            'pause_all'            => false,
            'append_company_name'  => false,
        ];
    }

    /**
     * Get all settings (merged with defaults).
     *
     * The `admin_phone` value is read-only and is always derived from the
     * active gateway's "From Number" credential — it is never persisted in
     * this option.
     *
     * @return array
     */
    public function all() {
        $stored = get_option( self::OPTION_KEY, [] );
        $stored = is_array( $stored ) ? $stored : [];

        $values = wp_parse_args( $stored, $this->defaults() );

        $values['admin_phone'] = $this->gateway_phone();

        return $values;
    }

    /**
     * Get the active gateway's "From Number".
     *
     * @return string
     */
    public function gateway_phone() {
        $gateway = texty()->settings()->gateway();

        if ( ! $gateway ) {
            return '';
        }

        $creds = texty()->settings()->get( $gateway );

        if ( ! is_array( $creds ) || empty( $creds['from'] ) ) {
            return '';
        }

        return (string) $creds['from'];
    }

    /**
     * Get a single setting value.
     *
     * @param string $key Setting key.
     *
     * @return mixed
     */
    public function get( $key ) {
        $all = $this->all();

        return isset( $all[ $key ] ) ? $all[ $key ] : null;
    }

    /**
     * Persist settings (merging with existing).
     *
     * @param array $values New values.
     *
     * @return array Final stored values.
     */
    public function update( array $values ) {
        // admin_phone is derived from the active gateway, never stored.
        unset( $values['admin_phone'] );

        $existing = get_option( self::OPTION_KEY, [] );
        $existing = is_array( $existing ) ? $existing : [];
        $existing = wp_parse_args( $existing, $this->defaults() );
        unset( $existing['admin_phone'] );

        $merged = array_merge( $existing, $values );

        update_option( self::OPTION_KEY, $merged, false );

        return $this->all();
    }
}
