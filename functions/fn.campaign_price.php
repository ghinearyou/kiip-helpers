<?php
$jsonFile = plugin_dir_path(__FILE__) . '../output/op.campaignprice.json'; // Adjust the path as needed

$dataArrays = [];
if (file_exists($jsonFile)) {
  $jsonContent = file_get_contents($jsonFile);
  $decoded = json_decode($jsonContent, true);

  if (is_array($decoded)) {
    // Flatten: ambil semua kampanye jadi array per kampanye
    foreach ($decoded as $campaign => $skuPrices) {
      if (is_array($skuPrices)) {
        $dataArrays[] = $skuPrices;
      }
    }
  }
}

function get_lowest_price_for_sku($sku, $dataArrays)
{
  $prices = [];

  foreach ($dataArrays as $dataArray) {
    if (isset($dataArray[$sku])) {
      $prices[] = (float) $dataArray[$sku];
    }
  }

  return !empty($prices) ? min($prices) : null;
}

// date_default_timezone_set("Asia/Jakarta");
// $hour = date("his");
// $hValue = (int)$hour;

add_filter('woocommerce_product_variation_get_price', 'campaign_price_filter', 99, 2);
function campaign_price_filter($price, $product)
{
  if (get_option('campaign_price_module') !== 'yes') {
    return $price;
  }

  global $dataArrays;
  if (is_cart() || is_checkout() || (defined('WC_DOING_AJAX') && WC_DOING_AJAX)) {
    return $price;
  }

  $sku = $product->get_sku();
  $lowestPrice = get_lowest_price_for_sku($sku, $dataArrays);
  return $lowestPrice !== null ? $lowestPrice : $price;
}

add_filter('woocommerce_get_price', 'campaign_price_filter2', 99, 2);
function campaign_price_filter2($price, $product)
{
  if (get_option('campaign_price_module') !== 'yes') {
    return $price;
  }

  global $dataArrays;
  if (is_cart() || is_checkout() || (defined('WC_DOING_AJAX') && WC_DOING_AJAX)) {
    return $price;
  }

  $sku = $product->get_sku();
  $lowestPrice = get_lowest_price_for_sku($sku, $dataArrays);
  return $lowestPrice !== null ? $lowestPrice : $price;
}

add_filter('woocommerce_variable_price_html', 'ramadhan_variation_html', 99, 2);
function ramadhan_variation_html($price_html, $product)
{
  if (get_option('campaign_price_module') !== 'yes') {
    return $price_html;
  }

  $variations = $product->get_available_variations();
  $prices = [];
  foreach ($variations as $variation) {
    $variation_id = $variation['variation_id'];
    $variation_product = wc_get_product($variation_id);
    $variation_price = $variation_product->get_price();
    $prices[] = $variation_price;
  }

  if (!empty($prices)) {
    $min_price = min($prices);
    $max_price = max($prices);
    if ($min_price !== $max_price) {
      $price_html = wc_price($min_price) . ' – ' . wc_price($max_price);
    } else {
      $formatted_price = number_format($min_price, 0, ',', '.');
      $pattern = '/<ins[^>]*>.*?<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">Rp<\/span>(.*?)<\/bdi><\/span><\/ins>/';
      $replacement = '<ins aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">Rp</span>' . $formatted_price . '</bdi></span></ins>';
      $price_html = preg_replace($pattern, $replacement, $price_html);
    }
  }
  return $price_html;
}
