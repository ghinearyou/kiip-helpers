<?php
/**
 * Update Harga dari Google Sheets (PUM).
 *
 * Alur:
 *  - Link Google Sheets disimpan di pengaturan (sheet harus bisa dilihat oleh siapa saja yang punya link).
 *  - Tab yang dipakai = tab "SK" dengan tanggal terbaru, format nama: "30 Okt SK 2026".
 *  - Kolom yang dibaca: SKU dan SRP. Harga = SRP x pengali (default 1000), dibandingkan dan diisi ke harga promo (sale price).
 *    Harga normal (regular price) tidak diubah.
 *  - SKU di sheet bisa SKU induk (ABC-DEF-GHI), sedangkan di web variasinya ABC-DEF-GHI-1, -2, dst.
 *    Jadi produk web dicocokkan dengan: SKU-nya sendiri, lalu SKU produk induk, lalu SKU tanpa akhiran "-angka".
 */

if (!defined('ABSPATH')) {
  exit;
}

const KIIP_PUM_CACHE_KEY = 'kiip_pum_sheet_cache';
const KIIP_PUM_MONTHS = [
  'jan' => 1, 'januari' => 1, 'january' => 1,
  'feb' => 2, 'februari' => 2, 'february' => 2, 'peb' => 2,
  'mar' => 3, 'maret' => 3, 'march' => 3,
  'apr' => 4, 'april' => 4,
  'mei' => 5, 'may' => 5,
  'jun' => 6, 'juni' => 6, 'june' => 6,
  'jul' => 7, 'juli' => 7, 'july' => 7,
  'agu' => 8, 'agt' => 8, 'ags' => 8, 'agus' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8,
  'sep' => 9, 'sept' => 9, 'september' => 9,
  'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10,
  'nov' => 11, 'nop' => 11, 'november' => 11, 'nopember' => 11,
  'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
];

function kiip_pum_settings()
{
  return [
    'url' => get_option('pum_sheet_url', ''),
    'tab_override' => get_option('pum_tab_override', ''),
    'multiplier' => (float) get_option('pum_multiplier', 1000) ?: 1000,
  ];
}

/* ---------------------------------------------------------------------------
 * Fungsi murni (tanpa WordPress), mudah dites
 * ------------------------------------------------------------------------ */

function kiip_pum_sheet_id($url)
{
  if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', (string) $url, $m)) {
    return $m[1];
  }
  return null;
}

/**
 * Baca tanggal dari nama tab "30 Okt SK 2026". Selain tab SK (mis. "KP") hasilnya null.
 *
 * @return int|null timestamp tengah malam UTC
 */
function kiip_pum_parse_tab_date($name)
{
  $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
  if (!preg_match('/^(\d{1,2})\s+([A-Za-z]+)\.?\s+SK\s+(\d{4})$/i', $name, $m)) {
    return null;
  }
  $month = KIIP_PUM_MONTHS[strtolower($m[2])] ?? null;
  $day = (int) $m[1];
  $year = (int) $m[3];
  if (!$month || !checkdate($month, $day, $year)) {
    return null;
  }
  return gmmktime(0, 0, 0, $month, $day, $year);
}

/**
 * Pilih tab SK dengan tanggal paling baru.
 *
 * @param array $tabs list of ['name' => ..., 'gid' => ...]
 * @return array|null
 */
function kiip_pum_pick_latest_tab($tabs)
{
  $best = null;
  $best_time = null;
  foreach ($tabs as $tab) {
    $time = kiip_pum_parse_tab_date($tab['name']);
    if ($time === null) {
      continue;
    }
    if ($best_time === null || $time >= $best_time) {
      $best = $tab;
      $best_time = $time;
    }
  }
  return $best;
}

/**
 * Ambil daftar tab (nama + gid) dari halaman htmlview Google Sheets.
 */
function kiip_pum_parse_tabs_from_html($html)
{
  $tabs = [];

  // Format 1: items.push({name: "30 Okt SK 2026", pageUrl: "...", gid: "123", ...});
  if (preg_match_all('/items\.push\(\{\s*name:\s*"((?:[^"\\\\]|\\\\.)*)".*?gid:\s*"(\d+)"/s', $html, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $tabs[$m[2]] = ['name' => kiip_pum_decode_js_string($m[1]), 'gid' => $m[2]];
    }
  }

  // Format 2: <li id="sheet-button-123"><a ...>Nama</a></li>
  if (!$tabs && preg_match_all('/id="sheet-button-(\d+)"[^>]*>\s*<a[^>]*>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $tabs[$m[1]] = ['name' => html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'), 'gid' => $m[1]];
    }
  }

  return array_values($tabs);
}

