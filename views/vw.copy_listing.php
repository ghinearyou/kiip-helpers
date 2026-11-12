<?php

if (!defined('ABSPATH')) {
  exit;
}

function kiip_cl_money($value)
{
  return $value > 0 ? 'Rp ' . number_format((float) $value, 0, ',', '.') : '<em>kosong</em>';
}

/**
 * Kode tombol bookmark. Dijalankan di halaman produk Shopee/Tokopedia di browser admin:
 * ambil data produk (API Shopee dari browser, JSON-LD, meta og, gambar), salin ke clipboard,
 * buka halaman Salin Listing, lalu kirim datanya lewat postMessage.
 */
function kiip_cl_bookmarklet()
{
  $admin = add_query_arg('src', 'bm', kiip_tab_url('copy_listing_module'));
  $js = <<<'JS'
(async function(){
var A=__ADMIN__,h=location.hostname;
if(!/(^|\.)(shopee\.co\.id|tokopedia\.com)$/.test(h)){alert('Buka halaman produk Shopee atau Tokopedia dulu, lalu klik bookmark ini.');return;}
var w=window.open(A,'kiipcopy');
if(!w){alert('Pop-up diblokir. Izinkan pop-up untuk situs ini, lalu klik bookmark lagi.');return;}
var o=new URL(A).origin,s=null,ready=false,sent=false;
var go=function(){if(s&&ready&&!sent){sent=true;try{w.postMessage(s,o);}catch(e){sent=false;}}};
window.addEventListener('message',function(e){if(e.origin===o&&e.data==='kiip-ready'){ready=true;go();}});
var p={kiip:1,url:location.href.split('#')[0],api:null,ld:[],og:{},imgs:[],title:'',desc:'',price:''};
var q=function(x){var e=document.querySelector(x);return e?e.innerText.trim():'';};
if(/shopee/.test(h)){
var m=location.pathname.match(/-i\.(\d+)\.(\d+)/)||location.pathname.match(/\/product\/(\d+)\/(\d+)/)||location.pathname.match(/^\/[^\/]+\/(\d+)\/(\d+)/);
if(m){var us=['/api/v4/pdp/get_pc?item_id='+m[2]+'&shop_id='+m[1]+'&tz_offset_minutes=420&detail_level=0','/api/v4/item/get?itemid='+m[2]+'&shopid='+m[1]];
for(var i=0;i<us.length&&!p.api;i++){try{var r=await fetch(us[i],{credentials:'include',headers:{'x-api-source':'pc','x-shopee-language':'id','x-requested-with':'XMLHttpRequest'}});var j=await r.json();var d=j&&j.data;if(d&&(d.item||d.itemid||d.name)){p.api=j;}}catch(e){}}}
if(!p.api){document.querySelectorAll('img').forEach(function(x){var u=x.currentSrc||x.src||'';if(/susercontent\.com\/file\//.test(u)&&(x.naturalWidth||x.width)>=80){u=u.replace(/@resize.*$/,'').replace(/_tn$/,'');if(p.imgs.indexOf(u)<0&&p.imgs.length<30)p.imgs.push(u);}});}
}else{
p.desc=q('[data-testid="lblPDPDescriptionProduk"]');p.price=q('[data-testid="lblPDPDetailProductPrice"]');
document.querySelectorAll('[data-testid="PDPImageThumbnail"] img,[data-testid="PDPMainImage"]').forEach(function(x){var u=(x.currentSrc||x.src||'').replace(/\/(100|200|300|500)-square\//,'/700/');if(u&&p.imgs.indexOf(u)<0)p.imgs.push(u);});
}
document.querySelectorAll('script[type="application/ld+json"]').forEach(function(x){try{p.ld.push(JSON.parse(x.textContent));}catch(e){}});
document.querySelectorAll('meta[property^="og:"]').forEach(function(x){p.og[x.getAttribute('property')]=x.content;});
p.title=q('h1')||document.title;
s=JSON.stringify(p);go();
setTimeout(async function(){if(sent)return;try{await navigator.clipboard.writeText(s);alert('Data produk sudah disalin. Buka tab Sakumini, klik kotak putih, lalu tekan Ctrl+V.');}catch(e){
var t=document.createElement('textarea');t.value=s;t.style.cssText='position:fixed;z-index:2147483647;top:10px;left:10px;width:420px;height:160px;background:#fff;color:#000;border:2px solid #ee4d2d';document.body.appendChild(t);t.select();
alert('Salin isi kotak yang sudah terpilih (Ctrl+C), lalu tempel di tab Sakumini.');}},8000);
})();
JS;
  $js = str_replace('__ADMIN__', wp_json_encode($admin), $js);
  $js = preg_replace('/\n/', '', $js);
  return 'javascript:' . rawurlencode($js);
}

function copy_listing_page()
{
  $base_url = kiip_tab_url('copy_listing_module');
  $notice = null;
  $created = null;

  if (isset($_POST['cl_action']) && check_admin_referer('kiip_cl_action')) {
    $action = sanitize_key($_POST['cl_action']);

    if (in_array($action, ['fetch', 'payload'], true)) {
      kiip_cl_clear_preview();
    }

    if ($action === 'fetch') {
      $data = kiip_cl_fetch(wp_unslash($_POST['cl_url'] ?? ''));
    } elseif ($action === 'payload') {
      $data = kiip_cl_normalize_payload(wp_unslash($_POST['cl_payload'] ?? ''));
    } else {
      $data = null;
    }

    if (is_wp_error($data)) {
      $notice = ['error', $data->get_error_message()];
    } elseif (is_array($data)) {
      if ($data['title'] === '') {
        $notice = ['error', 'Judul produk tidak terbaca dari data.'];
      } else {
        kiip_cl_save_preview($data);
      }
    }

    if ($action === 'cancel') {
      kiip_cl_clear_preview();
    }

    if ($action === 'create') {
      $preview = kiip_cl_get_preview();
      if (!$preview) {
        $notice = ['error', 'Preview sudah kedaluwarsa. Ambil data produknya lagi.'];
      } else {
        $selected = array_map('intval', (array) ($_POST['cl_images'] ?? []));
        $preview['images'] = array_values(array_intersect_key($preview['images'], array_flip($selected)));
        $result = kiip_cl_create_product($preview, [
          'title' => sanitize_text_field(wp_unslash($_POST['cl_title'] ?? '')),
          'category' => (int) ($_POST['cl_category'] ?? 0),
          'images' => !empty($_POST['cl_import_images']),
          'stock' => !empty($_POST['cl_stock']),
          'sku' => !empty($_POST['cl_sku']),
          'price_mode' => ($_POST['cl_price_mode'] ?? 'sale') === 'regular' ? 'regular' : 'sale',
        ]);
        if (is_wp_error($result)) {
          $notice = ['error', $result->get_error_message()];
        } else {
          kiip_cl_clear_preview();
          $created = $result;
        }
      }
    }
  }

  if ($notice) {
    printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
  }
  if ($created) {
    printf(
      '<div class="notice notice-success"><p>Draft produk dibuat. <a href="%s"><strong>Buka &amp; periksa produk →</strong></a> (cek kategori, berat, SKU, lalu Terbitkan)</p>%s</div>',
      esc_url(admin_url('post.php?post=' . (int) $created['id'] . '&action=edit')),
      $created['warnings'] ? '<ul style="list-style:disc;margin-left:20px"><li>' . implode('</li><li>', array_map('esc_html', $created['warnings'])) . '</li></ul>' : ''
    );
  }

  // Halaman penerima data dari tombol bookmark.
  if (($_GET['src'] ?? '') === 'bm' && !isset($_POST['cl_action'])) {
    kiip_cl_render_receiver();
    return;
  }

  $preview = kiip_cl_get_preview();
  if ($preview) {
    kiip_cl_render_preview($preview);
    return;
  }
  ?>
  <div class="kiip-card">
    <h2>Salin Listing dari Shopee / Tokopedia</h2>
    <p class="description">Produk akan dibuat sebagai <strong>draft</strong>: judul, deskripsi, gambar, harga, dan variasi. Anda bisa cek dulu sebelum diterbitkan.</p>
    <form method="post" class="kiip-inline-form">
      <?php wp_nonce_field('kiip_cl_action'); ?>
      <input type="url" name="cl_url" class="large-text" style="max-width:640px" required
        placeholder="https://shopee.co.id/nama-produk-i.123456.789012"
        value="<?php echo esc_attr(wp_unslash($_POST['cl_url'] ?? '')); ?>">
      <button type="submit" name="cl_action" value="fetch" class="button button-primary">Ambil Data</button>
    </form>
  </div>

  <div class="kiip-card">
    <h2>Kalau "Ambil Data" diblokir: tombol bookmark</h2>
    <p>Shopee sering menolak permintaan dari server hosting. Tombol ini mengambil data langsung dari browser Anda, jadi tidak diblokir.</p>
    <ol>
      <li>Tarik tombol ini ke bookmark bar browser (sekali saja):
        <a class="button kiip-bm" href="<?php echo esc_attr(kiip_cl_bookmarklet()); ?>" onclick="alert('Jangan diklik di sini. Tarik tombol ini ke bookmark bar.');return false;">Salin ke Sakumini</a>
      </li>
      <li>Buka halaman produk di Shopee atau Tokopedia (boleh sambil login).</li>
      <li>Klik bookmark <strong>Salin ke Sakumini</strong>. Tab baru terbuka di sini dengan preview produknya.</li>
    </ol>
    <p class="description">Bookmark bar tidak terlihat? Tekan Ctrl+Shift+B (Windows) atau Cmd+Shift+B (Mac). Kalau Anda pernah login ulang di situs ini, bookmark tetap bisa dipakai.</p>
  </div>

  <div class="kiip-card">
    <details class="kiip-details">
      <summary>Tempel data manual (JSON)</summary>
      <form method="post">
        <?php wp_nonce_field('kiip_cl_action'); ?>
        <p class="description">Tempel data dari tombol bookmark (otomatis tersalin ke clipboard) atau respons API produk Shopee.</p>
        <textarea name="cl_payload" rows="8" class="large-text code" required></textarea>
        <p><button type="submit" name="cl_action" value="payload" class="button">Proses Data</button></p>
      </form>
    </details>
  </div>
  <?php
}

/**
 * Halaman yang dibuka oleh tombol bookmark: menerima data lewat postMessage
 * (atau Ctrl+V dari clipboard), lalu mengirimnya ke server untuk preview.
 */
function kiip_cl_render_receiver()
{
  ?>
  <div class="kiip-card">
    <h2>Menerima data produk…</h2>
    <form method="post" id="kiip-cl-receive">
      <?php wp_nonce_field('kiip_cl_action'); ?>
      <input type="hidden" name="cl_action" value="payload">
      <p id="kiip-cl-wait">Tunggu sebentar, data sedang dikirim dari tab Shopee/Tokopedia.</p>
      <div id="kiip-cl-paste" hidden>
        <p>Data tidak terkirim otomatis. Klik kotak di bawah lalu tekan <strong>Ctrl+V</strong> (Mac: Cmd+V). Datanya sudah ada di clipboard.</p>
        <textarea name="cl_payload" id="kiip-cl-payload" rows="6" class="large-text code"></textarea>
        <p><button type="submit" class="button button-primary">Proses Data</button></p>
      </div>
    </form>
  </div>
  <script>
    (function () {
      var form = document.getElementById('kiip-cl-receive');
      var box = document.getElementById('kiip-cl-payload');
      var allowed = /^https:\/\/([a-z0-9-]+\.)*(shopee\.co\.id|tokopedia\.com)$/;
      var done = false;
      function submit(text) {
        if (done || !text || text.indexOf('"kiip":1') < 0) { return; }
        done = true;
        box.value = text;
        form.submit();
      }
      window.addEventListener('message', function (e) {
        if (allowed.test(e.origin) && typeof e.data === 'string') { submit(e.data); }
      });
      var tries = 0;
      var timer = setInterval(function () {
        if (done) { clearInterval(timer); return; }
        if (window.opener) {
          try { window.opener.postMessage('kiip-ready', '*'); } catch (err) {}
        }
        if (++tries === 20) {
          document.getElementById('kiip-cl-wait').hidden = true;
          document.getElementById('kiip-cl-paste').hidden = false;
          box.focus();
        }
        if (tries > 60) { clearInterval(timer); }
      }, 500);
      box.addEventListener('paste', function () {
        setTimeout(function () { submit(box.value); }, 50);
      });
    })();
  </script>
  <?php
}

function kiip_cl_render_preview($d)
{
  $existing = kiip_cl_find_existing($d);
  $has_stock = $d['stock'] !== null || array_filter(array_column($d['variations'], 'stock'), function ($s) {
    return $s !== null;
  });
  $has_sku = $d['sku'] !== '' || array_filter(array_column($d['variations'], 'sku'));
  $has_discount = $d['regular_price'] > $d['price'] || array_filter($d['variations'], function ($v) {
    return $v['regular_price'] > $v['price'];
  });
  ?>
  <div class="kiip-card">
    <h2>Preview <span class="kiip-muted">dari <?php echo esc_html(ucfirst($d['source'])); ?></span></h2>
    <?php if ($d['source_url']): ?>
      <p class="description"><a href="<?php echo esc_url($d['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($d['source_url']); ?></a></p>
    <?php endif; ?>
    <?php if ($existing): ?>
      <div class="notice notice-warning inline"><p>Produk ini sudah pernah disalin:
        <a href="<?php echo esc_url(admin_url('post.php?post=' . $existing . '&action=edit')); ?>"><?php echo esc_html(get_the_title($existing)); ?></a>
        (<?php echo esc_html(get_post_status($existing)); ?>). Membuat lagi akan menghasilkan produk ganda.</p></div>
    <?php endif; ?>

    <form method="post">
      <?php wp_nonce_field('kiip_cl_action'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="cl_title">Judul</label></th>
          <td><input type="text" id="cl_title" name="cl_title" class="large-text" value="<?php echo esc_attr($d['title']); ?>">
            <p class="description"><?php echo (int) mb_strlen($d['title']); ?> karakter.</p></td>
        </tr>
        <tr>
          <th scope="row">Harga</th>
          <td>
            <?php if ($d['variations']):
              $prices = array_filter(array_column($d['variations'], 'price'));
              $min = $prices ? min($prices) : 0;
              $max = $prices ? max($prices) : 0; ?>
              <?php echo kiip_cl_money($min); ?><?php echo $max > $min ? ' – ' . kiip_cl_money($max) : ''; ?>
            <?php else: ?>
              <?php echo kiip_cl_money($d['price']); ?>
              <?php if ($d['regular_price'] > $d['price']): ?>
                <span class="kiip-muted"><s><?php echo kiip_cl_money($d['regular_price']); ?></s></span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($has_discount): ?>
              <fieldset style="margin-top:6px">
                <label><input type="radio" name="cl_price_mode" value="sale" checked> Harga coret jadi harga normal, harga diskon jadi harga promo</label><br>
                <label><input type="radio" name="cl_price_mode" value="regular"> Pakai harga diskon sebagai harga normal (tanpa promo)</label>
              </fieldset>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="cl_category">Kategori</label></th>
          <td><?php
            wp_dropdown_categories([
              'taxonomy' => 'product_cat',
              'name' => 'cl_category',
              'id' => 'cl_category',
              'hide_empty' => false,
              'hierarchical' => true,
              'show_option_none' => '— Pilih nanti —',
              'option_none_value' => 0,
            ]);
          ?></td>
        </tr>
        <tr>
          <th scope="row">Gambar</th>
          <td>
            <?php if ($d['images']): ?>
              <label><input type="checkbox" name="cl_import_images" value="1" checked> Unduh gambar ke Media Library</label>
              <p class="description">Hapus centang gambar yang tidak perlu. Gambar pertama jadi gambar utama. Maksimal <?php echo (int) KIIP_CL_MAX_IMAGES; ?> gambar.</p>
              <div class="kiip-cl-images">
                <?php foreach ($d['images'] as $i => $img): ?>
                  <label><input type="checkbox" name="cl_images[]" value="<?php echo (int) $i; ?>" <?php checked($i < KIIP_CL_MAX_IMAGES); ?>>
                    <img src="<?php echo esc_url($img); ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></label>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <span class="kiip-muted">Tidak ada gambar terbaca.</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($has_stock || $has_sku): ?>
          <tr>
            <th scope="row">Lainnya</th>
            <td>
              <?php if ($has_stock): ?><label><input type="checkbox" name="cl_stock" value="1"> Salin jumlah stok</label><br><?php endif; ?>
              <?php if ($has_sku): ?><label><input type="checkbox" name="cl_sku" value="1" checked> Salin SKU</label><?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
      </table>

      <?php if ($d['variations']): ?>
        <h3>Variasi <span class="kiip-muted">(<?php echo count($d['variations']); ?>)</span></h3>
        <table class="widefat striped kiip-table">
          <thead><tr>
            <?php foreach ($d['attributes'] as $a): ?><th><?php echo esc_html($a['name']); ?></th><?php endforeach; ?>
            <th class="num">Harga</th><th class="num">Harga coret</th><th class="num">Stok</th><th>SKU</th>
          </tr></thead>
          <tbody>
            <?php foreach ($d['variations'] as $v): ?>
              <tr>
                <?php foreach ($v['options'] as $o): ?><td><?php echo esc_html($o); ?></td><?php endforeach; ?>
                <td class="num"><?php echo kiip_cl_money($v['price']); ?></td>
                <td class="num"><?php echo $v['regular_price'] > $v['price'] ? kiip_cl_money($v['regular_price']) : '–'; ?></td>
                <td class="num"><?php echo $v['stock'] === null ? '–' : (int) $v['stock']; ?></td>
                <td><code><?php echo esc_html($v['sku']); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h3>Deskripsi</h3>
      <div class="kiip-cl-desc"><?php echo $d['description'] !== '' ? wpautop(esc_html($d['description'])) : '<em>kosong</em>'; ?></div>

      <p class="kiip-pum-actions">
        <button type="submit" name="cl_action" value="create" class="button button-primary">Buat Draft Produk</button>
        <button type="submit" name="cl_action" value="cancel" class="button" formnovalidate>Batal</button>
      </p>
    </form>
  </div>
  <style>
    .kiip-wrap .kiip-cl-images { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
    .kiip-wrap .kiip-cl-images label { position:relative; display:block; }
    .kiip-wrap .kiip-cl-images input { position:absolute; top:4px; left:4px; margin:0; }
    .kiip-wrap .kiip-cl-images img { width:96px; height:96px; object-fit:cover; border:1px solid #dcdcde; border-radius:4px; display:block; }
    .kiip-wrap .kiip-cl-images input:not(:checked) + img { opacity:.35; }
    .kiip-wrap .kiip-cl-desc { max-height:320px; overflow:auto; padding:8px 12px; background:#f6f7f7; border-radius:4px; }
    .kiip-wrap .kiip-bm { cursor:grab; }
  </style>
  <?php
}
