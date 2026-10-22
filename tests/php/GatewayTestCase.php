<?php

namespace Texty\Tests;

use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Base class for gateway tests
 *
 * Fakes every outgoing HTTP request through `pre_http_request`, so no test
 * touches the network. Queue responses with `respond_with()` or
 * `respond_with_fixture()`, run the code under test, then assert on the
 * recorded requests. An unexpected request (nothing queued) fails the test.
 *
 * Fixtures live in `tests/fixtures/gateways/<gateway>/<name>.json`:
 *
 *     {
 *       "_source": "https://provider.example/docs/send",
 *       "_note":   "from docs" | "inferred",
 *       "status":  201,
 *       "headers": { "content-type": "application/json" },
 *       "body":    { ... }            // or "body_raw": "plain text"
 *     }
 *
 * @package Texty\Tests
 */
abstract class GatewayTestCase extends WP_UnitTestCase {

    /**
     * Queued fake responses (FIFO)
     *
     * @var array[]
     */
    protected $responses = [];

    /**
     * Requests captured so far
     *
     * @var array[]
     */
    protected $requests = [];

    /**
     * Hook the HTTP interceptor
     */
    public function set_up() {
        parent::set_up();

        $this->responses = [];
        $this->requests  = [];

        add_filter( 'pre_http_request', [ $this, 'intercept' ], 10, 3 );
    }

    /**
     * Unhook the interceptor and reset gateway settings
     */
    public function tear_down() {
        remove_filter( 'pre_http_request', [ $this, 'intercept' ], 10 );
        delete_option( 'texty_settings' );

        parent::tear_down();
    }

    /**
     * Save credentials for a gateway and optionally make it active
     *
     * @param string $gateway
     * @param array  $creds
     * @param bool   $activate
     *
     * @return void
     */
    protected function set_credentials( $gateway, array $creds, $activate = true ) {
        $settings             = get_option( 'texty_settings', [] );
        $settings             = is_array( $settings ) ? $settings : [];
        $settings[ $gateway ] = $creds;

        if ( $activate ) {
            $settings['gateway'] = $gateway;
        }

        update_option( 'texty_settings', $settings );
    }

