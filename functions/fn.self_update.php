<?php
/**
 * Self update: cek versi terbaru di GitHub (header "Version:" di main.php)
 * lalu pasang zip dari branch tersebut lewat Plugin_Upgrader bawaan WordPress.
 *
 * Repo private butuh GitHub token (fine-grained, akses "Contents: Read-only").
 * Token bisa diisi di halaman KiiP Helpers, atau lewat wp-config.php:
 *   define('KIIP_GITHUB_TOKEN', 'github_pat_xxx');
 */

if (!defined('ABSPATH')) {
  exit;
}

const KIIP_UPDATE_DEFAULT_REPO = 'ghinearyou/kiip-helpers';
const KIIP_UPDATE_DEFAULT_BRANCH = 'main';
const KIIP_UPDATE_CACHE_KEY = 'kiip_update_remote_version';

function kiip_update_settings()
{
  $token = defined('KIIP_GITHUB_TOKEN') ? KIIP_GITHUB_TOKEN : get_option('kiip_update_token', '');

  return [
    'repo' => get_option('kiip_update_repo', KIIP_UPDATE_DEFAULT_REPO) ?: KIIP_UPDATE_DEFAULT_REPO,
    'branch' => get_option('kiip_update_branch', KIIP_UPDATE_DEFAULT_BRANCH) ?: KIIP_UPDATE_DEFAULT_BRANCH,
    'token' => (string) $token,
    'token_from_config' => defined('KIIP_GITHUB_TOKEN'),
  ];
}

function kiip_installed_version()
{
  if (!function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  }
  $data = get_plugin_data(KIIP_HELPERS_FILE, false, false);
  return $data['Version'] ?? '0';
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

function kiip_github_request($path, $accept = 'application/vnd.github+json', $extra_args = [])
{
  $settings = kiip_update_settings();
  $headers = [
    'Accept' => $accept,
    'User-Agent' => 'kiip-helpers-updater',
    'X-GitHub-Api-Version' => '2022-11-28',
  ];
  if ($settings['token'] !== '') {
    $headers['Authorization'] = 'Bearer ' . $settings['token'];
  }

  $url = 'https://api.github.com/repos/' . $settings['repo'] . $path;
  return wp_remote_get($url, array_merge(['headers' => $headers, 'timeout' => 30], $extra_args));
}

function kiip_github_error($response)
{
  if (is_wp_error($response)) {
    return new WP_Error('kiip_update_http', 'Gagal menghubungi GitHub: ' . $response->get_error_message());
  }

  $code = (int) wp_remote_retrieve_response_code($response);
  $settings = kiip_update_settings();

  switch ($code) {
    case 401:
      $msg = 'GitHub menolak token (401). Token salah atau sudah kedaluwarsa.';
      break;
    case 403:
      $msg = 'GitHub menolak akses (403). Token tidak punya izin "Contents: Read" untuk repo ini, atau kena rate limit.';
      break;
    case 404:
      $msg = $settings['token'] === ''
        ? 'Repo tidak ditemukan (404). Repo ini private, isi GitHub token terlebih dahulu.'
        : 'Repo atau branch "' . $settings['branch'] . '" tidak ditemukan (404), atau token tidak punya akses ke repo ini.';
      break;
    default:
      $msg = 'GitHub membalas dengan kode ' . $code . '.';
  }

  return new WP_Error('kiip_update_http', $msg);
}

/**
 * Versi terbaru di GitHub. Hasilnya di-cache 1 jam.
 *
 * @return array|WP_Error ['version' => '1.4', 'checked' => timestamp, 'repo' => ..., 'branch' => ...]
 */
function kiip_get_remote_version($force = false)
{
  $settings = kiip_update_settings();
  $cached = get_transient(KIIP_UPDATE_CACHE_KEY);

  if (
    !$force && is_array($cached)
    && ($cached['repo'] ?? '') === $settings['repo']
    && ($cached['branch'] ?? '') === $settings['branch']
  ) {
    return $cached;
  }

  $response = kiip_github_request(
    '/contents/main.php?ref=' . rawurlencode($settings['branch']),
    'application/vnd.github.raw'
  );

  if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
    return kiip_github_error($response);
  }

  $version = kiip_parse_plugin_version(wp_remote_retrieve_body($response));
  if ($version === null) {
    return new WP_Error('kiip_update_version', 'Tidak menemukan "Version:" di main.php pada branch ' . $settings['branch'] . '.');
  }

  $data = [
    'version' => $version,
    'checked' => time(),
    'repo' => $settings['repo'],
    'branch' => $settings['branch'],
  ];
  set_transient(KIIP_UPDATE_CACHE_KEY, $data, HOUR_IN_SECONDS);

  return $data;
}

