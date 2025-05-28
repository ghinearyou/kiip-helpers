<?php
function astra_limit_woocommerce_product_title($title, $id = null)
{
  if (get_option('order_notification_module') !== 'yes') {
    return;
  }

  $limit = 40; // Set the character limit you prefer
  if (strlen($title) > $limit) {
    $title = substr($title, 0, $limit) . '...';
  }
  return $title;
}

add_filter('the_title', 'astra_limit_woocommerce_product_title', 10, 2);