<?php

namespace Texty\Tests\Http;

use Texty\Http\MacAuth;
use WP_UnitTestCase;

/**
 * Known-answer tests for the MAC signer.
 *
 * SMSGlobal's REST page shows a signed example but not the secret, so the
 * vectors below are computed (not provider-published): the SDK algorithm
 * (smsglobal-php Credentials::hashRequest) with the SDK test secret
 * `secret12345`, ts `1325376000` and nonce `random-string`. Reproduce with:
 *
 *     printf '1325376000\nrandom-string\nPOST\n/v2/sms\napi.smsglobal.com\n443\n\n' \
 *       | openssl dgst -sha256 -hmac secret12345 -binary | base64
 *
 * @covers \Texty\Http\MacAuth
 */
class MacAuthTest extends WP_UnitTestCase {

    const KEY    = 'key12345';
    const SECRET = 'secret12345';
    const TS     = 1325376000;
    const NONCE  = 'random-string';

    public function test_normalized_string_matches_docs_example() {
        $this->assertSame(
            "1325376000\nrandom-string\nPOST\n/v2/sms\napi.smsglobal.com\n443\n\n",
            MacAuth::normalized_string( 'post', 'https://api.smsglobal.com/v2/sms', self::TS, self::NONCE )
        );
    }

    public function test_normalized_string_keeps_query_and_explicit_port() {
        $this->assertSame(
            "1325376000\nrandom-string\nGET\n/v2/sms?limit=1\napi.smsglobal.com\n8443\n\n",
            MacAuth::normalized_string( 'GET', 'https://api.smsglobal.com:8443/v2/sms?limit=1', self::TS, self::NONCE )
        );
    }

    public function test_known_answer_post_send() {
        $signer = new MacAuth( self::KEY, self::SECRET );

        $this->assertSame(
            'N/UJHRdNMglyRhC1ka3S4qpgQK0Lm8sdwEkzeahc0GI=',
            $signer->mac( 'POST', 'https://api.smsglobal.com/v2/sms', self::TS, self::NONCE )
        );
    }

    public function test_known_answer_get_credit_balance() {
        $signer = new MacAuth( self::KEY, self::SECRET );

        $this->assertSame(
            '3XJkVlQ4Ci7uAiCqQhBQTGTnIJaHS+kkb/09GyvaYc8=',
            $signer->mac( 'GET', 'https://api.smsglobal.com/v2/user/credit-balance', self::TS, self::NONCE )
        );
    }

    public function test_known_answer_with_query_string() {
        $signer = new MacAuth( self::KEY, self::SECRET );

        $this->assertSame(
            'vQoD0463dPLG+61iokR+5pus1Rj0d3E21rt44ZCGVkw=',
            $signer->mac( 'GET', 'https://api.smsglobal.com/v2/sms?limit=1', self::TS, self::NONCE )
        );
    }

    public function test_full_header_with_injected_timestamp_and_nonce() {
        $signer = new MacAuth( self::KEY, self::SECRET, self::TS, self::NONCE );

        $this->assertSame(
            'MAC id="key12345", ts="1325376000", nonce="random-string", mac="N/UJHRdNMglyRhC1ka3S4qpgQK0Lm8sdwEkzeahc0GI="',
            $signer->header( 'POST', 'https://api.smsglobal.com/v2/sms' )
        );
    }

    public function test_default_header_uses_current_time_and_fresh_nonce() {
        $signer = new MacAuth( self::KEY, self::SECRET );
        $re     = '/^MAC id="key12345", ts="(\d+)", nonce="([^"]{1,32})", mac="[A-Za-z0-9+\/]+=*"$/';

        $first  = $signer->header( 'POST', 'https://api.smsglobal.com/v2/sms' );
        $second = $signer->header( 'POST', 'https://api.smsglobal.com/v2/sms' );

        $this->assertSame( 1, preg_match( $re, $first, $a ) );
        $this->assertSame( 1, preg_match( $re, $second, $b ) );
        $this->assertEqualsWithDelta( time(), (int) $a[1], 5 );
        $this->assertNotSame( $a[2], $b[2], 'Each header needs a fresh nonce.' );
    }

    public function test_generated_nonce_fits_provider_limit() {
        $nonce = MacAuth::generate_nonce();

        $this->assertNotSame( '', $nonce );
        $this->assertLessThanOrEqual( 32, strlen( $nonce ) );
        $this->assertSame( 1, preg_match( '/^[A-Za-z0-9]+$/', $nonce ) );
    }
}
