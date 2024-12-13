<?php
/**
 * Plugin Name: WC Admin Auto Shipping Recalc
 * Description: محاسبه خودکار هزینه حمل فقط زمانی که دکمه محاسبه مجدد زده شود.
 * Author: Hasan Movahed
 * Author URI: mailto:hasan.mova@gmail.com
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Admin_Auto_Shipping_Recalc {
    public function __construct() {
        add_action('woocommerce_saved_order_items', array($this, 'conditionally_recalc_shipping'), 30, 2);
    }

    public function conditionally_recalc_shipping($order_id, $items) {
       
        if (isset($_POST['action']) && $_POST['action'] === 'woocommerce_calc_line_taxes') {
            $this->auto_recalc_shipping($order_id);
        }
    }

    public function auto_recalc_shipping($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $base_country  = WC()->countries->get_base_country();
        $base_state    = WC()->countries->get_base_state();
        $base_postcode = WC()->countries->get_base_postcode();
        $base_city     = WC()->countries->get_base_city();
        $base_address  = WC()->countries->get_base_address();
        $base_address2 = WC()->countries->get_base_address_2();

        $country   = $order->get_shipping_country()   ? $order->get_shipping_country()   : $base_country;
        $state     = $order->get_shipping_state()     ? $order->get_shipping_state()     : $base_state;
        $postcode  = $order->get_shipping_postcode()  ? $order->get_shipping_postcode()  : $base_postcode;
        $city      = $order->get_shipping_city()      ? $order->get_shipping_city()      : $base_city;
        $address   = $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $base_address;
        $address_2 = $order->get_shipping_address_2() ? $order->get_shipping_address_2() : $base_address2;

        $packages = array();
        $packages[0] = array(
            'contents'        => array(),
            'contents_cost'   => 0,
            'applied_coupons' => array(),
            'destination'     => array(
                'country'   => $country,
                'state'     => $state,
                'postcode'  => $postcode,
                'city'      => $city,
                'address'   => $address,
                'address_2' => $address_2,
            ),
        );

        foreach ( $order->get_items() as $item ) {
            if ($item->is_type('line_item')) {
                $product = $item->get_product();
                if ($product && $product->needs_shipping()) {
                    $packages[0]['contents'][$item->get_id()] = array(
                        'data'        => $product,
                        'quantity'    => $item->get_quantity(),
                        'line_total'  => $item->get_total(),
                        'line_tax'    => $item->get_total_tax(),
                    );
                    $packages[0]['contents_cost'] += $item->get_total();
                }
            }
        }

 
        if (empty($packages[0]['contents'])) {
            foreach ($order->get_items('shipping') as $shipping_item_id => $shipping_item) {
                $order->remove_item($shipping_item_id);
            }
            $order->calculate_taxes();
            $order->calculate_totals(false);
            $order->save();
            return;
        }

        $shipping = WC()->shipping();
        $shipping->reset_shipping();
        $shipping->calculate_shipping($packages);
        $available_rates = $shipping->get_packages()[0]['rates'];

        if ( empty($available_rates) ) {
           
            foreach ($order->get_items('shipping') as $shipping_item_id => $shipping_item) {
                $order->remove_item($shipping_item_id);
            }
            $order->calculate_totals(false);
            $order->save();
            return;
        }

        $chosen_rate = current($available_rates);

        foreach ($order->get_items('shipping') as $shipping_item_id => $shipping_item) {
            $order->remove_item($shipping_item_id);
        }

        $new_shipping_item = new WC_Order_Item_Shipping();
        $new_shipping_item->set_method_title($chosen_rate->label);
        $new_shipping_item->set_method_id($chosen_rate->get_method_id());
        $new_shipping_item->set_total(wc_round_tax_total($chosen_rate->cost));

        if (isset($chosen_rate->taxes) && is_array($chosen_rate->taxes)) {
            $new_shipping_item->set_taxes($chosen_rate->taxes);
        }

        $order->add_item($new_shipping_item);
        $order->calculate_taxes();
        $order->calculate_totals(false);
        $order->save();
    }
}

new WC_Admin_Auto_Shipping_Recalc();
