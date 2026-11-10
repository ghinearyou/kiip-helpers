<?php
/*
Plugin Name: KiiP Helpers
Description: Plugin to add helper functions for project KiiP.
Author: Agi
Author URI: https://github.com/ghinearyou/
Version: 1.4.4
Requires PHP: 7.4

*/
if (!defined('ABSPATH')) {
  exit;
}

define('KIIP_HELPERS_FILE', __FILE__);

// Include functions.php, use require_once to stop the script if mfp-functions.php is not found
require_once plugin_dir_path(__FILE__) . 'functions/index.php';

function woo_universal_helpers()
{
  if (!class_exists('WooCommerce')) {
    deactivate_plugins(plugin_basename(__FILE__));
    wp_die('This plugin requires WooCommerce to be installed and active.');
  }
}
register_activation_hook(__FILE__, 'woo_universal_helpers');
