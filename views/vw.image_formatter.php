<?php

function image_formatter_page()
{
  if (isset($_POST['ifm_input_quality'])) {
    update_option('ifm_input_quality', sanitize_text_field($_POST['ifm_input_quality']));
  }

  $input1 = get_option('ifm_input_quality', '');

  ?>
  <form method="post">
    <label for="ifm_input_quality">Image Quality: </label>
    <input type="text" name="ifm_input_quality" value="<?php echo esc_attr($input1); ?>" />

    <br><br>
    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}