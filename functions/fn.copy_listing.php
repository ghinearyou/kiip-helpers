<?php
/**
 * Salin Listing (CLM): salin produk dari Shopee / Tokopedia menjadi draft produk WooCommerce.
 *
 * Sumber data, dicoba berurutan:
 *  1. Link produk diambil langsung dari server (API publik Shopee, lalu HTML + JSON-LD).
 *     Shopee sering memblokir permintaan dari server hosting, jadi jalur ini bisa gagal.
 *  2. Tombol bookmark "Salin ke Sakumini": dijalankan di halaman produk Shopee/Tokopedia yang sedang
 *     dibuka di browser admin, mengambil data dari sana, lalu mengirimnya ke halaman admin ini.
 *  3. Tempel JSON manual (respons API Shopee, atau data dari tombol bookmark).
 *
 * Semua sumber dinormalisasi ke format yang sama, ditampilkan sebagai preview,
 * dan baru dibuat menjadi produk (status draft) setelah admin menekan tombol "Buat Draft".
 */

if (!defined('ABSPATH')) {
  exit;
}

if (get_option('copy_listing_module') !== 'yes') {
  return;
}

const KIIP_CL_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const KIIP_CL_SHOPEE_IMG = 'https://down-id.img.susercontent.com/file/';
const KIIP_CL_MAX_IMAGES = 15;

/* ---------------------------------------------------------------------------
 * Link
 * ------------------------------------------------------------------------- */

/**
 * Kenali link produk. Link pendek (shp.ee, tk.tokopedia.com) diikuti dulu redirect-nya.
 *
 * @return array|WP_Error ['source' => 'shopee'|'tokopedia', 'url' => ..., 'shop_id' => ..., 'item_id' => ...]
 */
function kiip_cl_parse_link($url, $follow = true)
{
  $url = trim((string) $url);
  if ($url === '') {
    return new WP_Error('kiip_cl_empty', 'Isi link produk terlebih dahulu.');
  }
  if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
  }
  $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

  if (preg_match('/(^|\.)shopee\.co\.id$/', $host)) {
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (preg_match('/-i\.(\d+)\.(\d+)/', $path, $m) || preg_match('#/product/(\d+)/(\d+)#', $path, $m)
      || preg_match('#^/[^/]+/(\d+)/(\d+)/?$#', $path, $m)) {
      return ['source' => 'shopee', 'url' => $url, 'shop_id' => $m[1], 'item_id' => $m[2]];
    }
  }

  if (preg_match('/(^|\.)tokopedia\.com$/', $host) && $host !== 'tk.tokopedia.com') {
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
    if (substr_count($path, '/') === 1) {
      return ['source' => 'tokopedia', 'url' => strtok($url, '?'), 'shop_id' => '', 'item_id' => $path];
    }
  }

  $is_short = preg_match('/(^|\.)(shp\.ee|shope\.ee)$/', $host) || $host === 'tk.tokopedia.com'
    || preg_match('/^s\.shopee\.co\.id$/', $host);
  if ($follow && $is_short) {
    $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0, 'user-agent' => KIIP_CL_UA]);
    $location = is_wp_error($response) ? '' : wp_remote_retrieve_header($response, 'location');
    if (is_array($location)) {
      $location = end($location);
    }
    if ($location) {
      return kiip_cl_parse_link($location, false);
    }
    return new WP_Error('kiip_cl_short', 'Link pendek tidak bisa dibuka dari server. Buka link itu di browser, lalu salin link lengkap dari address bar.');
  }

  return new WP_Error('kiip_cl_link', 'Link tidak dikenali. Gunakan link halaman produk Shopee (shopee.co.id/...-i.123.456) atau Tokopedia (tokopedia.com/toko/produk).');
}

/* ---------------------------------------------------------------------------
 * Ambil dari server
 * ------------------------------------------------------------------------- */

/**
 * @return array|WP_Error data produk yang sudah dinormalisasi
 */
