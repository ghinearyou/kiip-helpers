<?php

add_action('woocommerce_cart_calculate_fees', 'delivery_fee_promo');

function delivery_fee_promo($cart) {
  if (is_admin() && !defined('DOING_AJAX')) {
      return;
  }

  if (get_option('delivery_fee_promo_module') !== 'yes') {
      return;
  }

  $input1 = get_option('dfpm_input_promo_name', '');
  $input2 = get_option('dfpm_input_promo_amount', '');

  $shipping_total = $cart->get_shipping_total();

  if ($shipping_total > 0) {
      $discount_amount = min($input2, $shipping_total);
      $cart->add_fee($input1, -$discount_amount);
  }
}