<?php

function title_limiter_page()
{
  if (isset($_POST['tlm_input_letter'])) {
    update_option('tlm_input_letter', sanitize_text_field($_POST['tlm_input_letter']));
  }
  $input1 = get_option('tlm_input_letter', 25);

  ?>
  <form method="post">
    <label for="tlm_input_letter">Limit title letter: </label>
    <input type="number" name="tlm_input_letter" value="<?php echo esc_attr($input1); ?>" min="10"/>

    <br><br>
    <input type="submit" class="button button-primary" value="Simpan Pengaturan">
  </form>
  <?php
}