function kiip_cl_fetch($url)
{
  $link = kiip_cl_parse_link($url);
  if (is_wp_error($link)) {
    return $link;
  }

  $errors = [];
  if ($link['source'] === 'shopee') {
    $base = 'https://shopee.co.id/api/v4/';
    $endpoints = [
      $base . 'pdp/get_pc?item_id=' . $link['item_id'] . '&shop_id=' . $link['shop_id'] . '&tz_offset_minutes=420&detail_level=0',
      $base . 'item/get?itemid=' . $link['item_id'] . '&shopid=' . $link['shop_id'],
    ];
    foreach ($endpoints as $endpoint) {
      $response = wp_remote_get($endpoint, [
        'timeout' => 20,
        'user-agent' => KIIP_CL_UA,
        'headers' => [
          'Accept' => 'application/json',
          'Referer' => $link['url'],
          'X-API-SOURCE' => 'pc',
          'X-Shopee-Language' => 'id',
          'X-Requested-With' => 'XMLHttpRequest',
        ],
      ]);
      if (is_wp_error($response)) {
        $errors[] = $response->get_error_message();
        continue;
      }
      $json = json_decode(wp_remote_retrieve_body($response), true);
      $item = is_array($json) ? kiip_cl_find_shopee_item($json) : null;
      if ($item) {
        return kiip_cl_normalize_shopee($json, $link['url']);
      }
      $errors[] = 'API ' . (int) wp_remote_retrieve_response_code($response)
        . (isset($json['error']) ? ' (kode ' . $json['error'] . ')' : '');
    }
  }

  // HTML halaman produk: JSON-LD + meta og.
  $response = wp_remote_get($link['url'], [
    'timeout' => 20,
    'user-agent' => KIIP_CL_UA,
    'headers' => ['Accept' => 'text/html', 'Accept-Language' => 'id-ID,id;q=0.9'],
  ]);
  if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
    $page = kiip_cl_parse_html(wp_remote_retrieve_body($response));
    $data = kiip_cl_normalize_page($page, $link['url'], $link['source']);
    if (!is_wp_error($data)) {
      return $data;
    }
    $errors[] = 'halaman tidak berisi data produk';
  } else {
    $errors[] = 'halaman ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response));
  }

  $name = $link['source'] === 'shopee' ? 'Shopee' : 'Tokopedia';
  return new WP_Error(
    'kiip_cl_blocked',
    $name . ' menolak permintaan dari server toko (' . implode('; ', $errors) . '). '
      . 'Ini wajar karena ' . $name . ' memblokir akses otomatis dari server hosting. '
      . 'Pakai tombol bookmark "Salin ke Sakumini" di bawah: buka produknya di browser, lalu klik bookmark itu.'
  );
}

/**
 * Ambil semua JSON-LD dan meta og:* dari HTML.
 */
function kiip_cl_parse_html($html)
{
  $page = ['ld' => [], 'og' => [], 'imgs' => []];
  if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $m)) {
    foreach ($m[1] as $raw) {
      $json = json_decode(html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
      if ($json === null) {
        $json = json_decode(trim($raw), true);
      }
      if (is_array($json)) {
        $page['ld'][] = $json;
      }
    }
  }
  if (preg_match_all('#<meta[^>]+property=["\'](og:[a-z:_]+)["\'][^>]+content=["\']([^"\']*)["\']#i', (string) $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
      if (!isset($page['og'][$row[1]])) {
        $page['og'][$row[1]] = html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      }
    }
  }
  return $page;
}

/* ---------------------------------------------------------------------------
 * Normalisasi
 *
 * Format hasil:
 * [
 *   'source' => 'shopee', 'source_url' => ..., 'source_id' => ...,
 *   'title' => ..., 'description' => (teks biasa), 'images' => [url, ...],
 *   'price' => harga jual, 'regular_price' => harga coret (0 kalau tidak ada diskon),
 *   'attributes' => [['name' => 'Warna', 'options' => ['Merah', 'Biru']], ...],
 *   'variations' => [['options' => ['Merah'], 'price' => ..., 'regular_price' => ..., 'stock' => int|null, 'sku' => '', 'image' => url|''], ...],
 *   'stock' => int|null, 'sku' => '',
 * ]
 * ------------------------------------------------------------------------- */

