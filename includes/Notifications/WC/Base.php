<?php

namespace Texty\Notifications\WC;

use Texty\Notifications\Notification;
use WC_Order_Item_Product;

class Base extends Notification {

    /**
     * @var object
     */
    protected $order;

    /**
     * Set the user ID
     *
     * @param mixed $order
     *
     * @return self
     */
    public function set_order( $order ) {
        $this->order = $order;

        return $this;
    }

    /**
     * Return the message
     *
     * @return string
     */
    public function get_message() {
        $message = parent::get_message_raw();

        if ( ! $this->order ) {
            return $message;
        }

        foreach ( $this->replacement_keys() as $search => $method ) {
            $value = method_exists( $this->order, $method ) ? $this->order->$method() : '';

            // WC accessors like `get_date_paid()` return null on unpaid orders
            // and `WC_DateTime` objects on paid ones — normalize both to a
            // string before any string handling. PHP 8.1 deprecates passing
            // null to str_replace's $replace argument.
            if ( null === $value ) {
                $value = '';
            } elseif ( ! is_scalar( $value ) ) {
                $value = (string) $value;
            }

            if ( 'order_total' === $search ) {
                $value = wp_strip_all_tags( html_entity_decode( (string) $value ) );
            }

            if ( 'items' === $search ) {
                $value = $this->get_items();
            }

            $message = str_replace( '{' . $search . '}', (string) $value, $message );
        }

        $message = $this->replace_global_keys( $message );

        return $message;
    }

    /**
     * Get product items from the order
     *
     * @return string
     */
    protected function get_items() {
        $products = [];

        foreach ( $this->order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();

            // A product may have been deleted after the order was placed —
            // `get_product()` then returns false. Fall back to the line-item
            // name stored on the order so building the message doesn't fatal.
            $name = $product ? $product->get_name() : $item->get_name();

            $products[] = sprintf( '%s x %d', $name, $item->get_quantity() );
        }

        $names = implode( "\n", $products );

        return $names;
    }

    /**
     * Return recipients
     *
     * @return array
     */
    public function get_recipients() {
        return $this->get_numbers_by_roles();
    }

    /**
     * Get replacement keys
     *
     * @return array
     */
    public function replacement_keys() {
        return [
            'order_id'        => 'get_id',
            'items'           => 'products',
            'date'            => 'get_date_paid',
            'status'          => 'get_status',
            'payment_method'  => 'get_payment_method_title',
            'shipping_method' => 'get_shipping_method',
            'transaction_id'  => 'get_transaction_id',
            'billing_name'    => 'get_formatted_billing_full_name',
            'billing_email'   => 'get_billing_email',
            'order_total'     => 'get_formatted_order_total',
            'shipping_total'  => 'get_shipping_total',
            'tax_total'       => 'get_total_tax',
            'discount'        => 'get_discount_total',
        ];
    }

    /**
     * Whether this notification was already sent for the bound order.
     *
     * Reads the `_texty_{id}` order meta that send() uses as its
     * at-most-once flag.
     *
     * @since 2.0.3
     *
     * @return bool
     */
    public function has_sent() {
        if ( empty( $this->order ) ) {
            return false;
        }

        return (bool) $this->order->get_meta( '_texty_' . $this->get_id(), true );
    }