function kiip_pum_decode_js_string($value)
{
  $value = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function ($m) {
    return chr(hexdec($m[1]));
  }, $value);
  $value = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
  }, $value);
  return stripslashes($value);
}

/**
 * Ubah teks SRP jadi angka. Mendukung "125", "12,5", "12.5", "1.250,5", "Rp 125".
 */
function kiip_pum_parse_number($raw)
{
  $value = preg_replace('/[^0-9.,\-]/', '', (string) $raw);
  if ($value === '' || $value === '-' ) {
    return null;
  }

  $has_dot = strpos($value, '.') !== false;
  $has_comma = strpos($value, ',') !== false;

  if ($has_dot && $has_comma) {
    // Pemisah yang terakhir muncul = desimal.
    if (strrpos($value, ',') > strrpos($value, '.')) {
      $value = str_replace(['.', ','], ['', '.'], $value);
    } else {
      $value = str_replace(',', '', $value);
    }
  } elseif ($has_comma) {
    $value = substr_count($value, ',') > 1 ? str_replace(',', '', $value) : str_replace(',', '.', $value);
  } elseif ($has_dot && substr_count($value, '.') > 1) {
    $value = str_replace('.', '', $value);
  }

  return is_numeric($value) ? (float) $value : null;
}

function kiip_pum_normalize_sku($sku)
{
  return strtoupper(trim((string) $sku));
}

/**
 * Baca CSV sheet: cari baris header yang punya kolom SKU dan SRP, lalu ambil datanya.
 *
 * @return array|WP_Error ['prices' => [SKU => harga], 'rows' => n, 'skipped' => [...], 'duplicates' => [...]]
 */
function kiip_pum_parse_csv($csv, $multiplier = 1000)
{
  $handle = fopen('php://temp', 'r+');
  fwrite($handle, (string) $csv);
  rewind($handle);

  $rows = [];
  while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    $rows[] = $row;
  }
  fclose($handle);

  $sku_col = null;
  $srp_col = null;
  $header_index = null;
  foreach (array_slice($rows, 0, 15, true) as $i => $row) {
    $upper = array_map(function ($cell) {
      return strtoupper(trim((string) $cell));
    }, $row);
    $sku = array_search('SKU', $upper, true);
    $srp = array_search('SRP', $upper, true);
    if ($sku !== false && $srp !== false) {
      $sku_col = $sku;
      $srp_col = $srp;
      $header_index = $i;
      break;
    }
  }

  if ($header_index === null) {
    return new WP_Error('kiip_pum_header', 'Kolom "SKU" dan "SRP" tidak ditemukan di 15 baris pertama sheet.');
  }

  $prices = [];
  $skipped = [];
  $duplicates = [];
  foreach (array_slice($rows, $header_index + 1) as $row) {
    $sku = kiip_pum_normalize_sku($row[$sku_col] ?? '');
    if ($sku === '') {
      continue;
    }
    $srp = kiip_pum_parse_number($row[$srp_col] ?? '');
    if ($srp === null || $srp <= 0) {
      $skipped[] = $sku;
      continue;
    }
    if (isset($prices[$sku])) {
      $duplicates[] = $sku;
      continue; // yang dipakai baris pertama
    }
    $prices[$sku] = (string) round($srp * $multiplier);
  }

  return [
    'prices' => $prices,
    'rows' => count($prices),
    'skipped' => array_values(array_unique($skipped)),
    'duplicates' => array_values(array_unique($duplicates)),
  ];
}

/**
 * SKU di sheet yang cocok untuk satu produk web, atau null.
 */
function kiip_pum_match_sku($sku, $parent_sku, $sheet_prices)
{
  $candidates = [
    kiip_pum_normalize_sku($sku),
    kiip_pum_normalize_sku($parent_sku),
    kiip_pum_normalize_sku(preg_replace('/-\d+$/', '', (string) $sku)),
  ];
  foreach ($candidates as $candidate) {
    if ($candidate !== '' && isset($sheet_prices[$candidate])) {
      return $candidate;
    }
  }
  return null;
}

