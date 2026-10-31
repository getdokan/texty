<?php

namespace Texty\Notifications;

use Closure;

abstract class Notification {

    /**
     * Name of the notification
     *
     * @var string
     */
    protected $title;

    /**
     * The notification ID
     *
     * @var string
     */
    protected $id;

    /**
     * Type of the notification
     *
     * @var string
     */
    protected $type = 'role';

    /**
     * The default message
     *
     * @var string
     */
    protected $default;

    /**
     * Default recipients
     *
     * @var mixed
     */
    protected $default_recipients;

    /**
     * Notification group
     *
     * @var string
     */
    protected $group = 'wp';

    /**
     * Get the title
     *
     * @return string
     */
    public function get_title() {
        return $this->title;
    }

    /**
     * Get the ID
     *
     * @return string
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Type of the message
     *
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Type of the message
     *
     * @return string
     */
    public function get_group() {
        return $this->group;
    }

    /**
     * Return the default message
     *
     * @return string
     */
    public function get_default_message() {
        return $this->default;
    }

    /**
     * Return the message
     *
     * @return string
     */
    public function get_message_raw() {
        $settings = $this->settings();

        if ( isset( $settings['message'] ) ) {
            return $settings['message'];
        }

        return $this->get_default_message();
    }

    /**
     * Message already rendered elsewhere (e.g. at queue time), used by send()
     * instead of rendering again.
     *
     * @var string|null
     */
    protected $prepared_message = null;

    /**
     * Use an already-rendered message for the next send().
     *
     * The background queue renders a notification when it's queued; passing
     * that text here makes send() use it as-is instead of calling
     * get_message() again, so `{placeholder}` callbacks run once.
     * `texty_notification_message*` filters still apply in send().
     *
     * @since 2.0.3
     *
     * @param string|null $message Rendered message, or null to render normally.
     *
     * @return self
     */
    public function set_prepared_message( $message ) {
        $this->prepared_message = null === $message ? null : (string) $message;

        return $this;
    }

    /**
     * The message send() will use: the prepared one if set, else get_message().
     *
     * @since 2.0.3
     *
     * @return string
     */
    protected function message_for_send() {
        return null !== $this->prepared_message ? $this->prepared_message : $this->get_message();
    }

    /**
     * Return the message, with `{placeholders}` replaced.
     *
     * Subclasses that bind an object (order, user, comment) override this to
     * pass their built-in values to render_placeholders().
     *
     * @return string
     */
    public function get_message() {
        return $this->render_placeholders( $this->get_message_raw() );
    }

    /**
     * Placeholders this notification supports, after third-party filters.
     *
     * Each entry is `'token' => [ 'label' => string, 'value' => callable|null ]`:
     * - `label` is shown in the UI ("Available variables", the order Send SMS box).
     * - `value` is null for built-in tokens (resolved by the notification
     *   itself), or a callable `function ( Notification $notification, string $token ): string`.
     *
     * Third parties can add, override or remove placeholders:
     *
     *     add_filter( 'texty_notification_placeholders', function ( $placeholders, $notification ) {
     *         // Add.
     *         if ( $notification instanceof \Texty\Notifications\WC\Base ) {
     *             $placeholders['tracking_number'] = [
     *                 'label' => __( 'Tracking number', 'my-plugin' ),
     *                 'value' => function ( $notification ) {
     *                     $order = $notification->get_order();
     *                     return $order ? (string) $order->get_meta( '_tracking_number' ) : '';
     *                 },
     *             ];
     *         }
     *         // Override a built-in value.
     *         $placeholders['site_name']['value'] = function () { return 'My Shop'; };
     *         // Remove (no longer listed, and left untouched in messages).
     *         unset( $placeholders['items'] );
     *
     *         return $placeholders;
     *     }, 10, 2 );
     *
     * Shorthand: an entry may also be just a value; the label is then derived
     * from the token. Only a Closure, an invokable object or an
     * `[ $object_or_class, 'method' ]` array is treated as a callback — a
     * string is always literal text (so `'time'` means the text "time", never
     * a call to time()).
     *
     * @since 2.0.3
     *
     * @return array<string, array{label:string, value:callable|null}>
     */
    public function placeholders() {
        $placeholders = [];

        foreach ( array_keys( (array) $this->replacement_keys() ) as $token ) {
            $placeholders[ $token ] = [
                'label' => self::placeholder_label( $token ),
                'value' => null,
            ];
        }

        foreach ( (array) $this->global_replacement_keys() as $token => $function ) {
            $placeholders[ $token ] = [
                'label' => self::placeholder_label( $token ),
                'value' => static function () use ( $function ) {
                    return is_callable( $function ) ? (string) call_user_func( $function ) : '';
                },
            ];
        }

        /**
         * Filter the placeholders available to every notification.
         *
         * Add, override (set `value`) or remove (`unset`) entries. Changes are
         * reflected both in the UI token lists and in message replacement.
         *
         * @since 2.0.3
         *
         * @param array        $placeholders `token => [ 'label' => string, 'value' => callable|null ]`.
         * @param Notification $notification The notification.
         */
        $placeholders = apply_filters( 'texty_notification_placeholders', $placeholders, $this );

        /**
         * Filter the placeholders of one notification, by id
         * (e.g. `texty_notification_placeholders_order_customer_processing`).
         *
         * @since 2.0.3
         *
         * @param array        $placeholders Placeholders after the general filter.
         * @param Notification $notification The notification.
         */
        $placeholders = apply_filters( 'texty_notification_placeholders_' . $this->get_id(), $placeholders, $this );

        return self::normalize_placeholders( $placeholders );
    }

