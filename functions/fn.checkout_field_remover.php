<?php

function custom_remove_checkout_fields($fields)
{
  if (get_option('order_notification_module') !== 'yes') {
    return;
  }

  unset($fields['billing']['billing_pinpoint_location']);
  unset($fields['shipping']['shipping_pinpoint_location']);
  return $fields;
}

add_filter('woocommerce_checkout_fields', 'custom_remove_checkout_fields');
