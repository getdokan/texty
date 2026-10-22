<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Result;
use Texty\Gateways\Twilio;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Twilio
 */
class TwilioTest extends GatewayTestCase {

    const SID   = 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
    const TOKEN = 'test-auth-token-1234';
    const URL   = 'https://api.twilio.com/2010-04-01/Accounts/ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX/Messages.json';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'twilio',
            [
                'sid'   => self::SID,
                'token' => self::TOKEN,
                'from'  => '+15017122661',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'twilio', 'success' );

        $result = ( new Twilio() )->send_result( '+15558675310', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( 'SM1f0e8ae6ade43cb3c0ce4525424e404f', $result->get_reference_id() );
        $this->assertSame( 201, $result->get_http_status() );

        $this->assert_request( 'POST', self::URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Authorization', 'Basic ' . base64_encode( self::SID . ':' . self::TOKEN ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $this->assertSame(
            [
                'From' => '+15017122661',
                'To'   => '+15558675310',
                'Body' => 'Hello from Texty',
            ],
            $this->request_form()
        );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'twilio', 'success' );

        ( new Twilio() )->send_result( '+15558675310', 'Hi', [ 'from' => 'TEXTY' ] );

        $this->assertSame( 'TEXTY', $this->request_form()['From'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'twilio', 'invalid-credentials' );

        $result = ( new Twilio() )->send_result( '+15558675310', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '20003', $result->get_error_code() );
        $this->assertSame( 'Authenticate', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'twilio', 'invalid-number' );

        $result = ( new Twilio() )->send_result( '+1555NOTANUMBER', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '21211', $result->get_error_code() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_server_error() {
        $this->respond_with_fixture( 'twilio', 'server-error' );

        $result = ( new Twilio() )->send_result( '+15558675310', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( '20500', $result->get_error_code() );
        $this->assertSame( 500, $result->get_http_status() );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Twilio() )->send_result( '+15558675310', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'twilio', [ 'sid' => '', 'token' => '' ] );

        $result = ( new Twilio() )->send_result( '+15558675310', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'twilio', 'validate-ok' );

        $creds = ( new Twilio() )->validate(
            $this->validate_request(
                'twilio',
                [
                    'sid'   => self::SID,
                    'token' => self::TOKEN,
                    'from'  => '+15017122661',
                ]
            )
        );

        $this->assertSame(
            [
                'sid'   => self::SID,
                'token' => self::TOKEN,
                'from'  => '+15017122661',
            ],
            $creds
        );
        $this->assert_request( 'GET', 'https://api.twilio.com/2010-04-01/Accounts.json' );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'twilio', 'validate-fail' );

        $result = ( new Twilio() )->validate(
            $this->validate_request( 'twilio', [ 'sid' => self::SID, 'token' => 'wrong' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 20003, $result->get_error_code() );
    }

    public function test_validate_requires_sid_and_token() {
        $result = ( new Twilio() )->validate( $this->validate_request( 'twilio', [ 'sid' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_direct_send_keeps_legacy_return_shape() {
        $this->respond_with_fixture( 'twilio', 'success' );
        $this->respond_with_fixture( 'twilio', 'invalid-number' );

        $ok   = ( new Twilio() )->send( '+15558675310', 'Hi' );
        $fail = ( new Twilio() )->send( '+1555NOTANUMBER', 'Hi' );

        $this->assertIsArray( $ok );
        $this->assertSame( 'SM1f0e8ae6ade43cb3c0ce4525424e404f', $ok['reference_id'] );
        $this->assertWPError( $fail );
        // WP_Error keys its codes, so PHP turns numeric strings into ints.
        $this->assertSame( '21211', (string) $fail->get_error_code() );
    }

    public function test_manager_returns_legacy_array_and_logs_reference_id() {
        $this->respond_with_fixture( 'twilio', 'success' );

        $seen = null;
        add_action(
            'texty_after_send_sms',
            function ( $result ) use ( &$seen ) {
                $seen = $result;
            }
        );

        $result = texty()->gateways()->send( '+15558675310', 'Hello from Texty' );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'SM1f0e8ae6ade43cb3c0ce4525424e404f', $result['reference_id'] );
        $this->assertSame( $result, $seen );
    }

    public function test_manager_returns_wp_error_on_failure() {
        $this->respond_with_fixture( 'twilio', 'invalid-number' );

        $result = texty()->gateways()->send( '+1555NOTANUMBER', 'Hi' );

        $this->assertWPError( $result );
        $this->assertSame( '21211', (string) $result->get_error_code() );
        $this->assertSame( 400, $result->get_error_data()['http_status'] );
    }
}
