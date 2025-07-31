<?php
function astra_limit_woocommerce_product_title($title, $id = null)
{
  if (get_option('title_limiter_module') !== 'yes') {
    return $title;
  }

  $limit = get_option('tlm_input_letter', 25);

  // Set the character limit you prefer
  if (strlen($title) > $limit) {
    $title = substr($title, 0, $limit) . '...';
  }
  return $title;
}

add_filter('the_title', 'astra_limit_woocommerce_product_title', 10, 2);
