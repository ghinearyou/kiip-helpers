<?php

if (!defined('ABSPATH')) {
  exit;
}

function image_formatter_page()
{
  kiip_save_simple_options('kiip_ifm_save', [
    'ifm_input_quality' => function ($value) {
      return (string) max(1, min(100, (int) $value));
    },
  ]);

  $quality = get_option('ifm_input_quality', 90);
  ?>
  <div class="kiip-card">
    <h2>Format Gambar ke WebP</h2>
    <p class="description">Gambar JPG/PNG yang diupload ke Media otomatis diubah menjadi WebP, dan nama filenya diganti dengan timestamp.</p>
    <form method="post">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="ifm_input_quality">Kualitas gambar</label></th>
          <td>
            <input type="number" id="ifm_input_quality" name="ifm_input_quality" min="1" max="100" class="small-text"
              value="<?php echo esc_attr($quality); ?>"> %
            <p class="description">1–100. Semakin kecil, file semakin ringan tapi gambar kurang tajam. Disarankan 80–90.</p>
          </td>
        </tr>
      </table>
      <?php kiip_save_button('kiip_ifm_save'); ?>
    </form>
  </div>
  <?php
}
