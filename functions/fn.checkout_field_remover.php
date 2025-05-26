<?php

function custom_remove_checkout_fields($fields)
{
  unset($fields['billing']['billing_pinpoint_location']);
  unset($fields['shipping']['shipping_pinpoint_location']);
  return $fields;
}

add_filter('woocommerce_checkout_fields', 'custom_remove_checkout_fields');
