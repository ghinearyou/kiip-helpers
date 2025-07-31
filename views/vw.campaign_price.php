<?php

function campaign_price_page()
{
  handle_campaign_deletion();
  handle_campaign_csv_upload();
  handle_campaign_schedule_update();
  ?>
  <div class="wrap">
    <h1>Campaign Price Setting</h1>

    <?php render_campaign_list(); ?>

    <h2>Tambah Campaign</h2>
    <form method="post" enctype="multipart/form-data">
      <label>Nama Campaign (huruf dan angka kecil):</label><br>
      <input type="text" name="campaign_name" required pattern="[a-z0-9]+" title="Hanya huruf kecil dan angka"><br><br>

      <label>File CSV (sku,price):</label><br>
      <input type="file" name="campaign_file" accept=".csv" required><br><br>

      <label>Hari Aktif (1 = Senin ... 7 = Minggu):</label><br>
      <input type="text" name="campaign_days" placeholder="contoh: 1,2,3,4,5" required><br><br>

      <label>Jam Mulai (0–23):</label><br>
      <input type="number" name="campaign_start" min="0" max="23" required><br><br>

      <label>Jam Selesai (0–23):</label><br>
      <input type="number" name="campaign_end" min="0" max="23" required><br><br>

      <input type="submit" name="upload_csv" value="Upload CSV" class="button button-primary">
    </form>
  </div>
  <?php
}

function render_campaign_list()
{
  $phpFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.php';
  if (!file_exists($phpFile)) {
    echo '<p>Tidak ada data campaign.</p>';
    return;
  }

  $allCampaigns = include $phpFile;
  if (empty($allCampaigns) || !is_array($allCampaigns)) {
    echo '<p>Tidak ada data campaign.</p>';
    return;
  }

  echo '<h2>Daftar Campaign</h2>';
  echo '<table class="widefat fixed striped">';
  echo '<thead><tr><th>Nama Campaign</th><th>Jumlah SKU</th><th>Days</th><th>Start</th><th>End</th><th>Aksi</th></tr></thead>';
  echo '<tbody>';

  foreach ($allCampaigns as $campaignName => $campaignData) {
    $count = isset($campaignData['prices']) && is_array($campaignData['prices']) ? count($campaignData['prices']) : 0;
    $schedule = $campaignData['schedule'] ?? ['days' => [], 'start' => '-', 'end' => '-'];

    $days = implode(',', $schedule['days'] ?? []);
    $start = $schedule['start'] ?? '-';
    $end = $schedule['end'] ?? '-';

    echo '<tr>';
    echo '<td>' . esc_html($campaignName) . '</td>';
    echo '<td>' . esc_html($count) . '</td>';
    echo '<td>' . esc_html($days) . '</td>';
    echo '<td>' . esc_html($start) . '</td>';
    echo '<td>' . esc_html($end) . '</td>';
    echo '<td>
      <a class="button button-secondary" href="' . esc_url(add_query_arg(['delete_campaign' => $campaignName])) . '" onclick="return confirm(\'Hapus campaign ini?\')">Hapus</a> 
      <button class="button" onclick="toggleEditForm(\'' . esc_js($campaignName) . '\')">Edit</button>
    </td>';
    echo '</tr>';

    // EDIT FORM (hidden)
    echo '<tr id="edit-form-' . esc_attr($campaignName) . '" style="display:none;background:#f9f9f9">';
    echo '<td colspan="6">
      <form method="post">
        <input type="hidden" name="campaign_edit_name" value="' . esc_attr($campaignName) . '">
        <label>Hari Aktif (1–7):</label>
        <input type="text" name="campaign_days" value="' . esc_attr($days) . '" required>
        <label>Jam Mulai:</label>
        <input type="number" name="campaign_start" min="0" max="23" value="' . esc_attr($start) . '" required>
        <label>Jam Selesai:</label>
        <input type="number" name="campaign_end" min="0" max="23" value="' . esc_attr($end) . '" required>
        <input type="submit" name="update_schedule" class="button button-primary" value="Simpan">
        <button type="button" class="button" onclick="toggleEditForm(\'' . esc_js($campaignName) . '\')">Batal</button>
      </form>
    </td>';
    echo '</tr>';
  }

  echo '</tbody></table>';

  // Tambahkan JS
  echo <<<HTML
<script>
function toggleEditForm(campaign) {
  const row = document.getElementById('edit-form-' + campaign);
  if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
HTML;
}

function handle_campaign_deletion()
{
  if (!current_user_can('manage_options')) return;

  if (isset($_GET['delete_campaign'])) {
    $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['delete_campaign']));
    if (empty($campaignName)) return;

    $phpFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.php';
    if (!file_exists($phpFile)) return;

    $allCampaigns = include $phpFile;
    if (!is_array($allCampaigns)) return;

    if (isset($allCampaigns[$campaignName])) {
      unset($allCampaigns[$campaignName]);

      file_put_contents($phpFile, '<?php return ' . var_export($allCampaigns, true) . ';');

      add_action('admin_notices', function () use ($campaignName) {
        echo '<div class="notice notice-success"><p>Campaign <strong>' . esc_html($campaignName) . '</strong> berhasil dihapus.</p></div>';
      });

      wp_safe_redirect(remove_query_arg(['delete_campaign', 'edit_campaign']));
      exit;
    }
  }
}