/**
 * Terima data dari tombol bookmark atau tempel manual (JSON), kembalikan data ternormalisasi.
 */
function kiip_cl_normalize_payload($raw)
{
  $json = is_array($raw) ? $raw : json_decode(trim((string) $raw), true);
  if (!is_array($json)) {
    return new WP_Error('kiip_cl_json', 'Data tidak bisa dibaca. Pastikan yang ditempel adalah JSON lengkap.');
  }

  // Data yang sudah ternormalisasi (misal hasil ekspor sebelumnya).
  if (isset($json['title'], $json['variations']) && is_array($json['variations'])) {
    return kiip_cl_clean($json);
  }

  $url = isset($json['url']) ? esc_url_raw($json['url']) : '';
  $link = $url ? kiip_cl_parse_link($url, false) : null;
  $source = (!$link || is_wp_error($link)) ? '' : $link['source'];

  // Kiriman bookmark: {url, api, ld, og, imgs}
  if (array_key_exists('api', $json) || array_key_exists('ld', $json)) {
    if (!empty($json['api']) && is_array($json['api']) && kiip_cl_find_shopee_item($json['api'])) {
      return kiip_cl_normalize_shopee($json['api'], $url);
    }
    $page = [
      'ld' => is_array($json['ld'] ?? null) ? $json['ld'] : [],
      'og' => is_array($json['og'] ?? null) ? $json['og'] : [],
      'imgs' => is_array($json['imgs'] ?? null) ? $json['imgs'] : [],
      'title' => (string) ($json['title'] ?? ''),
      'desc' => (string) ($json['desc'] ?? ''),
      'price' => (string) ($json['price'] ?? ''),
    ];
    return kiip_cl_normalize_page($page, $url, $source ?: 'shopee');
  }

  // Respons API Shopee yang ditempel langsung.
  if (kiip_cl_find_shopee_item($json)) {
    return kiip_cl_normalize_shopee($json, $url);
  }

  return new WP_Error('kiip_cl_json', 'Format JSON tidak dikenali sebagai data produk Shopee/Tokopedia.');
}

/**
 * Cari objek item Shopee di dalam respons API (item/get maupun pdp/get_pc).
 */
function kiip_cl_find_shopee_item($json, $depth = 0)
{
  if (!is_array($json) || $depth > 4) {
    return null;
  }
  $has_name = isset($json['name']) || isset($json['title']);
  $has_item = isset($json['models']) || isset($json['tier_variations']) || isset($json['itemid']) || isset($json['item_id']);
  if ($has_name && $has_item && (isset($json['price']) || isset($json['price_min']) || isset($json['models']))) {
    return $json;
  }
  foreach (['data', 'item', 'item_basic'] as $key) {
    if (isset($json[$key]) && is_array($json[$key])) {
      $found = kiip_cl_find_shopee_item($json[$key], $depth + 1);
      if ($found) {
        return $found;
      }
    }
  }
  return null;
}

/**
 * Harga Shopee disimpan x100000 (Rp 125.000 = 12500000000).
 */
function kiip_cl_shopee_price($value)
{
  $value = (float) $value;
  if ($value <= 0) {
    return 0;
  }
  return $value >= 100000 ? round($value / 100000) : round($value);
}

function kiip_cl_shopee_image($hash)
{
  $hash = trim((string) $hash);
  if ($hash === '') {
    return '';
  }
  if (preg_match('#^https?://#', $hash)) {
    return preg_replace('/_tn$/', '', $hash);
  }
  return KIIP_CL_SHOPEE_IMG . $hash;
}

