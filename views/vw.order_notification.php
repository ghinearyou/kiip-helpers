<?php

function order_notification_page()
{
  if (isset($_POST['title_limiter_module_input1'])) {
    update_option('title_limiter_module_input1', sanitize_text_field($_POST['title_limiter_module_input1']));
  }
  $input1 = get_option('title_limiter_module_input1', '');

  ?>
  <form method="post">
    <label for="order_notification_module_input1">Limit title letter: </label>
    <input type="text" name="order_notification_module_input1" value="<?php echo esc_attr($input1); ?>" />
    <input type="text" name="order_notification_module_input2" value="<?php echo esc_attr($input1); ?>" />
    <input type="text" name="order_notification_module_input3" value="<?php echo esc_attr($input1); ?>" />

    <br><br>
    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}