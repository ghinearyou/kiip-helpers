<?php

function remove_flat_rate_shipping($rates, $package)
{
  if (get_option('order_notification_module') !== 'yes') {
    return;
  }

  $user = wp_get_current_user();
  foreach ($rates as $rate_key => $rate) {
    if (!array_intersect(['administrator', 'reseller'], (array) $user->roles)) {
      if ($rate->label === 'Ambil Sendiri') {
        unset($rates[$rate_key]);
      }
    }
  }
  return $rates;
}

add_filter('woocommerce_package_rates', 'remove_flat_rate_shipping', 10, 2);