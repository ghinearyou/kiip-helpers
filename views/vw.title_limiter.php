<?php

if (!defined('ABSPATH')) {
  exit;
}

function title_limiter_page()
{
  kiip_save_simple_options('kiip_tlm_save', [
    'tlm_input_letter' => function ($value) {
      return (string) max(10, (int) $value);
    },
  ]);

  $limit = get_option('tlm_input_letter', 25);
  ?>
  <div class="kiip-card">
    <h2>Batasi Judul Produk</h2>
    <p class="description">Judul yang lebih panjang dari batas ini dipotong dan diberi "...".</p>
    <form method="post">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="tlm_input_letter">Maksimal karakter</label></th>
          <td>
            <input type="number" id="tlm_input_letter" name="tlm_input_letter" min="10" class="small-text"
              value="<?php echo esc_attr($limit); ?>">
            <p class="description">Minimal 10. Bawaan 25.</p>
          </td>
        </tr>
      </table>
      <?php kiip_save_button('kiip_tlm_save'); ?>
    </form>
  </div>
  <?php
}
