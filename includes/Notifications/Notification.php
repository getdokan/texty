<?php

namespace Texty\Notifications;

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
     * Return the message
     *
     * @return string
     */
    public function get_message() {
        return $this->get_message_raw();
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
     * Replace messages with global replacement keys
     *
     * @param string $message
     *
     * @return string
     */
    protected function replace_global_keys( $message ) {
        foreach ( $this->global_replacement_keys() as $search => $function ) {
            $message = str_replace( '{' . $search . '}', $function(), $message );
        }

        return $message;
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

        $numbers = [];
        $roles   = $this->get_recipients_raw();

        if ( ! ( is_array( $roles ) && $roles ) ) {
            return false;
        }

        foreach ( $roles as $role ) {
            // phpcs:disable
            $users = get_users( [
                'role'       => $role,
                'fields'     => 'ID',
                'meta_query' => [
                    [
                        'key' => 'texty_phone',
                    ],
                ],
            ] );
            // phpcs:enable

            if ( ! $users ) {
                continue;
            }

            $results = $wpdb->get_col(
                sprintf( "SELECT `meta_value` from $wpdb->usermeta WHERE `user_id` IN (%s) AND `meta_key` = 'texty_phone'", implode( ', ', $users ) ) // phpcs:ignore
            );

            foreach ( $results as $number ) {
                $numbers[] = $number;
            }
        }

        return $numbers;
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

        $content = $this->get_message();

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
