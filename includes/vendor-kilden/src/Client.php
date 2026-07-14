<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden;

use InvalidArgumentException;
use KildenWP\Vendor\Kilden\FeatureFlags\FlagCache;
use KildenWP\Vendor\Kilden\Internal\Json;
use KildenWP\Vendor\Kilden\Internal\Sender;
use KildenWP\Vendor\Kilden\Internal\Timestamps;
use KildenWP\Vendor\Kilden\Internal\Uuid;
use KildenWP\Vendor\Kilden\Transport\CurlTransport;
use KildenWP\Vendor\Kilden\Transport\StreamTransport;
use KildenWP\Vendor\Kilden\Transport\Transport;
use RuntimeException;
use Throwable;

/**
 * The Kilden server-side client. Constructed with the project's SECRET write
 * key — never the public (wk_) one: secret-key events are source=server,
 * verified=true on the platform.
 *
 * Contract 1: nothing here throws after construction. Contract 2: the
 * constructor fails fast on misconfiguration. The full behavior contract
 * lives in the kilden-sdk-spec repo, which this SDK implements.
 */
final class Client
{
    public const VERSION = '0.1.0-alpha.2';

    private const MAX_BATCH_EVENTS = 1000;
    private const MAX_EVENT_BYTES = 200;
    private const MAX_DISTINCT_ID_BYTES = 512;
    private const CLOSE_DEADLINE_SECONDS = 10.0;

    private const OPTION_KEYS = [
        'host', 'flush_at', 'flush_interval', 'max_queue_size',
        'timeout', 'transport', 'debug', 'enabled',
    ];

    /** @var string */
    private $writeKey;

    /** @var string */
    private $host = 'https://ingest.kilden.io';

    /** @var int */
    private $flushAt = 20;

    /** @var float */
    private $flushInterval = 10.0;

    /** @var int */
    private $maxQueueSize = 10000;

    /** @var float */
    private $timeout = 3.0;

    /** @var bool */
    private $debug = false;

    /** @var bool */
    private $enabled = true;

    /** @var Transport|null */
    private $transport;

    /** @var Sender|null */
    private $sender;

    /** @var FlagCache */
    private $flagCache;

    /** @var list<array<string, mixed>> */
    private $queue = [];

    /** @var int */
    private $droppedCount = 0;

    /** @var bool */
    private $closed = false;

    /** @var float */
    private $lastFlushAt;

    /** @var callable(string): void */
    private $logger;

    /**
     * @param array<string, mixed> $options see the spec: host, flush_at,
     *        flush_interval, max_queue_size, timeout, transport, debug, enabled
     */
    public function __construct(string $secretWriteKey, array $options = [])
    {
        if ($secretWriteKey === '') {
            throw new InvalidArgumentException('Kilden write key is required.');
        }
        if (strpos($secretWriteKey, 'wk_') === 0) {
            throw new InvalidArgumentException(
                'This is a PUBLIC write key (wk_). The server SDK needs the SECRET key: '
                . 'public-key events lose their server-side trust level. Find the secret key in '
                . 'your project settings, and never ship it to a browser.'
            );
        }
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Kilden option(s): ' . implode(', ', $unknown));
        }

        $this->writeKey = $secretWriteKey;
        $this->applyOptions($options);

        $this->logger = static function (string $message): void {
            error_log($message);
        };
        $this->flagCache = new FlagCache();
        $this->lastFlushAt = microtime(true);