/**
 * Salin semua file di folder output/ (data campaign, role price, dll)
 * supaya tidak hilang saat folder plugin diganti versi baru.
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

/**
 * Download zip branch dari GitHub lalu pasang menimpa folder plugin ini.
 *
 * @return true|WP_Error
 */
function kiip_perform_self_update()
{
  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/plugin.php';
  require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

  $settings = kiip_update_settings();
  $plugin_dir = dirname(KIIP_HELPERS_FILE);
  $slug = basename($plugin_dir);
  $output_dir = $plugin_dir . '/output';

  $tmp = wp_tempnam('kiip-helpers-update.zip');
  if (!$tmp) {
    return new WP_Error('kiip_update_tmp', 'Tidak bisa membuat file sementara di server.');
  }

  $response = kiip_github_request(
    '/zipball/' . rawurlencode($settings['branch']),
    'application/vnd.github+json',
    ['timeout' => 300, 'stream' => true, 'filename' => $tmp]
  );

  if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
    @unlink($tmp);
    return kiip_github_error($response);
  }

  $output_backup = kiip_backup_output_dir($output_dir);

  // Zip dari GitHub berisi folder "owner-repo-sha", ganti namanya jadi folder plugin yang terpasang.
  $rename_source = function ($source, $remote_source, $upgrader, $hook_extra = []) use ($slug) {
    if (empty($hook_extra['kiip_self_update'])) {
      return $source;
    }
    global $wp_filesystem;
    $target = trailingslashit($remote_source) . $slug . '/';
    if (untrailingslashit($source) === untrailingslashit($target)) {
      return $source;
    }
    if (!$wp_filesystem->move($source, $target, true)) {
      return new WP_Error('kiip_update_rename', 'Gagal menyiapkan folder plugin hasil download.');
    }
    return $target;
  };
  add_filter('upgrader_source_selection', $rename_source, 10, 4);

  $skin = new WP_Ajax_Upgrader_Skin();
  $upgrader = new Plugin_Upgrader($skin);
  $upgrader->init();
  $upgrader->upgrade_strings();

  $result = $upgrader->run([
    'package' => $tmp,
    'destination' => WP_PLUGIN_DIR,
    'clear_destination' => true,
    'clear_working' => true,
    'hook_extra' => [
      'plugin' => plugin_basename(KIIP_HELPERS_FILE),
      'type' => 'plugin',
      'action' => 'update',
      'kiip_self_update' => true,
    ],
  ]);

  remove_filter('upgrader_source_selection', $rename_source, 10);
  if (file_exists($tmp)) {
    @unlink($tmp);
  }

  // Kembalikan data output/ (juga kalau update gagal di tengah jalan).
  kiip_restore_output_dir($output_dir, $output_backup);

  if (is_wp_error($result)) {
    return $result;
  }
  if (!$result) {
    $errors = $skin->get_errors();
    if (is_wp_error($errors) && $errors->has_errors()) {
      return new WP_Error('kiip_update_failed', implode(' ', $errors->get_error_messages()));
    }
    return new WP_Error('kiip_update_failed', 'Update gagal. Kemungkinan WordPress tidak punya izin menulis ke folder plugin.');
  }

  delete_transient(KIIP_UPDATE_CACHE_KEY);
  wp_clean_plugins_cache();

  return true;
}

