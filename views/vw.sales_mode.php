<?php

if (!defined('ABSPATH')) {
  exit;
}

function sales_mode_page()
{
  if (isset($_POST['smm_save']) && check_admin_referer('kiip_smm_save')) {
    update_option('smm_status', ($_POST['smm_status'] ?? '') === 'open' ? 'open' : 'closed');
    update_option('smm_message', sanitize_textarea_field(wp_unslash($_POST['smm_message'] ?? '')));
    update_option('smm_admin_bypass', isset($_POST['smm_admin_bypass']) ? 'yes' : 'no');
    echo '<div class="notice notice-success is-dismissible"><p>Pengaturan mode berjualan disimpan.</p></div>';
  }

  $status = get_option('smm_status', 'open');
  $message = get_option('smm_message', '');
  $bypass = get_option('smm_admin_bypass', 'no');
  $is_open = $status !== 'closed';
  ?>
  <div class="kiip-card">
    <h2>Mode Berjualan</h2>
    <p class="description">Saat penjualan <strong>nonaktif</strong>, produk tetap tampil beserta harganya, tetapi tidak bisa
      dimasukkan ke keranjang atau di-checkout. Isi keranjang pengunjung yang sudah ada juga akan dikosongkan oleh WooCommerce.</p>

    <div class="kiip-sales-status <?php echo $is_open ? 'is-open' : 'is-closed'; ?>">
      Status sekarang: <strong><?php echo $is_open ? 'Penjualan AKTIF' : 'Penjualan NONAKTIF'; ?></strong>
    </div>

    <form method="post">
      <?php wp_nonce_field('kiip_smm_save'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">Penjualan</th>
          <td>
            <fieldset class="kiip-segmented">
              <label><input type="radio" name="smm_status" value="open" <?php checked($is_open); ?>> <span>Aktif</span></label>
              <label><input type="radio" name="smm_status" value="closed" <?php checked(!$is_open); ?>> <span>Nonaktif</span></label>
            </fieldset>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="smm_message">Pesan untuk pengunjung</label></th>
          <td>
            <textarea id="smm_message" name="smm_message" rows="2" class="large-text"
              placeholder="<?php echo esc_attr(KIIP_SALES_MODE_DEFAULT_MESSAGE); ?>"><?php echo esc_textarea($message); ?></textarea>
            <p class="description">Tampil di bagian atas website dan di halaman produk saat penjualan nonaktif. Kosongkan untuk memakai pesan bawaan.</p>
          </td>
        </tr>
        <tr>
          <th scope="row">Pengecualian</th>
          <td>
            <label><input type="checkbox" name="smm_admin_bypass" value="yes" <?php checked($bypass, 'yes'); ?>>
              Admin dan Shop Manager tetap bisa belanja (untuk testing)</label>
          </td>
        </tr>
      </table>
      <p><input type="submit" name="smm_save" class="button button-primary" value="Simpan"></p>
    </form>
  </div>
  <?php
}
