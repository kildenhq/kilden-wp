<?php

use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    protected function setUp(): void
    {
        kilden_test_reset();
    }

    public function testDelegatesToWpRemotePost(): void
    {
        $transport = new Kilden_WP_Transport();
        $response = $transport->send('https://ingest.example/capture', '{"a":1}', array('Content-Type' => 'application/json'), 3.0);

        $posts = $GLOBALS['kilden_test']['remote_posts'];
        self::assertCount(1, $posts);
        self::assertSame('https://ingest.example/capture', $posts[0]['url']);
        self::assertSame('{"a":1}', $posts[0]['args']['body']);
        self::assertSame('application/json', $posts[0]['args']['headers']['Content-Type']);
        self::assertSame(3.0, $posts[0]['args']['timeout']);
        self::assertSame(0, $posts[0]['args']['redirection']);

        self::assertSame(200, $response->status());
        self::assertFalse($response->isNetworkError());
    }

    public function testWpErrorBecomesNetworkError(): void
    {
        $GLOBALS['kilden_test']['remote_response'] = new WP_Error('http_request_failed', 'cURL error 28');

        $transport = new Kilden_WP_Transport();
        $response = $transport->send('https://ingest.example/capture', '{}', array(), 3.0);

        self::assertTrue($response->isNetworkError());
        self::assertSame('cURL error 28', $response->errorMessage());
    }

    public function testResponseHeadersSurvive(): void
    {
        $GLOBALS['kilden_test']['remote_response'] = array(
            'response' => array('code' => 429),
            'body'     => 'Too Many Requests',
            'headers'  => array('Retry-After' => '7'),
        );

        $response = (new Kilden_WP_Transport())->send('https://x/capture', '{}', array(), 3.0);

        self::assertSame(429, $response->status());
        self::assertSame('7', $response->header('retry-after'));
    }
}
