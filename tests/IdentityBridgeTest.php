<?php

use PHPUnit\Framework\TestCase;

/**
 * Who an order belongs to.
 *
 * The bridged id arrives from the visitor's browser — a hidden field on the
 * classic checkout, a REST call on the blocks one. It is useful (it carries
 * the anonymous id the visitor browsed under, so the order joins the rest of
 * their journey) and it is entirely untrusted: anything reaching the platform
 * from here is sent server-side with the secret key, which the platform reads
 * as authenticated fact (verified=1). So the browser may hand us an anonymous
 * id to stitch to, and nothing else.
 */
final class IdentityBridgeTest extends TestCase
{
    /** Mirror of kilden-core's internal/verify.AnonPattern (docs/11). */
    private const ANON = '/^anon_[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected function setUp(): void
    {
        kilden_test_reset();
    }

    private function order(array $data = array()): Kilden_Fake_Order
    {
        $order = new Kilden_Fake_Order($data);
        $GLOBALS['kilden_test']['orders'][$order->get_id()] = $order;

        return $order;
    }

    public function testWellFormedBridgedAnonymousIdIsUsed(): void
    {
        $order = $this->order(array('id' => 3001));
        $order->update_meta_data('_kilden_distinct_id', 'anon_019f6db7-7941-7290-93fe-66e1e112e2b2');

        $this->assertSame(
            'anon_019f6db7-7941-7290-93fe-66e1e112e2b2',
            Kilden_WooCommerce::distinct_id_for($order)
        );
    }

    public function testAuthenticatedCustomerOutranksTheBridgedId(): void
    {
        // A logged-in buyer whose browser claims to be someone else. The site
        // authenticated customer 42; the hidden field is just a form input.
        $order = $this->order(array('id' => 3002, 'customer_id' => 42));
        $order->update_meta_data('_kilden_distinct_id', 'anon_019f6db7-7941-7290-93fe-66e1e112e2b2');

        $this->assertSame('42', Kilden_WooCommerce::distinct_id_for($order));
    }

    public function testBridgedIdClaimingAnotherIdentityIsIgnored(): void
    {
        // The attack: a guest edits the hidden field to name a victim, and
        // their order's revenue lands on the victim's timeline as verified
        // fact. Only the anonymous shape is accepted, so this cannot happen.
        $order = $this->order(array('id' => 3003));
        $order->update_meta_data('_kilden_distinct_id', 'victim_ceo_user_1');

        $this->assertNotSame('victim_ceo_user_1', Kilden_WooCommerce::distinct_id_for($order));
        $this->assertMatchesRegularExpression(self::ANON, Kilden_WooCommerce::distinct_id_for($order));
    }

    public function testBridgedIdWithAnonPrefixButWrongShapeIsIgnored(): void
    {
        // `anon_` alone proves nothing: the platform only reads an id as
        // anonymous when the whole thing is anon_ + a canonical UUIDv7.
        // Anything else is read as a customer-declared *identified* id.
        $order = $this->order(array('id' => 3004));
        $order->update_meta_data('_kilden_distinct_id', 'anon_af9126bfd770fe62f1627663d93fd6cb');

        $this->assertNotSame('anon_af9126bfd770fe62f1627663d93fd6cb', Kilden_WooCommerce::distinct_id_for($order));
        $this->assertMatchesRegularExpression(self::ANON, Kilden_WooCommerce::distinct_id_for($order));
    }

    public function testFallbackIsAWellFormedAnonymousIdAndIsStable(): void
    {
        // Guest checkout where the bridge never ran (JS blocked, headless
        // order). We do not know who this is, so the id must say exactly
        // that — in the shape the platform recognises — rather than invent
        // an identified person per order.
        $order = $this->order(array('id' => 3005));

        $first = Kilden_WooCommerce::distinct_id_for($order);
        $this->assertMatchesRegularExpression(self::ANON, $first);
        // Deterministic: reprocessing the same order lands on the same person.
        $this->assertSame($first, Kilden_WooCommerce::distinct_id_for($order));
    }

    public function testFallbackDiffersPerOrder(): void
    {
        $a = Kilden_WooCommerce::distinct_id_for($this->order(array('id' => 3006)));
        $b = Kilden_WooCommerce::distinct_id_for($this->order(array('id' => 3007)));

        $this->assertNotSame($a, $b);
    }

    public function testPersistRefusesToStoreAValueThatIsNotAnonymous(): void
    {
        // Rejected on the way in as well as on the way out: a spoofed value
        // should never reach the database in the first place.
        $order = $this->order(array('id' => 3008));
        $_POST['kilden_distinct_id'] = 'victim_ceo_user_1';

        Kilden_WooCommerce::persist_distinct_id_classic($order, null);

        $this->assertSame('', (string) $order->get_meta('_kilden_distinct_id'));
        unset($_POST['kilden_distinct_id']);
    }

    public function testPersistStoresAWellFormedAnonymousId(): void
    {
        $order = $this->order(array('id' => 3009));
        $_POST['kilden_distinct_id'] = 'anon_019f6db7-7941-7290-93fe-66e1e112e2b2';

        Kilden_WooCommerce::persist_distinct_id_classic($order, null);

        $this->assertSame('anon_019f6db7-7941-7290-93fe-66e1e112e2b2', (string) $order->get_meta('_kilden_distinct_id'));
        unset($_POST['kilden_distinct_id']);
    }

    // --- blocks checkout: the id travels through the Woo session ---

    public function testBlocksBridgeStoresTheSessionAnonymousId(): void
    {
        $order = $this->order(array('id' => 3010));
        WC()->session = new Kilden_Fake_WC_Session();
        WC()->session->set('kilden_distinct_id', 'anon_019f6db7-7941-7290-93fe-66e1e112e2b2');

        Kilden_WooCommerce::persist_distinct_id_blocks($order);

        $this->assertSame('anon_019f6db7-7941-7290-93fe-66e1e112e2b2', (string) $order->get_meta('_kilden_distinct_id'));
    }

    public function testBlocksBridgeRefusesANonAnonymousSessionValue(): void
    {
        $order = $this->order(array('id' => 3011));
        WC()->session = new Kilden_Fake_WC_Session();
        WC()->session->set('kilden_distinct_id', 'victim_ceo_user_1');

        Kilden_WooCommerce::persist_distinct_id_blocks($order);

        $this->assertSame('', (string) $order->get_meta('_kilden_distinct_id'));
    }

    public function testBridgeRouteRejectsAnIdThatIsNotAnonymous(): void
    {
        $response = Kilden_WooCommerce::handle_bridge(new Kilden_Fake_Request(array('distinct_id' => 'victim_ceo_user_1')));

        $this->assertSame(400, $response->status);
        $this->assertSame(array('status' => 'ignored'), $response->data);
    }

    public function testBridgeRouteAsksWooToBootTheSessionItNeeds(): void
    {
        // WooCommerce boots no session on a custom REST route, so without
        // this the route wrote to nothing — and still answered 200, which is
        // how the default checkout lost every guest's id in silence.
        $this->assertNull(WC()->session);

        $response = Kilden_WooCommerce::handle_bridge(
            new Kilden_Fake_Request(array('distinct_id' => 'anon_019f6db7-7941-7290-93fe-66e1e112e2b2'))
        );

        $this->assertSame(1, $GLOBALS['kilden_test']['wc_load_cart_calls']);
        $this->assertSame(200, $response->status);
        $this->assertSame(
            'anon_019f6db7-7941-7290-93fe-66e1e112e2b2',
            WC()->session->get('kilden_distinct_id')
        );
    }
}
