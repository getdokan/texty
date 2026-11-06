<?php

namespace Texty\Tests;

use Texty\Gateways\Secrets;
use Texty\Gateways\Twilio;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Saved secrets never reach the browser or the SMS log.
 *
 * @covers \Texty\Gateways\Secrets
 * @covers \Texty\Api\SettingsController
 * @covers \Texty\Api\Settings
 */
class SecretsTest extends GatewayTestCase {

    const TOKEN = 'super-secret-auth-token';

    public function set_up() {
        parent::set_up();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->set_credentials(
            'twilio',
            [
                'sid'   => 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
                'token' => self::TOKEN,
                'from'  => '+15017122661',
            ]
        );
    }

    public function test_schema_endpoint_masks_saved_secret() {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/texty/v1/settings/schema' ) );
        $json     = wp_json_encode( $response->get_data() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertStringNotContainsString( self::TOKEN, $json );
        $this->assertSame( Secrets::MASK, $response->get_data()['values']['twilio']['token'] );
        $this->assertSame( 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', $response->get_data()['values']['twilio']['sid'] );
    }

    public function test_schema_page_carries_metadata() {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/texty/v1/settings/schema' ) );
        $pages    = wp_list_filter( $response->get_data()['schema'], [ 'id' => 'twilio' ] );
        $page     = reset( $pages );

        $this->assertSame( 'stable', $page['meta']['status'] );
        $this->assertArrayHasKey( 'features', $page['meta'] );
    }

    public function test_legacy_settings_endpoint_masks_saved_secret() {
        foreach ( [ 'view', 'edit' ] as $context ) {
            $request = new WP_REST_Request( 'GET', '/texty/v1/settings' );
            $request->set_param( 'context', $context );

            $json = wp_json_encode( rest_get_server()->dispatch( $request )->get_data() );

            $this->assertStringNotContainsString( self::TOKEN, $json, "Secret leaked in {$context} context." );
        }
    }

    public function test_saving_unchanged_mask_keeps_secret() {
        $this->respond_with_fixture( 'twilio', 'validate-ok' );

        $response = $this->save_twilio( Secrets::MASK );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( self::TOKEN, get_option( 'texty_settings' )['twilio']['token'] );
        $this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $response->get_data() ) );

        // validate() must have authenticated with the real token, not the mask.
        $this->assert_request_header( 'Authorization', 'Basic ' . base64_encode( 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX:' . self::TOKEN ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    public function test_saving_new_value_replaces_secret() {
        $this->respond_with_fixture( 'twilio', 'validate-ok' );

        $this->save_twilio( 'brand-new-token' );

        $this->assertSame( 'brand-new-token', get_option( 'texty_settings' )['twilio']['token'] );
    }

    public function test_required_secret_cannot_be_cleared_via_save() {
        $response = $this->save_twilio( '' );

        // Twilio's validate() requires the token, so the save is rejected
        // and the stored secret survives. Use Disconnect to remove it.
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( self::TOKEN, get_option( 'texty_settings' )['twilio']['token'] );
    }

    public function test_restore_treats_empty_value_as_clear_not_keep() {
        $restored = Secrets::restore( new Twilio(), [ 'token' => '' ], [ 'token' => self::TOKEN ] );

        $this->assertSame( '', $restored['token'] );
    }

    public function test_log_row_redacts_secret_echoed_by_provider() {
        $this->respond_with(
            400,
            [
                'code'    => 21211,
                'message' => 'Bad request for token ' . self::TOKEN,
            ]
        );

        texty()->gateways()->send( '+15558675310', 'Hi' );

        global $wpdb;
        $row = $wpdb->get_var( "SELECT response FROM {$wpdb->prefix}texty_sms_stat ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $this->assertNotEmpty( $row );
        $this->assertStringNotContainsString( self::TOKEN, $row );
        $this->assertStringContainsString( Secrets::REDACTED, $row );
    }

    public function test_redact_walks_nested_payloads() {
        $payload = [
            'a' => 'token=' . self::TOKEN,
            'b' => [ 'c' => self::TOKEN ],
            'd' => 42,
        ];

        $this->assertSame(
            [
                'a' => 'token=' . Secrets::REDACTED,
                'b' => [ 'c' => Secrets::REDACTED ],
                'd' => 42,
            ],
            Secrets::redact( $payload, [ self::TOKEN ] )
        );
    }

    /**
     * Save Twilio credentials through the schema endpoint
     *
     * @param string $token Token value as the browser would submit it
     *
     * @return WP_REST_Response
     */
    private function save_twilio( $token ) {
        $request = new WP_REST_Request( 'POST', '/texty/v1/settings/schema' );
        $request->set_param( 'scopeId', 'twilio' );
        $request->set_param(
            'values',
            [
                'twilio_sid'   => 'ACXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
                'twilio_token' => $token,
                'twilio_from'  => '+15017122661',
            ]
        );

        return rest_get_server()->dispatch( $request );
    }
}
