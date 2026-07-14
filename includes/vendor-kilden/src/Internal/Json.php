<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Internal;

use stdClass;

/**
 * @internal
 */
final class Json
{
    /**
     * Wire-format encoding: UTF-8 preserved, slashes unescaped. Returns null
     * when the value cannot be represented as JSON (recursion, resources,
     * invalid UTF-8) — the caller drops the event instead of throwing
     * (contract 1).
     *
     * @param mixed $value
     */
    public static function encode($value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /**
     * A properties/traits map must reach the wire as a JSON object. PHP's
     * empty array is ambiguous ([] vs {}), so empty maps become stdClass.
     * A non-empty list (sequential integer keys) is not a map at all —
     * null tells the caller to drop the event.
     *
     * @param array<mixed> $map
     * @return array<mixed>|stdClass|null
     */
    public static function asObject(array $map)
    {
        if ($map === []) {
            return new stdClass();
        }
        if (self::isList($map)) {
            return null;
        }

        return $map;
    }

    /**
     * @param array<mixed> $array
     */
    private static function isList(array $array): bool
    {
        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            ++$expected;
        }

        return true;
    }
}
