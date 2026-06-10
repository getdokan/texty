<?php

namespace Texty;

use Texty\Gateways\GatewayInterface;
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
     * @param string $to      The TO phone number
     * @param string $message The message to send
     *
     * @return bool|WP_Error
     */
    public function send( $to, $message ) {
        $gateway = $this->active_gateway();

        if ( ! ( $gateway instanceof GatewayInterface ) ) {
            return false;
        }

        /**
         * Filter the recipient phone number.
         *
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         */
        $to = apply_filters( 'texty_sms_to', $to, $message, $gateway );

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
         * @param string           $message The message body
         * @param string           $to      The recipient phone number
         * @param GatewayInterface $gateway The active gateway instance
         */
        $message = apply_filters( 'texty_sms_message', $message, $to, $gateway );

        /**
         * Short-circuit SMS sending. Return non-null to skip sending.
         *
         * @param null|mixed       $pre_send Return non-null to short-circuit
         * @param string           $to       The recipient phone number
         * @param string           $message  The message body
         * @param GatewayInterface $gateway  The active gateway instance
         */
        $pre_send = apply_filters( 'texty_pre_send_sms', null, $to, $message, $gateway );

        if ( null !== $pre_send ) {
            return $pre_send;
        }

        /**
         * Fires before each SMS is sent.
         *
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         */
        do_action( 'texty_before_send_sms', $to, $message, $gateway );

        $result = $gateway->send( $to, $message );

        /**
         * Fires after each SMS is sent.
         *
         * @param bool|WP_Error    $result  The send result
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         */
        do_action( 'texty_after_send_sms', $result, $to, $message, $gateway );

        if ( is_wp_error( $result ) ) {
            /**
             * Fires when SMS sending fails.
             *
             * @param WP_Error         $result  The error object
             * @param string           $to      The recipient phone number
             * @param string           $message The message body
             * @param GatewayInterface $gateway The active gateway instance
             */
            do_action( 'texty_send_sms_failed', $result, $to, $message, $gateway );
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
