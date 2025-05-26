<?php

function list_menu()
{
  $modules = [
    'checkout_field_remover_module' => 'Hapus field checkout',
    'order_notification_module' => 'Tampilkan notifikasi saat diskon diterapkan',
    'role_price_module' => 'Diskon kuantitas berdasarkan jumlah dan role',
    'shipment_method_limit_module' => 'Batasi metode pengiriman',
    'title_limiter_module' => 'Batasi judul produk',
  ];

  if ($_SERVER['REQUEST_METHOD'] === 'POST' & count($_POST) > 0) {
    foreach (array_keys($_POST) as $key) {
      update_option($key, isset($_POST[$key]) ? 'yes' : 'no');
    }
  }

  // Ambil status saat ini
  $statuses = [];
  foreach (array_keys($modules) as $key) {
    $statuses[$key] = get_option($key, 'no');
  }
  ?>

  <h1>KiiP Helpers</h1>
  <br>
  <h2>List Module</h2>
  <form method="post">
    <?php foreach ($modules as $key => $label): ?>
      <label>
        <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="yes" <?php checked($statuses[$key], 'yes'); ?> />
        <?php echo esc_html($label); ?>
      </label>
      <br><br>
    <?php endforeach; ?>

    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}
