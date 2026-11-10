<?php

if (!defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/vw.campaign_price.php';
require_once __DIR__ . '/vw.delivery_fee_promo.php';
require_once __DIR__ . '/vw.image_formatter.php';
require_once __DIR__ . '/vw.order_notification.php';
require_once __DIR__ . '/vw.role_price.php';
require_once __DIR__ . '/vw.title_limiter.php';
require_once __DIR__ . '/vw.sales_mode.php';
require_once __DIR__ . '/vw.price_update.php';

/**
 * Daftar modul. Key = nama option on/off (nilai 'yes' / 'no').
 * 'page' = fungsi halaman pengaturan, null kalau modul tidak punya pengaturan.
 */
function kiip_modules()
{
  return [
    'campaign_price_module' => [
      'code' => 'CPM',
      'name' => 'Campaign Price',
      'desc' => 'Diskon berdasarkan campaign yang sedang berjalan.',
      'page' => 'campaign_price_page',
    ],
    'checkout_field_remover_module' => [
      'code' => 'CFRM',
      'name' => 'Checkout Field Remover',
      'desc' => 'Hapus field pinpoint lokasi di halaman checkout.',
      'page' => null,
    ],
    'delivery_fee_promo_module' => [
      'code' => 'DFPM',
      'name' => 'Delivery Fee Promo',
      'desc' => 'Promo diskon ongkir.',
      'page' => 'delivery_fee_promo_page',
    ],
    'image_formatter_module' => [
      'code' => 'IFM',
      'name' => 'Image Formatter',
      'desc' => 'Format gambar menjadi WebP.',
      'page' => 'image_formatter_page',
    ],
    'order_notification_module' => [
      'code' => 'ONM',
      'name' => 'Order Notification',
      'desc' => 'Tampilkan notifikasi saat ada order baru.',
      'page' => 'order_notification_page',
    ],
    'price_update_module' => [
      'code' => 'PUM',
      'name' => 'Update Harga',
      'desc' => 'Update harga produk dari Google Sheets (tab SK terbaru).',
      'page' => 'price_update_page',
    ],
    'role_price_module' => [
      'code' => 'RPM',
      'name' => 'Role Price',
      'desc' => 'Diskon kuantitas berdasarkan jumlah dan role.',
      'page' => 'role_price_page',
    ],
    'sales_mode_module' => [
      'code' => 'SMM',
      'name' => 'Mode Berjualan',
      'desc' => 'Aktifkan atau hentikan penjualan di toko dengan satu tombol.',
      'page' => 'sales_mode_page',
    ],
    'shipment_method_limit_module' => [
      'code' => 'SMLM',
      'name' => 'Shipment Method Limit',
      'desc' => 'Sembunyikan "Ambil Sendiri" kecuali untuk admin dan reseller.',
      'page' => null,
    ],
    'title_limiter_module' => [
      'code' => 'TLM',
      'name' => 'Title Limiter',
      'desc' => 'Batasi panjang judul produk.',
      'page' => 'title_limiter_page',
    ],
  ];
}

function kiip_tab_url($tab)
{
  return admin_url('admin.php?page=kiip-helpers&tab=' . rawurlencode($tab));
}

function list_menu_page()
{
  if (!current_user_can('manage_options')) {
    wp_die('Tidak punya izin.');
  }

  $modules = kiip_modules();

  if (isset($_POST['module_control']) && check_admin_referer('kiip_module_control')) {
    foreach (array_keys($modules) as $key) {
      update_option($key, isset($_POST[$key]) ? 'yes' : 'no');
    }
    echo '<div class="notice notice-success is-dismissible"><p>Pengaturan modul disimpan.</p></div>';
  }

  $statuses = [];
  foreach (array_keys($modules) as $key) {
    $statuses[$key] = get_option($key, 'no');
  }

  $tab = sanitize_key($_GET['tab'] ?? 'modules');
  if ($tab !== 'modules' && (!isset($modules[$tab]) || $statuses[$tab] !== 'yes' || !$modules[$tab]['page'])) {
    $tab = 'modules';
  }

  kiip_admin_styles();
  ?>
  <div class="wrap kiip-wrap">
    <h1 class="wp-heading-inline">KiiP Helpers</h1>
    <span class="kiip-version">v<?php echo esc_html(kiip_installed_version()); ?></span>
    <?php if (function_exists('kiip_sales_closed') && kiip_sales_closed()): ?>
      <a class="kiip-pill kiip-pill-red" href="<?php echo esc_url(kiip_tab_url('sales_mode_module')); ?>">Penjualan nonaktif</a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <?php kiip_render_notice(); ?>

    <nav class="nav-tab-wrapper">
      <a href="<?php echo esc_url(kiip_tab_url('modules')); ?>"
        class="nav-tab <?php echo $tab === 'modules' ? 'nav-tab-active' : ''; ?>">Modul</a>
      <?php foreach ($modules as $key => $module):
        if ($statuses[$key] !== 'yes' || !$module['page']) {
          continue;
        } ?>
        <a href="<?php echo esc_url(kiip_tab_url($key)); ?>"
          class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($module['name']); ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="kiip-tab-body">
      <?php
      if ($tab === 'modules') {
        kiip_render_update_box();
        kiip_render_module_list($modules, $statuses);
      } else {
        call_user_func($modules[$tab]['page']);
      }
      ?>
    </div>
  </div>
  <?php
}

function kiip_render_module_list($modules, $statuses)
{
  $active = count(array_filter($statuses, function ($v) {
    return $v === 'yes';
  }));
  ?>
  <div class="kiip-card">
    <h2>Daftar Modul <span class="kiip-muted"><?php echo (int) $active; ?> dari <?php echo count($modules); ?> aktif</span></h2>
    <form method="post">
      <?php wp_nonce_field('kiip_module_control'); ?>
      <table class="widefat kiip-modules">
        <thead>
          <tr>
            <th class="col-toggle">Aktif</th>
            <th>Modul</th>
            <th>Keterangan</th>
            <th class="col-link"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($modules as $key => $module):
            $on = $statuses[$key] === 'yes'; ?>
            <tr class="<?php echo $on ? 'is-on' : 'is-off'; ?>">
              <td class="col-toggle">
                <label class="kiip-switch" title="Aktif / nonaktif">
                  <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="yes" <?php checked($on); ?>>
                  <span class="kiip-slider"></span>
                </label>
              </td>
              <td>
                <span class="kiip-code"><?php echo esc_html($module['code']); ?></span>
                <strong><?php echo esc_html($module['name']); ?></strong>
              </td>
              <td class="kiip-muted"><?php echo esc_html($module['desc']); ?></td>
              <td class="col-link">
                <?php if ($on && $module['page']): ?>
                  <a href="<?php echo esc_url(kiip_tab_url($key)); ?>">Pengaturan →</a>
                <?php elseif ($module['page']): ?>
                  <span class="kiip-muted">Aktifkan untuk mengatur</span>
                <?php else: ?>
                  <span class="kiip-muted">Tanpa pengaturan</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p><input type="submit" name="module_control" class="button button-primary" value="Simpan Pengaturan"></p>
    </form>
  </div>
  <?php
}

function kiip_render_update_box()
{
  if (!current_user_can('update_plugins')) {
    return;
  }

  $settings = kiip_update_settings();
  $installed = kiip_installed_version();
  $remote = get_transient(KIIP_UPDATE_CACHE_KEY);
  if (!is_array($remote) && $settings['token'] !== '') {
    // Cek otomatis sekali per jam kalau token sudah ada.
    $remote = kiip_get_remote_version();
  }
  $has_update = is_array($remote) && version_compare($remote['version'], $installed, '>');
  ?>
  <div class="kiip-card kiip-update <?php echo $has_update ? 'has-update' : ''; ?>">
    <div class="kiip-update-row">
      <div>
        <h2>Update Plugin</h2>
        <p>
          Terpasang: <strong><?php echo esc_html($installed); ?></strong>
          &nbsp;·&nbsp; Terbaru di GitHub (<code><?php echo esc_html($settings['branch']); ?></code>):
          <?php if (is_array($remote)): ?>
            <strong><?php echo esc_html($remote['version']); ?></strong>
            <span class="kiip-muted">(dicek <?php echo esc_html(human_time_diff($remote['checked'])); ?> lalu)</span>
          <?php elseif (is_wp_error($remote)): ?>
            <span class="kiip-error"><?php echo esc_html($remote->get_error_message()); ?></span>
          <?php else: ?>
            <span class="kiip-muted">belum dicek</span>
          <?php endif; ?>
        </p>
      </div>
      <div class="kiip-update-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="kiip_check_update">
          <?php wp_nonce_field('kiip_check_update'); ?>
          <button class="button">Cek Update</button>
        </form>
        <?php if (is_array($remote)): ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js($has_update ? 'Update KiiP Helpers ke versi ' . $remote['version'] . '?' : 'Pasang ulang KiiP Helpers dari GitHub?'); ?>');">
            <input type="hidden" name="action" value="kiip_run_update">
            <?php wp_nonce_field('kiip_run_update'); ?>
            <?php if ($has_update): ?>
              <button class="button button-primary">Update ke <?php echo esc_html($remote['version']); ?></button>
            <?php else: ?>
              <button class="button">Pasang Ulang</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <details class="kiip-details" <?php echo $settings['token'] === '' ? 'open' : ''; ?>>
      <summary>Pengaturan GitHub</summary>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kiip_save_update_settings">
        <?php wp_nonce_field('kiip_save_update_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="kiip_update_token">GitHub token</label></th>
            <td>
              <?php if ($settings['token_from_config']): ?>
                <p>Token diatur lewat <code>KIIP_GITHUB_TOKEN</code> di wp-config.php.</p>
              <?php else: ?>
                <input type="password" id="kiip_update_token" name="kiip_update_token" class="regular-text" autocomplete="off"
                  placeholder="<?php echo $settings['token'] !== '' ? 'Tersimpan (isi untuk mengganti)' : 'github_pat_...'; ?>">
                <?php if ($settings['token'] !== ''): ?>
                  <label><input type="checkbox" name="kiip_update_token_clear" value="1"> Hapus token</label>
                <?php endif; ?>
                <p class="description">Repo private, jadi butuh token. Buat di GitHub → Settings → Developer settings →
                  Fine-grained tokens, pilih repo ini saja, izin <strong>Contents: Read-only</strong>.</p>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="kiip_update_repo">Repo</label></th>
            <td><input type="text" id="kiip_update_repo" name="kiip_update_repo" class="regular-text"
                value="<?php echo esc_attr($settings['repo']); ?>"></td>
          </tr>
          <tr>
            <th scope="row"><label for="kiip_update_branch">Branch</label></th>
            <td>
              <input type="text" id="kiip_update_branch" name="kiip_update_branch" class="regular-text"
                value="<?php echo esc_attr($settings['branch']); ?>">
              <p class="description">Biasanya <code>main</code>. Isi branch lain hanya untuk mencoba versi yang belum di-merge (mis. di staging).</p>
            </td>
          </tr>
        </table>
        <p><button class="button">Simpan Pengaturan GitHub</button></p>
      </form>
    </details>
  </div>
  <?php
}

function kiip_admin_styles()
{
  ?>
  <style>
    .kiip-wrap .kiip-version { display:inline-block; margin-left:6px; padding:2px 8px; border-radius:10px; background:#f0f0f1; color:#50575e; font-size:12px; vertical-align:middle; }
    .kiip-wrap .kiip-pill { display:inline-block; margin-left:6px; padding:2px 10px; border-radius:10px; font-size:12px; font-weight:600; text-decoration:none; vertical-align:middle; }
    .kiip-wrap .kiip-pill-red { background:#fcf0f1; color:#b32d2e; border:1px solid #f0b8b9; }
    .kiip-wrap .nav-tab-wrapper { margin-bottom:0; }
    .kiip-wrap .kiip-tab-body { padding-top:16px; }
    .kiip-wrap .kiip-card { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px 20px; margin:0 0 16px; max-width:1100px; box-sizing:border-box; }
    .kiip-wrap .kiip-card h2 { margin:0 0 10px; font-size:16px; }
    .kiip-wrap .kiip-muted { color:#646970; font-weight:normal; }
    .kiip-wrap h2 .kiip-muted { font-size:13px; margin-left:6px; }
    .kiip-wrap .kiip-error { color:#b32d2e; }
    .kiip-wrap .kiip-update.has-update { border-left:4px solid #dba617; }
    .kiip-wrap .kiip-update-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; }
    .kiip-wrap .kiip-update-row p { margin:0; }
    .kiip-wrap .kiip-update-actions { display:flex; gap:8px; }
    .kiip-wrap .kiip-details { margin-top:12px; }
    .kiip-wrap .kiip-details summary { cursor:pointer; color:#2271b1; }
    .kiip-wrap .kiip-details .form-table th { width:170px; }
    .kiip-wrap table.kiip-modules { border-radius:4px; }
    .kiip-wrap table.kiip-modules td, .kiip-wrap table.kiip-modules th { vertical-align:middle; padding:10px 12px; }
    .kiip-wrap table.kiip-modules .col-toggle { width:60px; }
    .kiip-wrap table.kiip-modules .col-link { width:170px; text-align:right; white-space:nowrap; }
    .kiip-wrap table.kiip-modules tr.is-off strong { color:#646970; }
    .kiip-wrap table.kiip-modules tbody tr + tr td { border-top:1px solid #f0f0f1; }
    .kiip-wrap .kiip-code { display:inline-block; min-width:44px; margin-right:8px; padding:1px 6px; border-radius:3px; background:#f0f6fc; color:#2271b1; font:600 11px/18px Menlo, Consolas, monospace; text-align:center; }
    .kiip-wrap .kiip-switch { position:relative; display:inline-block; width:36px; height:20px; }
    .kiip-wrap .kiip-switch input { opacity:0; width:0; height:0; position:absolute; }
    .kiip-wrap .kiip-slider { position:absolute; inset:0; background:#c3c4c7; border-radius:20px; transition:.15s; cursor:pointer; }
    .kiip-wrap .kiip-slider:before { content:""; position:absolute; width:14px; height:14px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.15s; }
    .kiip-wrap .kiip-switch input:checked + .kiip-slider { background:#2271b1; }
    .kiip-wrap .kiip-switch input:checked + .kiip-slider:before { transform:translateX(16px); }
    .kiip-wrap .kiip-switch input:focus-visible + .kiip-slider { box-shadow:0 0 0 2px #fff, 0 0 0 4px #2271b1; }
    .kiip-wrap .kiip-sales-status { display:inline-block; padding:8px 14px; border-radius:4px; margin:6px 0 4px; }
    .kiip-wrap .kiip-sales-status.is-open { background:#edfaef; color:#00702a; }
    .kiip-wrap .kiip-sales-status.is-closed { background:#fcf0f1; color:#b32d2e; }
    .kiip-wrap .kiip-segmented { display:inline-flex; border:1px solid #8c8f94; border-radius:4px; overflow:hidden; }
    .kiip-wrap .kiip-segmented label { margin:0 !important; }
    .kiip-wrap .kiip-segmented input { position:absolute; opacity:0; }
    .kiip-wrap .kiip-segmented span { display:inline-block; padding:6px 16px; cursor:pointer; }
    .kiip-wrap .kiip-segmented input:checked + span { background:#2271b1; color:#fff; }
    .kiip-wrap .kiip-segmented input:focus-visible + span { outline:2px solid #2271b1; outline-offset:-2px; }
    .kiip-wrap .kiip-subsub { margin:4px 0 0; float:none; }
    .kiip-wrap .kiip-pum-actions { margin:12px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .kiip-wrap .kiip-table { max-width:1100px; }
    .kiip-wrap .kiip-table .num { text-align:right; white-space:nowrap; }
    .kiip-wrap .kiip-up { color:#00702a; }
    .kiip-wrap .kiip-down { color:#b32d2e; }
    @media (max-width: 782px) {
      .kiip-wrap table.kiip-modules .col-link { display:none; }
    }
  </style>
  <?php
}
