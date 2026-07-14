<?php

use KildenWP\Vendor\Kilden\Transport\Transport;
use KildenWP\Vendor\Kilden\Transport\TransportResponse;

/**
 * Records every request the client sends and answers 200 — the wire-level
 * spy the unit tests inspect.
 */
class Kilden_Recording_Transport implements Transport
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public $requests = array();

    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse
    {
        $decoded = $body;
        if (isset($headers['Content-Encoding']) && $headers['Content-Encoding'] === 'gzip') {
            $decoded = (string) gzdecode($body);
        }
        $this->requests[] = array('url' => $url, 'body' => $decoded, 'headers' => $headers);

        return new TransportResponse(200, '{"status":"ok"}');
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        $events = array();
        foreach ($this->requests as $request) {
            $payload = json_decode($request['body'], true);
            foreach ($payload['batch'] ?? array() as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
