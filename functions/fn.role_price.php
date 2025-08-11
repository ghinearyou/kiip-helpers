<?php

function read_custom_php_file()
{
    $file_path = plugin_dir_path(__FILE__) . '../output/op.roleprice.php';
    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', 'The specified PHP file does not exist.');
    }

    $data = include $file_path;

    if (!is_array($data)) {
        return new WP_Error('php_error', 'The PHP file does not return a valid array.');
    }

    return $data;
}

add_action('woocommerce_before_calculate_totals', function($cart) {
    if (get_option('role_price_module') === 'yes') {
        $php_data = read_custom_php_file();
        if (!is_wp_error($php_data)) {
            $user = wp_get_current_user();
            $role = $user->roles[0] ?? '';
            if (!empty($php_data[$role]['prices'])) {
                $temp_qty = [];

                foreach ($cart->get_cart() as $cart_item) {
                    $product = $cart_item['data'];
                    $parent_id = $product->get_parent_id();
                    $temp_qty[$parent_id] = ($temp_qty[$parent_id] ?? 0) + $cart_item['quantity'];
                }

                foreach ($cart->get_cart() as $cart_item) {
                    $product = $cart_item['data'];
                    $sku = $product->get_sku();
                    $parent_id = $product->get_parent_id();
                    $quantity = $cart_item['quantity'];

                    if (!empty($php_data[$role]['prices'][$sku])) {
                        if (($parent_id > 0 && $temp_qty[$parent_id] >= 5) || ($parent_id === 0 && $quantity >= 5)) {
                            $product->set_price($php_data[$role]['prices'][$sku]);
                        }
                    }
                }
            }
        }
    }
}, 20);