/**
 * Bandingkan produk web dengan harga di sheet.
 *
 * @param array $items list of ['id','sku','parent_sku','name','status','price' (sale price),'regular','parent_id']
 * @return array ['diffs' => [...], 'same' => n, 'web_missing' => [...], 'sheet_missing' => [...]]
 */
function kiip_pum_compare($items, $sheet_prices)
{
  $diffs = [];
  $same = 0;
  $web_missing = [];
  $used = [];

  foreach ($items as $item) {
    $match = kiip_pum_match_sku($item['sku'], $item['parent_sku'], $sheet_prices);
    if ($match === null) {
      $web_missing[] = $item;
      continue;
    }
    $used[$match] = true;
    $new = $sheet_prices[$match];
    if ((string) $item['price'] === '' || (float) $item['price'] !== (float) $new) {
      $diffs[] = $item + ['sheet_sku' => $match, 'new_price' => $new];
    } else {
      $same++;
    }
  }

  $sheet_missing = [];
  foreach ($sheet_prices as $sku => $price) {
    if (!isset($used[$sku])) {
      $sheet_missing[] = ['sku' => $sku, 'price' => $price];
    }
  }

  return [
    'diffs' => $diffs,
    'same' => $same,
    'web_missing' => $web_missing,
    'sheet_missing' => $sheet_missing,
  ];
}

/* ---------------------------------------------------------------------------
 * Akses Google Sheets & WooCommerce
 * ------------------------------------------------------------------------ */

