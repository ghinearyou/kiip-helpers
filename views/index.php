<?php

require_once __DIR__ . '/vw.image_formatter.php';
require_once __DIR__ . '/vw.order_notification.php';
require_once __DIR__ . '/vw.role_price.php';
require_once __DIR__ . '/vw.title_limiter.php';

function list_menu_page()
{
  $modules = [
    'campaign_price_module' => 'CPM | Diskon berdasarkan campaign yang sedang berjalan',
    'checkout_field_remover_module' => 'CFRM | Hapus field checkout',
    'image_formatter_module' => 'IFM | Format gambar menjadi WebP',
    'order_notification_module' => 'ONM | Tampilkan notifikasi saat diskon diterapkan',
    'role_price_module' => 'RPM | Diskon kuantitas berdasarkan jumlah dan role',
    'shipment_method_limit_module' => 'SMLM | Batasi metode pengiriman',
    'title_limiter_module' => 'TLM | Batasi judul produk'
  ];

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['module_control'])) {
      foreach (array_keys($modules) as $key) {
        update_option($key, isset($_POST[$key]) ? 'yes' : 'no');
      }
    }
  }

  // Ambil status saat ini
  $statuses = [];
  foreach (array_keys($modules) as $key) {
    $statuses[$key] = get_option($key, 'no');
  }
  ?>

  <h1>KiiP Helpers</h1>
  <br>
  <h2>List Module</h2>
  <form method="post">
    <?php foreach ($modules as $key => $label): ?>
      <label>
        <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="yes" <?php checked($statuses[$key], 'yes'); ?> />
        <?php echo esc_html($label); ?>
      </label>
      <br><br>
    <?php endforeach; ?>

    <input type="submit" name="module_control" class="button button-primary" value="Simpan Pengaturan">
  </form>

  <style>
    /* Style the tab */
    .tab {
      overflow: hidden;
      border: 1px solid #ccc;
      background-color: #f1f1f1;
    }

    /* Style the buttons inside the tab */
    .tab button {
      background-color: inherit;
      float: left;
      border: none;
      outline: none;
      cursor: pointer;
      padding: 14px 16px;
      transition: 0.3s;
      font-size: 17px;
    }

    /* Change background color of buttons on hover */
    .tab button:hover {
      background-color: #ddd;
    }

    /* Create an active/current tablink class */
    .tab button.active {
      background-color: #ccc;
    }

    /* Style the tab content */
    .tabcontent {
      display: none;
      padding: 6px 12px;
      border: 1px solid #ccc;
      border-top: none;
    }
  </style>

  <body>

    <h2>Tabs</h2>
    <p>Click on the buttons inside the tabbed menu:</p>

    <div class="tab">
      <?php foreach (array_keys(array_filter($statuses, fn($v) => $v === 'yes')) as $key): ?>
        <button class="tablinks"
          onclick="openCity(event, '<?php echo esc_attr($key); ?>')"><?php echo explode(" | ", $modules[$key])[0] ?></button>
      <?php endforeach; ?>
    </div>

    <div id="checkout_field_remover_module" class="tabcontent">
      <p>Hapus field checkout</p>
    </div>

    <div id="image_formatter_module" class="tabcontent">
      <?php image_formatter_page(); ?>
    </div>

    <div id="order_notification_module" class="tabcontent">
      <?php order_notification_page(); ?>
    </div>

    <div id="role_price_module" class="tabcontent">
      <?php role_price_page(); ?>
    </div>

    <div id="shipment_method_limit_module" class="tabcontent">
      <p>Batasi metode pengiriman</p>
    </div>

    <div id="title_limiter_module" class="tabcontent">
      <?php title_limiter_page(); ?>
    </div>

    <script>
      function openCity(evt, cityName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tabcontent");
        for (i = 0; i < tabcontent.length; i++) {
          tabcontent[i].style.display = "none";
        }
        tablinks = document.getElementsByClassName("tablinks");
        for (i = 0; i < tablinks.length; i++) {
          tablinks[i].className = tablinks[i].className.replace(" active", "");
        }
        document.getElementById(cityName).style.display = "block";
        evt.currentTarget.className += " active";
      }
    </script>

    <?php
}
