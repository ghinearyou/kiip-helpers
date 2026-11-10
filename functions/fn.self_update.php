<?php
/**
 * Update KiiP Helpers lewat daftar Plugins bawaan WordPress.
 *
 * WordPress mengecek update plugin secara berkala (dan saat "Cek lagi" di Dasbor → Pembaruan).
 * Di situ kita baca "Version:" dari main.php di branch main repo GitHub (publik). Kalau lebih baru,
 * KiiP Helpers muncul dengan "Ada versi baru... Perbarui sekarang" di halaman Plugins, dan
 * WordPress mengunduh zip dari GitHub.
 *
 * Setiap rilis wajib menaikkan "Version:" di main.php, kalau tidak update tidak akan muncul.
 */

if (!defined('ABSPATH')) {
  exit;
}

const KIIP_UPDATE_REPO = 'ghinearyou/kiip-helpers';
const KIIP_UPDATE_BRANCH = 'main';
const KIIP_UPDATE_CACHE_KEY = 'kiip_update_remote_version';

function kiip_installed_version()
{
  if (!function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  }
  $data = get_plugin_data(KIIP_HELPERS_FILE, false, false);
  return $data['Version'] ?? '0';
}

function kiip_plugin_basename()
{
  return plugin_basename(KIIP_HELPERS_FILE);
}

/**
 * Ambil nilai "Version:" dari header plugin.
 */
function kiip_parse_plugin_version($php_source)
{
  if (preg_match('/^[ \t\/*#@]*Version:\s*([0-9A-Za-z.\-+]+)/mi', (string) $php_source, $m)) {
    return $m[1];
  }
  return null;
}

function kiip_update_package_url()
{
  return 'https://codeload.github.com/' . KIIP_UPDATE_REPO . '/zip/refs/heads/' . KIIP_UPDATE_BRANCH;
}

/**
 * Versi terbaru di GitHub, di-cache 1 jam. Null kalau gagal dicek.
 */
function kiip_get_remote_version($force = false)
{
  $cached = get_transient(KIIP_UPDATE_CACHE_KEY);
  if (!$force && is_array($cached)) {
    return $cached['version'];
  }

  $response = wp_remote_get(
    'https://raw.githubusercontent.com/' . KIIP_UPDATE_REPO . '/' . KIIP_UPDATE_BRANCH . '/main.php',
    ['timeout' => 15, 'headers' => ['User-Agent' => 'kiip-helpers-updater']]
  );

  $version = null;
  if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
    $version = kiip_parse_plugin_version(wp_remote_retrieve_body($response));
  }

  // Gagal pun di-cache sebentar supaya tidak membebani setiap request.
  set_transient(KIIP_UPDATE_CACHE_KEY, ['version' => $version], $version ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
  return $version;
}

/**
 * Daftarkan info update ke transient update_plugins milik WordPress.
 */
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
  if (!is_object($transient) || empty($transient->checked)) {
    return $transient;
  }

  $basename = kiip_plugin_basename();
  // "Cek lagi" di Dasbor → Pembaruan memaksa cek ulang ke GitHub.
  $force = !empty($_GET['force-check']);
  $remote = kiip_get_remote_version($force);
  if (!$remote) {
    return $transient;
  }

  $item = (object) [
    'id' => 'github.com/' . KIIP_UPDATE_REPO,
    'slug' => dirname($basename),
    'plugin' => $basename,
    'new_version' => $remote,
    'url' => 'https://github.com/' . KIIP_UPDATE_REPO,
    'package' => kiip_update_package_url(),
    'icons' => [],
    'banners' => [],
    'tested' => '',
    'requires_php' => '7.4',
  ];

  if (version_compare($remote, kiip_installed_version(), '>')) {
    $transient->response[$basename] = $item;
    unset($transient->no_update[$basename]);
  } else {
    unset($transient->response[$basename]);
    $transient->no_update[$basename] = $item;
  }

  return $transient;
});

/**
 * Isi popup "Lihat detail" di halaman Plugins.
 */
