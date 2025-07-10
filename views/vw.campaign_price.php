<?php

function campaign_price_page()
{
  ?>
  <div class="wrap">
    <h1>Campaign Price Setting</h1>
    <form method="post" enctype="multipart/form-data">
      <label>Nama Campaign (huruf dan angka kecil):</label><br>
      <input type="text" name="campaign_name" required pattern="[a-z0-9]+" title="Hanya huruf kecil dan angka"><br><br>

      <label>File CSV (sku,price):</label><br>
      <input type="file" name="campaign_file" accept=".csv" required><br><br>

      <input type="submit" name="upload_csv" value="Upload CSV">
    </form>
  </div>
  <?php
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
      echo '<div class="notice notice-error"><p>Nama campaign tidak valid. Hanya huruf kecil dan angka.</p></div>';
      return;
    }

    $file = $_FILES['campaign_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
      echo '<div class="notice notice-error"><p>Gagal upload file.</p></div>';
      return;
    }

    if (mime_content_type($file['tmp_name']) !== 'text/plain' && mime_content_type($file['tmp_name']) !== 'text/csv') {
      echo '<div class="notice notice-error"><p>File harus berupa CSV.</p></div>';
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
      echo '<div class="notice notice-error"><p>CSV kosong atau format salah (harus sku,price).</p></div>';
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

    if (file_put_contents($jsonFile, json_encode($allCampaigns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
      echo '<div class="notice notice-success"><p>Upload berhasil! Campaign <strong>' . esc_html($campaignName) . '</strong> ditambahkan/diupdate.</p></div>';
    } else {
      echo '<div class="notice notice-error"><p>Gagal menyimpan data JSON.</p></div>';
    }
  }
}
