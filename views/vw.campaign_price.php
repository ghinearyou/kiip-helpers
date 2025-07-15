<?php

function campaign_price_page()
{
  handle_campaign_deletion();
  handle_campaign_csv_upload();

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

      <input type="submit" name="upload_csv" value="Upload CSV" class="button button-primary">
    </form>
  </div>
  <?php
}


function render_campaign_list()
{
  $jsonFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.json';

  if (!file_exists($jsonFile)) {
    echo '<p>Tidak ada data campaign.</p>';
    return;
  }

  $jsonContent = file_get_contents($jsonFile);
  $allCampaigns = json_decode($jsonContent, true);

  if (empty($allCampaigns) || !is_array($allCampaigns)) {
    echo '<p>Tidak ada data campaign.</p>';
    return;
  }

  echo '<h2>Daftar Campaign</h2>';
  echo '<table class="widefat fixed striped">';
  echo '<thead><tr><th>Nama Campaign</th><th>Jumlah SKU</th><th>Aksi</th></tr></thead>';
  echo '<tbody>';

  foreach ($allCampaigns as $campaignName => $products) {
    $count = is_array($products) ? count($products) : 0;
    $deleteUrl = esc_url(add_query_arg(['delete_campaign' => $campaignName]));

    echo '<tr>';
    echo '<td>' . esc_html($campaignName) . '</td>';
    echo '<td>' . esc_html($count) . '</td>';
    echo '<td><a class="button button-secondary" href="' . $deleteUrl . '" onclick="return confirm(\'Hapus campaign ini?\')">Hapus</a></td>';
    echo '</tr>';
  }

  echo '</tbody>';
  echo '</table>';
}

function handle_campaign_deletion()
{
  if (!current_user_can('manage_options')) return;

  if (isset($_GET['delete_campaign'])) {
    $campaignName = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['delete_campaign']));
    if (empty($campaignName)) return;

    $jsonFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.json';
    if (!file_exists($jsonFile)) return;

    $jsonContent = file_get_contents($jsonFile);
    $allCampaigns = json_decode($jsonContent, true);
    if (!is_array($allCampaigns)) return;

    if (isset($allCampaigns[$campaignName])) {
      unset($allCampaigns[$campaignName]);

      file_put_contents($jsonFile, json_encode($allCampaigns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

      add_action('admin_notices', function () use ($campaignName) {
        echo '<div class="notice notice-success"><p>Campaign <strong>' . esc_html($campaignName) . '</strong> berhasil dihapus.</p></div>';
      });

      // Redirect bersih
      wp_safe_redirect(remove_query_arg('delete_campaign'));
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

    if (empty($campaignName)) {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Nama campaign tidak valid. Hanya huruf kecil dan angka.</p></div>';
      });
      return;
    }

    $file = $_FILES['campaign_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Gagal upload file.</p></div>';
      });
      return;
    }

    if (mime_content_type($file['tmp_name']) !== 'text/plain' && mime_content_type($file['tmp_name']) !== 'text/csv') {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>File harus berupa CSV.</p></div>';
      });
      return;
    }

    // Parse CSV
    $data = [];
    if (($handle = fopen($file['tmp_name'], "r")) !== false) {
      $header = fgetcsv($handle, 1000, ","); // skip header
      while (($row = fgetcsv($handle, 1000, ",")) !== false) {
        if (count($row) < 2) continue;
        $sku = strtoupper(trim($row[0]));
        $price = (float) trim($row[1]);
        if ($sku !== '' && $price > 0) {
          $data[$sku] = $price;
        }
      }
      fclose($handle);
    }

    if (empty($data)) {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>CSV kosong atau format salah (harus sku,price).</p></div>';
      });
      return;
    }

    $jsonFile = plugin_dir_path(__DIR__) . 'output/op.campaignprice.json';

    // Baca data lama
    $allCampaigns = [];
    if (file_exists($jsonFile)) {
      $jsonContent = file_get_contents($jsonFile);
      $decoded = json_decode($jsonContent, true);
      if (is_array($decoded)) {
        $allCampaigns = $decoded;
      }
    }

    // Tambah/update campaign
    $allCampaigns[$campaignName] = $data;

    // Simpan kembali
    if (file_put_contents($jsonFile, json_encode($allCampaigns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
      add_action('admin_notices', function () use ($campaignName) {
        echo '<div class="notice notice-success"><p>Upload berhasil! Campaign <strong>' . esc_html($campaignName) . '</strong> ditambahkan/diupdate.</p></div>';
      });
    } else {
      add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Gagal menyimpan data JSON.</p></div>';
      });
    }
  }
}
