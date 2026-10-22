<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Bandwidth;
use Texty\Gateways\Metadata;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Bandwidth
 */
class BandwidthTest extends GatewayTestCase {

    const ACCOUNT_ID     = '1234567';
    const CLIENT_ID      = 'bw-client-id-0001';
    const CLIENT_SECRET  = 'bw-client-secret-1234';
    const APPLICATION_ID = '93de2206-9669-4e07-948d-329f4b722ee2';
    const FROM           = '+15553332222';
    const TOKEN          = 'eyJhbGciOiJIUzI1NiIs...';
    const TOKEN_URL      = 'https://api.bandwidth.com/api/v1/oauth2/token';
    const SEND_URL       = 'https://messaging.bandwidth.com/api/v2/users/1234567/messages';
    const MEDIA_URL      = 'https://messaging.bandwidth.com/api/v2/users/1234567/media';

    public function set_up() {
        parent::set_up();

        delete_transient( $this->cache_key() );

        $this->set_credentials( 'bandwidth', $this->creds() );
    }

    public function tear_down() {
        delete_transient( $this->cache_key() );

        parent::tear_down();
    }

    /**
     * Saved / submitted credentials
     *
     * @return array
     */
    private function creds() {
        return [
            'account_id'     => self::ACCOUNT_ID,
            'client_id'      => self::CLIENT_ID,
            'client_secret'  => self::CLIENT_SECRET,
            'application_id' => self::APPLICATION_ID,
            'from'           => self::FROM,
        ];
    }

    /**
     * Expected token transient key
     *
     * @param string $client_id
     * @param string $client_secret
     *
     * @return string
     */
    private function cache_key( $client_id = self::CLIENT_ID, $client_secret = self::CLIENT_SECRET ) {
        return 'texty_bandwidth_token_' . md5( $client_id . '|' . $client_secret );
    }

    /**
     * Headers of a captured request, lower-cased
     *
     * @param int $index
     *
     * @return array
     */
    private function headers_of( $index ) {
        return array_change_key_case( $this->requests[ $index ]['headers'], CASE_LOWER );
    }

