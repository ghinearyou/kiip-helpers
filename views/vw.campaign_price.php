<?php

if (!defined('ABSPATH')) {
  exit;
}

const KIIP_DAY_NAMES = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

function kiip_campaign_file()
{
  return plugin_dir_path(__DIR__) . 'output/op.campaignprice.php';
}

function kiip_campaign_load()
{
  $file = kiip_campaign_file();
  $data = file_exists($file) ? include $file : [];
  return is_array($data) ? $data : [];
}

function kiip_campaign_save($all)
{
  file_put_contents(kiip_campaign_file(), '<?php return ' . var_export($all, true) . ';');
}

/**
 * Hari aktif dari form: bisa array checkbox (campaign_days[]) atau teks "1,2,3".
 */
function kiip_campaign_days_from_post()
{
  $raw = $_POST['campaign_days'] ?? [];
  $raw = is_array($raw) ? $raw : explode(',', (string) $raw);
  $days = array_values(array_unique(array_filter(array_map('intval', $raw), function ($d) {
    return $d >= 1 && $d <= 7;
  })));
  sort($days);
  return $days;
}

function kiip_format_days($days)
{
  $days = array_map('intval', (array) $days);
  sort($days);
  if ($days === [1, 2, 3, 4, 5, 6, 7]) {
    return 'Setiap hari';
  }
  if ($days === [1, 2, 3, 4, 5]) {
    return 'Sen–Jum';
  }
  return implode(', ', array_map(function ($d) {
    return KIIP_DAY_NAMES[$d] ?? $d;
  }, $days)) ?: '-';
}

function kiip_day_checkboxes($name, $selected)
{
  echo '<fieldset class="kiip-days">';
  foreach (KIIP_DAY_NAMES as $num => $label) {
    printf(
      '<label><input type="checkbox" name="%s[]" value="%d" %s><span>%s</span></label>',
      esc_attr($name),
      $num,
      checked(in_array($num, (array) $selected, true), true, false),
      esc_html($label)
    );
  }
  echo '</fieldset>';
}

/**
 * Sama dengan logika di fn.campaign_price.php: aktif kalau hari cocok dan jam di antara start–end (inklusif).
 */
function kiip_campaign_is_running($schedule)
{
  if (!$schedule) {
    return true;
  }
  $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
  $hour = (int) $now->format('G');
  $day = (int) $now->format('N');
  return in_array($day, (array) $schedule['days']) && $hour >= $schedule['start'] && $hour <= $schedule['end'];
}

