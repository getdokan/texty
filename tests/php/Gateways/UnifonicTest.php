<?php

namespace Texty\Tests\Gateways;

use Texty\Gateways\Metadata;
use Texty\Gateways\Result;
use Texty\Gateways\Secrets;
use Texty\Gateways\Unifonic;
use Texty\Tests\GatewayTestCase;

/**
 * @covers \Texty\Gateways\Unifonic
 */
class UnifonicTest extends GatewayTestCase {

    const APP_SID      = 'unifonic-app-sid-1234';
    const SEND_URL     = 'https://el.cloud.unifonic.com/rest/SMS/messages';
    const VALIDATE_URL = 'https://el.cloud.unifonic.com/rest/SMS/Messages/GetMessagesDetails';

    public function set_up() {
        parent::set_up();

        $this->set_credentials(
            'unifonic',
            [
                'app_sid'   => self::APP_SID,
                'sender_id' => 'TEXTY',
            ]
        );
    }

    public function test_successful_send() {
        $this->respond_with_fixture( 'unifonic', 'success' );

        $result = ( new Unifonic() )->send_result( '+966505980169', 'Hello from Texty' );

        $this->assertInstanceOf( Result::class, $result );
        $this->assertTrue( $result->is_success() );
        $this->assertSame( '42000348806924', $result->get_reference_id() );
        $this->assertSame( 200, $result->get_http_status() );

        $this->assert_request( 'POST', self::SEND_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Content-Type', 'application/x-www-form-urlencoded' );
        $this->assert_request_header( 'Accept', 'application/json' );
        $this->assertSame(
            [
                'AppSid'       => self::APP_SID,
                'SenderID'     => 'TEXTY',
                'Recipient'    => '966505980169',
                'Body'         => 'Hello from Texty',
                'responseType' => 'JSON',
            ],
            $this->request_form()
        );
        $this->assertStringNotContainsString( self::APP_SID, $this->last_request()['url'] );
        $this->assertStringNotContainsString( self::APP_SID, (string) wp_json_encode( $result->get_raw_response() ) );
    }

    public function test_string_true_success_flag_is_accepted() {
        $this->respond_with_fixture( 'unifonic', 'success-string-bool' );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '3200017889310', $result->get_reference_id() );
    }

    public function test_string_false_success_flag_is_a_failure() {
        $this->respond_with(
            200,
            [
                'success'   => 'false',
                'message'   => 'Something went wrong',
                'errorCode' => 'ER-00',
                'data'      => [
                    'MessageID' => 42000348806924,
                    'Status'    => 'Sent',
                ],
            ]
        );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unifonic_http_200', $result->get_error_code() );
        $this->assertSame( 'Something went wrong', $result->get_error_message() );
    }

