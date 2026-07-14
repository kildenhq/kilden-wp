<?php

use KildenWP\Vendor\Kilden\Client;
use PHPUnit\Framework\TestCase;

final class WooCommerceTest extends TestCase
{
    /** @var Kilden_Recording_Transport */
    private $transport;

    protected function setUp(): void
    {
        kilden_test_reset();
        $this->transport = new Kilden_Recording_Transport();
        $client = new Client('sk_test_secret', array(
            'transport' => $this->transport,
            'flush_at'  => 1000,
        ));
        add_filter('kilden_pre_client', static function () use ($client) {
            return $client;
        });
        $GLOBALS['kilden_test']['client'] = $client;
    }

    private function order(array $data = array()): Kilden_Fake_Order
    {
        $order = new Kilden_Fake_Order($data);
        $GLOBALS['kilden_test']['orders'][$order->get_id()] = $order;

        return $order;
    }

    /** @return list<array<string, mixed>> */
    private function sentEvents(): array
    {
        $GLOBALS['kilden_test']['client']->flush();

        return $this->transport->events();
    }

    public function testOrderCompletedMapsTheSpecProperties(): void
    {
        $order = $this->order(array(
            'id'       => 2001,
            'total'    => 64.98,
            'currency' => 'CLP',
            'coupons'  => array('WELCOME10'),
            'items'    => array(
                array('product_id' => 11, 'name' => 'Poster A2', 'total' => 39.98, 'quantity' => 2),
                array('product_id' => 12, 'name' => 'Frame', 'total' => 25.0, 'quantity' => 1),
            ),
        ));
        $order->update_meta_data('_kilden_distinct_id', 'anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b');

        Kilden_WooCommerce::track_order_completed(2001);

        $events = $this->sentEvents();
        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame('order_completed', $event['event']);
        self::assertSame('anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $event['distinct_id']);
        self::assertSame('2001', $event['properties']['order_id']);
        self::assertSame(64.98, $event['properties']['revenue']);
        self::assertSame('CLP', $event['properties']['currency']);
        self::assertSame('WELCOME10', $event['properties']['coupon']);
        self::assertSame(array(
            array('product_id' => '11', 'name' => 'Poster A2', 'price' => 19.99, 'quantity' => 2),
            array('product_id' => '12', 'name' => 'Frame', 'price' => 25, 'quantity' => 1),
        ), $event['properties']['items']);
    }

    public function testPaymentCompleteAndStatusCompletedFireOnce(): void
    {
        $this->order(array('id' => 2002));

        Kilden_WooCommerce::track_order_completed(2002);
        Kilden_WooCommerce::track_order_completed(2002); // the fallback hook

        self::assertCount(1, $this->sentEvents());
    }

    public function testDistinctIdPrecedence(): void
    {
        // 1. Bridged meta wins.
        $bridged = $this->order(array('id' => 1, 'customer_id' => 7));
        $bridged->update_meta_data('_kilden_distinct_id', 'anon_from_browser');
        self::assertSame('anon_from_browser', Kilden_WooCommerce::distinct_id_for($bridged));

        // 2. Then the logged-in customer id.
        $customer = $this->order(array('id' => 2, 'customer_id' => 7));
        self::assertSame('7', Kilden_WooCommerce::distinct_id_for($customer));

        // 3. Last resort: deterministic per-order id.
        $guest = $this->order(array('id' => 3));
        self::assertSame('anon_wp_order_3', Kilden_WooCommerce::distinct_id_for($guest));
    }

    public function testRefundEvent(): void
    {
        $this->order(array('id' => 2004, 'currency' => 'CLP'));
        $refund = $this->order(array('id' => 9001, 'amount' => 15.5));

        Kilden_WooCommerce::track_order_refunded(2004, 9001);

        $events = $this->sentEvents();
        self::assertCount(1, $events);
        self::assertSame('order_refunded', $events[0]['event']);
        self::assertSame('2004', $events[0]['properties']['order_id']);
        self::assertSame(15.5, $events[0]['properties']['refund_amount']);
    }

    public function testClassicCheckoutBridgePersistsPostedId(): void
    {
        $order = $this->order(array('id' => 2005));
        $_POST['kilden_distinct_id'] = 'anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b';

        Kilden_WooCommerce::persist_distinct_id_classic($order);

        self::assertSame('anon_0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b', $order->get_meta('_kilden_distinct_id'));
        unset($_POST['kilden_distinct_id']);
    }

    public function testClassicBridgeRejectsOversizeIds(): void
    {
        $order = $this->order(array('id' => 2006));
        $_POST['kilden_distinct_id'] = str_repeat('x', 513);

        Kilden_WooCommerce::persist_distinct_id_classic($order);

        self::assertSame('', $order->get_meta('_kilden_distinct_id'));
        unset($_POST['kilden_distinct_id']);
    }

    public function testMissingClientIsANoop(): void
    {
        kilden_test_reset(); // drops the pre_client filter and any secret key
        $this->order(array('id' => 2007));

        Kilden_WooCommerce::track_order_completed(2007);

        self::assertSame(array(), $this->transport->requests);
    }
}