add_action('admin_init', 'handle_campaign_csv_upload');
function handle_campaign_csv_upload()
{
  if (!current_user_can('manage_options')) return;

  if (
    isset($_POST['upload_csv']) &&
    isset($_POST['campaign_name']) &&
    isset($_FILES['campaign_file'])
  ) {
    $campaignName = strtolower(trim($_POST['campaign_name']));
    $campaignName = preg_replace('/[^a-z0-9]/', '', $campaignName);

    if (empty($campaignName)) return;

    $file = $_FILES['campaign_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) return;

    $data = [];
    if (($handle = fopen($file['tmp_name'], "r")) !== false) {
      fgetcsv($handle, 1000, ",");
      while (($row = fgetcsv($handle, 1000, ",")) !== false) {
        if (count($row) < 2) continue;
        $sku = strtoupper(trim($row[0]));
        $price = (float) trim($row[1]);
        if ($sku !== '' && $price > 0) $data[$sku] = $price;
      }
      fclose($handle);
    }

    if (empty($data)) return;

    $days = array_map('intval', explode(',', $_POST['campaign_days']));
    $startHour = max(0, min(23, (int) $_POST['campaign_start']));
    $endHour = max(0, min(23, (int) $_POST['campaign_end']));

    $phpFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.php';
    $allCampaigns = file_exists($phpFile) ? include $phpFile : [];

    $allCampaigns[$campaignName] = [
      'schedule' => ['days' => $days, 'start' => $startHour, 'end' => $endHour],
      'prices' => $data
    ];

    file_put_contents($phpFile, '<?php return ' . var_export($allCampaigns, true) . ';');

    add_action('admin_notices', function () use ($campaignName) {
      echo '<div class="notice notice-success"><p>Upload berhasil! Campaign <strong>' . esc_html($campaignName) . '</strong> ditambahkan/diupdate.</p></div>';
    });
  }
}

function handle_campaign_schedule_update()
{
  if (!current_user_can('manage_options')) return;

  if (isset($_POST['update_schedule']) && isset($_POST['campaign_edit_name'])) {
    $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['campaign_edit_name']));
    $days = array_map('intval', explode(',', $_POST['campaign_days']));
    $start = max(0, min(23, (int) $_POST['campaign_start']));
    $end = max(0, min(23, (int) $_POST['campaign_end']));

    $phpFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.php';
    if (!file_exists($phpFile)) return;

    $allCampaigns = include $phpFile;
    if (!is_array($allCampaigns) || !isset($allCampaigns[$campaignName])) return;

    $allCampaigns[$campaignName]['schedule'] = [
      'days' => $days,
      'start' => $start,
      'end' => $end
    ];

    file_put_contents($phpFile, '<?php return ' . var_export($allCampaigns, true) . ';');

    add_action('admin_notices', function () use ($campaignName) {
      echo '<div class="notice notice-success"><p>Schedule <strong>' . esc_html($campaignName) . '</strong> berhasil diperbarui.</p></div>';
    });

    wp_safe_redirect(remove_query_arg(['edit_campaign']));
    exit;
  }
}