function kiip_pum_http_get($url)
{
  $response = wp_remote_get($url, ['timeout' => 45, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (KiiP Helpers)']);
  if (is_wp_error($response)) {
    return new WP_Error('kiip_pum_http', 'Gagal menghubungi Google Sheets: ' . $response->get_error_message());
  }
  $code = (int) wp_remote_retrieve_response_code($response);
  $body = wp_remote_retrieve_body($response);
  $type = (string) wp_remote_retrieve_header($response, 'content-type');

  if ($code === 401 || $code === 403 || $code === 404 || stripos($body, 'accounts.google.com/ServiceLogin') !== false) {
    return new WP_Error('kiip_pum_access', 'Sheet tidak bisa dibuka. Pastikan akses sheet "Siapa saja yang memiliki link" bisa melihat.');
  }
  if ($code !== 200) {
    return new WP_Error('kiip_pum_http', 'Google Sheets membalas dengan kode ' . $code . '.');
  }
  return ['body' => $body, 'type' => $type];
}

/**
 * Ambil harga dari tab terbaru. Hasil di-cache 15 menit supaya "Update Harga"
 * memakai data yang sama dengan yang terakhir di-preview.
 *
 * @return array|WP_Error
 */
function kiip_pum_fetch_sheet($force = false)
{
  $settings = kiip_pum_settings();
  $sheet_id = kiip_pum_sheet_id($settings['url']);
  if (!$sheet_id) {
    return new WP_Error('kiip_pum_url', 'Link Google Sheets belum diisi atau formatnya tidak dikenali.');
  }

  $cache_signature = md5($sheet_id . '|' . $settings['tab_override'] . '|' . $settings['multiplier']);
  $cached = get_transient(KIIP_PUM_CACHE_KEY);
  if (!$force && is_array($cached) && ($cached['signature'] ?? '') === $cache_signature) {
    return $cached;
  }

  $page = kiip_pum_http_get('https://docs.google.com/spreadsheets/d/' . $sheet_id . '/htmlview');
  if (is_wp_error($page)) {
    return $page;
  }
  $tabs = kiip_pum_parse_tabs_from_html($page['body']);

  if ($settings['tab_override'] !== '') {
    $tab = null;
    foreach ($tabs as $candidate) {
      if (trim($candidate['name']) === trim($settings['tab_override'])) {
        $tab = $candidate;
        break;
      }
    }
    if (!$tab) {
      return new WP_Error('kiip_pum_tab', 'Tab "' . $settings['tab_override'] . '" tidak ditemukan di sheet.');
    }
  } else {
    if (!$tabs) {
      return new WP_Error('kiip_pum_tab', 'Tidak bisa membaca daftar tab di sheet.');
    }
    $tab = kiip_pum_pick_latest_tab($tabs);
    if (!$tab) {
      return new WP_Error('kiip_pum_tab', 'Tidak ada tab dengan format nama "30 Okt SK 2026".');
    }
  }

  $csv = kiip_pum_http_get('https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=csv&gid=' . rawurlencode($tab['gid']));
  if (is_wp_error($csv)) {
    return $csv;
  }
  if (stripos($csv['type'], 'text/html') !== false) {
    return new WP_Error('kiip_pum_access', 'Sheet tidak bisa diunduh sebagai CSV. Pastikan akses sheet "Siapa saja yang memiliki link" bisa melihat.');
  }

  $parsed = kiip_pum_parse_csv($csv['body'], $settings['multiplier']);
  if (is_wp_error($parsed)) {
    return $parsed;
  }

  $data = $parsed + [
    'signature' => $cache_signature,
    'tab' => $tab,
    'tabs' => $tabs,
    'fetched_at' => time(),
  ];
  set_transient(KIIP_PUM_CACHE_KEY, $data, 15 * MINUTE_IN_SECONDS);

  return $data;
}

/**
 * Semua produk simple dan variasi di web (selain yang di tong sampah).
 */
function kiip_pum_web_items()
{
  $statuses = ['publish', 'private', 'draft', 'pending', 'future'];
  $products = wc_get_products([
    'status' => $statuses,
    'type' => ['simple', 'variable'],
    'limit' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
  ]);

  $items = [];
  foreach ($products as $product) {
    if ($product->is_type('variable')) {
      $parent_sku = $product->get_sku('edit');
      foreach ($product->get_children() as $child_id) {
        $variation = wc_get_product($child_id);
        if (!$variation) {
          continue;
        }
        $items[] = [
          'id' => $variation->get_id(),
          'parent_id' => $product->get_id(),
          'sku' => $variation->get_sku('edit'),
          'parent_sku' => $parent_sku,
          'name' => $variation->get_name(),
          'status' => $product->get_status(),
          'price' => $variation->get_sale_price('edit'),
          'regular' => $variation->get_regular_price('edit'),
        ];
      }
    } else {
      $items[] = [
        'id' => $product->get_id(),
        'parent_id' => 0,
        'sku' => $product->get_sku('edit'),
        'parent_sku' => '',
        'name' => $product->get_name(),
        'status' => $product->get_status(),
        'price' => $product->get_sale_price('edit'),
        'regular' => $product->get_regular_price('edit'),
      ];
    }
  }
  return $items;
}

/**
 * @return array|WP_Error hasil kiip_pum_compare + data sheet
 */
function kiip_pum_build_report($force_fetch = false)
{
  $sheet = kiip_pum_fetch_sheet($force_fetch);
  if (is_wp_error($sheet)) {
    return $sheet;
  }
  return kiip_pum_compare(kiip_pum_web_items(), $sheet['prices']) + ['sheet' => $sheet];
}

/**
 * Terapkan harga sheet ke produk yang harganya berbeda.
 *
 * @param int[]|null $only_ids batasi ke ID tertentu (null = semua yang berbeda)
 * @return array|WP_Error ['updated' => [...], 'failed' => [...]]
 */
function kiip_pum_apply($only_ids = null)
{
  $report = kiip_pum_build_report(false);
  if (is_wp_error($report)) {
    return $report;
  }

  $updated = [];
  $failed = [];
  foreach ($report['diffs'] as $diff) {
    if ($only_ids !== null && !in_array((int) $diff['id'], $only_ids, true)) {
      continue;
    }
    $product = wc_get_product($diff['id']);
    if (!$product) {
      $failed[] = $diff;
      continue;
    }
    try {
      $product->set_sale_price($diff['new_price']);
      $product->save();
      $updated[] = $diff;
    } catch (Exception $e) {
      $failed[] = $diff + ['error' => $e->getMessage()];
    }
  }

  update_option('pum_last_run', [
    'time' => time(),
    'user' => get_current_user_id(),
    'tab' => $report['sheet']['tab']['name'],
    'updated' => count($updated),
    'failed' => count($failed),
  ], false);

  return ['updated' => $updated, 'failed' => $failed, 'tab' => $report['sheet']['tab']['name']];
}
