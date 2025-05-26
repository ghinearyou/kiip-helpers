<?php

function read_custom_json_file()
{
	$file_path = plugin_dir_path(__FILE__) . '../output/op.roleprice.json'; // Adjust the path as needed
	if (!file_exists($file_path)) {
		return new WP_Error('file_not_found', 'The specified JSON file does not exist.');
	}

	$json_data = file_get_contents($file_path);
	$parsed_data = json_decode($json_data, true);

	if (json_last_error() !== JSON_ERROR_NONE) {
		return new WP_Error('json_error', 'Error decoding JSON: ' . json_last_error_msg());
	}

	return $parsed_data;
}

function apply_quantity_discount($cart)
{
	if (get_option('kiip_discount_enabled') !== 'yes') {
		return;
	}

	$json_data = read_custom_json_file();
	$user = wp_get_current_user();
	$role = $user->roles[0];

	if ($json_data[$role]) {
		if (is_admin() && !defined('DOING_AJAX')) {
			return;
		}

		$temp_qty = [];

		foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
			$quantity = $cart_item['quantity'];
			$product = $cart_item['data'];
			$parent_id = $product->get_parent_id();
			if ($temp_qty[$parent_id]) {
				$temp_qty[$parent_id] += $quantity;
			} else {
				$temp_qty[$parent_id] = $quantity;
			}
		}

		foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
			$quantity = $cart_item['quantity'];
			$product = $cart_item['data'];
			$parent_id = $product->get_parent_id();
			$regular_price = $product->get_sale_price();
			$sku = $product->get_sku();

			if ($parent_id > 0) {
				if ($temp_qty[$parent_id] >= 5 && $json_data[$role][$sku]) {
					$product->set_price($json_data[$role][$sku]);
				}
			} else {
				if ($quantity >= 5) {
					$product->set_price($json_data[$role][$sku]);
				}
			}
		}
	}
}

add_action('woocommerce_before_calculate_totals', 'apply_quantity_discount', 10, 1);