<?php

function role_price_page()
{
  if (isset($_POST['kiip_discount_enabled'])) {
    update_option('kiip_discount_enabled', 'yes');
  } else {
    update_option('kiip_discount_enabled', 'no');
  }

  // Ambil status saat ini
  $enabled = get_option('kiip_discount_enabled', 'no');
  ?>
  <div class="wrap">
    <h1>Role Price Setting</h1>
    <form method="post">
      <label>
        <input type="checkbox" name="kiip_discount_enabled" value="yes" <?php checked($enabled, 'yes'); ?> />
        Aktifkan diskon kuantitas berdasarkan jumlah dan role
      </label>
      <br><br>
      <input type="submit" class="button button-primary" value="Simpan Pengaturan">
    </form>
    <form method="post" enctype="multipart/form-data">
      <?php
      settings_fields('my_custom_plugin_settings_group');
      do_settings_sections('my-custom-plugin');
      ?>
      <h2>Upload Files</h2>
      <input type="file" name="my_custom_files[]" multiple />
      <p class="description">Upload CSV.</p>
      <?php
      submit_button('Upload Files');
      ?>
    </form>
  </div>
  <?php

  // Handle file uploads after form submission
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['my_custom_files'])) {
    upload_handler();
  }
}

function upload_handler()
{
  $uploaded_files = $_FILES['my_custom_files'];
  $myJson = new stdClass();

  if (empty($uploaded_files['name'][0])) {
    echo "<p style='color: red;'>No files uploaded.</p>";
    return;
  }

  $upload_dir = wp_upload_dir();
  $allowed_types = ['text/csv'];

  foreach ($uploaded_files['name'] as $key => $filename) {
    if ($uploaded_files['error'][$key] !== UPLOAD_ERR_OK) {
      echo "<p style='color: red;'>Error uploading file: $filename.</p>";
      continue;
    }

    $file_type = $uploaded_files['type'][$key];
    if (!in_array($file_type, $allowed_types)) {
      echo "<p style='color: red;'>Invalid file type: $filename. Allowed type only CSV.</p>";
      continue;
    }

    $tmp_name = $uploaded_files['tmp_name'][$key];
    $destination = $upload_dir['path'] . '/' . basename($filename);

    if (move_uploaded_file($tmp_name, $destination)) {
      echo "<p style='color: green;'>File uploaded successfully: $filename.</p>";
      if (($handle = fopen($destination, "r")) !== FALSE) {
        $rowCount = 0;
        $iSKU = '';
        $iPrice = '';
        while (($data = fgetcsv($handle, 1000, ",", "\"", "\\")) !== FALSE) {
          if ($iSKU == '' || $iPrice == '') {
            for ($i = 0; $i < count($data); $i++) {
              if (strtolower($data[$i]) == 'sku') {
                $iSKU = $i;
              }

              if (strtolower($data[$i]) == 'reseller price' || strtolower($data[$i]) == 'harga reseller') {
                $iPrice = $i;
              }
            }
          }

          if ($rowCount > 4) {
            $sku = $data[$iSKU];
            $retailPrice = $data[$iPrice];

            if ($sku && $retailPrice) {
              $finalPrice = intval(str_replace(".", "", str_replace("Rp", "", $retailPrice)));
              if ($finalPrice > 0) {
                $myJson->$sku = intval($finalPrice);
              }
            }
          }
          $rowCount++;
        }
        fclose($handle);
        wp_delete_file($destination);
      } else {
        echo "<p style='color: red;'>Failed to check csv file: $filename.</p>";
      }
    } else {
      echo "<p style='color: red;'>Failed to upload file: $filename.</p>";
    }
  }

  $rolePrice = new stdClass();
  $rolePrice->reseller = $myJson;

  $json_data = json_encode($rolePrice, JSON_PRETTY_PRINT);
  $json_file_path = plugin_dir_path(__DIR__) . '../output/op.roleprice.json';

  if (file_put_contents($json_file_path, $json_data)) {
    echo '<p style="color: green;">JSON file created successfully</p>';
  } else {
    echo '<p style="color: red;">Failed to create JSON file.</p>';
  }
}


