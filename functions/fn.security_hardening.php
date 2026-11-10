<?php
/**
 * Keamanan Dasar (SHM).
 *
 * Menutup celah umum WordPress yang ditemukan di audit keamanan:
 * - XML-RPC dimatikan total (system.multicall untuk brute force, pingback.ping untuk DDoS).
 * - Daftar user lewat REST (/wp/v2/users) hanya untuk yang sudah login.
 * - Enumerasi username lewat ?author=N dan halaman arsip penulis dialihkan ke beranda.
 * - Nama penulis dihapus dari respons oEmbed.
 * - Header keamanan (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
 * - Versi WordPress disembunyikan (meta generator, ?ver= aset inti) dan header X-Powered-By dihapus.
 *
 * Login via REST + Application Password, wp-admin, WooCommerce REST API, dan updater KiiP Helpers
 * tidak terpengaruh.
 */

if (!defined('ABSPATH')) {
  exit;
}

function kiip_shm_active()
{
  return get_option('security_hardening_module') === 'yes';
}

/* ---------- XML-RPC ---------- */

add_filter('xmlrpc_enabled', function ($enabled) {
  return kiip_shm_active() ? false : $enabled;
});

add_filter('xmlrpc_methods', function ($methods) {
  return kiip_shm_active() ? [] : $methods;
});

// Tolak request ke xmlrpc.php sebelum server XML-RPC berjalan.
add_action('init', function () {
  if (kiip_shm_active() && defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    status_header(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'XML-RPC dinonaktifkan.';
    exit;
  }
}, 0);

// Hapus header X-Pingback dan link RSD yang mengiklankan xmlrpc.php.
add_filter('wp_headers', function ($headers) {
  if (kiip_shm_active()) {
    unset($headers['X-Pingback']);
  }
  return $headers;
});

add_action('init', function () {
  if (kiip_shm_active()) {
    remove_action('wp_head', 'rsd_link');
  }
});

/* ---------- Enumerasi user ---------- */

// /wp-json/wp/v2/users hanya untuk user yang login (editor blok tetap bisa memilih penulis).
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
  if (!kiip_shm_active() || is_user_logged_in()) {
    return $result;
  }
  if (preg_match('#^/wp/v2/users(/|$)#', $request->get_route())) {
    return new WP_Error('rest_user_cannot_view', 'Tidak diizinkan.', ['status' => 401]);
  }
  return $result;
}, 10, 3);

// ?author=1 dan /author/<slug>/ membocorkan username. Alihkan ke beranda untuk pengunjung.
add_action('template_redirect', function () {
  if (!kiip_shm_active() || is_user_logged_in()) {
    return;
  }
  if (isset($_GET['author']) || is_author()) {
    wp_safe_redirect(home_url('/'), 301);
    exit;
  }
}, 1);

add_filter('oembed_response_data', function ($data) {
  if (kiip_shm_active()) {
    unset($data['author_name'], $data['author_url']);
  }
  return $data;
});

add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
  return (kiip_shm_active() && $name === 'users') ? false : $provider;
}, 10, 2);

/* ---------- Header keamanan ---------- */

function kiip_shm_send_security_headers()
{
  if (!kiip_shm_active() || headers_sent()) {
    return;
  }
  header_remove('X-Powered-By');
  if (is_ssl()) {
    header('Strict-Transport-Security: max-age=31536000');
  }
  header('X-Frame-Options: SAMEORIGIN');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), usb=()');
}
add_action('send_headers', 'kiip_shm_send_security_headers');
add_action('admin_init', 'kiip_shm_send_security_headers');
add_action('login_init', 'kiip_shm_send_security_headers');
add_action('rest_api_init', function () {
  if (kiip_shm_active() && !headers_sent()) {
    header_remove('X-Powered-By');
  }
});

/* ---------- Sembunyikan versi ---------- */

add_action('init', function () {
  if (kiip_shm_active()) {
    remove_action('wp_head', 'wp_generator');
  }
});

add_filter('the_generator', function ($gen) {
  return kiip_shm_active() ? '' : $gen;
});

add_filter('aioseo_generator_disable', function ($disable) {
  return kiip_shm_active() ? true : $disable;
});

// ?ver= yang sama dengan versi WordPress diganti hash, supaya cache aset tetap pecah saat update.
function kiip_shm_mask_wp_version($src)
{
  if (!kiip_shm_active() || !$src) {
    return $src;
  }
  $wp_version = get_bloginfo('version');
  if (!preg_match('/[?&]ver=' . preg_quote($wp_version, '/') . '(&|$)/', $src)) {
    return $src;
  }
  return add_query_arg('ver', substr(md5($wp_version . wp_salt('auth')), 0, 8), $src);
}
add_filter('style_loader_src', 'kiip_shm_mask_wp_version', 9999);
add_filter('script_loader_src', 'kiip_shm_mask_wp_version', 9999);