/* ---------------------------------------------------------------------------
 * Notifikasi sederhana antar redirect
 * ------------------------------------------------------------------------ */

function kiip_set_notice($type, $message)
{
  set_transient('kiip_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
}

function kiip_render_notice()
{
  $key = 'kiip_notice_' . get_current_user_id();
  $notice = get_transient($key);
  if (!$notice) {
    return;
  }
  delete_transient($key);
  printf(
    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
    esc_attr($notice['type']),
    wp_kses_post($notice['message'])
  );
}

function kiip_redirect_back($tab = 'modules')
{
  wp_safe_redirect(admin_url('admin.php?page=kiip-helpers&tab=' . rawurlencode($tab)));
  exit;
}

/* ---------------------------------------------------------------------------
 * Handler form (admin-post.php)
 * ------------------------------------------------------------------------ */

add_action('admin_post_kiip_check_update', function () {
  if (!current_user_can('update_plugins')) {
    wp_die('Tidak punya izin.');
  }
  check_admin_referer('kiip_check_update');

  $remote = kiip_get_remote_version(true);
  if (is_wp_error($remote)) {
    kiip_set_notice('error', $remote->get_error_message());
  } elseif (version_compare($remote['version'], kiip_installed_version(), '>')) {
    kiip_set_notice('warning', 'Versi baru tersedia: ' . esc_html($remote['version']) . '.');
  } else {
    kiip_set_notice('success', 'Plugin sudah versi terbaru (' . esc_html($remote['version']) . ').');
  }
  kiip_redirect_back();
});

add_action('admin_post_kiip_run_update', function () {
  if (!current_user_can('update_plugins')) {
    wp_die('Tidak punya izin.');
  }
  check_admin_referer('kiip_run_update');

  $before = kiip_installed_version();
  $result = kiip_perform_self_update();

  if (is_wp_error($result)) {
    kiip_set_notice('error', 'Update gagal: ' . esc_html($result->get_error_message()));
  } else {
    // Baca ulang header dari file yang baru terpasang.
    clearstatcache();
    $after = kiip_parse_plugin_version(file_get_contents(KIIP_HELPERS_FILE)) ?: '?';
    kiip_set_notice('success', 'KiiP Helpers berhasil diupdate dari ' . esc_html($before) . ' ke ' . esc_html($after) . '.');
  }
  kiip_redirect_back();
});

add_action('admin_post_kiip_save_update_settings', function () {
  if (!current_user_can('update_plugins')) {
    wp_die('Tidak punya izin.');
  }
  check_admin_referer('kiip_save_update_settings');

  $repo = sanitize_text_field(wp_unslash($_POST['kiip_update_repo'] ?? ''));
  $branch = sanitize_text_field(wp_unslash($_POST['kiip_update_branch'] ?? ''));
  $token = trim(sanitize_text_field(wp_unslash($_POST['kiip_update_token'] ?? '')));

  if ($repo !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
    kiip_set_notice('error', 'Format repo harus owner/nama-repo.');
    kiip_redirect_back();
  }

  update_option('kiip_update_repo', $repo ?: KIIP_UPDATE_DEFAULT_REPO, false);
  update_option('kiip_update_branch', $branch ?: KIIP_UPDATE_DEFAULT_BRANCH, false);

  if (!empty($_POST['kiip_update_token_clear'])) {
    delete_option('kiip_update_token');
  } elseif ($token !== '') {
    update_option('kiip_update_token', $token, false);
  }

  delete_transient(KIIP_UPDATE_CACHE_KEY);
  kiip_set_notice('success', 'Pengaturan update disimpan.');
  kiip_redirect_back();
});
