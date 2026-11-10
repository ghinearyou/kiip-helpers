<?php

if (!defined('ABSPATH')) {
  exit;
}

function delivery_fee_promo_page()
{
  kiip_save_simple_options('kiip_dfpm_save', [
    'dfpm_input_promo_name' => 'sanitize_text_field',
    'dfpm_input_promo_amount' => function ($value) {
      return (string) max(0, (float) preg_replace('/[^0-9.]/', '', (string) $value));
    },
  ]);

  $name = get_option('dfpm_input_promo_name', '');
  $amount = get_option('dfpm_input_promo_amount', '');
  ?>
  <div class="kiip-card">
    <h2>Promo Diskon Ongkir</h2>
    <p class="description">Ongkir di keranjang dipotong sebesar nominal di bawah (maksimal sebesar ongkirnya), dan tampil sebagai baris biaya negatif.</p>
    <form method="post">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="dfpm_input_promo_name">Nama promo</label></th>
          <td>
            <input type="text" id="dfpm_input_promo_name" name="dfpm_input_promo_name" class="regular-text"
              value="<?php echo esc_attr($name); ?>" placeholder="contoh: Potongan Ongkir">
            <p class="description">Teks yang dilihat pembeli di keranjang dan checkout.</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="dfpm_input_promo_amount">Potongan maksimal</label></th>
          <td>
            <span class="kiip-prefix">Rp</span>
            <input type="number" id="dfpm_input_promo_amount" name="dfpm_input_promo_amount" min="0" step="1" class="regular-text"
              value="<?php echo esc_attr($amount); ?>" placeholder="contoh: 10000">
          </td>
        </tr>
      </table>
      <?php kiip_save_button('kiip_dfpm_save'); ?>
    </form>
  </div>
  <?php
}
