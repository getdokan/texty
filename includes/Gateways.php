<?php

namespace Texty;

use Texty\Gateways\GatewayInterface;
use Texty\Gateways\Result;
use Texty\Gateways\SendOptions;
use WP_Error;

/**
 * Manager Class
 */
class Gateways {

    /**
     * Registered gateway classes
     *
     * @var array
     */
    private $gateways = [];

    /**
     * Whether built-in gateways have been registered
     *
     * @var bool
     */
    private $registered = false;

    /**
     * Register a gateway
     *
     * @param string $key       Gateway identifier
     * @param string $classname Fully qualified class name
     *
     * @return void
     */
    public function register( $key, $classname ) {
        $this->gateways[ $key ] = $classname;
    }

    /**
     * Send the message from the active gateway
     *
     * Gateways may return a `Result`; it is converted to the legacy
     * array / WP_Error shape before any hook fires, so hook consumers and
     * callers always see `array|WP_Error`.
     *
     * @since 2.1.0 Added `$options`.
     *
     * @param string $to      The TO phone number
     * @param string $message The message to send
     * @param array  $options Per-message options, see `SendOptions`
     *
     * @return bool|array|WP_Error
     */
    public function send( $to, $message, $options = [] ) {
        $gateway = $this->active_gateway();

        if ( ! ( $gateway instanceof GatewayInterface ) ) {
            return false;
        }

        $options = SendOptions::normalize( $options );

        /**
         * Filter the recipient phone number.
         *
         * @since 2.1.0 Added `$options`.
         *
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         * @param array            $options Per-message options
         */
        $to = apply_filters( 'texty_sms_to', $to, $message, $gateway, $options );

        // Bail if there's still no recipient after the filter — the filter
        // runs first so extensions keep their chance to supply the number.
        // Returning a WP_Error keeps `texty_after_send_sms` consumers (the
        // SMS-stat logger included) on their is_wp_error(...) failure path
        // instead of triggering type errors downstream.
        if ( ! is_string( $to ) || '' === trim( $to ) ) {
            return new WP_Error(
                'texty_missing_recipient',
                __( 'No recipient phone number was provided.', 'texty' )
            );
        }

        /**
         * Filter the SMS message body.
         *
         * @since 2.1.0 Added `$options`.
         *
         * @param string           $message The message body
         * @param string           $to      The recipient phone number
         * @param GatewayInterface $gateway The active gateway instance
         * @param array            $options Per-message options
         */
        $message = apply_filters( 'texty_sms_message', $message, $to, $gateway, $options );

        /**
         * Short-circuit SMS sending. Return non-null to skip sending.
         *
         * @since 2.1.0 Added `$options`.
         *
         * @param null|mixed       $pre_send Return non-null to short-circuit
         * @param string           $to       The recipient phone number
         * @param string           $message  The message body
         * @param GatewayInterface $gateway  The active gateway instance
         * @param array            $options  Per-message options
         */
        $pre_send = apply_filters( 'texty_pre_send_sms', null, $to, $message, $gateway, $options );

        if ( null !== $pre_send ) {
            return $pre_send;
        }

        /**
         * Fires before each SMS is sent.
         *
         * @since 2.1.0 Added `$options`.
         *
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         * @param array            $options Per-message options
         */
        do_action( 'texty_before_send_sms', $to, $message, $gateway, $options );

        // Gateways implementing the original two-argument send() ignore the
        // extra argument, so third-party gateways keep working unchanged.
        $result = $gateway->send( $to, $message, $options );

        if ( $result instanceof Result ) {
            $result = $result->to_legacy();
        }

        /**
         * Fires after each SMS is sent.
         *
         * @since 2.1.0 Added `$options`.
         *
         * @param array|bool|WP_Error $result  The send result
         * @param string              $to      The recipient phone number
         * @param string              $message The message body
         * @param GatewayInterface    $gateway The active gateway instance
         * @param array               $options Per-message options
         */
        do_action( 'texty_after_send_sms', $result, $to, $message, $gateway, $options );

        if ( is_wp_error( $result ) ) {
            /**
             * Fires when SMS sending fails.
             *
             * @since 2.1.0 Added `$options`.
             *
             * @param WP_Error         $result  The error object
             * @param string           $to      The recipient phone number
             * @param string           $message The message body
             * @param GatewayInterface $gateway The active gateway instance
             * @param array            $options Per-message options
             */
            do_action( 'texty_send_sms_failed', $result, $to, $message, $gateway, $options );
        }

        return $result;
    }

    /**
     * Get the active gateway
     *
     * @return Gateways\GatewayInterface|false
     */
    public function active_gateway() {
        $gateways  = $this->all();
        $gateway   = texty()->settings()->gateway();

        if ( $gateway && array_key_exists( $gateway, $gateways ) ) {
            $instance = new $gateways[ $gateway ]();

            /**
             * Filter the gateway instance after creation.
             *
             * @param GatewayInterface $instance The gateway instance
             * @param string           $gateway  The gateway identifier
             */
            return apply_filters( 'texty_gateway_instance', $instance, $gateway );
        }

        return false;
    }

    /**
     * Get all the available gateways
     *
     * @return array
     */
    public function all() {
        if ( ! $this->registered ) {
            $this->gateways = [
                'twilio'     => __NAMESPACE__ . '\Gateways\Twilio',
                'vonage'     => __NAMESPACE__ . '\Gateways\Vonage',
                'clickatell' => __NAMESPACE__ . '\Gateways\Clickatell',
                'plivo'      => __NAMESPACE__ . '\Gateways\Plivo',
                'textmagic'  => __NAMESPACE__ . '\Gateways\Textmagic',
                'sinch'      => __NAMESPACE__ . '\Gateways\Sinch',
                'clicksend'  => __NAMESPACE__ . '\Gateways\ClickSend',
                'infobip'    => __NAMESPACE__ . '\Gateways\Infobip',
                'telnyx'     => __NAMESPACE__ . '\Gateways\Telnyx',
                'bird'       => __NAMESPACE__ . '\Gateways\Bird',
            ];

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $this->gateways['fake'] = __NAMESPACE__ . '\Gateways\Fake';
            }

            /**
             * Fires to allow registration of custom gateways.
             *
             * @param Gateways $manager The gateway manager instance
             */
            do_action( 'texty_register_gateways', $this );

            $this->registered = true;
        }

        return apply_filters( 'texty_available_gateways', $this->gateways );
    }
}
