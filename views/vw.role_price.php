<?php

if (!defined('ABSPATH')) {
  exit;
}

function kiip_role_price_file()
{
  return plugin_dir_path(__DIR__) . 'output/op.roleprice.php';
}

function kiip_role_price_load()
{
  $file = kiip_role_price_file();
  $data = file_exists($file) ? include $file : [];
  return is_array($data) ? $data : [];
}

function kiip_role_price_save($all)
{
  file_put_contents(kiip_role_price_file(), '<?php return ' . var_export($all, true) . ';');
}

function kiip_sanitize_role_slug($value)
{
  return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) $value)));
}

function role_price_page()
{
  $roles = kiip_role_price_load();
  $wp_roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
  ?>
  <div class="kiip-card">
    <h2>Daftar Harga per Role <span class="kiip-muted"><?php echo count($roles); ?> role</span></h2>
    <p class="description">Harga khusus berlaku kalau pembeli dengan role tersebut membeli minimal 5 pcs (dihitung per produk induk).</p>
    <?php render_role_list($roles, $wp_roles); ?>
  </div>

  <div class="kiip-card">
    <h2>Tambah / Ganti Harga Role</h2>
    <p class="description">Kalau role sudah ada di daftar, harganya akan diganti dengan file yang baru.</p>
    <form method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('kiip_role_upload', 'kiip_role_nonce'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="role_name">Role</label></th>
          <td>
            <?php if ($wp_roles): ?>
              <select id="role_name" name="role_name" required>
                <option value="">Pilih role…</option>
                <?php foreach ($wp_roles as $slug => $label): ?>
                  <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html(translate_user_role($label) . ' (' . $slug . ')'); ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="text" id="role_name" name="role_name" class="regular-text" required>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="role_file">File CSV</label></th>
          <td>
            <input type="file" id="role_file" name="role_file" accept=".csv" required>
            <p class="description">Dua kolom: <code>sku,price</code>. Baris pertama dianggap judul kolom.</p>
          </td>
        </tr>
      </table>
      <p><input type="submit" name="upload_csv" value="Upload Harga Role" class="button button-primary"></p>
    </form>
  </div>
  <?php
}

function render_role_list($roles = null, $wp_roles = [])
{
  $roles = $roles ?? kiip_role_price_load();
  if (!$roles) {
    echo '<p class="kiip-muted">Belum ada harga role. Tambahkan lewat form di bawah.</p>';
    return;
  }
  ?>
  <table class="widefat striped kiip-table">
    <thead>
      <tr>
        <th>Role</th>
        <th class="num">Jumlah SKU</th>
        <th class="kiip-actions-col"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($roles as $name => $data):
        $count = isset($data['prices']) && is_array($data['prices']) ? count($data['prices']) : 0;
        $delete_url = wp_nonce_url(add_query_arg(['delete_role' => $name]), 'kiip_delete_role_' . $name); ?>
        <tr>
          <td>
            <strong><?php echo esc_html(isset($wp_roles[$name]) ? translate_user_role($wp_roles[$name]) : $name); ?></strong>
            <code><?php echo esc_html($name); ?></code>
            <?php if ($wp_roles && !isset($wp_roles[$name])): ?>
              <span class="kiip-pill kiip-pill-red">Role tidak ada di WordPress</span>
            <?php endif; ?>
          </td>
          <td class="num"><?php echo (int) $count; ?></td>
          <td class="kiip-actions-col">
            <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
              onclick="return confirm('Hapus harga untuk role <?php echo esc_js($name); ?>?')">Hapus</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
}

add_action('admin_init', 'handle_role_deletion');
function handle_role_deletion()
{
  if (!current_user_can('manage_options') || !isset($_GET['delete_role'])) {
    return;
  }

  $roleName = kiip_sanitize_role_slug($_GET['delete_role']);
  if ($roleName === '') {
    return;
  }
  check_admin_referer('kiip_delete_role_' . $roleName);

  $all = kiip_role_price_load();
  if (isset($all[$roleName])) {
    unset($all[$roleName]);
    kiip_role_price_save($all);
    kiip_flash('success', 'Harga role <strong>' . esc_html($roleName) . '</strong> berhasil dihapus.');
  }

  wp_safe_redirect(remove_query_arg(['delete_role', '_wpnonce']));
  exit;
}

add_action('admin_init', 'handle_role_csv_upload');
function handle_role_csv_upload()
{
  if (
    !current_user_can('manage_options')
    || !isset($_POST['upload_csv'], $_POST['role_name'], $_FILES['role_file'])
  ) {
    return;
  }
  check_admin_referer('kiip_role_upload', 'kiip_role_nonce');

  $roleName = kiip_sanitize_role_slug($_POST['role_name']);
  if ($roleName === '') {
    kiip_flash('error', 'Pilih role terlebih dahulu.');
    return;
  }

  $data = kiip_read_sku_price_csv($_FILES['role_file']);
  if (is_string($data)) {
    kiip_flash('error', $data);
    return;
  }

  $all = kiip_role_price_load();
  $all[$roleName] = ['prices' => $data];
  kiip_role_price_save($all);

  kiip_flash('success', 'Harga role <strong>' . esc_html($roleName) . '</strong> disimpan dengan ' . count($data) . ' SKU.');
  wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=kiip-helpers&tab=role_price_module'));
  exit;
}
