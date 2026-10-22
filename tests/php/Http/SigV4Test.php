<?php

namespace Texty\Tests\Http;

use Texty\Http\SigV4;
use WP_UnitTestCase;

/**
 * Known-answer tests from the AWS SigV4 test suite
 *
 * Suite credentials and inputs: access key `AKIDEXAMPLE`, secret
 * `wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY`, `20150830T123600Z`,
 * region `us-east-1`, service `service`.
 *
 * @see https://bazel.googlesource.com/bazel/+/ed8a5ecffe83bf55da8ceacda47dba15dd02bd8a/third_party/aws-sig-v4-test-suite/post-vanilla/
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 *
 * @covers \Texty\Http\SigV4
 */
class SigV4Test extends WP_UnitTestCase {

    const ACCESS_KEY = 'AKIDEXAMPLE';
    const SECRET     = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    const NOW        = 1440938160; // 2015-08-30T12:36:00Z
    const URL        = 'https://example.amazonaws.com/';
    const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Signer with the suite credentials
     *
     * @param string $service
     *
     * @return SigV4
     */
    private function signer( $service = 'service' ) {
        return new SigV4( self::ACCESS_KEY, self::SECRET, 'us-east-1', $service, self::NOW );
    }

    public function test_post_vanilla_canonical_request() {
        $expected = "POST\n/\n\nhost:example.amazonaws.com\nx-amz-date:20150830T123600Z\n\nhost;x-amz-date\n" . self::EMPTY_HASH;

        $this->assertSame(
            $expected,
            $this->signer()->canonical_request( 'POST', self::URL, [ 'X-Amz-Date' => '20150830T123600Z' ], self::EMPTY_HASH )
        );
    }

    public function test_post_vanilla_string_to_sign() {
        $signer = $this->signer();
        $creq   = $signer->canonical_request( 'POST', self::URL, [ 'X-Amz-Date' => '20150830T123600Z' ], self::EMPTY_HASH );

        $this->assertSame(
            "AWS4-HMAC-SHA256\n20150830T123600Z\n20150830/us-east-1/service/aws4_request\n553f88c9e4d10fc9e109e2aeb65f030801b70c2f6468faca261d401ae622fc87",
            $signer->string_to_sign( '20150830T123600Z', $signer->credential_scope( '20150830' ), $creq )
        );
    }

    public function test_post_vanilla_authorization() {
        $headers = $this->signer()->sign( 'POST', self::URL, [ 'Host' => 'example.amazonaws.com' ], '' );

        $this->assertSame( '20150830T123600Z', $headers['X-Amz-Date'] );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b',
            $headers['Authorization']
        );
    }

    public function test_get_vanilla_authorization() {
        $headers = $this->signer()->sign( 'GET', self::URL, [], '' );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization']
        );
        $this->assertArrayNotHasKey( 'Host', $headers, 'Host is signed from the URL, not added to the sent headers.' );
    }

    public function test_signing_key_derivation() {
        $this->assertSame(
            'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9',
            bin2hex( $this->signer( 'iam' )->signing_key( '20150830' ) )
        );
    }

    public function test_existing_date_and_authorization_headers_are_replaced() {
        $headers = $this->signer()->sign(
            'GET',
            self::URL,
            [
                'x-amz-date'    => '19990101T000000Z',
                'authorization' => 'stale',
            ],
            ''
        );

        $this->assertArrayNotHasKey( 'x-amz-date', $headers );
        $this->assertArrayNotHasKey( 'authorization', $headers );
        $this->assertStringEndsWith( 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $headers['Authorization'] );
    }

    public function test_extra_headers_are_signed_in_sorted_order() {
        $headers = $this->signer()->sign(
            'POST',
            self::URL,
            [
                'X-Amz-Target' => 'Svc.Op',
                'Content-Type' => 'application/x-amz-json-1.0',
            ],
            '{}'
        );

        $this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date;x-amz-target,', $headers['Authorization'] );
        $this->assertMatchesRegularExpression( '/Signature=[0-9a-f]{64}$/', $headers['Authorization'] );
    }

    public function test_clock_accepts_a_callable() {
        $signer = new SigV4( self::ACCESS_KEY, self::SECRET, 'us-east-1', 'service', function () {
            return self::NOW;
        } );

        $this->assertSame( self::NOW, $signer->now() );
    }
}
