<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Infobip;
use Texty\Gateways\Result;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Infobip
 */
class InfobipTest extends GatewayTestCase {

    const KEY  = '003026bbc133714df1834b8638bb496e-8f4b3d9a-e931-478d-a994-28a725159ab9';
    const BASE = 'https://abc123.api.infobip.com';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'infobip',
            [
                'api_key'  => self::KEY,
                'base_url' => self::BASE,
                'sender'   => 'ServiceSMS',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'infobip', 'success' );

        $result = ( new Infobip() )->send_result( '+41 79 302 67 27', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '2250be2d4219-3af1-78856-aabe-1362af1edfd2', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::BASE . '/sms/3/messages' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'App ' . self::KEY );
        $this->assert_request_header( 'Content-Type', 'application/json' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'messages' => [
                    [
                        'sender'       => 'ServiceSMS',
                        'destinations' => [
                            [ 'to' => '41793026727' ],
                        ],
                        'content'      => [ 'text' => 'Hello from Texty' ],
                    ],
                ],
            ],
            $this->request_json()
        );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'infobip', 'success' );

        ( new Infobip() )->send_result( '41793026727', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_json()['messages'][0]['sender'] );
    }

    public function test_sender_is_omitted_when_empty() {
        $this->set_credentials(
            'infobip',
            [
                'api_key'  => self::KEY,
                'base_url' => self::BASE,
                'sender'   => '',
            ]
        );
        $this->respond_with_fixture( 'infobip', 'success' );

        ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertArrayNotHasKey( 'sender', $this->request_json()['messages'][0] );
    }

    public function test_base_url_without_scheme_is_normalized() {
        $this->set_credentials(
            'infobip',
            [
                'api_key'  => self::KEY,
                'base_url' => 'ABC123.api.infobip.com/some/path/',
            ]
        );
        $this->respond_with_fixture( 'infobip', 'success' );

        ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assert_request( 'POST', self::BASE . '/sms/3/messages' );
    }

    public function test_base_url_off_the_allowlist_never_calls_the_api() {
        $this->set_credentials(
            'infobip',
            [
                'api_key'  => self::KEY,
                'base_url' => 'https://api.infobip.com.evil.com',
            ]
        );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'infobip_invalid_base_url', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'infobip', 'invalid-credentials' );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E401', $result->get_error_code() );
        $this->assertSame( 'The request lacks valid authentication credentials for the requested resource.', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_number_bad_request() {
        $this->respond_with_fixture( 'infobip', 'invalid-number' );

        $result = ( new Infobip() )->send_result( '123', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E400', $result->get_error_code() );
        $this->assertSame( 'Request cannot be processed. messages[0].destinations[0].to: must be a valid phone number', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_invalid_number_rejected_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'infobip', 'invalid-number-rejected' );

        $result = ( new Infobip() )->send_result( '123', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'REJECTED_DESTINATION', $result->get_error_code() );
        $this->assertSame( 'Invalid destination address', $result->get_error_message() );
        $this->assertSame( 200, $result->get_http_status() );
    }

    public function test_rejected_status_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'infobip', 'rejected-status' );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'REJECTED_NOT_ENOUGH_CREDITS', $result->get_error_code() );
        $this->assertSame( 'Not enough credits', $result->get_error_message() );
    }

    public function test_legacy_error_shape_is_parsed() {
        $this->respond_with(
            429,
            [
                'requestError' => [
                    'serviceException' => [
                        'messageId' => 'TOO_MANY_REQUESTS',
                        'text'      => 'Too many requests',
                    ],
                ],
            ]
        );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'TOO_MANY_REQUESTS', $result->get_error_code() );
        $this->assertSame( 'Too many requests', $result->get_error_message() );
        $this->assertSame( 429, $result->get_http_status() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'infobip', 'server-error' );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'E500', $result->get_error_code() );
        $this->assertSame( 'Something went wrong.', $result->get_error_message() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'infobip', [ 'api_key' => '', 'base_url' => '' ] );

        $result = ( new Infobip() )->send_result( '41793026727', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'infobip', 'validate-ok' );

        $creds = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => ' ' . self::KEY . ' ',
                    'base_url' => 'abc123.api.infobip.com/',
                    'sender'   => 'ServiceSMS',
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'  => self::KEY,
                'base_url' => self::BASE,
                'sender'   => 'ServiceSMS',
            ],
            $creds
        );
        $this->assert_request( 'GET', self::BASE . '/account/1/balance' );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'App ' . self::KEY );
        $this->assert_request_header( 'Accept', 'application/json' );
    }

    public function test_validate_accepts_key_without_balance_scope() {
        $this->respond_with_fixture( 'infobip', 'validate-forbidden' );

        $creds = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => self::KEY,
                    'base_url' => self::BASE,
                ]
            )
        );

        $this->assertSame(
            [
                'api_key'  => self::KEY,
                'base_url' => self::BASE,
                'sender'   => '',
            ],
            $creds
        );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'infobip', 'validate-fail' );

        $result = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => 'wrong',
                    'base_url' => self::BASE,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'infobip_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'UNAUTHORIZED', $result->get_error_data()['requestError']['serviceException']['messageId'] );
    }

    public function test_validate_reports_server_error() {
        $this->respond_with_fixture( 'infobip', 'server-error' );

        $result = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => self::KEY,
                    'base_url' => self::BASE,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'E500', $result->get_error_code() );
    }

    public function test_validate_returns_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => self::KEY,
                    'base_url' => self::BASE,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    /**
     * @dataProvider disallowed_base_urls
     *
     * @param string $base_url
     */
    public function test_validate_rejects_disallowed_base_url( $base_url ) {
        $result = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => self::KEY,
                    'base_url' => $base_url,
                ]
            )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'infobip_invalid_base_url', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    /**
     * Base URLs that must never receive the API key
     *
     * @return array
     */
    public function disallowed_base_urls() {
        return [
            'plain http'      => [ 'http://abc123.api.infobip.com' ],
            'other host'      => [ 'evil.com' ],
            'suffix attack'   => [ 'https://abc123.api.infobip.com.evil.com' ],
            'userinfo attack' => [ 'https://abc123.api.infobip.com@evil.com' ],
            'custom port'     => [ 'https://abc123.api.infobip.com:8443' ],
            'other scheme'    => [ 'ftp://abc123.api.infobip.com' ],
        ];
    }

    public function test_allowed_hosts_filter_extends_the_allowlist() {
        $filter = function () {
            return [ 'sms.example.com' ];
        };
        add_filter( 'texty_infobip_allowed_hosts', $filter );

        $this->respond_with_fixture( 'infobip', 'validate-ok' );

        $creds = ( new Infobip() )->validate(
            $this->validate_request(
                'infobip',
                [
                    'api_key'  => self::KEY,
                    'base_url' => 'https://sms.example.com',
                ]
            )
        );

        remove_filter( 'texty_infobip_allowed_hosts', $filter );

        $this->assertIsArray( $creds );
        $this->assertSame( 'https://sms.example.com', $creds['base_url'] );
        $this->assert_request( 'GET', 'https://sms.example.com/account/1/balance' );
    }

    public function test_validate_requires_key_and_base_url() {
        $result = ( new Infobip() )->validate( $this->validate_request( 'infobip', [ 'api_key' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
