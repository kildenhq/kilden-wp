<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\FeatureFlags;

/**
 * The frozen rollout hashing (spec §8.3). Not used by v1 — evaluation is
 * remote — but pinned now, tested against the platform's vectors, so local
 * evaluation can ship later without an API change or a bucketing drift.
 *
 * @internal
 */
final class Rollout
{
    /**
     * bucket = u64_be(sha256(flag_key ":" distinct_id)[0..8)) / 2^64 * 100
     */
    public static function bucket(string $flagKey, string $distinctId): float
    {
        return self::fraction($flagKey . ':' . $distinctId) * 100.0;
    }

    /**
     * Variant pick: independent point (":variant" appended), walked over
     * cumulative weights; first point < cumulative wins.
     *
     * @param list<array{key: string, rollout_percentage: int}> $variants
     * @return string|true
     */
    public static function variantFor(string $flagKey, string $distinctId, array $variants)
    {
        $point = self::fraction($flagKey . ':' . $distinctId . ':variant') * 100.0;
        $cumulative = 0.0;
        foreach ($variants as $variant) {
            $cumulative += (float) $variant['rollout_percentage'];
            if ($point < $cumulative) {
                return $variant['key'];
            }
        }

        return true;
    }

    private static function fraction(string $input): float
    {
        $digest = hash('sha256', $input, true);
        /** @var array{1: int, 2: int} $halves */
        $halves = unpack('N2', substr($digest, 0, 8));
        // Two exact 32-bit halves combined in float space: hi * 2^32 is
        // exact (32 significant bits), the single rounding happens on the
        // addition — identical to a direct uint64→float64 conversion, and
        // free of PHP's signed-integer pitfalls.
        $value = ((float) $halves[1]) * 4294967296.0 + (float) $halves[2];

        return $value / 18446744073709551616.0; // 2^64
    }
}