add_filter('plugins_api', function ($result, $action, $args) {
  if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(kiip_plugin_basename())) {
    return $result;
  }
  $remote = kiip_get_remote_version() ?: kiip_installed_version();
  return (object) [
    'name' => 'KiiP Helpers',
    'slug' => $args->slug,
    'version' => $remote,
    'author' => 'Agi',
    'homepage' => 'https://github.com/' . KIIP_UPDATE_REPO,
    'download_link' => kiip_update_package_url(),
    'requires_php' => '7.4',
    'sections' => [
      'description' => 'Helper functions untuk project KiiP. Update diambil dari branch ' . KIIP_UPDATE_BRANCH . ' di GitHub.',
      'changelog' => '<a href="https://github.com/' . KIIP_UPDATE_REPO . '/commits/' . KIIP_UPDATE_BRANCH . '" target="_blank">Lihat riwayat perubahan di GitHub</a>',
    ],
  ];
}, 10, 3);

function kiip_is_own_upgrade($hook_extra)
{
  return isset($hook_extra['plugin']) && $hook_extra['plugin'] === kiip_plugin_basename();
}

/**
 * Zip dari GitHub berisi folder "kiip-helpers-main", ganti namanya jadi folder plugin yang terpasang.
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = []) {
  if (!kiip_is_own_upgrade($hook_extra)) {
    return $source;
  }
  global $wp_filesystem;
  $target = trailingslashit($remote_source) . dirname(kiip_plugin_basename()) . '/';
  if (untrailingslashit($source) === untrailingslashit($target)) {
    return $source;
  }
  if (!$wp_filesystem->move($source, $target, true)) {
    return new WP_Error('kiip_update_rename', 'Gagal menyiapkan folder plugin KiiP Helpers hasil download.');
  }
  return $target;
}, 10, 4);

/**
 * Data di folder output/ (Campaign Price, Role Price) disimpan sebelum folder plugin
 * diganti, lalu dikembalikan sesudahnya.
 */
function kiip_backup_output_dir($dir)
{
  $files = [];
  if (!is_dir($dir)) {
    return $files;
  }
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/\\');
      $files[$relative] = file_get_contents($file->getPathname());
    }
  }
  return $files;
}

function kiip_restore_output_dir($dir, $files)
{
  foreach ($files as $relative => $contents) {
    $target = trailingslashit($dir) . $relative;
    wp_mkdir_p(dirname($target));
    file_put_contents($target, $contents);
  }
}

add_filter('upgrader_pre_install', function ($response, $hook_extra) {
  if (kiip_is_own_upgrade($hook_extra)) {
    $GLOBALS['kiip_output_backup'] = kiip_backup_output_dir(dirname(KIIP_HELPERS_FILE) . '/output');
  }
  return $response;
}, 10, 2);

add_filter('upgrader_post_install', function ($response, $hook_extra, $result) {
  if (kiip_is_own_upgrade($hook_extra) && !empty($GLOBALS['kiip_output_backup'])) {
    kiip_restore_output_dir(dirname(KIIP_HELPERS_FILE) . '/output', $GLOBALS['kiip_output_backup']);
    unset($GLOBALS['kiip_output_backup']);
  }
  return $response;
}, 10, 3);

// Setelah update selesai, hapus cache versi supaya pengecekan berikutnya segar.
add_action('upgrader_process_complete', function ($upgrader, $options) {
  if (($options['type'] ?? '') === 'plugin' && in_array(kiip_plugin_basename(), (array) ($options['plugins'] ?? []), true)) {
    delete_transient(KIIP_UPDATE_CACHE_KEY);
  }
}, 10, 2);

// Bersihkan option lama dari versi 1.4/1.4.1 (pengaturan GitHub di halaman plugin).
add_action('admin_init', function () {
  if (get_option('kiip_update_token', null) !== null || get_option('kiip_update_repo', null) !== null) {
    delete_option('kiip_update_token');
    delete_option('kiip_update_repo');
    delete_option('kiip_update_branch');
  }
});