        if ($this->enabled) {
            $this->transport = $this->resolveTransport($options);
            $this->sender = new Sender($this->transport, $this->host, $this->writeKey, $this->timeout, null, $this->logger);
            register_shutdown_function(function (): void {
                $this->shutdownFlush();
            });
        }
    }

    /**
     * @param array<string, mixed> $properties event properties, an
     *        associative array (JSON object on the wire)
     * @param array<string, mixed> $opts timestamp (ISO 8601 UTC) and/or uuid
     */
    public function track(string $distinctId, string $event, array $properties = [], array $opts = []): void
    {
        $this->guard(function () use ($distinctId, $event, $properties, $opts): void {
            if (!$this->validIds($distinctId, $event)) {
                return;
            }
            if ($this->debug && strpos($event, '$') === 0) {
                $this->warn("event '{$event}' uses the \$-prefix reserved for Kilden system events; sending anyway");
            }
            $props = Json::asObject($properties);
            if ($props === null) {
                $this->warn("dropping '{$event}': properties must be an associative array (map), not a list");

                return;
            }
            $this->enqueue($distinctId, $event, $props, $opts);
        });
    }

    /**
     * @param array<string, mixed> $traits person traits, applied as $set
     * @param array<string, mixed> $opts timestamp (ISO 8601 UTC) and/or uuid
     */
    public function identify(string $distinctId, array $traits = [], array $opts = []): void
    {
        $this->guard(function () use ($distinctId, $traits, $opts): void {
            if (!$this->validIds($distinctId, '$identify')) {
                return;
            }
            $set = Json::asObject($traits);
            if ($set === null) {
                $this->warn('dropping $identify: traits must be an associative array (map), not a list');

                return;
            }
            $this->enqueue($distinctId, '$identify', ['$set' => $set], $opts);
        });
    }

    /**
     * Attaches $distinctId as a new identity of the person $previousId
     * already resolves to.
     */
    public function alias(string $previousId, string $distinctId): void
    {
        $this->guard(function () use ($previousId, $distinctId): void {
            if ($previousId === '' || $distinctId === '') {
                $this->warn('dropping $alias: previous_id and distinct_id are both required');

                return;
            }
            if (!$this->validIds($previousId, '$alias')) {
                return;
            }
            $this->enqueue($previousId, '$alias', ['$alias' => $distinctId], []);
        });
    }

    /**
     * @param array<string, mixed> $opts person_properties and/or default
     */
    public function isEnabled(string $flagKey, string $distinctId, array $opts = []): bool
    {
        $value = $this->getFeatureFlag($flagKey, $distinctId, $opts);

        return $value === true || is_string($value);
    }

    /**
     * @param array<string, mixed> $opts person_properties and/or default
     * @return mixed false | true | string (variant key), or the caller's default
     */
    public function getFeatureFlag(string $flagKey, string $distinctId, array $opts = [])
    {
        $default = array_key_exists('default', $opts) ? $opts['default'] : false;

        return $this->guard(function () use ($flagKey, $distinctId, $opts, $default) {
            if (!$this->enabled || $this->closed || $flagKey === '' || $distinctId === '') {
                return $default;
            }
            $personProperties = isset($opts['person_properties']) && is_array($opts['person_properties'])
                ? $opts['person_properties']
                : null;

            $flags = $this->fetchFlags($distinctId, $personProperties);
            if ($flags === null) {
                return $default;
            }

            return array_key_exists($flagKey, $flags) ? $flags[$flagKey] : $default;
        }, $default);
    }

    /**
     * Blocking: drains everything queued at this instant, retries included.
     */
    public function flush(): void
    {
        $this->guard(function (): void {
            $this->flushQueue(null);
        });
    }

    /**
     * flush() with the 10-second shutdown deadline, then the client refuses
     * further events. Idempotent.
     */
    public function close(): void
    {
        $this->guard(function (): void {
            if ($this->closed) {
                return;
            }
            $this->flushQueue(microtime(true) + self::CLOSE_DEADLINE_SECONDS);
            $this->closed = true;
        });
    }

    /**
     * Events dropped so far: full queue, invalid input at the wire limits,
     * exhausted retries (contract 7).
     */
    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    // --- internals ---

    /**
     * Contract 1: the public surface never throws after construction.
     *
     * @template T
     * @param callable(): T $fn
     * @param T $fallback
     * @return T
     */
    private function guard(callable $fn, $fallback = null)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->warn('suppressed internal error: ' . $e->getMessage());

            return $fallback;
        }
    }

    private function validIds(string $distinctId, string $event): bool
    {
        if ($distinctId === '') {
            $this->warn("dropping '{$event}': distinct_id is required");

            return false;
        }
        if (strlen($distinctId) > self::MAX_DISTINCT_ID_BYTES) {
            $this->warn("dropping '{$event}': distinct_id exceeds " . self::MAX_DISTINCT_ID_BYTES . ' bytes');

            return false;
        }
        if ($event === '') {
            $this->warn('dropping event: event name is required');

            return false;
        }
        if (strlen($event) > self::MAX_EVENT_BYTES) {
            $this->warn('dropping event: event name exceeds ' . self::MAX_EVENT_BYTES . ' bytes');

            return false;
        }

        return true;
    }

    /**
     * @param array<mixed>|\stdClass $properties
     * @param array<string, mixed> $opts
     */
    private function enqueue(string $distinctId, string $event, $properties, array $opts): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->closed) {
            $this->warn("dropping '{$event}': client is closed");
            ++$this->droppedCount;

            return;
        }

        $timestamp = Timestamps::now();
        if (array_key_exists('timestamp', $opts)) {
            $normalized = Timestamps::normalize($opts['timestamp']);
            if ($normalized === null) {
                $this->warn("dropping '{$event}': timestamp cannot be interpreted as a time");

                return;
            }
            $timestamp = $normalized;
        }

        $uuid = Uuid::v7();
        if (array_key_exists('uuid', $opts)) {
            if (!is_string($opts['uuid']) || !Uuid::isValid($opts['uuid'])) {
                $this->warn("dropping '{$event}': explicit uuid is not a canonical RFC 4122 UUID");

                return;
            }
            $uuid = $opts['uuid'];
        }

        if (Json::encode($properties) === null) {
            $this->warn("dropping '{$event}': properties are not JSON-serializable");

            return;
        }

        if (count($this->queue) >= $this->maxQueueSize) {
            ++$this->droppedCount;
            $this->warn("queue full ({$this->maxQueueSize}), dropping newest event '{$event}'");

            return;
        }

        $this->queue[] = [
            'uuid' => $uuid,
            'event' => $event,
            'distinct_id' => $distinctId,
            'properties' => $properties,
            'timestamp' => $timestamp,
        ];

        $now = microtime(true);
        if (count($this->queue) >= $this->flushAt || $now - $this->lastFlushAt >= $this->flushInterval) {
            $this->flushQueue(null);
        }
    }

    private function flushQueue(?float $deadline): void
    {
        if ($this->sender === null || $this->queue === []) {
            return;
        }
        $this->lastFlushAt = microtime(true);
        while ($this->queue !== []) {
            $chunk = array_splice($this->queue, 0, self::MAX_BATCH_EVENTS);
            if (!$this->sender->sendBatch($chunk, $deadline)) {
                $this->droppedCount += count($chunk);
            }
        }
    }

    /**
     * The shutdown hook: hand the response back to the user first when the
     * SAPI allows it, then drain. Telemetry must never add visible latency.
     */
    private function shutdownFlush(): void
    {
        if ($this->closed || $this->queue === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $this->close();
    }

    /**
     * @param array<mixed>|null $personProperties
     * @return array<string, mixed>|null the flags map, null on any failure
     */
    private function fetchFlags(string $distinctId, ?array $personProperties): ?array
    {
        $bypassCache = $personProperties !== null;
        $now = microtime(true);

        if (!$bypassCache) {
            $cached = $this->flagCache->get($distinctId, $now);
            if ($cached !== null) {
                return $cached;
            }
        }

        $request = ['write_key' => $this->writeKey, 'distinct_id' => $distinctId];
        if ($personProperties !== null) {
            $props = Json::asObject($personProperties);
            if ($props === null) {
                $this->warn('person_properties must be an associative array; ignoring flag call');

                return null;
            }
            $request['person_properties'] = $props;
        }
        $body = Json::encode($request);
        if ($body === null || $this->transport === null) {
            return null;
        }

        // One attempt, no retries: a flag answer that arrives after a retry
        // budget is useless to the caller (spec §8.2).
        $response = $this->transport->send(
            rtrim($this->host, '/') . '/decide',
            $body,
            ['Content-Type' => 'application/json', 'User-Agent' => 'kilden-php/' . self::VERSION],
            $this->timeout
        );
        if ($response->isNetworkError() || $response->status() !== 200) {
            $this->warn('decide failed: ' . ($response->isNetworkError() ? $response->errorMessage() : 'HTTP ' . $response->status()));

            return null;
        }
        $decoded = json_decode($response->body(), true);
        if (!is_array($decoded) || !isset($decoded['flags']) || !is_array($decoded['flags'])) {
            $this->warn('decide returned a malformed body');

            return null;
        }

        /** @var array<string, mixed> $flags */
        $flags = $decoded['flags'];
        if (!$bypassCache) {
            $this->flagCache->put($distinctId, $flags, $now);
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyOptions(array $options): void
    {
        if (isset($options['host'])) {
            $host = $options['host'];
            if (!is_string($host) || $host === '') {
                throw new InvalidArgumentException('host must be a non-empty string.');
            }
            $this->host = $host;
        }
        $this->flushAt = self::intOption($options, 'flush_at', $this->flushAt);
        $this->maxQueueSize = self::intOption($options, 'max_queue_size', $this->maxQueueSize);
        $this->flushInterval = self::secondsOption($options, 'flush_interval', $this->flushInterval);
        $this->timeout = self::secondsOption($options, 'timeout', $this->timeout);
        $this->debug = self::boolOption($options, 'debug', $this->debug);
        $this->enabled = self::boolOption($options, 'enabled', $this->enabled);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, string $key, int $default): int
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = $options[$key];
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function secondsOption(array $options, string $key, float $default): float
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = $options[$key];
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException("{$key} must be a positive number of seconds.");
        }
        $seconds = (float) $value;
        if ($seconds <= 0) {
            throw new InvalidArgumentException("{$key} must be a positive number of seconds.");
        }

        return $seconds;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function boolOption(array $options, string $key, bool $default): bool
    {
        if (!isset($options[$key])) {
            return $default;
        }
        $value = $options[$key];
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$key} must be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveTransport(array $options): Transport
    {
        if (isset($options['transport'])) {
            if (!$options['transport'] instanceof Transport) {
                throw new InvalidArgumentException('transport must implement Kilden\Transport\Transport.');
            }

            return $options['transport'];
        }
        if (CurlTransport::available()) {
            return new CurlTransport();
        }
        if (StreamTransport::available()) {
            return new StreamTransport();
        }

        throw new RuntimeException(
            'No HTTP transport available: ext-curl is not loaded and allow_url_fopen is off. '
            . 'Enable one of them, inject a custom transport, or construct with [\'enabled\' => false].'
        );
    }

    private function warn(string $message): void
    {
        ($this->logger)('kilden: ' . $message);
    }
}
