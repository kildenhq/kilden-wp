<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Transport;

final class CurlTransport implements Transport
{
    public static function available(): bool
    {
        return \extension_loaded('curl');
    }

    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return TransportResponse::failure('curl_init failed');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
            // The SDK never decompresses responses, but an empty string lets
            // curl negotiate transparently if the server ever compresses.
            CURLOPT_ENCODING => '',
        ]);

        $responseBody = curl_exec($handle);
        if ($responseBody === false || $responseBody === true) {
            $message = curl_error($handle);
            curl_close($handle);

            return TransportResponse::failure($message === '' ? 'curl request failed' : $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new TransportResponse($status, $responseBody, $responseHeaders);
    }
}
