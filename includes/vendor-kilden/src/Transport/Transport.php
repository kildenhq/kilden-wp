<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Transport;

/**
 * The one seam between the SDK and the network. Implementations NEVER throw:
 * every outcome, including a dead socket, is a TransportResponse. WordPress
 * injects its own implementation backed by wp_remote_post(); PSR-18 lives in
 * a separate adapter package. The core ships curl and stream implementations
 * and stays dependency-free.
 */
interface Transport
{
    /**
     * @param string                $url     Absolute URL to POST to.
     * @param string                $body    Raw request body (possibly gzipped).
     * @param array<string, string> $headers Header name => value.
     * @param float                 $timeout Seconds for the whole request.
     */
    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse;
}