function kiip_cl_normalize_shopee($json, $url = '')
{
  $item = kiip_cl_find_shopee_item($json);
  $data = $json['data'] ?? [];

  $title = (string) ($item['title'] ?? $item['name'] ?? '');

  // Deskripsi: teks biasa, atau rich text (paragraf + gambar).
  $description = (string) ($item['description'] ?? '');
  if ($description === '' && !empty($item['rich_text_description']['paragraph_list'])) {
    $parts = [];
    foreach ($item['rich_text_description']['paragraph_list'] as $p) {
      if (!empty($p['text'])) {
        $parts[] = $p['text'];
      }
    }
    $description = implode("\n", $parts);
  }
  if ($description === '' && !empty($data['product_detail']['description'])) {
    $description = (string) $data['product_detail']['description'];
  }

  $images = [];
  $image_lists = [$item['images'] ?? null, $data['product_images']['images'] ?? null];
  foreach ($image_lists as $list) {
    if (is_array($list)) {
      foreach ($list as $img) {
        $images[] = kiip_cl_shopee_image(is_array($img) ? ($img['image'] ?? $img['id'] ?? '') : $img);
      }
    }
  }
  if (!$images && !empty($item['image'])) {
    $images[] = kiip_cl_shopee_image($item['image']);
  }

  // Variasi
  $tiers = [];
  foreach ((array) ($item['tier_variations'] ?? []) as $i => $tier) {
    $options = array_values(array_map('strval', (array) ($tier['options'] ?? [])));
    if (!$options) {
      continue;
    }
    $tiers[] = [
      'name' => trim((string) ($tier['name'] ?? '')) ?: 'Variasi' . ($i ? ' ' . ($i + 1) : ''),
      'options' => $options,
      'images' => array_values((array) ($tier['images'] ?? [])),
    ];
  }

  $variations = [];
  foreach ((array) ($item['models'] ?? []) as $model) {
    $index = $model['extinfo']['tier_index'] ?? $model['tier_index'] ?? null;
    $options = [];
    if (is_array($index) && count($index) === count($tiers)) {
      foreach ($index as $t => $o) {
        $options[] = $tiers[$t]['options'][$o] ?? '';
      }
    } else {
      $options = array_map('trim', explode(',', (string) ($model['name'] ?? '')));
      $index = null;
    }
    $image = '';
    if ($tiers && $index !== null && isset($index[0]) && !empty($tiers[0]['images'][$index[0]])) {
      $image = kiip_cl_shopee_image($tiers[0]['images'][$index[0]]);
    }
    $stock = $model['stock'] ?? $model['normal_stock'] ?? null;
    $variations[] = [
      'options' => $options,
      'price' => kiip_cl_shopee_price($model['price'] ?? 0),
      'regular_price' => kiip_cl_shopee_price($model['price_before_discount'] ?? 0),
      'stock' => $stock === null ? null : (int) $stock,
      'sku' => (string) ($model['sku'] ?? $model['model_sku'] ?? ''),
      'image' => $image,
    ];
  }

  $price = kiip_cl_shopee_price($item['price'] ?? $item['price_min'] ?? 0);
  $regular = kiip_cl_shopee_price($item['price_before_discount'] ?? $item['price_min_before_discount'] ?? 0);

  // Satu model tanpa tier = produk tunggal.
  if (!$tiers) {
    if (count($variations) === 1) {
      $price = $variations[0]['price'] ?: $price;
      $regular = $variations[0]['regular_price'] ?: $regular;
    }
    $stock = count($variations) === 1 ? $variations[0]['stock'] : ($item['stock'] ?? null);
    $sku = count($variations) === 1 ? $variations[0]['sku'] : '';
    $variations = [];
  } else {
    $stock = null;
    $sku = '';
  }

  $source_id = ($item['shopid'] ?? $item['shop_id'] ?? '') . '.' . ($item['itemid'] ?? $item['item_id'] ?? '');
  if (!$url && $source_id !== '.') {
    $url = 'https://shopee.co.id/product/' . str_replace('.', '/', $source_id);
  }

  return kiip_cl_clean([
    'source' => 'shopee',
    'source_url' => $url,
    'source_id' => $source_id,
    'title' => $title,
    'description' => $description,
    'images' => $images,
    'price' => $price,
    'regular_price' => $regular,
    'attributes' => array_map(function ($t) {
      return ['name' => $t['name'], 'options' => $t['options']];
    }, $tiers),
    'variations' => $variations,
    'stock' => $stock === null ? null : (int) $stock,
    'sku' => $sku,
  ]);
}

