<?php

if (!defined('ABSPATH')) {
  exit;
}

require_once __DIR__ . '/helpers.php';
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

    <?php kiip_render_flash(); ?>


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
    .kiip-wrap .kiip-pill-green { background:#edfaef; color:#00702a; border:1px solid #b8e6c3; margin-left:0; }
    .kiip-wrap .kiip-pill-grey { background:#f0f0f1; color:#646970; border:1px solid #dcdcde; margin-left:0; font-weight:normal; }
    .kiip-wrap .kiip-table td, .kiip-wrap .kiip-table th { vertical-align:middle; }
    .kiip-wrap .kiip-table code { font-size:11px; }
    .kiip-wrap .kiip-actions-col { text-align:right; white-space:nowrap; width:1%; }
    .kiip-wrap .kiip-actions-col .button + .button { margin-left:4px; }
    .kiip-wrap .kiip-edit-row td { background:#f6f7f7; }
    .kiip-wrap .kiip-inline-form { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .kiip-wrap .kiip-days { display:inline-flex; flex-wrap:wrap; gap:4px; border:0; padding:0; margin:0; }
    .kiip-wrap .kiip-days label { margin:0 !important; }
    .kiip-wrap .kiip-days input { position:absolute; opacity:0; }
    .kiip-wrap .kiip-days span { display:inline-block; min-width:38px; padding:4px 6px; border:1px solid #8c8f94; border-radius:3px; text-align:center; cursor:pointer; background:#fff; }
    .kiip-wrap .kiip-days input:checked + span { background:#2271b1; border-color:#2271b1; color:#fff; }
    .kiip-wrap .kiip-days input:focus-visible + span { outline:2px solid #2271b1; outline-offset:1px; }
    .kiip-wrap .kiip-prefix { color:#646970; margin-right:4px; }
    .kiip-wrap .kiip-card > .description:first-of-type { margin-top:-4px; margin-bottom:12px; }
    @media (max-width: 782px) {
      .kiip-wrap table.kiip-modules .col-link { display:none; }
    }
  </style>
  <script>
    function kiipToggle(id) {
      var el = document.getElementById(id);
      if (el) { el.hidden = !el.hidden; }
    }
  </script>
  <?php
}
