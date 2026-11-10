<?php
/**
 * Mode Berjualan (SMM).
 * Kalau modul aktif dan status penjualan "nonaktif", semua produk tidak bisa dibeli:
 * tombol add to cart hilang, keranjang & checkout tidak bisa dipakai, dan pengunjung
 * melihat pesan yang bisa diatur. Harga tetap tampil.
 */

if (!defined('ABSPATH')) {
  exit;
}

const KIIP_SALES_MODE_DEFAULT_MESSAGE = 'Mohon maaf, saat ini toko sedang tidak menerima pesanan.';

function kiip_sales_mode_message()
{
  $message = get_option('smm_message', '');
  return $message !== '' ? $message : KIIP_SALES_MODE_DEFAULT_MESSAGE;
}

/**
 * True kalau toko sedang ditutup (penjualan nonaktif).
 */
function kiip_sales_closed()
{
  return get_option('sales_mode_module') === 'yes' && get_option('smm_status', 'open') === 'closed';
}

/**
 * True kalau penjualan diblok untuk pengunjung saat ini.
 * Admin bisa dikecualikan supaya tetap bisa mencoba alur belanja.
 */
function kiip_sales_blocked_for_current_user()
{
  if (!kiip_sales_closed()) {
    return false;
  }
  if (get_option('smm_admin_bypass') === 'yes' && current_user_can('manage_woocommerce')) {
    return false;
  }
  return true;
}

add_filter('woocommerce_is_purchasable', function ($purchasable) {
  return kiip_sales_blocked_for_current_user() ? false : $purchasable;
}, 99);

add_filter('woocommerce_variation_is_purchasable', function ($purchasable) {
  return kiip_sales_blocked_for_current_user() ? false : $purchasable;
}, 99);

add_filter('woocommerce_add_to_cart_validation', function ($passed) {
  if (kiip_sales_blocked_for_current_user()) {
    wc_add_notice(kiip_sales_mode_message(), 'error');
    return false;
  }
  return $passed;
}, 99);

// Jaga-jaga untuk checkout klasik: tolak proses order.
add_action('woocommerce_checkout_process', function () {
  if (kiip_sales_blocked_for_current_user()) {
    wc_add_notice(kiip_sales_mode_message(), 'error');
  }
});

// Jaga-jaga untuk checkout block / Store API.
add_action('woocommerce_store_api_checkout_update_order_from_request', function () {
  if (kiip_sales_blocked_for_current_user() && class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
    throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('kiip_sales_closed', kiip_sales_mode_message(), 403);
  }
});

// Pesan di halaman produk, menggantikan tombol beli.
add_action('woocommerce_single_product_summary', function () {
  if (kiip_sales_blocked_for_current_user()) {
    echo '<p class="kiip-sales-closed-note">' . esc_html(kiip_sales_mode_message()) . '</p>';
  }
}, 31);

// Banner di bagian atas semua halaman depan.
add_action('wp_body_open', function () {
  if (!kiip_sales_closed() || is_admin()) {
    return;
  }
  ?>
  <div class="kiip-sales-closed-banner" role="status"
    style="background:#b32d2e;color:#fff;text-align:center;padding:10px 16px;font-size:15px;line-height:1.4;position:relative;z-index:9999;">
    <?php echo esc_html(kiip_sales_mode_message()); ?>
    <?php if (!kiip_sales_blocked_for_current_user()): ?>
      <small style="opacity:.85;">(Admin: Anda tetap bisa belanja untuk testing)</small>
    <?php endif; ?>
  </div>
  <?php
});

// Penanda di admin bar supaya admin tahu toko sedang tutup.
add_action('admin_bar_menu', function ($bar) {
  if (!kiip_sales_closed() || !current_user_can('manage_options')) {
    return;
  }
  $bar->add_node([
    'id' => 'kiip-sales-closed',
    'title' => '<span style="color:#ff8085;font-weight:600;">● Penjualan nonaktif</span>',
    'href' => admin_url('admin.php?page=kiip-helpers&tab=sales_mode_module'),
  ]);
}, 100);