    /**
     * Assert a captured request is a well-formed token request
     *
     * @param int $index
     *
     * @return void
     */
    private function assert_token_request( $index ) {
        $request = $this->requests[ $index ];
        $headers = $this->headers_of( $index );
        $body    = $request['body'];

        if ( is_string( $body ) ) {
            parse_str( $body, $body );
        }

        $this->assertSame( 'POST', $request['method'] );
        $this->assertSame( self::TOKEN_URL, $request['url'] );
        $this->assertSame( 'Basic ' . base64_encode( self::CLIENT_ID . ':' . self::CLIENT_SECRET ), $headers['authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $this->assertSame( 'application/x-www-form-urlencoded', $headers['content-type'] );
        $this->assertSame( [ 'grant_type' => 'client_credentials' ], $body );
        $this->assertTrue( $request['args']['sslverify'] );
    }

    public function test_successful_send_fetches_token_and_sends() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hello world' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1589228074636lm4k2je7j7jklbn2', $result->get_reference_id() );
        $this->assertSame( 202, $result->get_http_status() );

        $this->assertCount( 2, $this->requests );
        $this->assert_token_request( 0 );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'applicationId' => self::APPLICATION_ID,
                'to'            => [ '+15552223333' ],
                'from'          => self::FROM,
                'text'          => 'Hello world',
                'tag'           => 'texty',
            ],
            $this->request_json()
        );

        $this->assertSame( self::TOKEN, get_transient( $this->cache_key() ) );
    }

    public function test_cached_token_is_reused() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'success' );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        $gateway = new Bandwidth();
        $first   = $gateway->send_result( '+15552223333', 'One' );
        $second  = ( new Bandwidth() )->send_result( '+15552223333', 'Two' );

        $this->assertTrue( $first->is_success() );
        $this->assertTrue( $second->is_success() );
        $this->assertCount( 3, $this->requests );
        $this->assertSame( self::SEND_URL, $this->requests[2]['url'] );
        $this->assertSame( 'Bearer ' . self::TOKEN, $this->headers_of( 2 )['authorization'] );
        $this->assert_all_responses_used();
    }

    public function test_pre_cached_token_skips_token_request() {
        set_transient( $this->cache_key(), 'cached-token-abc', 600 );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertCount( 1, $this->requests );
        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_header( 'Authorization', 'Bearer cached-token-abc' );
    }

    public function test_token_is_cached_for_expires_in_minus_sixty_seconds() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $timeout = (int) get_option( '_transient_timeout_' . $this->cache_key() );

        if ( wp_using_ext_object_cache() ) {
            $this->markTestSkipped( 'Transient TTL is not readable with an external object cache.' );
        }

        $this->assertGreaterThanOrEqual( time() + 3540 - 5, $timeout );
        $this->assertLessThanOrEqual( time() + 3540, $timeout );
    }

    public function test_401_with_cached_token_refreshes_once_and_retries() {
        set_transient( $this->cache_key(), 'stale-token', 600 );

        $this->respond_with_fixture( 'bandwidth', 'invalid-token-empty' );
        $this->respond_with(
            200,
            [
                'access_token'    => 'fresh-token-xyz',
                'access_token_id' => 'tokid',
                'token_type'      => 'Bearer',
                'expires_in'      => 3600,
            ]
        );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '1589228074636lm4k2je7j7jklbn2', $result->get_reference_id() );
        $this->assertCount( 3, $this->requests );

        $this->assertSame( self::SEND_URL, $this->requests[0]['url'] );
        $this->assertSame( 'Bearer stale-token', $this->headers_of( 0 )['authorization'] );
        $this->assert_token_request( 1 );
        $this->assertSame( self::SEND_URL, $this->requests[2]['url'] );
        $this->assertSame( 'Bearer fresh-token-xyz', $this->headers_of( 2 )['authorization'] );
        $this->assertSame( $this->requests[0]['body'], $this->requests[2]['body'] );

        $this->assertSame( 'fresh-token-xyz', get_transient( $this->cache_key() ) );
        $this->assert_all_responses_used();
    }

    public function test_second_401_after_refresh_is_not_retried_again() {
        set_transient( $this->cache_key(), 'stale-token', 600 );

        $this->respond_with_fixture( 'bandwidth', 'invalid-token-empty' );
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'invalid-credentials' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unauthorized', $result->get_error_code() );
        $this->assertSame( 'Authentication Failed', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertCount( 3, $this->requests );
        $this->assert_all_responses_used();
    }

    public function test_401_with_freshly_fetched_token_is_not_retried() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'invalid-token-empty' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'invalid_token', $result->get_error_code() );
        $this->assertSame( 'The access token is invalid or has expired', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertCount( 2, $this->requests );
    }

    public function test_from_option_overrides_saved_sender() {
        set_transient( $this->cache_key(), self::TOKEN, 600 );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        ( new Bandwidth() )->send_result( '+15552223333', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['from'] );
    }

    public function test_number_is_normalized_to_e164_with_plus() {
        set_transient( $this->cache_key(), self::TOKEN, 600 );
        $this->respond_with_fixture( 'bandwidth', 'success' );
        $this->respond_with_fixture( 'bandwidth', 'success' );

        ( new Bandwidth() )->send_result( '15552223333', 'Hi' );
        $this->assertSame( [ '+15552223333' ], $this->request_json()['to'] );

        ( new Bandwidth() )->send_result( '+1 (555) 222-3333', 'Hi' );
        $this->assertSame( [ '+15552223333' ], $this->request_json()['to'] );
    }

    public function test_invalid_client_credentials_at_token_step() {
        $this->respond_with_fixture( 'bandwidth', 'token-invalid-client' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'invalid_client', $result->get_error_code() );
        $this->assertSame( 'Provided client credentials are invalid', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
        $this->assertFalse( get_transient( $this->cache_key() ) );
    }

    public function test_forbidden_send() {
        set_transient( $this->cache_key(), self::TOKEN, 600 );
        $this->respond_with_fixture( 'bandwidth', 'forbidden' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'forbidden', $result->get_error_code() );
        $this->assertSame( 'Access Denied', $result->get_error_message() );
        $this->assertSame( 403, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'invalid-number' );

        $result = ( new Bandwidth() )->send_result( '+invalid', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'request-validation', $result->get_error_code() );
        $this->assertSame( "to: '+invalid' must be replaced with a valid E164 formatted telephone number", $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_rate_limited() {
        set_transient( $this->cache_key(), self::TOKEN, 600 );
        $this->respond_with_fixture( 'bandwidth', 'rate-limited' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'max-message-queue-size-exceeded', $result->get_error_code() );
        $this->assertSame( 429, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'server-error' );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'internal-server-error', $result->get_error_code() );
        $this->assertSame( 'Internal Server Error', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
        $this->assertCount( 2, $this->requests );
    }

    public function test_202_without_message_id_is_a_failure() {
        set_transient( $this->cache_key(), self::TOKEN, 600 );
        $this->respond_with( 202, [ 'owner' => self::FROM ] );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_transport_error_on_send() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_transport_error();

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 2, $this->requests );
    }

    public function test_transport_error_on_token_request() {
        $this->respond_with_transport_error();

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'bandwidth', [ 'account_id' => self::ACCOUNT_ID, 'client_id' => self::CLIENT_ID ] );

        $result = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_secret_and_token_are_not_in_the_result() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'success' );
        $this->respond_with_fixture( 'bandwidth', 'token-invalid-client' );

        $ok = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        delete_transient( $this->cache_key() );

        $fail = ( new Bandwidth() )->send_result( '+15552223333', 'Hi' );

        foreach ( [ $ok, $fail ] as $result ) {
            $logged = (string) wp_json_encode( $result->to_array() );

            $this->assertStringNotContainsString( self::CLIENT_SECRET, $logged );
            $this->assertStringNotContainsString( self::TOKEN, $logged );
            $this->assertStringNotContainsString( 'access_token', $logged );
        }
    }

    public function test_validate_accepts_valid_credentials() {
        // A cached token must not be used by validate().
        set_transient( $this->cache_key(), 'stale-token', 600 );

        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'validate-ok' );

        $submitted                  = $this->creds();
        $submitted['account_id']    = ' ' . self::ACCOUNT_ID . ' ';
        $submitted['client_secret'] = ' ' . self::CLIENT_SECRET . ' ';

        $creds = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $submitted ) );

        $this->assertSame( $this->creds(), $creds );
        $this->assertCount( 2, $this->requests );
        $this->assert_token_request( 0 );
        $this->assert_request( 'GET', self::MEDIA_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Bearer ' . self::TOKEN );

        $this->assertSame( self::TOKEN, get_transient( $this->cache_key() ) );
    }

    public function test_validate_rejects_invalid_client_credentials() {
        $this->respond_with_fixture( 'bandwidth', 'validate-fail' );

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $this->creds() ) );

        $this->assertWPError( $result );
        $this->assertSame( 'invalid_client', $result->get_error_code() );
        $this->assertSame( 'Provided client credentials are invalid', $result->get_error_message() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_validate_rejects_account_without_messaging_access() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'forbidden' );

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $this->creds() ) );

        $this->assertWPError( $result );
        $this->assertSame( 'forbidden', $result->get_error_code() );
        $this->assertSame( [ 'type' => 'forbidden', 'description' => 'Access Denied' ], $result->get_error_data() );
        $this->assertFalse( get_transient( $this->cache_key() ) );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $this->creds() ) );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_all_fields() {
        $creds = $this->creds();
        unset( $creds['client_secret'] );

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $creds ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_legacy_account_id() {
        $creds               = $this->creds();
        $creds['account_id'] = 'u-be8dvafwrs63rwpm7pfil4b';

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $creds ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_account_id', $result->get_error_code() );
        $this->assertStringContainsString( 'legacy', $result->get_error_message() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_requires_seven_digit_account_id() {
        $creds               = $this->creds();
        $creds['account_id'] = '12345';

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $creds ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_account_id', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_rejects_bad_sender() {
        $creds         = $this->creds();
        $creds['from'] = '5553332222';

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $creds ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_invalid_sender', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_alphanumeric_sender() {
        $this->respond_with_fixture( 'bandwidth', 'token-ok' );
        $this->respond_with_fixture( 'bandwidth', 'validate-ok' );

        $creds         = $this->creds();
        $creds['from'] = 'TEXTY';

        $result = ( new Bandwidth() )->validate( $this->validate_request( 'bandwidth', $creds ) );

        $this->assertIsArray( $result );
        $this->assertSame( 'TEXTY', $result['from'] );
    }

    public function test_client_secret_is_a_password_field() {
        $settings = ( new Bandwidth() )->get_settings();

        $this->assertSame( [ 'account_id', 'client_id', 'client_secret', 'application_id', 'from' ], array_keys( $settings ) );
        $this->assertSame( 'password', $settings['client_secret']['type'] );
        $this->assertSame( self::CLIENT_SECRET, $settings['client_secret']['value'] );
        $this->assertTrue( $settings['from']['alphanumeric'] );

        foreach ( $settings as $setting ) {
            $this->assertArrayNotHasKey( 'required', $setting );
        }
    }

    public function test_metadata() {
        $meta = ( new Bandwidth() )->metadata();

        $this->assertSame( Metadata::STATUS_BETA, $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [ 'US', 'CA' ], $meta['regions'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertFalse( $meta['features']['balance_check'] );
    }
}
