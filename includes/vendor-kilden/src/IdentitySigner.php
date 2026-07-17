<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden;

use InvalidArgumentException;

/**
 * Signs the short-lived identity tokens Kilden's trust model runs on
 * (kilden.io/docs/identity-verification). Deliberately separate from Client:
 * a page-rendering controller wants a token, not an event queue.
 *
 * The output is byte-frozen across every Kilden server SDK (spec §6.1) —
 * a wrong signature fails silently as verified=false, so this class matches
 * the platform's reference vectors exactly instead of trusting a JWT
 * library's serialization choices.
 *
 * SECURITY: only sign a $sub your backend authenticated. Signing
 * request input ($signer->sign($_POST['user_id'])) lets anyone impersonate
 * anyone — with a "verified" stamp on top.
 */
final class IdentitySigner
{
    private const MAX_TTL = 604800; // 7 days; identity tokens are short-lived by design

    /** @var string */
    private $secret;

    /** @var string */
    private $kid;

    /**
     * @param array<string, mixed> $options 'kid' (required)
     */
    public function __construct(string $identitySecret, array $options = [])
    {
        if ($identitySecret === '') {
            throw new InvalidArgumentException('Kilden identity secret must not be empty.');
        }
        $kid = isset($options['kid']) ? $options['kid'] : '';
        if (!is_string($kid) || $kid === '') {
            throw new InvalidArgumentException(
                "IdentitySigner requires a 'kid': the platform looks the secret up by key id, and a token without a known kid never verifies."
            );
        }
        $unknown = array_diff(array_keys($options), ['kid']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown IdentitySigner option(s): ' . implode(', ', $unknown));
        }

        $this->secret = $identitySecret;
        $this->kid = $kid;
    }

    /**
     * @param array<string, mixed> $opts ttl (seconds) and/or traits (map)
     */
    public function sign(string $sub, array $opts = []): string
    {
        if ($sub === '') {
            throw new InvalidArgumentException('sub must be the authenticated distinct_id; it cannot be empty.');
        }
        $unknown = array_diff(array_keys($opts), ['ttl', 'traits']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown sign() option(s): ' . implode(', ', $unknown));
        }

        $ttl = isset($opts['ttl']) ? $opts['ttl'] : 3600;
        if (!is_int($ttl) || $ttl <= 0 || $ttl > self::MAX_TTL) {
            throw new InvalidArgumentException('ttl must be an integer in (0, 604800] seconds — identity tokens must expire.');
        }
        $traits = isset($opts['traits']) ? $opts['traits'] : [];
        if (!is_array($traits)) {
            throw new InvalidArgumentException('traits must be an associative array.');
        }

        $iat = time();

        return $this->signAt($sub, $iat, $iat + $ttl, $traits);
    }

    /**
     * Fixed-instant variant, used by the spec vector runner. Not part of the
     * public surface contract but stable for testing.
     *
     * @param array<mixed> $traits
     */
    public function signAt(string $sub, int $iat, int $exp, array $traits = []): string
    {
        $header = ['alg' => 'HS256', 'kid' => $this->kid, 'typ' => 'JWT'];
        $payload = ['exp' => $exp, 'iat' => $iat, 'sub' => $sub];
        if ($traits !== []) {
            $payload['traits'] = $traits;
        }

        $signingInput = self::base64url(self::canonicalJson($header))
            . '.' . self::base64url(self::canonicalJson($payload));
        $signature = hash_hmac('sha256', $signingInput, $this->secret, true);

        return $signingInput . '.' . self::base64url($signature);
    }

    /**
     * Canonical JSON (spec §6.1): keys sorted lexicographically at every
     * nesting level, compact separators, UTF-8 preserved, the three
     * HTML-unsafe chars escaped as lowercase \u0026 / \u003c / \u003e and the
     * JS line separators U+2028/U+2029 as \u2028 / \u2029, matching the
     * platform's Go serializer byte for byte.
     *
     * @param array<string, mixed> $value
     */
    private static function canonicalJson(array $value): string
    {
        $sorted = self::sortKeysRecursively($value);
        $encoded = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new InvalidArgumentException('traits are not JSON-serializable: ' . json_last_error_msg());
        }

        // PHP's JSON_HEX_* flags emit uppercase hex; the platform's Go
        // serializer emits lowercase (\u003c). Raw &, <, > (and the JS line
        // separators) only ever appear inside string values at this point,
        // so a plain replace is exact.
        return str_replace(
            ['&', '<', '>', "\u{2028}", "\u{2029}"],
            ['\u0026', '\u003c', '\u003e', '\u2028', '\u2029'],
            $encoded
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeysRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortKeysRecursively($item);
        }

        return $value;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
