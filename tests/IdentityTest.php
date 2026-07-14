<?php

use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        kilden_test_reset();
        update_option(Kilden_Settings::OPTION, array(
            'identity_secret' => 'test-identity-secret',
            'identity_kid'    => 'k1',
        ));
        Kilden_Settings::reset_cache();
    }

    public function testAnonymousVisitorGets204(): void
    {
        $GLOBALS['kilden_test']['current_user'] = null;

        $response = Kilden_Identity::handle(null);

        self::assertSame(204, $response->status);
        self::assertSame(1, $GLOBALS['kilden_test']['nocache_calls']);
        self::assertStringContainsString('no-store', $response->headers['Cache-Control']);
    }

    public function testLoggedInVisitorGetsSignedIdentity(): void
    {
        $GLOBALS['kilden_test']['current_user'] = new WP_User(42, 'user@example.com', 'Test User');

        $response = Kilden_Identity::handle(null);

        self::assertSame(200, $response->status);
        self::assertSame('42', $response->data['distinct_id']);
        self::assertSame('user@example.com', $response->data['traits']['email']);
        self::assertStringContainsString('no-store', $response->headers['Cache-Control']);

        // The token verifies: HS256, kid in the header, sub matches.
        $token = $response->data['token'];
        list($h, $p, $sig) = explode('.', $token);
        $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
        $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

        self::assertSame(array('alg' => 'HS256', 'kid' => 'k1', 'typ' => 'JWT'), $header);
        self::assertSame('42', $payload['sub']);
        self::assertGreaterThan(time(), $payload['exp']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'test-identity-secret', true)), '+/', '-_'), '=');
        self::assertSame($expected, $sig);
    }

    public function testTraitsAndDistinctIdAreFilterable(): void
    {
        $GLOBALS['kilden_test']['current_user'] = new WP_User(42, 'user@example.com', 'Test User');
        add_filter('kilden_distinct_id_for_user', static function ($id, $user) {
            return 'customer_' . $id;
        });
        add_filter('kilden_identity_traits', static function ($traits) {
            $traits['plan'] = 'pro';

            return $traits;
        });

        $response = Kilden_Identity::handle(null);

        self::assertSame('customer_42', $response->data['distinct_id']);
        self::assertSame('pro', $response->data['traits']['plan']);
    }

    public function testMissingSignerConfigTurnsFeatureOff(): void
    {
        update_option(Kilden_Settings::OPTION, array('identity_secret' => '', 'identity_kid' => ''));
        Kilden_Settings::reset_cache();

        self::assertFalse(Kilden_Identity::active());

        $GLOBALS['kilden_test']['current_user'] = new WP_User(42);
        $response = Kilden_Identity::handle(null);
        self::assertSame(204, $response->status);
    }
}
