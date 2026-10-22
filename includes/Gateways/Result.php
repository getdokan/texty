<?php

namespace Texty\Gateways;

use Texty\Http\Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized gateway send result
 *
 * A send only counts as successful when the gateway can name the provider's
 * message id, so `success()` refuses an empty reference id.
 *
 * `Gateways::send()` converts this to the legacy array / WP_Error shape
 * before firing hooks, so existing `texty_after_send_sms` consumers keep
 * working unchanged.
 *
 * @since 2.1.0
 */
class Result {

    /**
     * Whether the send succeeded
     *
     * @var bool
     */
    private $success = false;

    /**
     * Provider message id
     *
     * @var string|null
     */
    private $reference_id = null;

    /**
     * Error code
     *
     * @var string
     */
    private $error_code = '';

    /**
     * Error message
     *
     * @var string
     */
    private $error_message = '';

    /**
     * HTTP status of the provider response (0 if none)
     *
     * @var int
     */
    private $http_status = 0;

    /**
     * Provider response body (decoded JSON or raw string)
     *
     * @var array|string|null
     */
    private $raw_response = null;

    /**
     * Successful send
     *
     * @since 2.1.0
     *
     * @param string|int|null $reference_id Provider message id
     * @param Response|null   $response     Provider response
     *
     * @return Result
     */
    public static function success( $reference_id, $response = null ) {
        $reference_id = is_scalar( $reference_id ) ? trim( (string) $reference_id ) : '';

        if ( '' === $reference_id ) {
            return self::failure(
                'texty_missing_message_id',
                __( 'The gateway did not return a message ID, so the message cannot be confirmed as sent.', 'texty' ),
                $response
            );
        }

        $result               = self::with_response( $response );
        $result->success      = true;
        $result->reference_id = $reference_id;

        return $result;
    }

    /**
     * Failed send
     *
     * @since 2.1.0
     *
     * @param string|int    $code     Error code
     * @param string        $message  Error message
     * @param Response|null $response Provider response
     *
     * @return Result
     */
    public static function failure( $code, $message, $response = null ) {
        $result                = self::with_response( $response );
        $result->error_code    = '' === (string) $code ? 'texty_send_failed' : (string) $code;
        $result->error_message = '' === (string) $message ? __( 'The gateway could not send the message.', 'texty' ) : (string) $message;

        return $result;
    }

    /**
     * Failed send caused by a transport error (no response received)
     *
     * @since 2.1.0
     *
     * @param Response $response
     *
     * @return Result
     */
    public static function from_transport_error( Response $response ) {
        $error = $response->get_error();

        if ( ! $error instanceof WP_Error ) {
            return self::failure( 'texty_http_error', '', $response );
        }

        return self::failure( $error->get_error_code(), $error->get_error_message(), $response );
    }

    /**
     * Seed a result with the response's status and payload
     *
     * @param Response|null $response
     *
     * @return Result
     */
    private static function with_response( $response ) {
        $result = new self();

        if ( $response instanceof Response ) {
            $result->http_status  = $response->status();
            $result->raw_response = $response->is_transport_error() ? null : $response->payload();
        }

        return $result;
    }

    /**
     * Whether the send succeeded
     *
     * @since 2.1.0
     *
     * @return bool
     */
    public function is_success() {
        return $this->success;
    }

    /**
     * Get the provider message id
     *
     * @since 2.1.0
     *
     * @return string|null
     */
    public function get_reference_id() {
        return $this->reference_id;
    }

    /**
     * Get the error code
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function get_error_code() {
        return $this->error_code;
    }

    /**
     * Get the error message
     *
     * @since 2.1.0
     *
     * @return string
     */
    public function get_error_message() {
        return $this->error_message;
    }

    /**
     * Get the HTTP status
     *
     * @since 2.1.0
     *
     * @return int
     */
    public function get_http_status() {
        return $this->http_status;
    }

    /**
     * Get the provider response body
     *
     * @since 2.1.0
     *
     * @return array|string|null
     */
    public function get_raw_response() {
        return $this->raw_response;
    }

    /**
     * Array form
     *
     * @since 2.1.0
     *
     * @return array
     */
    public function to_array() {
        return [
            'success'       => $this->success,
            'reference_id'  => $this->reference_id,
            'error_code'    => $this->error_code,
            'error_message' => $this->error_message,
            'http_status'   => $this->http_status,
            'raw_response'  => $this->raw_response,
        ];
    }

    /**
     * WP_Error form, with the full result array as error data
     *
     * @since 2.1.0
     *
     * @return WP_Error
     */
    public function to_wp_error() {
        return new WP_Error( $this->error_code, $this->error_message, $this->to_array() );
    }

    /**
     * Legacy send return shape: array on success, WP_Error on failure
     *
     * @since 2.1.0
     *
     * @return array|WP_Error
     */
    public function to_legacy() {
        return $this->success ? $this->to_array() : $this->to_wp_error();
    }
}