    public function test_sixteen_digit_message_id_stays_a_string() {
        $this->respond_with(
            200,
            '{"success":true,"message":"","errorCode":"ER-00","data":{"MessageID":4200034880692412,"Status":"Sent"}}',
            [ 'content-type' => 'application/json' ]
        );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '4200034880692412', $result->get_reference_id() );
    }

    public function test_message_id_beyond_int64_keeps_every_digit() {
        $this->respond_with(
            200,
            '{"success":true,"message":"","errorCode":"ER-00","data":{"MessageID":92233720368547758070,"Status":"Sent"}}',
            [ 'content-type' => 'application/json' ]
        );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertTrue( $result->is_success() );
        $this->assertSame( '92233720368547758070', $result->get_reference_id() );
    }

    public function test_missing_message_id_is_a_failure() {
        $this->respond_with(
            200,
            [
                'success'   => true,
                'message'   => '',
                'errorCode' => 'ER-00',
                'data'      => [ 'Status' => 'Sent' ],
            ]
        );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_message_id', $result->get_error_code() );
    }

    public function test_from_option_overrides_saved_sender() {
        $this->respond_with_fixture( 'unifonic', 'success' );

        ( new Unifonic() )->send_result( '966505980169', 'Hi', [ 'from' => 'UNITEST' ] );

        $this->assertSame( 'UNITEST', $this->request_form()['SenderID'] );
    }

    public function test_sender_id_is_omitted_when_empty() {
        $this->set_credentials( 'unifonic', [ 'app_sid' => self::APP_SID ] );
        $this->respond_with_fixture( 'unifonic', 'success' );

        ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertSame(
            [
                'AppSid'       => self::APP_SID,
                'Recipient'    => '966505980169',
                'Body'         => 'Hi',
                'responseType' => 'JSON',
            ],
            $this->request_form()
        );
    }

    public function test_no_correlation_id_and_unsupported_options_are_ignored() {
        $this->respond_with_fixture( 'unifonic', 'success' );

        ( new Unifonic() )->send_result(
            '966505980169',
            'Hi',
            [
                'unicode'     => true,
                'template_id' => 'tpl-1',
                'variables'   => [ 'name' => 'Ali' ],
            ]
        );

        $form = $this->request_form();

        $this->assertArrayNotHasKey( 'CorrelationID', $form );
        $this->assertArrayNotHasKey( 'baseEncode', $form );
        $this->assertSame( [ 'AppSid', 'SenderID', 'Recipient', 'Body', 'responseType' ], array_keys( $form ) );
    }

    public function test_recipient_is_normalized_to_international_digits() {
        $this->respond_with_fixture( 'unifonic', 'success' );
        $this->respond_with_fixture( 'unifonic', 'success' );

        ( new Unifonic() )->send_result( '+966 50-598-0169', 'Hi' );
        $this->assertSame( '966505980169', $this->request_form()['Recipient'] );

        ( new Unifonic() )->send_result( '00966505980169', 'Hi' );
        $this->assertSame( '966505980169', $this->request_form()['Recipient'] );
    }

    public function test_invalid_credentials() {
        $this->respond_with_fixture( 'unifonic', 'invalid-credentials' );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ER-01', $result->get_error_code() );
        $this->assertSame( 'Authentication failed', $result->get_error_message() );
        $this->assertSame( 401, $result->get_http_status() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_invalid_phone_number() {
        $this->respond_with_fixture( 'unifonic', 'invalid-number' );

        $result = ( new Unifonic() )->send_result( '966', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'ER-04', $result->get_error_code() );
        $this->assertSame( 'Dest num is too short', $result->get_error_message() );
        $this->assertSame( 400, $result->get_http_status() );
    }

    public function test_sender_not_allowed_uses_documented_status_meaning() {
        $this->respond_with_fixture( 'unifonic', 'sender-not-allowed' );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unifonic_http_480', $result->get_error_code() );
        $this->assertSame( 'This user cannot use the specified SenderID', $result->get_error_message() );
    }

    public function test_rejected_status_inside_200_is_a_failure() {
        $this->respond_with_fixture( 'unifonic', 'rejected-status' );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unifonic_rejected', $result->get_error_code() );
        $this->assertNull( $result->get_reference_id() );
    }

    public function test_server_error_keeps_raw_body() {
        $this->respond_with_fixture( 'unifonic', 'server-error' );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'unifonic_http_503', $result->get_error_code() );
        $this->assertSame( 'Unknown error', $result->get_error_message() );
        $this->assertSame( 503, $result->get_http_status() );
        $this->assertSame( '<html><body><h1>503 Service Unavailable</h1></body></html>', $result->get_raw_response() );
    }

    public function test_server_error_is_never_retried() {
        $this->respond_with_fixture( 'unifonic', 'server-error' );

        ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertCount( 1, $this->requests );
    }

    public function test_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
        $this->assertSame( 0, $result->get_http_status() );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_credentials_never_calls_the_api() {
        $this->set_credentials( 'unifonic', [ 'app_sid' => '' ] );

        $result = ( new Unifonic() )->send_result( '966505980169', 'Hi' );

        $this->assertFalse( $result->is_success() );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }

    public function test_direct_send_keeps_legacy_return_shape() {
        $this->respond_with_fixture( 'unifonic', 'success' );
        $this->respond_with_fixture( 'unifonic', 'invalid-number' );

        $ok   = ( new Unifonic() )->send( '966505980169', 'Hi' );
        $fail = ( new Unifonic() )->send( '966', 'Hi' );

        $this->assertIsArray( $ok );
        $this->assertSame( '42000348806924', $ok['reference_id'] );
        $this->assertWPError( $fail );
        $this->assertSame( 'ER-04', $fail->get_error_code() );
    }

    public function test_app_sid_is_a_secret_field() {
        $gateway = new Unifonic();

        $this->assertSame( [ 'app_sid' ], Secrets::fields( $gateway ) );
        $this->assertSame(
            [ 'echo' => Secrets::REDACTED ],
            Secrets::redact( [ 'echo' => self::APP_SID ], Secrets::values( $gateway ) )
        );
    }

    public function test_metadata() {
        $meta = ( new Unifonic() )->metadata();

        $this->assertSame( Metadata::STATUS_BETA, $meta['status'] );
        $this->assertSame( 'live', $meta['validation'] );
        $this->assertSame( [ 'SA', 'AE', 'KW', 'QA', 'OM', 'BH' ], $meta['regions'] );
        $this->assertTrue( $meta['features']['unicode'] );
        $this->assertTrue( $meta['features']['sender_id'] );
        $this->assertFalse( $meta['features']['template'] );
        $this->assertFalse( $meta['features']['balance_check'] );
    }

    public function test_validate_accepts_valid_credentials() {
        $this->respond_with_fixture( 'unifonic', 'validate-ok' );

        $creds = ( new Unifonic() )->validate(
            $this->validate_request(
                'unifonic',
                [
                    'app_sid'   => ' ' . self::APP_SID . ' ',
                    'sender_id' => 'TEXTY',
                ]
            )
        );

        $this->assertSame(
            [
                'app_sid'   => self::APP_SID,
                'sender_id' => 'TEXTY',
            ],
            $creds
        );
        $this->assert_request( 'POST', self::VALIDATE_URL );
        $this->assert_request_is_safe();
        $this->assert_request_header( 'Content-Type', 'application/x-www-form-urlencoded' );
        $this->assertSame(
            [
                'AppSid'       => self::APP_SID,
                'Limit'        => 1,
                'responseType' => 'JSON',
            ],
            $this->request_form()
        );
    }

    public function test_validate_rejects_invalid_credentials() {
        $this->respond_with_fixture( 'unifonic', 'validate-fail' );

        $result = ( new Unifonic() )->validate(
            $this->validate_request( 'unifonic', [ 'app_sid' => 'wrong' ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'unifonic_invalid_credentials', $result->get_error_code() );
        $this->assertSame( 'Authentication failed', $result->get_error_message() );
        $this->assertSame( 'ER-01', $result->get_error_data()['errorCode'] );
    }

    public function test_validate_surfaces_ip_restriction() {
        $this->respond_with_fixture( 'unifonic', 'validate-ip-restricted' );

        $result = ( new Unifonic() )->validate(
            $this->validate_request( 'unifonic', [ 'app_sid' => self::APP_SID ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'unifonic_http_403', $result->get_error_code() );
        $this->assertSame( 'Invalid IP address', $result->get_error_message() );
    }

    public function test_validate_transport_error() {
        $this->respond_with_transport_error();

        $result = ( new Unifonic() )->validate(
            $this->validate_request( 'unifonic', [ 'app_sid' => self::APP_SID ] )
        );

        $this->assertWPError( $result );
        $this->assertSame( 'http_request_failed', $result->get_error_code() );
    }

    public function test_validate_requires_app_sid() {
        $result = ( new Unifonic() )->validate( $this->validate_request( 'unifonic', [ 'app_sid' => '' ] ) );

        $this->assertWPError( $result );
        $this->assertSame( 'texty_missing_credentials', $result->get_error_code() );
        $this->assertEmpty( $this->requests );
    }
}
