<?php
/**
 * Transport backed by wp_remote_post(). WordPress solved HTTP portability
 * fifteen years ago — curl or streams depending on the host, the site's
 * configured proxies, the host's filters — so on WordPress we do not
 * reimplement any of it (docs/27 §3).
 */

if (!defined('ABSPATH')) {
    exit;
}

use KildenWP\Vendor\Kilden\Transport\Transport;
use KildenWP\Vendor\Kilden\Transport\TransportResponse;

class Kilden_WP_Transport implements Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse
    {
        $response = wp_remote_post($url, array(
            'body'    => $body,
            'headers' => $headers,
            'timeout' => $timeout,
            // Telemetry never follows redirects: a redirected capture
            // endpoint is a misconfiguration, not something to paper over.
            'redirection' => 0,
        ));

        if (is_wp_error($response)) {
            return TransportResponse::failure($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $resp_headers = array();
        foreach ((array) wp_remote_retrieve_headers($response) as $name => $value) {
            $resp_headers[(string) $name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return new TransportResponse($status, (string) wp_remote_retrieve_body($response), $resp_headers);
    }
}
