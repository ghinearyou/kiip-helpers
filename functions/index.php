<?php
/*
 * Add my new menu to the Admin Control Panel
 */
// Hook the 'admin_menu' action hook, run the function named 'mfp_Add_My_Admin_Link()'
// Add a menu item
// require_once plugin_dir_path(__FILE__) . '../functions/fn.role_price.php';
require_once plugin_dir_path(__FILE__) . '../views/index.php';
require_once plugin_dir_path(__FILE__) . '../views/vw.role_price.php';

$function_path = plugin_dir_path(__FILE__) . '../functions/';
foreach (glob($view_path . '*.php') as $filename) {
  require_once $filename;
}

function index_menu()
{
  add_menu_page(
    'KiiP Helpers', // Title of the page
    'KiiP Helpers', // Text to show on the menu link
    'manage_options', // Capability requirement to see the link
    'kiip-helpers', // The 'slug' - file to display when clicking the link
    'list_menu' // Function to display the page content
  );

  // Reseller Price
  add_submenu_page(
    'kiip-helpers',      // Parent slug (harus sama dengan menu utama)
    'Custom Module',       // Judul halaman
    'Custom Module',       // Teks menu (yang tampil di bawah menu utama)
    'manage_options',        // Capability
    'custom-module',       // Slug unik untuk submenu ini
    'list_menu_page'   // Fungsi callback untuk kontennya
  );

  // Role Price
  add_submenu_page(
    'kiip-helpers',      // Parent slug (harus sama dengan menu utama)
    'Role Price',       // Judul halaman
    'Role Price',       // Teks menu (yang tampil di bawah menu utama)
    'manage_options',        // Capability
    'role-price',       // Slug unik untuk submenu ini
    'role_price_page'   // Fungsi callback untuk kontennya
  );

  // Remove the submenu page from the menu
  add_action('admin_head', function () {
    remove_submenu_page('kiip-helpers', 'kiip-helpers');
  });
}

add_action('admin_menu', 'index_menu');