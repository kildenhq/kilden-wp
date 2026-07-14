<?php

declare(strict_types=1);

namespace KildenWP\Vendor\Kilden\Transport;

/**
 * HTTP over stream wrappers (file_get_contents). The fallback for hosts
 * without ext-curl; requires allow_url_fopen. Slower and cruder than curl,
 * but it works on the shared hosting the PHP floor exists for.
 */
final class StreamTransport implements Transport
{
    public static function available(): bool
    {
        return (bool) ini_get('allow_url_fopen');
    }

    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $body,
                'timeout' => $timeout,
                // Without this a non-2xx response becomes a PHP warning and
                // a false return instead of a readable status code.
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $error = error_get_last();

            return TransportResponse::failure($error !== null ? $error['message'] : 'stream request failed');
        }
        $rawHeaders = $http_response_header;

        $status = 0;
        $responseHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // Redirect chains repeat status lines; the last one wins and
                // resets the header set, mirroring what the client saw last.
                $status = (int) $m[1];
                $responseHeaders = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
        }

        return new TransportResponse($status, $responseBody, $responseHeaders);
    }
}
