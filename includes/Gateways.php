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
     * A send that is refused before it reaches the gateway (no active
     * gateway, no recipient, a `texty_pre_send_sms` refusal such as "Pause
     * All Notifications") is still reported through hooks, so it is logged
     * as a failed attempt.
     *
     * @since 2.2.0 Added `$options`.
     *
     * @param string $to      The TO phone number
     * @param string $message The message to send
     * @param array  $options Per-message options, see `SendOptions`
     *
     * @return bool|array|WP_Error False when no gateway is active.
     */
    public function send( $to, $message, $options = [] ) {
        $gateway = $this->active_gateway();
        $options = SendOptions::normalize( $options );

        if ( ! ( $gateway instanceof GatewayInterface ) ) {
            $error = new WP_Error(
                'texty_no_gateway',
                __( 'No SMS gateway is active. Set one up under Texty → Gateway.', 'texty' )
            );

            /**
             * Fires when a message can't be sent because no gateway is active.
             *
             * `texty_after_send_sms` and `texty_send_sms_failed` don't fire
             * for this case: both pass the gateway instance, and there is none.
             *
             * @since TEXTY_VERSION
             *
             * @param WP_Error $error   The `texty_no_gateway` error
             * @param string   $to      The recipient phone number
             * @param string   $message The message body
             * @param array    $options Per-message options
             */
            do_action( 'texty_send_sms_no_gateway', $error, $to, $message, $options );

            // Still `false`, not the error: callers tell "no gateway" apart
            // from a gateway failure by this value.
            return false;
        }

        /**
         * Filter the recipient phone number.
         *
         * @since 2.2.0 Added `$options`.
         *
         * @param string           $to      The recipient phone number
         * @param string           $message The message body
         * @param GatewayInterface $gateway The active gateway instance
         * @param array            $options Per-message options
         */
        $to = apply_filters( 'texty_sms_to', $to, $message, $gateway, $options );

        // Bail if there's still no recipient after the filter — the filter
        // runs first so extensions keep their chance to supply the number.
        // Reported as a failed attempt so it is logged; hook consumers get a
        // string `$to` ('' when the filter returned something else) and a
        // WP_Error, keeping them on their is_wp_error(...) failure path
        // instead of triggering type errors downstream.
        if ( ! is_string( $to ) || '' === trim( $to ) ) {
            return $this->refuse(
                new WP_Error(
                    'texty_missing_recipient',
                    __( 'No recipient phone number was provided.', 'texty' )
                ),
                is_string( $to ) ? $to : '',
                $message,
                $gateway,
                $options
            );
        }

        /**
         * Filter the SMS message body.
         *
         * @since 2.2.0 Added `$options`.
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
         * Return a `WP_Error` to refuse the send: it is reported as a failed
         * attempt (`texty_after_send_sms` and `texty_send_sms_failed` fire, so
         * it is logged). Any other non-null value means the filter handled
         * the message itself; it is returned as is and nothing is logged.
         *
         * @since 2.2.0 Added `$options`.
         * @since TEXTY_VERSION A `WP_Error` return is logged as a failed send.
         *
         * @param null|mixed       $pre_send Return non-null to short-circuit
         * @param string           $to       The recipient phone number
         * @param string           $message  The message body
         * @param GatewayInterface $gateway  The active gateway instance
         * @param array            $options  Per-message options
         */
        $pre_send = apply_filters( 'texty_pre_send_sms', null, $to, $message, $gateway, $options );

        if ( null !== $pre_send ) {
            // A refusal (e.g. "Pause All Notifications") never reaches the
            // gateway, but it is still a failed attempt: report it like one so
            // it gets a Failed row in Logs. Without it, the queue's Pending
            // row is claimed and nothing replaces it, and the order note's
            // "details in Texty → Logs" points at an entry that doesn't exist.
            if ( is_wp_error( $pre_send ) ) {
                return $this->refuse( $pre_send, $to, $message, $gateway, $options );
            }

            return $pre_send;
        }

        /**
         * Fires before each SMS is sent.
         *
         * @since 2.2.0 Added `$options`.
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
         * @since 2.2.0 Added `$options`.
         * @since TEXTY_VERSION Also fires, with the `WP_Error`, when the send
         *                      is refused before the gateway: a `WP_Error`
         *                      from `texty_pre_send_sms`, or no recipient.
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
             * @since 2.2.0 Added `$options`.
             * @since TEXTY_VERSION Also fires when the send is refused before
             *                      the gateway: a `WP_Error` from
             *                      `texty_pre_send_sms`, or no recipient.
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
     * Report a send that was refused before it reached the gateway.
     *
     * Fires the same hooks as a gateway failure, so the attempt gets a
     * Failed row in Logs and failure listeners hear about it.
     *
     * @since TEXTY_VERSION
     *
     * @param WP_Error         $error   Why the send was refused
     * @param string           $to      The recipient phone number
     * @param string           $message The message body
     * @param GatewayInterface $gateway The active gateway instance
     * @param array            $options Per-message options
     *
     * @return WP_Error The same error, for send() to return.
     */
    private function refuse( WP_Error $error, $to, $message, GatewayInterface $gateway, array $options ) {
        /** This action is documented in includes/Gateways.php */
        do_action( 'texty_after_send_sms', $error, $to, $message, $gateway, $options );

        /** This action is documented in includes/Gateways.php */
        do_action( 'texty_send_sms_failed', $error, $to, $message, $gateway, $options );

        return $error;
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
                // Alphabetical by display name.
                'africastalking' => __NAMESPACE__ . '\Gateways\AfricasTalking',
                'aws_sms'        => __NAMESPACE__ . '\Gateways\AwsSms',
                'bandwidth'      => __NAMESPACE__ . '\Gateways\Bandwidth',
                'bird'           => __NAMESPACE__ . '\Gateways\Bird',
                'brevo'          => __NAMESPACE__ . '\Gateways\Brevo',
                'bulkgate'       => __NAMESPACE__ . '\Gateways\BulkGate',
                'bulksms'        => __NAMESPACE__ . '\Gateways\BulkSMS',
                'clickatell'     => __NAMESPACE__ . '\Gateways\Clickatell',
                'clicksend'      => __NAMESPACE__ . '\Gateways\ClickSend',
                'd7networks'     => __NAMESPACE__ . '\Gateways\D7Networks',
                'fast2sms'       => __NAMESPACE__ . '\Gateways\Fast2SMS',
                'infobip'        => __NAMESPACE__ . '\Gateways\Infobip',
                'msg91'          => __NAMESPACE__ . '\Gateways\MSG91',
                'octopush'       => __NAMESPACE__ . '\Gateways\Octopush',
                'plivo'          => __NAMESPACE__ . '\Gateways\Plivo',
                'sinch'          => __NAMESPACE__ . '\Gateways\Sinch',
                'smsapi'         => __NAMESPACE__ . '\Gateways\SMSAPI',
                'smsglobal'      => __NAMESPACE__ . '\Gateways\SMSGlobal',
                'sslwireless'    => __NAMESPACE__ . '\Gateways\SslWireless',
                'taqnyat'        => __NAMESPACE__ . '\Gateways\Taqnyat',
                'telnyx'         => __NAMESPACE__ . '\Gateways\Telnyx',
                'textmagic'      => __NAMESPACE__ . '\Gateways\Textmagic',
                'twilio'         => __NAMESPACE__ . '\Gateways\Twilio',
                'unifonic'       => __NAMESPACE__ . '\Gateways\Unifonic',
                'vonage'         => __NAMESPACE__ . '\Gateways\Vonage',
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
