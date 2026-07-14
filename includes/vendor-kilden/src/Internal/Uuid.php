<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Internal;

/**
 * UUID v7 (RFC 9562): 48-bit unix milliseconds + random. Generated per event
 * at call time so retries stay idempotent — ClickHouse dedups by uuid.
 *
 * @internal
 */
final class Uuid
{
    private const CANONICAL = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public static function v7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        // 48-bit big-endian unix milliseconds + 80 random bits, then the
        // version and variant nibbles stamped over the random field.
        $bytes = substr(pack('J', $ms << 16), 0, 6) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC variant

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Canonical RFC 4122 form, any version — explicit caller uuids are sent
     * verbatim (contract 6), they just have to be real UUIDs.
     */
    public static function isValid(string $uuid): bool
    {
        return preg_match(self::CANONICAL, $uuid) === 1;
    }
}
