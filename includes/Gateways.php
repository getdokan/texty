<?php

namespace Texty;

use Texty\Gateways\GatewayInterface;
use WP_Error;

/**
 * Manager Class
 */
class Gateways {

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

        if ( $gateway instanceof GatewayInterface ) {
            return $gateway->send( $to, $message );
        }

        return false;
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
            return new $gateways[ $gateway ]();
        }

        return false;
    }

    /**
     * Get all the available gateways
     *
     * @return array
     */
    public function all() {
        $gateways = [
            'twilio'     => __NAMESPACE__ . '\Gateways\Twilio',
            'vonage'     => __NAMESPACE__ . '\Gateways\Vonage',
            'clickatell' => __NAMESPACE__ . '\Gateways\Clickatell',
            'plivo'      => __NAMESPACE__ . '\Gateways\Plivo',
            'octopush'   => __NAMESPACE__ . '\Gateways\Octopush',
        ];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $gateways['fake'] = __NAMESPACE__ . '\Gateways\Fake';
        }

        return apply_filters( 'texty_available_gateways', $gateways );
    }
}