/**
 * Data dari halaman (JSON-LD schema.org Product / ProductGroup, meta og, gambar di halaman).
 * Variasi hanya terbaca kalau halaman memakai ProductGroup + hasVariant.
 */
function kiip_cl_normalize_page($page, $url, $source)
{
  $product = null;
  $stack = $page['ld'] ?? [];
  while ($stack) {
    $node = array_shift($stack);
    if (!is_array($node)) {
      continue;
    }
    $type = $node['@type'] ?? '';
    $type = is_array($type) ? implode(',', $type) : (string) $type;
    if (preg_match('/\b(Product|ProductGroup)\b/', $type)) {
      $product = $node;
      break;
    }
    foreach (['@graph', 'mainEntity', 'itemListElement'] as $k) {
      if (isset($node[$k]) && is_array($node[$k])) {
        $stack = array_merge($stack, isset($node[$k][0]) ? $node[$k] : [$node[$k]]);
      }
    }
    if (isset($node[0])) {
      $stack = array_merge($stack, $node);
    }
  }

  $og = $page['og'] ?? [];
  $title = (string) ($product['name'] ?? '') ?: (string) ($page['title'] ?? '') ?: (string) ($og['og:title'] ?? '');
  $title = preg_replace('/\s*\|\s*(Shopee Indonesia|Tokopedia).*$/i', '', $title);
  $description = (string) ($product['description'] ?? '') ?: (string) ($page['desc'] ?? '') ?: (string) ($og['og:description'] ?? '');

  $images = [];
  foreach ((array) ($product['image'] ?? []) as $img) {
    $images[] = is_array($img) ? ($img['url'] ?? $img['contentUrl'] ?? '') : $img;
  }
  foreach ((array) ($page['imgs'] ?? []) as $img) {
    $images[] = $img;
  }
  if (!$images && !empty($og['og:image'])) {
    $images[] = $og['og:image'];
  }
  $images = array_map('kiip_cl_shopee_image', array_filter(array_map('strval', $images)));

  $offer_price = function ($offers) {
    if (isset($offers[0])) {
      $offers = $offers[0];
    }
    if (!is_array($offers)) {
      return 0;
    }
    $p = $offers['price'] ?? $offers['lowPrice'] ?? 0;
    return (float) preg_replace('/[^\d.]/', '', (string) $p);
  };
  $price = $product ? $offer_price($product['offers'] ?? []) : 0;
  if (!$price && !empty($page['price'])) {
    $price = (float) preg_replace('/[^\d]/', '', explode('-', (string) $page['price'])[0]);
  }

  // ProductGroup dengan varian.
  $attributes = [];
  $variations = [];
  if ($product && !empty($product['hasVariant']) && is_array($product['hasVariant'])) {
    $varies = array_map(function ($v) {
      return preg_replace('#^https?://schema\.org/#', '', is_array($v) ? ($v['@id'] ?? '') : (string) $v);
    }, (array) ($product['variesBy'] ?? []));
    $options_by_attr = [];
    foreach ($product['hasVariant'] as $variant) {
      $opts = [];
      foreach ($varies as $prop) {
        $val = $variant[$prop] ?? $variant[lcfirst($prop)] ?? '';
        $val = is_array($val) ? ($val['name'] ?? '') : (string) $val;
        $opts[] = $val;
        $options_by_attr[$prop][$val] = true;
      }
      if (!$varies) {
        $opts = [(string) ($variant['name'] ?? '')];
        $options_by_attr['Variasi'][$opts[0]] = true;
      }
      $img = $variant['image'] ?? '';
      $variations[] = [
        'options' => $opts,
        'price' => $offer_price($variant['offers'] ?? []),
        'regular_price' => 0,
        'stock' => null,
        'sku' => (string) ($variant['sku'] ?? ''),
        'image' => is_array($img) ? (string) ($img[0] ?? '') : (string) $img,
      ];
    }
    $labels = ['color' => 'Warna', 'size' => 'Ukuran', 'pattern' => 'Motif', 'material' => 'Bahan'];
    foreach ($options_by_attr as $prop => $opts) {
      $attributes[] = ['name' => $labels[strtolower($prop)] ?? ucfirst($prop), 'options' => array_keys($opts)];
    }
  }

  if ($title === '' || (!$price && !$variations)) {
    return new WP_Error('kiip_cl_page', 'Data produk tidak ditemukan di halaman.');
  }

  return kiip_cl_clean([
    'source' => $source,
    'source_url' => $url,
    'source_id' => '',
    'title' => $title,
    'description' => $description,
    'images' => $images,
    'price' => $price,
    'regular_price' => 0,
    'attributes' => $attributes,
    'variations' => $variations,
    'stock' => null,
    'sku' => (string) ($product['sku'] ?? ''),
  ]);
}