    /**
     * Replace `{placeholders}` in a message.
     *
     * For each placeholder still present after filtering: a callable `value`
     * (a third-party addition or override) wins; otherwise the built-in value
     * from `$values` is used. Removed placeholders are left untouched.
     *
     * @since 2.0.3
     *
     * @param string               $message Message template.
     * @param array<string, mixed> $values  Built-in token => value for the bound object.
     *
     * @return string
     */
    public function render_placeholders( $message, array $values = [] ) {
        $message = (string) $message;

        foreach ( $this->placeholders() as $token => $placeholder ) {
            $search = '{' . $token . '}';

            if ( false === strpos( $message, $search ) ) {
                continue;
            }

            if ( self::is_placeholder_callback( $placeholder['value'] ) ) {
                $value = call_user_func( $placeholder['value'], $this, $token );
            } elseif ( array_key_exists( $token, $values ) ) {
                $value = $values[ $token ];
            } else {
                continue;
            }

            $message = str_replace( $search, self::placeholder_string( $value ), $message );
        }

        return $message;
    }

    /**
     * Normalize filtered placeholders to `token => [ label, value ]`.
     *
     * @param mixed $placeholders Filtered placeholders.
     *
     * @return array<string, array{label:string, value:callable|null}>
     */
    protected static function normalize_placeholders( $placeholders ) {
        $normalized = [];

        foreach ( (array) $placeholders as $token => $placeholder ) {
            $token = trim( (string) $token, " \t\n\r\0\x0B{}" );

            if ( '' === $token || false === $placeholder || null === $placeholder ) {
                continue;
            }

            if ( ! is_array( $placeholder ) ) {
                // Shorthand: a callable or a fixed value.
                $placeholder = [ 'value' => $placeholder ];
            }

            $value = array_key_exists( 'value', $placeholder ) ? $placeholder['value'] : null;

            if ( null !== $value && ! self::is_placeholder_callback( $value ) ) {
                $fixed = self::placeholder_string( $value );
                $value = static function () use ( $fixed ) {
                    return $fixed;
                };
            }

            $normalized[ $token ] = [
                'label' => isset( $placeholder['label'] ) && '' !== (string) $placeholder['label'] ? (string) $placeholder['label'] : self::placeholder_label( $token ),
                'value' => $value,
            ];
        }

        return $normalized;
    }

    /**
     * Whether a placeholder value is a callback.
     *
     * Deliberately excludes strings: a plain string value is literal text even
     * when it happens to name a PHP function.
     *
     * @param mixed $value Value.
     *
     * @return bool
     */
    protected static function is_placeholder_callback( $value ) {
        if ( $value instanceof Closure ) {
            return true;
        }

        if ( is_object( $value ) || is_array( $value ) ) {
            return is_callable( $value );
        }

        return false;
    }

    /**
     * Human label for a token: `order_total` → "Order Total".
     *
     * @param string $token Token without braces.
     *
     * @return string
     */
    protected static function placeholder_label( $token ) {
        return ucwords( str_replace( '_', ' ', (string) $token ) );
    }

    /**
     * Coerce a placeholder value to a string (null → '', objects with
     * __toString such as WC_DateTime → their string).
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    protected static function placeholder_string( $value ) {
        if ( null === $value || is_array( $value ) ) {
            return '';
        }

        if ( is_object( $value ) && ! method_exists( $value, '__toString' ) ) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Get replacement keys
     *
     * @return array
     */
    public function replacement_keys() {
        return __return_empty_array();
    }

    /**
     * Global replacement keys
     *
     * @return array
     */
    public function global_replacement_keys() {
        return [
            'site_name' => 'get_bloginfo',
            'site_url'  => 'home_url',
        ];
    }

    /**
     * Replace the global and third-party placeholders in a message.
     *
     * Kept for subclasses (e.g. texty-pro automations) that replace their own
     * tokens first and then call this: it applies every placeholder that has
     * a value callable — the global keys plus anything added or overridden
     * through `texty_notification_placeholders` — so third-party placeholders
     * work for those notifications too.
     *
     * Limitation: by the time this runs, such a subclass has already replaced
     * its own built-in tokens, so overriding or removing *those* tokens via
     * the filter has no effect there. Subclasses should pass their values to
     * render_placeholders( $message, $values ) instead to fully support it.
     *
     * @param string $message
     *
     * @return string
     */
    protected function replace_global_keys( $message ) {
        return $this->render_placeholders( $message );
    }

