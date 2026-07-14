<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\FeatureFlags;

/**
 * Per-distinct_id cache of /decide responses: TTL 30s, LRU-bounded at 1000
 * ids (spec §8.2). PHP arrays preserve insertion order, which is the whole
 * LRU mechanism here: hits re-insert, eviction drops the first key.
 *
 * @internal
 */
final class FlagCache
{
    public const TTL_SECONDS = 30;
    public const MAX_ENTRIES = 1000;

    /** @var array<string, array{at: float, flags: array<string, mixed>}> */
    private $entries = [];

    /**
     * @return array<string, mixed>|null the cached flags map, or null on miss
     */
    public function get(string $distinctId, float $now): ?array
    {
        if (!isset($this->entries[$distinctId])) {
            return null;
        }
        $entry = $this->entries[$distinctId];
        if ($now - $entry['at'] >= self::TTL_SECONDS) {
            unset($this->entries[$distinctId]);

            return null;
        }
        // Refresh recency without touching the stored timestamp: TTL counts
        // from fetch, LRU counts from last use.
        unset($this->entries[$distinctId]);
        $this->entries[$distinctId] = $entry;

        return $entry['flags'];
    }

    /**
     * @param array<string, mixed> $flags
     */
    public function put(string $distinctId, array $flags, float $now): void
    {
        unset($this->entries[$distinctId]);
        while (count($this->entries) >= self::MAX_ENTRIES) {
            unset($this->entries[array_key_first($this->entries)]);
        }
        $this->entries[$distinctId] = ['at' => $now, 'flags' => $flags];
    }
}
