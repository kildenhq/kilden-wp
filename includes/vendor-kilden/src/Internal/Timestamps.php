<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * The wire format is frozen for every SDK: YYYY-MM-DDTHH:MM:SS.mmmZ — UTC,
 * exactly three fractional digits, Z suffix (SPEC.md §4.4).
 *
 * @internal
 */
final class Timestamps
{
    public static function now(): string
    {
        [$usec, $sec] = explode(' ', microtime());

        return gmdate('Y-m-d\TH:i:s', (int) $sec) . sprintf('.%03dZ', (int) floor(((float) $usec) * 1000));
    }

    /**
     * Normalizes a caller-supplied timestamp (ISO 8601 string or
     * DateTimeInterface) to the wire format. Null means "cannot be
     * interpreted as a time" — the caller drops the event with a warning.
     *
     * @param mixed $value
     */
    public static function normalize($value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            $utc = (new DateTimeImmutable('@' . $value->format('U.u')))->setTimezone(new DateTimeZone('UTC'));

            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($value) && $value !== '') {
            try {
                $parsed = new DateTimeImmutable($value);
            } catch (Exception $e) {
                return null;
            }

            return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }

        return null;
    }
}