    /**
     * Return recipients
     *
     * @return array
     */
    public function get_recipients_raw() {
        $settings = $this->settings();

        if ( isset( $settings['recipients'] ) ) {
            return $settings['recipients'];
        }

        return $this->default_recipients;
    }

    /**
     * Return recipients
     *
     * @return array
     */
    public function get_recipients() {
        return $this->get_recipients_raw();
    }

    /**
     * Get phone numbers by roles
     *
     * @return array
     */
    protected function get_numbers_by_roles() {
        global $wpdb;

        $roles = $this->get_recipients_raw();

        if ( ! ( is_array( $roles ) && $roles ) ) {
            return false;
        }

        // One user query across every role (a user holding two of the roles
        // comes back once), then one meta query for all their numbers —
        // instead of two queries per role.
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $user_ids = get_users(
            [
                'role__in'    => array_values( $roles ),
                'fields'      => 'ID',
                'count_total' => false,
                'meta_query'  => [
                    [
                        'key'     => 'texty_phone',
                        'compare' => 'EXISTS',
                    ],
                ],
            ]
        );
        // phpcs:enable

        if ( ! $user_ids ) {
            return [];
        }

        $user_ids     = array_map( 'absint', $user_ids );
        $placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $numbers = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'texty_phone' AND user_id IN ({$placeholders})",
                $user_ids
            )
        );
        // phpcs:enable

        return is_array( $numbers ) ? $numbers : [];
    }

    /**
     * Check if the gateway is enabled
     *
     * @return bool
     */
    public function enabled() {
        $settings = $this->settings();
        $enabled  = isset( $settings['enabled'] ) && $settings['enabled'] === true;

        /**
         * Filter whether a notification is enabled.
         *
         * @param bool         $enabled      Whether the notification is enabled
         * @param string       $id           The notification ID
         * @param Notification $notification The notification instance
         */
        return apply_filters( 'texty_notification_enabled', $enabled, $this->get_id(), $this );
    }

    /**
     * Get the notification settings
     *
     * @return array
     */
    public function settings() {
        $settings = texty()->notifications()->settings();
        $value    = isset( $settings[ $this->get_id() ] ) ? $settings[ $this->get_id() ] : [];

        /**
         * Filter the notification settings.
         *
         * @param array        $value        The notification settings
         * @param string       $id           The notification ID
         * @param Notification $notification The notification instance
         */
        return apply_filters( 'texty_notification_settings', $value, $this->get_id(), $this );
    }

    /**
     * Send message to recipients
     *
     * @return bool
     */
    public function send(): bool {
        if ( ! $this->enabled() ) {
            return false;
        }

        /**
         * Filter the recipients for a notification.
         *
         * @param array        $recipients   The recipient phone numbers
         * @param Notification $notification The notification instance
         */
        $recipients = apply_filters( 'texty_notification_recipients', $this->get_recipients(), $this );

        // Drop nulls / empty strings before deduping — these can leak in
        // from an empty `texty_phone` meta or a third-party filter.
        $recipients = is_array( $recipients ) ? array_values( array_filter( $recipients ) ) : [];

        if ( ! $recipients ) {
            return false;
        }

        // Check unique recipients numbers
        $recipients = array_unique( $recipients );

        $content = $this->message_for_send();

        /**
         * Filter the notification message content.
         *
         * @param string       $content      The message content
         * @param Notification $notification The notification instance
         */
        $content = apply_filters( 'texty_notification_message', $content, $this );

        /**
         * Filter the message for a specific notification type.
         *
         * @param string       $content      The message content
         * @param Notification $notification The notification instance
         */
        $content = apply_filters( 'texty_notification_message_' . $this->get_id(), $content, $this );

        /**
         * Fires before the notification send loop.
         *
         * @param Notification $notification The notification instance
         * @param array        $recipients   The recipient phone numbers
         * @param string       $content      The message content
         */
        do_action( 'texty_before_notification', $this, $recipients, $content );

        $gateway = texty()->gateways();

        // Stash the active notification so the after-send logger can attach
        // notification_id / notification_group to each SmsStat row without
        // threading them through the gateway pipeline.
        texty()->notifications()->set_active( $this );

        try {
            foreach ( $recipients as $number ) {
                if ( empty( $number ) ) {
                    continue;
                }

                $gateway->send( $number, $content );
            }
        } finally {
            texty()->notifications()->clear_active();
        }

        /**
         * Fires after the notification send loop.
         *
         * @param Notification $notification The notification instance
         * @param array        $recipients   The recipient phone numbers
         * @param string       $content      The message content
         */
        do_action( 'texty_after_notification', $this, $recipients, $content );

        return true;
    }
}