/**
 * Rapikan data: buang duplikat gambar, opsi kosong, karakter "|" (pemisah opsi WooCommerce).
 */
function kiip_cl_clean($d)
{
  $text = function ($s) {
    return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $s)));
  };
  $opt = function ($s) use ($text) {
    return trim(str_replace('|', '/', $text($s)));
  };

  $images = [];
  foreach ((array) ($d['images'] ?? []) as $img) {
    $img = esc_url_raw(trim((string) $img));
    if ($img !== '' && !in_array($img, $images, true)) {
      $images[] = $img;
    }
  }

  $attributes = [];
  foreach ((array) ($d['attributes'] ?? []) as $a) {
    $options = array_values(array_unique(array_filter(array_map($opt, (array) ($a['options'] ?? [])), 'strlen')));
    if ($options) {
      $attributes[] = ['name' => $opt($a['name'] ?? 'Variasi') ?: 'Variasi', 'options' => $options];
    }
  }

  $variations = [];
  if ($attributes) {
    foreach ((array) ($d['variations'] ?? []) as $v) {
      $options = array_map($opt, (array) ($v['options'] ?? []));
      if (count($options) !== count($attributes)) {
        continue;
      }
      $variations[] = [
        'options' => $options,
        'price' => max(0, (float) ($v['price'] ?? 0)),
        'regular_price' => max(0, (float) ($v['regular_price'] ?? 0)),
        'stock' => isset($v['stock']) && $v['stock'] !== null ? (int) $v['stock'] : null,
        'sku' => $text($v['sku'] ?? ''),
        'image' => esc_url_raw((string) ($v['image'] ?? '')),
      ];
    }
  }
  if (!$variations) {
    $attributes = [];
  }

  $prices = array_filter(array_column($variations, 'price'));
  $price = (float) ($d['price'] ?? 0) ?: ($prices ? min($prices) : 0);

  // Deskripsi: pertahankan baris baru.
  $description = str_replace(["\r\n", "\r"], "\n", wp_strip_all_tags(html_entity_decode((string) ($d['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

  return [
    'source' => in_array($d['source'] ?? '', ['shopee', 'tokopedia'], true) ? $d['source'] : 'shopee',
    'source_url' => esc_url_raw((string) ($d['source_url'] ?? '')),
    'source_id' => $text($d['source_id'] ?? ''),
    'title' => $text($d['title'] ?? ''),
    'description' => trim($description),
    'images' => $images,
    'price' => $price,
    'regular_price' => (float) ($d['regular_price'] ?? 0),
    'attributes' => $attributes,
    'variations' => $variations,
    'stock' => isset($d['stock']) && $d['stock'] !== null ? (int) $d['stock'] : null,
    'sku' => $text($d['sku'] ?? ''),
  ];
}

/* ---------------------------------------------------------------------------
 * Preview (disimpan sementara per admin)
 * ------------------------------------------------------------------------- */

function kiip_cl_preview_key()
{
  return 'kiip_cl_preview_' . get_current_user_id();
}

function kiip_cl_save_preview($data)
{
  set_transient(kiip_cl_preview_key(), $data, 2 * HOUR_IN_SECONDS);
}

function kiip_cl_get_preview()
{
  $data = get_transient(kiip_cl_preview_key());
  return is_array($data) ? $data : null;
}

function kiip_cl_clear_preview()
{
  delete_transient(kiip_cl_preview_key());
}

/**
 * Produk yang sudah pernah disalin dari sumber yang sama.
 */
function kiip_cl_find_existing($data)
{
  $key = $data['source_id'] !== '' ? $data['source_id'] : $data['source_url'];
  if ($key === '') {
    return 0;
  }
  $ids = get_posts([
    'post_type' => 'product',
    'post_status' => 'any',
    'fields' => 'ids',
    'posts_per_page' => 1,
    'meta_query' => [['key' => '_kiip_cl_source', 'value' => $data['source'] . ':' . $key]],
  ]);
  return $ids ? (int) $ids[0] : 0;
}

/* ---------------------------------------------------------------------------
 * Buat produk
 * ------------------------------------------------------------------------- */

/**
 * Unduh gambar ke Media Library. Link CDN Shopee tidak punya ekstensi, jadi nama file dibuat sendiri.
 *
 * @return int|WP_Error attachment ID
 */
function kiip_cl_sideload_image($url, $post_id, $title, $n)
{
  require_once ABSPATH . 'wp-admin/includes/file.php';
  require_once ABSPATH . 'wp-admin/includes/media.php';
  require_once ABSPATH . 'wp-admin/includes/image.php';

  $tmp = download_url($url, 30);
  if (is_wp_error($tmp)) {
    return $tmp;
  }
  $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : '';
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? '';
  if ($ext === '') {
    @unlink($tmp);
    return new WP_Error('kiip_cl_img', 'Bukan file gambar: ' . $url);
  }
  $file = [
    'name' => sanitize_file_name(sanitize_title(mb_substr($title, 0, 60)) . '-' . $n . '.' . $ext),
    'tmp_name' => $tmp,
  ];
  $id = media_handle_sideload($file, $post_id, $title);
  if (is_wp_error($id)) {
    @unlink($tmp);
    return $id;
  }
  update_post_meta($id, '_wp_attachment_image_alt', $title);
  return $id;
}

/**
 * Pasang harga ke produk / variasi. Kalau ada harga coret yang lebih tinggi, harga jual jadi harga promo.
 */
function kiip_cl_set_prices($product, $price, $regular)
{
  $price = (float) $price;
  $regular = (float) $regular;
  if ($regular > $price && $price > 0) {
    $product->set_regular_price(wc_format_decimal($regular));
    $product->set_sale_price(wc_format_decimal($price));
  } elseif ($price > 0) {
    $product->set_regular_price(wc_format_decimal($price));
    $product->set_sale_price('');
  }
}

/**
 * Buat draft produk dari data preview.
 *
 * @param array $data    data ternormalisasi
 * @param array $options ['title', 'category' => term id, 'images' => bool, 'stock' => bool, 'sku' => bool, 'price_mode' => 'sale'|'regular']
 * @return array|WP_Error ['id' => product id, 'warnings' => [...]]
 */
function kiip_cl_create_product($data, $options)
{
  if (!class_exists('WC_Product_Simple')) {
    return new WP_Error('kiip_cl_wc', 'WooCommerce tidak aktif.');
  }
  if (function_exists('set_time_limit')) {
    @set_time_limit(300);
  }

  $warnings = [];
  $title = $options['title'] !== '' ? $options['title'] : $data['title'];
  $use_sale = ($options['price_mode'] ?? 'sale') === 'sale';
  $is_variable = !empty($data['attributes']) && !empty($data['variations']);

  $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
  $product->set_name($title);
  $product->set_status('draft');
  $product->set_description(wpautop(esc_html($data['description'])));
  if (!empty($options['category'])) {
    $product->set_category_ids([(int) $options['category']]);
  }

  $sku_ok = function ($sku) use (&$warnings) {
    if ($sku === '') {
      return false;
    }
    if (wc_get_product_id_by_sku($sku)) {
      $warnings[] = 'SKU ' . $sku . ' sudah dipakai produk lain, dikosongkan.';
      return false;
    }
    return true;
  };

  if (!$is_variable) {
    kiip_cl_set_prices($product, $data['price'], $use_sale ? $data['regular_price'] : 0);
    if (!empty($options['stock']) && $data['stock'] !== null) {
      $product->set_manage_stock(true);
      $product->set_stock_quantity($data['stock']);
    }
    if (!empty($options['sku']) && $sku_ok($data['sku'])) {
      $product->set_sku($data['sku']);
    }
  } else {
    $attrs = [];
    foreach ($data['attributes'] as $i => $a) {
      $attr = new WC_Product_Attribute();
      $attr->set_id(0);
      $attr->set_name($a['name']);
      $attr->set_options($a['options']);
      $attr->set_position($i);
      $attr->set_visible(true);
      $attr->set_variation(true);
      $attrs[] = $attr;
    }
    $product->set_attributes($attrs);
  }

  $product_id = $product->save();
  if (!$product_id) {
    return new WP_Error('kiip_cl_save', 'Produk gagal disimpan.');
  }
  update_post_meta($product_id, '_kiip_cl_source', $data['source'] . ':' . ($data['source_id'] !== '' ? $data['source_id'] : $data['source_url']));
  update_post_meta($product_id, '_kiip_cl_source_url', $data['source_url']);

  // Gambar
  $image_ids = [];
  if (!empty($options['images'])) {
    $urls = array_slice($data['images'], 0, KIIP_CL_MAX_IMAGES);
    foreach ($urls as $n => $url) {
      $id = kiip_cl_sideload_image($url, $product_id, $title, $n + 1);
      if (is_wp_error($id)) {
        $warnings[] = 'Gambar ' . ($n + 1) . ' gagal diunduh: ' . $id->get_error_message();
        continue;
      }
      $image_ids[$url] = $id;
    }
    $ids = array_values($image_ids);
    if ($ids) {
      $product->set_image_id(array_shift($ids));
      $product->set_gallery_image_ids($ids);
      $product->save();
    }
  }

  // Variasi
  if ($is_variable) {
    $n = count($image_ids);
    foreach ($data['variations'] as $v) {
      $variation = new WC_Product_Variation();
      $variation->set_parent_id($product_id);
      $set = [];
      foreach ($data['attributes'] as $i => $a) {
        $set[sanitize_title($a['name'])] = $v['options'][$i];
      }
      $variation->set_attributes($set);
      kiip_cl_set_prices($variation, $v['price'] ?: $data['price'], $use_sale ? $v['regular_price'] : 0);
      if (!empty($options['stock']) && $v['stock'] !== null) {
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($v['stock']);
      }
      if (!empty($options['sku']) && $sku_ok($v['sku'])) {
        $variation->set_sku($v['sku']);
      }
      if (!empty($options['images']) && $v['image'] !== '') {
        if (!isset($image_ids[$v['image']]) && $n < KIIP_CL_MAX_IMAGES + 10) {
          $id = kiip_cl_sideload_image($v['image'], $product_id, $title . ' ' . implode(' ', $v['options']), ++$n);
          if (!is_wp_error($id)) {
            $image_ids[$v['image']] = $id;
          }
        }
        if (isset($image_ids[$v['image']])) {
          $variation->set_image_id($image_ids[$v['image']]);
        }
      }
      $variation->set_status('publish');
      $variation->save();
    }
    WC_Product_Variable::sync($product_id);
  }

  wc_delete_product_transients($product_id);
  return ['id' => $product_id, 'warnings' => $warnings];
}
