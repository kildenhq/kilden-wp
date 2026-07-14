<?php

use KildenWP\Vendor\Kilden\Client;
use KildenWP\Vendor\Kilden\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end against the spec mock capture server: the vendored (prefixed)
 * core delivers a real WooCommerce order event over the wire and the mock
 * validates it with production rules.
 *
 * Needs KILDEN_MOCK_URL (CI starts the mock from the spec repo checkout);
 * skipped otherwise.
 *
 * @group integration
 */
final class IntegrationTest extends TestCase
{
    /** @var string */
    private $mock;

    protected function setUp(): void
    {
        $url = getenv('KILDEN_MOCK_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('KILDEN_MOCK_URL not set');
        }
        $this->mock = rtrim($url, '/');
        kilden_test_reset();
        $this->control('/__mock/reset', array());
    }

    /** @param array<string, mixed> $body */
    private function control(string $path, array $body): void
    {
        $ch = curl_init($this->mock . $path);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
        ));
        curl_exec($ch);
        curl_close($ch);
    }

    /** @return array<string, mixed> */
    private function captured(): array
    {
        $ch = curl_init($this->mock . '/__mock/captured');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $raw = (string) curl_exec($ch);
        curl_close($ch);

        return json_decode($raw, true);
    }

    public function testOrderCompletedLandsOnTheMock(): void
    {
        $client = new Client('sk_test_secret', array(
            'host'      => $this->mock,
            'transport' => new CurlTransport(),
            'flush_at'  => 1000,
        ));
        add_filter('kilden_pre_client', static function () use ($client) {
            return $client;
        });

        $order = new Kilden_Fake_Order(array(
            'id'       => 3001,
            'total'    => 99.9,
            'currency' => 'CLP',
            'items'    => array(
                array('product_id' => 5, 'name' => 'Láminas Mundial 1982', 'total' => 99.9, 'quantity' => 1),
            ),
        ));
        $order->update_meta_data('_kilden_distinct_id', 'anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b');
        $GLOBALS['kilden_test']['orders'][3001] = $order;

        Kilden_WooCommerce::track_order_completed(3001);
        $client->flush();

        $captured = $this->captured();
        self::assertCount(1, $captured['events']);
        $event = $captured['events'][0];
        self::assertSame('order_completed', $event['event']);
        self::assertSame('anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $event['distinct_id']);
        $properties = json_decode(json_encode($event['properties']), true);
        self::assertSame('3001', $properties['order_id']);
        self::assertSame(99.9, $properties['revenue']);
        self::assertSame('Láminas Mundial 1982', $properties['items'][0]['name']);
    }
}
