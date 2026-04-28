<?php

namespace Texty;

defined( 'ABSPATH' ) || exit;

/**
 * Global notification settings.
 *
 * Stores compliance + global SMS toggles in a single option (`texty_notification_settings`)
 * and the opt-out phone list in `texty_opted_out_numbers`.
 *
 * @since 1.2.0
 */
class NotificationSettings {

    /**
     * Option key for notification compliance settings.
     */
    const OPTION_KEY = 'texty_notification_settings';

    /**
     * Option key for the opt-out phone list.
     */
    const OPT_OUT_KEY = 'texty_opted_out_numbers';

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
            'append_unsubscribe'   => false,
            'append_company_name'  => false,
        ];
    }

    /**
     * Get all settings (merged with defaults).
     *
     * @return array
     */
    public function all() {
        $stored = get_option( self::OPTION_KEY, [] );
        $stored = is_array( $stored ) ? $stored : [];

        return wp_parse_args( $stored, $this->defaults() );
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
        $existing = $this->all();
        $merged   = array_merge( $existing, $values );

        update_option( self::OPTION_KEY, $merged, false );

        return $merged;
    }

    /**
     * Get all opted-out numbers.
     *
     * @return string[]
     */
    public function opted_out_numbers() {
        $list = get_option( self::OPT_OUT_KEY, [] );

        return is_array( $list ) ? array_values( $list ) : [];
    }

    /**
     * Whether a number has opted out.
     *
     * @param string $number Phone number.
     *
     * @return bool
     */
    public function is_opted_out( $number ) {
        $normalized = $this->normalize_number( $number );

        if ( '' === $normalized ) {
            return false;
        }

        return in_array( $normalized, $this->opted_out_numbers(), true );
    }

    /**
     * Add a number to the opt-out list.
     *
     * @param string $number Phone number.
     *
     * @return void
     */
    public function opt_out( $number ) {
        $normalized = $this->normalize_number( $number );

        if ( '' === $normalized ) {
            return;
        }

        $list = $this->opted_out_numbers();

        if ( ! in_array( $normalized, $list, true ) ) {
            $list[] = $normalized;
            update_option( self::OPT_OUT_KEY, $list, false );

            /**
             * Fires after a phone number opts out of notifications.
             *
             * @since 1.2.0
             *
             * @param string $number The normalized phone number that opted out.
             */
            do_action( 'texty_number_opted_out', $normalized );
        }
    }

    /**
     * Remove a number from the opt-out list.
     *
     * @param string $number Phone number.
     *
     * @return void
     */
    public function opt_in( $number ) {
        $normalized = $this->normalize_number( $number );

        if ( '' === $normalized ) {
            return;
        }

        $list    = $this->opted_out_numbers();
        $updated = array_values( array_filter(
            $list,
            function ( $existing ) use ( $normalized ) {
                return $existing !== $normalized;
            }
        ) );

        if ( count( $updated ) !== count( $list ) ) {
            update_option( self::OPT_OUT_KEY, $updated, false );

            /**
             * Fires after a phone number opts back into notifications.
             *
             * @since 1.2.0
             *
             * @param string $number The normalized phone number.
             */
            do_action( 'texty_number_opted_in', $normalized );
        }
    }

    /**
     * Detect a compliance keyword (STOP / START) in an inbound message body.
     *
     * Matches common industry keywords used by carriers and aggregators:
     *   - STOP family:  STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT
     *   - START family: START, YES, UNSTOP
     *
     * @param string $body Inbound message body.
     *
     * @return string '' | 'opt_out' | 'opt_in'
     */
    public function detect_keyword( $body ) {
        if ( ! is_string( $body ) ) {
            return '';
        }

        $first = strtoupper( trim( $body ) );
        $first = preg_split( '/\s+/', $first );
        $first = isset( $first[0] ) ? $first[0] : '';

        $opt_out = [ 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' ];
        $opt_in  = [ 'START', 'YES', 'UNSTOP' ];

        /**
         * Filter the list of keywords that opt a recipient OUT of notifications.
         *
         * @since 1.2.0
         *
         * @param string[] $opt_out Default opt-out keywords (uppercase).
         */
        $opt_out = apply_filters( 'texty_opt_out_keywords', $opt_out );

        /**
         * Filter the list of keywords that opt a recipient back IN.
         *
         * @since 1.2.0
         *
         * @param string[] $opt_in Default opt-in keywords (uppercase).
         */
        $opt_in = apply_filters( 'texty_opt_in_keywords', $opt_in );

        if ( in_array( $first, $opt_out, true ) ) {
            return 'opt_out';
        }

        if ( in_array( $first, $opt_in, true ) ) {
            return 'opt_in';
        }

        return '';
    }

    /**
     * Normalize a phone number for comparison (digits + leading +).
     *
     * @param string $number Raw phone input.
     *
     * @return string
     */
    public function normalize_number( $number ) {
        if ( ! is_string( $number ) ) {
            return '';
        }

        $trimmed = trim( $number );

        if ( '' === $trimmed ) {
            return '';
        }

        $has_plus = '+' === substr( $trimmed, 0, 1 );
        $digits   = preg_replace( '/\D+/', '', $trimmed );

        if ( '' === $digits ) {
            return '';
        }

        return $has_plus ? '+' . $digits : $digits;
    }
}
