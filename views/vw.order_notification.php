<?php

function order_notification_page()
{
  if (isset($_POST['onm_input_bot_url'])) {
    update_option('onm_input_bot_url', sanitize_text_field($_POST['onm_input_bot_url']));
  }
  if (isset($_POST['onm_input_web_name'])) {
    update_option('onm_input_web_name', sanitize_text_field($_POST['onm_input_web_name']));
  }
  if (isset($_POST['onm_input_web_url'])) {
    update_option('onm_input_web_url', sanitize_text_field($_POST['onm_input_web_url']));
  }

  $input1 = get_option('onm_input_bot_url', '');
  $input2 = get_option('onm_input_web_name', '');
  $input3 = get_option('onm_input_web_url', '');

  ?>
  <form method="post">
    <label for="onm_input_bot_url">Lark Bot URL: </label>
    <input type="text" name="onm_input_bot_url" value="<?php echo esc_attr($input1); ?>" />
    <label for="onm_input_bot_url">Website name: </label>
    <input type="text" name="onm_input_web_name" value="<?php echo esc_attr($input2); ?>" />
    <label for="onm_input_bot_url">Website URL: </label>
    <input type="text" name="onm_input_web_url" value="<?php echo esc_attr($input3); ?>" />

    <br><br>
    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}