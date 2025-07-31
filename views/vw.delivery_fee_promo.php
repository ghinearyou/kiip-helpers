<?php

function delivery_fee_promo_page()
{
  if (isset($_POST['dfpm_input_promo_name'])) {
    update_option('dfpm_input_promo_name', sanitize_text_field($_POST['dfpm_input_promo_name']));
  }
  if (isset($_POST['dfpm_input_promo_amount'])) {
    update_option('dfpm_input_promo_amount', sanitize_text_field($_POST['dfpm_input_promo_amount']));
  }

  $input1 = get_option('dfpm_input_promo_name', '');
  $input2 = get_option('dfpm_input_promo_amount', '');

  ?>
  <form method="post">
    <label for="dfpm_input_promo_name">Promo Name: </label>
    <input type="text" name="dfpm_input_promo_name" value="<?php echo esc_attr($input1); ?>" />
    <label for="dfpm_input_promo_amount">Promo Amount: </label>
    <input type="text" name="dfpm_input_promo_amount" value="<?php echo esc_attr($input2); ?>" />

    <br><br>
    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}