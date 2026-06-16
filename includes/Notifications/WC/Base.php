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

    public function send(): bool {
        if ( ! $this->enabled() ) {
            return false;
        }

        $meta_key = '_texty_' . $this->get_id();
        $has_sent = $this->order->get_meta( $meta_key, true );

        // if we've already sent the message, don't send again
        if ( $has_sent ) {
            return false;
        }

        // mark as sent
        $this->order->add_meta_data( $meta_key, 1 );
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

        $gateway = texty()->gateways();

        // Stash the active notification so the after-send logger can attach
        // notification_id / notification_group to each SmsStat row without
        // threading them through the gateway pipeline.
        texty()->notifications()->set_active( $this );

        try {
            foreach ( $recipients as $number ) {
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