function campaign_price_page()
{
  $campaigns = kiip_campaign_load();
  ?>
  <div class="kiip-card">
    <h2>Daftar Campaign <span class="kiip-muted"><?php echo count($campaigns); ?> campaign</span></h2>
    <?php render_campaign_list($campaigns); ?>
  </div>

  <div class="kiip-card">
    <h2>Tambah / Ganti Campaign</h2>
    <p class="description">Kalau nama campaign sudah ada, isinya akan diganti dengan file yang baru.</p>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('kiip_campaign_upload', 'kiip_campaign_nonce'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="campaign_name">Nama campaign</label></th>
          <td>
            <input type="text" id="campaign_name" name="campaign_name" class="regular-text" required pattern="[a-z0-9]+"
              title="Hanya huruf kecil dan angka" placeholder="contoh: flashsale11">
            <p class="description">Hanya huruf kecil dan angka, tanpa spasi.</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="campaign_file">File CSV</label></th>
          <td>
            <input type="file" id="campaign_file" name="campaign_file" accept=".csv" required>
            <p class="description">Dua kolom: <code>sku,price</code>. Baris pertama dianggap judul kolom.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Hari aktif</th>
          <td><?php kiip_day_checkboxes('campaign_days', [1, 2, 3, 4, 5, 6, 7]); ?></td>
        </tr>
        <tr>
          <th scope="row">Jam aktif</th>
          <td>
            <input type="number" name="campaign_start" min="0" max="23" value="0" class="small-text" required aria-label="Jam mulai">
            sampai
            <input type="number" name="campaign_end" min="0" max="23" value="23" class="small-text" required aria-label="Jam selesai">
            <p class="description">Jam 0–23 WIB. Contoh 10 sampai 12 berarti aktif dari 10:00 hingga 12:59.</p>
          </td>
        </tr>
      </table>
      <p><input type="submit" name="upload_csv" value="Upload Campaign" class="button button-primary"></p>
    </form>
  </div>
  <?php
}

function render_campaign_list($campaigns = null)
{
  $campaigns = $campaigns ?? kiip_campaign_load();
  if (!$campaigns) {
    echo '<p class="kiip-muted">Belum ada campaign. Tambahkan lewat form di bawah.</p>';
    return;
  }
  ?>
  <table class="widefat striped kiip-table">
    <thead>
      <tr>
        <th>Campaign</th>
        <th class="num">Jumlah SKU</th>
        <th>Hari</th>
        <th>Jam (WIB)</th>
        <th>Status</th>
        <th class="kiip-actions-col"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($campaigns as $name => $data):
        $count = isset($data['prices']) && is_array($data['prices']) ? count($data['prices']) : 0;
        $schedule = $data['schedule'] ?? null;
        $days = $schedule['days'] ?? [];
        $start = $schedule['start'] ?? '';
        $end = $schedule['end'] ?? '';
        $running = kiip_campaign_is_running($schedule);
        $delete_url = wp_nonce_url(add_query_arg(['delete_campaign' => $name]), 'kiip_delete_campaign_' . $name);
        $row_id = 'kiip-edit-campaign-' . sanitize_html_class($name);
        ?>
        <tr>
          <td><strong><?php echo esc_html($name); ?></strong></td>
          <td class="num"><?php echo (int) $count; ?></td>
          <td><?php echo esc_html($schedule ? kiip_format_days($days) : 'Setiap hari'); ?></td>
          <td><?php echo $schedule ? sprintf('%02d:00 – %02d:59', (int) $start, (int) $end) : 'Sepanjang hari'; ?></td>
          <td>
            <?php if ($running): ?>
              <span class="kiip-pill kiip-pill-green">Sedang aktif</span>
            <?php else: ?>
              <span class="kiip-pill kiip-pill-grey">Di luar jadwal</span>
            <?php endif; ?>
          </td>
          <td class="kiip-actions-col">
            <button type="button" class="button button-small" onclick="kiipToggle('<?php echo esc_js($row_id); ?>')">Ubah jadwal</button>
            <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
              onclick="return confirm('Hapus campaign <?php echo esc_js($name); ?>?')">Hapus</a>
          </td>
        </tr>
        <tr id="<?php echo esc_attr($row_id); ?>" class="kiip-edit-row" hidden>
          <td colspan="6">
            <form method="post" class="kiip-inline-form">
              <?php wp_nonce_field('kiip_campaign_schedule', 'kiip_campaign_nonce'); ?>
              <input type="hidden" name="campaign_edit_name" value="<?php echo esc_attr($name); ?>">
              <?php kiip_day_checkboxes('campaign_days', $days ?: [1, 2, 3, 4, 5, 6, 7]); ?>
              <span>
                Jam
                <input type="number" name="campaign_start" min="0" max="23" value="<?php echo esc_attr($start === '' ? 0 : $start); ?>" class="small-text" required aria-label="Jam mulai">
                sampai
                <input type="number" name="campaign_end" min="0" max="23" value="<?php echo esc_attr($end === '' ? 23 : $end); ?>" class="small-text" required aria-label="Jam selesai">
              </span>
              <input type="submit" name="update_schedule" class="button button-primary" value="Simpan">
              <button type="button" class="button" onclick="kiipToggle('<?php echo esc_js($row_id); ?>')">Batal</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

/**
 * Handler dijalankan di admin_init (sebelum halaman tampil) supaya redirect dan notifikasi berfungsi.
 */
add_action('admin_init', 'handle_campaign_deletion');
function handle_campaign_deletion()
{
  if (!current_user_can('manage_options') || !isset($_GET['delete_campaign'])) {
    return;
  }

  $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower((string) $_GET['delete_campaign']));
  if ($campaignName === '') {
    return;
  }
  check_admin_referer('kiip_delete_campaign_' . $campaignName);

  $all = kiip_campaign_load();
  if (isset($all[$campaignName])) {
    unset($all[$campaignName]);
    kiip_campaign_save($all);
    kiip_flash('success', 'Campaign <strong>' . esc_html($campaignName) . '</strong> berhasil dihapus.');
  }

  wp_safe_redirect(remove_query_arg(['delete_campaign', '_wpnonce']));
  exit;
}

add_action('admin_init', 'handle_campaign_csv_upload');
function handle_campaign_csv_upload()
{
  if (
    !current_user_can('manage_options')
    || !isset($_POST['upload_csv'], $_POST['campaign_name'], $_FILES['campaign_file'])
  ) {
    return;
  }
  check_admin_referer('kiip_campaign_upload', 'kiip_campaign_nonce');

  $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $_POST['campaign_name'])));
  if ($campaignName === '') {
    kiip_flash('error', 'Nama campaign hanya boleh huruf kecil dan angka.');
    return;
  }

  $data = kiip_read_sku_price_csv($_FILES['campaign_file']);
  if (is_string($data)) {
    kiip_flash('error', $data);
    return;
  }

  $days = kiip_campaign_days_from_post();
  if (!$days) {
    kiip_flash('error', 'Pilih minimal satu hari aktif.');
    return;
  }

  $all = kiip_campaign_load();
  $all[$campaignName] = [
    'schedule' => [
      'days' => $days,
      'start' => max(0, min(23, (int) $_POST['campaign_start'])),
      'end' => max(0, min(23, (int) $_POST['campaign_end'])),
    ],
    'prices' => $data,
  ];
  kiip_campaign_save($all);

  kiip_flash('success', 'Campaign <strong>' . esc_html($campaignName) . '</strong> disimpan dengan ' . count($data) . ' SKU.');
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=kiip-helpers&tab=campaign_price_module'));
  exit;
}

add_action('admin_init', 'handle_campaign_schedule_update');
function handle_campaign_schedule_update()
{
  if (!current_user_can('manage_options') || !isset($_POST['update_schedule'], $_POST['campaign_edit_name'])) {
    return;
  }
  check_admin_referer('kiip_campaign_schedule', 'kiip_campaign_nonce');

  $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower((string) $_POST['campaign_edit_name']));
  $all = kiip_campaign_load();
  if (!isset($all[$campaignName])) {
    return;
  }

  $days = kiip_campaign_days_from_post();
  if (!$days) {
    kiip_flash('error', 'Pilih minimal satu hari aktif.');
  } else {
    $all[$campaignName]['schedule'] = [
      'days' => $days,
      'start' => max(0, min(23, (int) $_POST['campaign_start'])),
      'end' => max(0, min(23, (int) $_POST['campaign_end'])),
    ];
    kiip_campaign_save($all);
    kiip_flash('success', 'Jadwal <strong>' . esc_html($campaignName) . '</strong> berhasil diperbarui.');
  }

  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=kiip-helpers&tab=campaign_price_module'));
  exit;
}
