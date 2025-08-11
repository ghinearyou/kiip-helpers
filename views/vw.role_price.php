<?php

function role_price_page()
{
  handle_role_deletion();
  handle_role_csv_upload();
  ?>
  <div class="wrap">
    <h1>Role Price Setting</h1>

    <?php render_role_list(); ?>

    <h2>Tambah Role</h2>
    <form method="post" enctype="multipart/form-data">
      <label>Role Name (huruf dan angka kecil):</label><br>
      <input type="text" name="role_name" required pattern="[a-z0-9]+" title="Hanya huruf kecil dan angka"><br><br>

      <label>File CSV (sku,price):</label><br>
      <input type="file" name="role_file" accept=".csv" required><br><br>

      <input type="submit" name="upload_csv" value="Upload CSV" class="button button-primary">
    </form>
  </div>
  <?php
}

function render_role_list()
{
  $phpFile = plugin_dir_path(__DIR__) . 'output/op.roleprice.php';
  if (!file_exists($phpFile)) {
    echo '<p>Tidak ada data role.</p>';
    return;
  }

  $allRoles = include $phpFile;
  if (empty($allRoles) || !is_array($allRoles)) {
    echo '<p>Tidak ada data role.</p>';
    return;
  }

  echo '<h2>Daftar Role</h2>';
  echo '<table class="widefat fixed striped">';
  echo '<thead><tr><th>Role Name</th><th>Jumlah SKU</th><th>Aksi</th></tr></thead>';
  echo '<tbody>';

  foreach ($allRoles as $roleName => $roleData) {
    $count = isset($roleData['prices']) && is_array($roleData['prices']) ? count($roleData['prices']) : 0;

    echo '<tr>';
    echo '<td>' . esc_html($roleName) . '</td>';
    echo '<td>' . esc_html($count) . '</td>';
    echo '<td>
      <a class="button button-secondary" href="' . esc_url(add_query_arg(['delete_role' => $roleName])) . '" onclick="return confirm(\'Hapus role ini?\')">Hapus</a>
    </td>';
    echo '</tr>';
  }

  echo '</tbody></table>';
}

function handle_role_deletion()
{
  if (!current_user_can('manage_options')) return;

  if (isset($_GET['delete_role'])) {
    $roleName = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['delete_role']));
    if (empty($roleName)) return;

    $phpFile = plugin_dir_path(__DIR__) . 'output/op.roleprice.php';
    if (!file_exists($phpFile)) return;

    $allRoles = include $phpFile;
    if (!is_array($allRoles)) return;

    if (isset($allRoles[$roleName])) {
      unset($allRoles[$roleName]);

      file_put_contents($phpFile, '<?php return ' . var_export($allRoles, true) . ';');

      add_action('admin_notices', function () use ($roleName) {
        echo '<div class="notice notice-success"><p>Role <strong>' . esc_html($roleName) . '</strong> berhasil dihapus.</p></div>';
      });

      wp_safe_redirect(remove_query_arg(['delete_role']));
      exit;
    }
  }
}

add_action('admin_init', 'handle_role_csv_upload');
function handle_role_csv_upload()
{
  if (!current_user_can('manage_options')) return;

  if (
    isset($_POST['upload_csv']) &&
    isset($_POST['role_name']) &&
    isset($_FILES['role_file'])
  ) {
    $roleName = strtolower(trim($_POST['role_name']));
    $roleName = preg_replace('/[^a-z0-9]/', '', $roleName);

    if (empty($roleName)) return;

    $file = $_FILES['role_file'];
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

    $phpFile = plugin_dir_path(__DIR__) . 'output/op.roleprice.php';
    $allRoles = file_exists($phpFile) ? include $phpFile : [];

    $allRoles[$roleName] = [
      'prices' => $data
    ];

    file_put_contents($phpFile, '<?php return ' . var_export($allRoles, true) . ';');

    add_action('admin_notices', function () use ($roleName) {
      echo '<div class="notice notice-success"><p>Upload berhasil! Role <strong>' . esc_html($roleName) . '</strong> ditambahkan/diupdate.</p></div>';
    });
  }
}
