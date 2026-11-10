<?php

if (!defined('ABSPATH')) {
  exit;
}

function kiip_pum_money($value)
{
  if ($value === '' || $value === null) {
    return '<em>kosong</em>';
  }
  return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function kiip_pum_edit_link($item)
{
  $id = !empty($item['parent_id']) ? $item['parent_id'] : $item['id'];
  return esc_url(admin_url('post.php?post=' . (int) $id . '&action=edit'));
}

function price_update_page()
{
  $base_url = admin_url('admin.php?page=kiip-helpers&tab=price_update_module');
  $subtabs = [
    'update' => 'Update Harga',
    'web-missing' => 'Ada di web, tidak ada di Sheets',
    'sheet-missing' => 'Ada di Sheets, tidak ada di web',
  ];
  $sub = sanitize_key($_GET['sub'] ?? 'update');
  if (!isset($subtabs[$sub])) {
    $sub = 'update';
  }

  // Simpan pengaturan
  if (isset($_POST['pum_save_settings']) && check_admin_referer('kiip_pum_settings')) {
    $url = esc_url_raw(trim(wp_unslash($_POST['pum_sheet_url'] ?? '')));
    if ($url !== '' && !kiip_pum_sheet_id($url)) {
      echo '<div class="notice notice-error"><p>Link tidak dikenali. Gunakan link Google Sheets seperti https://docs.google.com/spreadsheets/d/.../edit</p></div>';
    } else {
      update_option('pum_sheet_url', $url, false);
      update_option('pum_tab_override', sanitize_text_field(wp_unslash($_POST['pum_tab_override'] ?? '')), false);
      $multiplier = (float) ($_POST['pum_multiplier'] ?? 1000);
      update_option('pum_multiplier', $multiplier > 0 ? $multiplier : 1000, false);
      delete_transient(KIIP_PUM_CACHE_KEY);
      echo '<div class="notice notice-success is-dismissible"><p>Pengaturan Update Harga disimpan.</p></div>';
    }
  }

  $settings = kiip_pum_settings();

  // Aksi
  $action = '';
  if (isset($_POST['pum_action']) && check_admin_referer('kiip_pum_action')) {
    $action = sanitize_key($_POST['pum_action']);
  }

  $apply_result = null;
  if ($action === 'apply') {
    $apply_result = kiip_pum_apply();
  }

  $report = null;
  $show_preview = in_array($action, ['preview', 'apply', 'refresh'], true) || $sub !== 'update';
  if ($settings['url'] !== '' && $show_preview) {
    $report = kiip_pum_build_report(in_array($action, ['preview', 'refresh'], true));
  }
  ?>

  <div class="kiip-card">
    <h2>Update Harga dari Google Sheets</h2>
    <details <?php echo $settings['url'] === '' ? 'open' : ''; ?> class="kiip-details">
      <summary>Pengaturan Sheets</summary>
      <form method="post">
        <?php wp_nonce_field('kiip_pum_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="pum_sheet_url">Link Google Sheets</label></th>
            <td>
              <input type="url" id="pum_sheet_url" name="pum_sheet_url" class="large-text"
                value="<?php echo esc_attr($settings['url']); ?>"
                placeholder="https://docs.google.com/spreadsheets/d/.../edit">
              <p class="description">Akses sheet harus "Siapa saja yang memiliki link" bisa melihat. Tab yang dipakai otomatis tab
                SK dengan tanggal terbaru, contoh <code>30 Okt SK 2026</code>. Kolom yang dibaca: <code>SKU</code> dan <code>SRP</code>.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="pum_multiplier">Pengali SRP</label></th>
            <td>
              <input type="number" id="pum_multiplier" name="pum_multiplier" min="1" step="any"
                value="<?php echo esc_attr($settings['multiplier']); ?>" class="small-text">
              <p class="description">Harga normal = SRP × pengali. Contoh SRP 125 × 1000 = Rp 125.000.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="pum_tab_override">Paksa pakai tab</label></th>
            <td>
              <input type="text" id="pum_tab_override" name="pum_tab_override" class="regular-text"
                value="<?php echo esc_attr($settings['tab_override']); ?>" placeholder="Kosongkan = otomatis tab SK terbaru">
            </td>
          </tr>
        </table>
        <p><input type="submit" name="pum_save_settings" class="button button-primary" value="Simpan Pengaturan"></p>
      </form>
    </details>
  </div>

  <?php if ($settings['url'] === ''): ?>
    <p>Isi link Google Sheets terlebih dahulu.</p>
    <?php return; ?>
  <?php endif; ?>

  <ul class="subsubsub kiip-subsub">
    <?php $i = 0; foreach ($subtabs as $key => $label): ?>
      <li>
        <a href="<?php echo esc_url(add_query_arg('sub', $key, $base_url)); ?>" class="<?php echo $sub === $key ? 'current' : ''; ?>">
          <?php echo esc_html($label); ?>
          <?php if ($report && !is_wp_error($report) && $key !== 'update'): ?>
            <span class="count">(<?php echo count($key === 'web-missing' ? $report['web_missing'] : $report['sheet_missing']); ?>)</span>
          <?php endif; ?>
        </a><?php echo ++$i < count($subtabs) ? ' |' : ''; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <br class="clear">

  <?php
  if ($apply_result !== null) {
    if (is_wp_error($apply_result)) {
      echo '<div class="notice notice-error"><p>' . esc_html($apply_result->get_error_message()) . '</p></div>';
    } else {
      printf(
        '<div class="notice notice-success"><p>Selesai. %d harga diupdate dari tab <strong>%s</strong>%s.</p></div>',
        count($apply_result['updated']),
        esc_html($apply_result['tab']),
        $apply_result['failed'] ? ', ' . count($apply_result['failed']) . ' gagal' : ''
      );
    }
  }

  if (is_wp_error($report)) {
    echo '<div class="notice notice-error"><p>' . esc_html($report->get_error_message()) . '</p></div>';
  }
  ?>

  <form method="post" class="kiip-pum-actions">
    <?php wp_nonce_field('kiip_pum_action'); ?>
    <?php if ($sub === 'update'): ?>
      <button type="submit" name="pum_action" value="preview" class="button">Preview</button>
      <button type="submit" name="pum_action" value="apply" class="button button-primary"
        onclick="return confirm('Update harga normal semua produk yang berbeda dengan Google Sheets?');">Update Harga</button>
    <?php else: ?>
      <button type="submit" name="pum_action" value="refresh" class="button">Ambil ulang dari Sheets</button>
    <?php endif; ?>
    <?php if ($report && !is_wp_error($report)): ?>
      <span class="description">
        Tab: <strong><?php echo esc_html($report['sheet']['tab']['name']); ?></strong>,
        <?php echo (int) $report['sheet']['rows']; ?> SKU,
        diambil <?php echo esc_html(human_time_diff($report['sheet']['fetched_at'])); ?> lalu.
      </span>
    <?php endif; ?>
  </form>

  <?php
  $last = get_option('pum_last_run');
  if ($sub === 'update' && is_array($last) && !$report) {
    printf(
      '<p class="description">Update terakhir: %s, tab %s, %d harga diubah.</p>',
      esc_html(wp_date('j M Y H:i', $last['time'])),
      esc_html($last['tab']),
      (int) $last['updated']
    );
  }

  if (!$report || is_wp_error($report)) {
    return;
  }

  if ($sub === 'update') {
    kiip_pum_render_diffs($report, $apply_result);
  } elseif ($sub === 'web-missing') {
    kiip_pum_render_web_missing($report['web_missing']);
  } else {
    kiip_pum_render_sheet_missing($report['sheet_missing']);
  }

  if (!empty($report['sheet']['skipped']) || !empty($report['sheet']['duplicates'])) {
    echo '<p class="description">';
    if ($report['sheet']['skipped']) {
      echo 'SKU di sheet dengan SRP kosong/tidak valid (dilewati): ' . esc_html(implode(', ', $report['sheet']['skipped'])) . '. ';
    }
    if ($report['sheet']['duplicates']) {
      echo 'SKU ganda di sheet (dipakai baris pertama): ' . esc_html(implode(', ', $report['sheet']['duplicates'])) . '.';
    }
    echo '</p>';
  }
}

function kiip_pum_render_diffs($report, $apply_result)
{
  $diffs = $report['diffs'];
  if ($apply_result && !is_wp_error($apply_result)) {
    // Setelah update, tampilkan apa yang barusan diubah.
    $diffs = $apply_result['updated'];
    echo '<h3>Harga yang barusan diupdate (' . count($diffs) . ')</h3>';
  } else {
    printf(
      '<h3>SKU dengan harga berbeda (%d)</h3><p class="description">%d produk sudah sama dengan sheet, %d produk web tidak ada di sheet.</p>',
      count($diffs),
      (int) $report['same'],
      count($report['web_missing'])
    );
  }

  if (!$diffs) {
    echo '<p>Tidak ada harga yang berbeda. Semua sudah sesuai Google Sheets.</p>';
    return;
  }
  ?>
  <table class="widefat striped kiip-table">
    <thead>
      <tr>
        <th>SKU web</th>
        <th>SKU sheet</th>
        <th>Produk</th>
        <th class="num">Harga web</th>
        <th class="num">Harga sheet</th>
        <th class="num">Selisih</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($diffs as $row):
        $delta = (float) $row['new_price'] - (float) $row['price']; ?>
        <tr>
          <td><code><?php echo esc_html($row['sku'] ?: '-'); ?></code></td>
          <td><code><?php echo esc_html($row['sheet_sku']); ?></code></td>
          <td><a href="<?php echo kiip_pum_edit_link($row); ?>"><?php echo esc_html($row['name']); ?></a></td>
          <td class="num"><?php echo kiip_pum_money($row['price']); ?></td>
          <td class="num"><strong><?php echo kiip_pum_money($row['new_price']); ?></strong></td>
          <td class="num <?php echo $delta > 0 ? 'kiip-up' : 'kiip-down'; ?>">
            <?php echo $row['price'] === '' ? '-' : ($delta > 0 ? '+' : '−') . esc_html(number_format(abs($delta), 0, ',', '.')); ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

function kiip_pum_render_web_missing($items)
{
  echo '<h3>Produk di web yang tidak ada di Google Sheets (' . count($items) . ')</h3>';
  if (!$items) {
    echo '<p>Semua produk web ada di sheet.</p>';
    return;
  }
  ?>
  <table class="widefat striped kiip-table">
    <thead>
      <tr><th>SKU</th><th>Produk</th><th>Status</th><th class="num">Harga web</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><code><?php echo esc_html($item['sku'] ?: '(tanpa SKU)'); ?></code></td>
          <td><a href="<?php echo kiip_pum_edit_link($item); ?>"><?php echo esc_html($item['name']); ?></a></td>
          <td><?php echo esc_html($item['status']); ?></td>
          <td class="num"><?php echo kiip_pum_money($item['price']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

function kiip_pum_render_sheet_missing($items)
{
  echo '<h3>SKU di Google Sheets yang tidak ada di web (' . count($items) . ')</h3>';
  if (!$items) {
    echo '<p>Semua SKU di sheet ditemukan di web.</p>';
    return;
  }
  ?>
  <table class="widefat striped kiip-table">
    <thead>
      <tr><th>SKU sheet</th><th class="num">Harga sheet</th></tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><code><?php echo esc_html($item['sku']); ?></code></td>
          <td class="num"><?php echo kiip_pum_money($item['price']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}