    /**
     * Queue a fake response
     *
     * @param int          $status
     * @param array|string $body    Arrays are JSON-encoded
     * @param array        $headers
     *
     * @return void
     */
    protected function respond_with( $status, $body = '', array $headers = [] ) {
        if ( is_array( $body ) ) {
            $body                    = wp_json_encode( $body );
            $headers['content-type'] = isset( $headers['content-type'] ) ? $headers['content-type'] : 'application/json';
        }

        $this->responses[] = [
            'headers'  => $headers,
            'body'     => (string) $body,
            'response' => [
                'code'    => (int) $status,
                'message' => get_status_header_desc( (int) $status ),
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * Queue a fake response loaded from a fixture file
     *
     * @param string $gateway Fixture directory name
     * @param string $name    Fixture file name without `.json`
     *
     * @return void
     */
    protected function respond_with_fixture( $gateway, $name ) {
        $fixture = $this->load_fixture( $gateway, $name );
        $headers = isset( $fixture['headers'] ) && is_array( $fixture['headers'] ) ? $fixture['headers'] : [];

        if ( array_key_exists( 'body_raw', $fixture ) ) {
            $this->respond_with( $fixture['status'], (string) $fixture['body_raw'], $headers );

            return;
        }

        $this->respond_with( $fixture['status'], isset( $fixture['body'] ) ? $fixture['body'] : '', $headers );
    }

    /**
     * Queue a transport failure (no response at all)
     *
     * @param string $code
     * @param string $message
     *
     * @return void
     */
    protected function respond_with_transport_error( $code = 'http_request_failed', $message = 'cURL error 28: Operation timed out' ) {
        $this->responses[] = new WP_Error( $code, $message );
    }

    /**
     * Load and decode a fixture
     *
     * @param string $gateway
     * @param string $name
     *
     * @return array
     */
    protected function load_fixture( $gateway, $name ) {
        $path = dirname( __DIR__ ) . "/fixtures/gateways/{$gateway}/{$name}.json";

        $this->assertFileExists( $path, "Missing fixture {$gateway}/{$name}.json" );

        $fixture = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        $this->assertIsArray( $fixture, "Fixture {$gateway}/{$name}.json is not valid JSON" );
        $this->assertArrayHasKey( 'status', $fixture, "Fixture {$gateway}/{$name}.json needs a status" );
        $this->assertArrayHasKey( '_source', $fixture, "Fixture {$gateway}/{$name}.json must cite its docs URL in _source" );

        return $fixture;
    }

    /**
     * `pre_http_request` callback: record the request, return the next fake response
     *
     * @param false|array|WP_Error $pre
     * @param array                $args
     * @param string               $url
     *
     * @return array|WP_Error
     */
    public function intercept( $pre, $args, $url ) {
        $this->requests[] = [
            'url'     => $url,
            'method'  => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET',
            'headers' => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [],
            'body'    => isset( $args['body'] ) ? $args['body'] : null,
            'args'    => $args,
        ];

        if ( empty( $this->responses ) ) {
            $this->fail( "Unexpected HTTP request to {$url} — no fake response queued." );
        }

        return array_shift( $this->responses );
    }

    /**
     * Get the last captured request
     *
     * @return array
     */
    protected function last_request() {
        $this->assertNotEmpty( $this->requests, 'No HTTP request was made.' );

        return end( $this->requests );
    }

    /**
     * Assert the method and URL of the last request
     *
     * @param string $method
     * @param string $url
     *
     * @return void
     */
    protected function assert_request( $method, $url ) {
        $request = $this->last_request();

        $this->assertSame( strtoupper( $method ), $request['method'] );
        $this->assertSame( $url, $request['url'] );
    }

    /**
     * Assert a header on the last request
     *
     * @param string $name
     * @param string $value
     *
     * @return void
     */
    protected function assert_request_header( $name, $value ) {
        $headers = array_change_key_case( $this->last_request()['headers'], CASE_LOWER );
        $name    = strtolower( $name );

        $this->assertArrayHasKey( $name, $headers, "Header {$name} was not sent." );
        $this->assertSame( $value, $headers[ $name ] );
    }

    /**
     * Assert TLS verification and a timeout were set on the last request
     *
     * @return void
     */
    protected function assert_request_is_safe() {
        $args = $this->last_request()['args'];

        $this->assertTrue( $args['sslverify'], 'TLS verification must stay enabled.' );
        $this->assertGreaterThan( 0, $args['timeout'] );
    }

    /**
     * Decoded JSON body of the last request
     *
     * @return array
     */
    protected function request_json() {
        $body = $this->last_request()['body'];

        $this->assertIsString( $body, 'Request body is not a JSON string.' );

        $decoded = json_decode( $body, true );

        $this->assertIsArray( $decoded, 'Request body is not valid JSON.' );

        return $decoded;
    }

    /**
     * Form body of the last request
     *
     * @return array
     */
    protected function request_form() {
        $body = $this->last_request()['body'];

        if ( is_string( $body ) ) {
            parse_str( $body, $parsed );
            $body = $parsed;
        }

        $this->assertIsArray( $body, 'Request body is not form data.' );

        return $body;
    }

    /**
     * Assert every queued response was used
     *
     * @return void
     */
    protected function assert_all_responses_used() {
        $this->assertEmpty( $this->responses, count( $this->responses ) . ' queued response(s) were never requested.' );
    }

    /**
     * Build a validate() request the way SettingsController does
     *
     * @param string $gateway
     * @param array  $creds
     *
     * @return WP_REST_Request
     */
    protected function validate_request( $gateway, array $creds ) {
        $request = new WP_REST_Request( 'POST' );
        $request->set_param( $gateway, $creds );

        return $request;
    }
}
