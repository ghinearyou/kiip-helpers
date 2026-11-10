<?php

if (!defined('ABSPATH')) {
  exit;
}

function order_notification_page()
{
  kiip_save_simple_options('kiip_onm_save', [
    'onm_input_bot_url' => 'esc_url_raw',
    'onm_input_web_name' => 'sanitize_text_field',
    'onm_input_web_url' => 'esc_url_raw',
  ]);

  $bot_url = get_option('onm_input_bot_url', '');
  $web_name = get_option('onm_input_web_name', '');
  $web_url = get_option('onm_input_web_url', '');
  ?>
  <div class="kiip-card">
    <h2>Notifikasi Order Baru</h2>
    <p class="description">Setiap ada order baru, pesan dikirim ke grup Lark lewat webhook bot.</p>
    <form method="post">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="onm_input_bot_url">Lark bot webhook URL</label></th>
          <td>
            <input type="url" id="onm_input_bot_url" name="onm_input_bot_url" class="large-text"
              value="<?php echo esc_attr($bot_url); ?>" placeholder="https://open.larksuite.com/open-apis/bot/v2/hook/...">
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="onm_input_web_name">Nama website</label></th>
          <td>
            <input type="text" id="onm_input_web_name" name="onm_input_web_name" class="regular-text"
              value="<?php echo esc_attr($web_name); ?>" placeholder="contoh: Sakumini">
            <p class="description">Muncul di pesan: "New Order at <em>nama website</em>".</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="onm_input_web_url">URL website</label></th>
          <td>
            <input type="url" id="onm_input_web_url" name="onm_input_web_url" class="regular-text"
              value="<?php echo esc_attr($web_url); ?>" placeholder="https://sakumini.id">
          </td>
        </tr>
      </table>
      <?php kiip_save_button('kiip_onm_save'); ?>
    </form>
  </div>
  <?php
}
