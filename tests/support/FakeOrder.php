<?php
/**
 * The slice of WC_Order the integration reads. WooCommerce itself is not a
 * test dependency: the mapping is pure data.
 */

class Kilden_Fake_Order_Item
{
    /** @var array<string, mixed> */
    private $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get_product_id()
    {
        return $this->data['product_id'];
    }

    public function get_name()
    {
        return $this->data['name'];
    }

    public function get_total()
    {
        return $this->data['total'];
    }

    public function get_quantity()
    {
        return $this->data['quantity'];
    }
}

class Kilden_Fake_Order
{
    /** @var array<string, mixed> */
    public $meta = array();

    /** @var array<string, mixed> */
    private $data;

    /** @var int */
    public $saves = 0;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = array())
    {
        $this->data = array_merge(array(
            'id'          => 1001,
            'total'       => 149.90,
            'currency'    => 'CLP',
            'customer_id' => 0,
            'coupons'     => array(),
            'items'       => array(),
        ), $data);
    }

    public function get_id()
    {
        return $this->data['id'];
    }

    public function get_total()
    {
        return $this->data['total'];
    }

    public function get_currency()
    {
        return $this->data['currency'];
    }

    public function get_customer_id()
    {
        return $this->data['customer_id'];
    }

    public function get_coupon_codes()
    {
        return $this->data['coupons'];
    }

    /** @return list<Kilden_Fake_Order_Item> */
    public function get_items()
    {
        return array_map(static function ($item) {
            return new Kilden_Fake_Order_Item($item);
        }, $this->data['items']);
    }

    public function get_meta($key)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save(): void
    {
        $this->saves++;
    }

    public function get_amount()
    {
        return $this->data['amount'] ?? 0.0;
    }
}
