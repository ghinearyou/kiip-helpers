<?php
/**
 * Helper bersama untuk halaman admin KiiP Helpers.
 */

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Simpan pesan untuk ditampilkan setelah redirect.
 */
function kiip_flash($type, $message)
{
  $key = 'kiip_flash_' . get_current_user_id();
  $messages = get_transient($key) ?: [];
  $messages[] = ['type' => $type, 'message' => $message];
  set_transient($key, $messages, 120);
}

function kiip_render_flash()
{
  $key = 'kiip_flash_' . get_current_user_id();
  $messages = get_transient($key);
  if (!$messages) {
    return;
  }
  delete_transient($key);
  foreach ($messages as $notice) {
    printf(
      '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
      esc_attr($notice['type']),
      wp_kses_post($notice['message'])
    );
  }
}

/**
 * Baca CSV dua kolom sku,price (baris pertama = judul kolom).
 *
 * @return array|string [SKU => harga], atau pesan error
 */
function kiip_read_sku_price_csv($file)
{
  if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return 'File CSV gagal diupload.';
  }

  $data = [];
  if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
    fgetcsv($handle, 1000, ',', '"', '');
    while (($row = fgetcsv($handle, 1000, ',', '"', '')) !== false) {
      if (count($row) < 2) {
        continue;
      }
      $sku = strtoupper(trim($row[0]));
      $price = (float) trim($row[1]);
      if ($sku !== '' && $price > 0) {
        $data[$sku] = $price;
      }
    }
    fclose($handle);
  }

  return $data ?: 'Tidak ada baris valid di CSV. Pastikan formatnya sku,price dengan harga lebih dari 0.';
}

/**
 * Simpan field-field option sederhana dari form (dengan nonce).
 *
 * @param string $nonce_action
 * @param array  $fields option_name => sanitizer callback
 * @return bool true kalau tersimpan
 */
function kiip_save_simple_options($nonce_action, $fields)
{
  if (empty($_POST['kiip_save']) || $_POST['kiip_save'] !== $nonce_action) {
    return false;
  }
  check_admin_referer($nonce_action);
  foreach ($fields as $option => $sanitize) {
    if (isset($_POST[$option])) {
      update_option($option, call_user_func($sanitize, wp_unslash($_POST[$option])));
    }
  }
  echo '<div class="notice notice-success is-dismissible"><p>Pengaturan disimpan.</p></div>';
  return true;
}

function kiip_save_button($nonce_action, $label = 'Simpan Pengaturan')
{
  wp_nonce_field($nonce_action);
  printf(
    '<p><button type="submit" name="kiip_save" value="%s" class="button button-primary">%s</button></p>',
    esc_attr($nonce_action),
    esc_html($label)
  );
}
