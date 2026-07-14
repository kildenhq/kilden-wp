<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Internal;

use KildenWP\Vendor\Kilden\Transport\Transport;

/**
 * Owns one capture request end to end: payload encoding, gzip, and the
 * frozen retry policy (spec §4.3). Failed batches never go back to the main
 * queue — this loop owns them until success or exhaustion.
 *
 * @internal
 */
final class Sender
{
    private const MAX_RETRIES = 3;
    private const GZIP_THRESHOLD_BYTES = 1024;

    /** @var Transport */
    private $transport;

    /** @var string */
    private $captureUrl;

    /** @var string */
    private $writeKey;

    /** @var float */
    private $timeout;

    /** @var callable(float): void */
    private $sleeper;

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(float): void|null $sleeper injectable for tests
     * @param callable(string): void|null $logger
     */
    public function __construct(
        Transport $transport,
        string $host,
        string $writeKey,
        float $timeout,
        ?callable $sleeper = null,
        ?callable $logger = null
    ) {
        $this->transport = $transport;
        $this->captureUrl = rtrim($host, '/') . '/capture';
        $this->writeKey = $writeKey;
        $this->timeout = $timeout;
        $this->sleeper = $sleeper !== null ? $sleeper : static function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        };
        $this->logger = $logger !== null ? $logger : static function (string $message): void {
            error_log($message);
        };
    }

    /**
     * @param list<array<string, mixed>> $events already-validated envelopes
     * @param float|null $deadline monotonic instant (microtime(true)) after
     *                             which no more waiting is allowed (close())
     * @return bool true when the batch was accepted
     */
    public function sendBatch(array $events, ?float $deadline = null): bool
    {
        $payload = Json::encode([
            'write_key' => $this->writeKey,
            'sent_at' => Timestamps::now(),
            'batch' => $events,
        ]);
        if ($payload === null) {
            ($this->logger)('kilden: dropping batch of ' . count($events) . ' events: payload is not JSON-serializable');

            return false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'kilden-php/' . \Kilden\Client::VERSION,
        ];
        $body = $payload;
        if (strlen($payload) > self::GZIP_THRESHOLD_BYTES && function_exists('gzencode')) {
            $gzipped = gzencode($payload);
            if ($gzipped !== false) {
                $body = $gzipped;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $attempt = 0;
        while (true) {
            $response = $this->transport->send($this->captureUrl, $body, $headers, $this->timeout);
            $outcome = $this->classify($response);

            if ($outcome === 'ok') {
                return true;
            }
            if ($outcome === 'drop') {
                ($this->logger)(sprintf(
                    'kilden: dropping batch of %d events: HTTP %d %s',
                    count($events),
                    $response->status(),
                    trim($response->body())
                ));

                return false;
            }

            // retryable
            if ($attempt >= self::MAX_RETRIES) {
                ($this->logger)(sprintf(
                    'kilden: dropping batch of %d events after %d attempts (%s)',
                    count($events),
                    $attempt + 1,
                    $response->isNetworkError() ? $response->errorMessage() : 'HTTP ' . $response->status()
                ));

                return false;
            }
            ++$attempt;
            $wait = $this->backoff($attempt, $response->status() === 429 ? $response->header('Retry-After') : null);
            if ($deadline !== null && microtime(true) + $wait > $deadline) {
                ($this->logger)('kilden: shutdown deadline reached, dropping batch of ' . count($events) . ' events');

                return false;
            }
            ($this->sleeper)($wait);
        }
    }

    /**
     * @return string 'ok' | 'drop' | 'retry'
     */
    private function classify(\KildenWP\Vendor\Kilden\Transport\TransportResponse $response): string
    {
        if ($response->isNetworkError()) {
            return 'retry';
        }
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            // Any 2xx is success — the response body is never parsed; the
            // status is the whole signal (SPEC.md §4.3).
            return 'ok';
        }
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }

        return 'drop';
    }

    /**
     * Backoff before retry n (1-based): min(0.5 * 2^(n-1), 30) seconds with
     * jitter in [0.5, 1.5]. Retry-After (429) replaces it, without jitter.
     */
    private function backoff(int $retry, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(0.0, (float) $retryAfter);
        }
        $base = min(0.5 * (2 ** ($retry - 1)), 30.0);
        $jitter = 0.5 + mt_rand() / mt_getrandmax();

        return $base * $jitter;
    }
}