    public function send(): bool {
        if ( ! $this->enabled() ) {
            return false;
        }

        $meta_key = '_texty_' . $this->get_id();

        // if we've already sent the message, don't send again
        if ( $this->has_sent() ) {
            return false;
        }

        // Claim the send up-front so concurrent status changes stay at-most-once.
        // update_meta_data (not add_meta_data) keeps exactly one row per order/notification.
        $this->order->update_meta_data( $meta_key, 1 );
        $this->order->save_meta_data();

        if ( 'user' === $this->get_type() ) {
            $number = $this->order->get_billing_phone();

            $recipients = $number ? [ $number ] : [];
        } else {
            $recipients = $this->get_recipients();
        }

        /**
         * Filter the recipients for a notification.
         *
         * @param array        $recipients   The recipient phone numbers
         * @param Notification $notification The notification instance
         */
        $recipients = apply_filters( 'texty_notification_recipients', $recipients, $this );

        // Drop nulls / empty strings — a stale `texty_phone` meta value or a
        // third-party filter can leave them in the array and crash the
        // gateway send (which expects a string).
        $recipients = is_array( $recipients ) ? array_values( array_filter( $recipients ) ) : [];

        if ( ! $recipients ) {
            return false;
        }

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

        $gateway  = texty()->gateways();
        $any_sent = false;
        $outcomes = [];

        // Stash the active notification so the after-send logger can attach
        // notification_id / notification_group to each SmsStat row without
        // threading them through the gateway pipeline.
        texty()->notifications()->set_active( $this );

        try {
            foreach ( $recipients as $number ) {
                $result = $gateway->send( $number, $content );

                if ( ! is_wp_error( $result ) && false !== $result ) {
                    $any_sent            = true;
                    $outcomes[ $number ] = true;
                } else {
                    $outcomes[ $number ] = is_wp_error( $result ) ? (string) $result->get_error_code() : 'texty_no_gateway';
                }
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

        $this->add_sms_note( $content, $outcomes );

        // Every recipient failed — release the claim so the next matching status
        // change can retry. A partial success keeps the flag so the recipients
        // who already received the SMS are not messaged again.
        if ( ! $any_sent ) {
            $this->order->delete_meta_data( $meta_key );
            $this->order->save_meta_data();

            return false;
        }

        return true;
    }

    /**
     * Add a private order note recording what was sent and to whom.
     *
     * One note per notification send, listing each recipient's result and
     * the message text, so staff can trace SMS from the order screen the
     * same way WooCommerce records emails.
     *
     * @since 2.0.3
     *
     * @param string                $content  Message that was sent.
     * @param array<string, true|string> $outcomes Recipient => true, or the error code.
     *
     * @return void
     */
    protected function add_sms_note( $content, array $outcomes ) {
        if ( empty( $this->order ) || ! $outcomes || ! method_exists( $this->order, 'add_order_note' ) ) {
            return;
        }

        /**
         * Filter whether Texty adds a private order note for each SMS
         * notification sent about an order.
         *
         * @since 2.0.3
         *
         * @param bool   $add          Whether to add the note. Default true.
         * @param Base   $notification The notification that was sent.
         * @param object $order        The order.
         */
        if ( ! apply_filters( 'texty_add_order_sms_note', true, $this, $this->order ) ) {
            return;
        }

        $gateway = texty()->gateways()->active_gateway();
        $lines   = [
            sprintf(
                /* translators: 1: notification title, 2: gateway name */
                __( 'SMS “%1$s” via %2$s:', 'texty' ),
                $this->get_title() ? $this->get_title() : $this->get_id(),
                $gateway ? $gateway->name() : __( 'no gateway', 'texty' )
            ),
        ];

        // Private notes are also shown to Dokan vendors on their orders, so:
        // only the customer's number (already on the order) is shown in full;
        // admin / vendor numbers are masked; failures show the error code
        // only — the full gateway error (which can include e.g. the server
        // IP) stays in Texty → Logs, which is admin-only.
        $show_full = 'user' === $this->get_type();

        foreach ( $outcomes as $number => $outcome ) {
            $display = $show_full ? $number : self::mask_phone( $number );

            $lines[] = true === $outcome
                /* translators: %s: phone number (masked for staff/vendor recipients) */
                ? sprintf( __( '✓ Sent to %s', 'texty' ), $display )
                /* translators: 1: phone number (masked for staff/vendor recipients), 2: error code */
                : sprintf( __( '✗ Failed for %1$s (%2$s), details in Texty → Logs', 'texty' ), $display, $outcome );
        }

        /* translators: %s: SMS text */
        $lines[] = sprintf( __( 'Message: “%s”', 'texty' ), $content );

        $this->order->add_order_note( implode( "\n", $lines ) );
    }

    /**
     * Mask a phone number for notes, keeping enough to recognise it.
     *
     * `+8801712345678` becomes `+880•••••••678`.
     *
     * @since 2.0.3
     *
     * @param string $number Phone number.
     *
     * @return string
     */
    public static function mask_phone( $number ) {
        $number = (string) $number;
        $length = strlen( $number );

        if ( $length <= 7 ) {
            return str_repeat( '•', max( 0, $length - 2 ) ) . substr( $number, -2 );
        }

        return substr( $number, 0, 4 ) . str_repeat( '•', $length - 7 ) . substr( $number, -3 );
    }
}
