# Sync Harga Reseller (Shopify app Sakumini)

App Shopify (embedded, tampil di dalam admin Shopify) untuk menyamakan **harga reseller B2B**
dengan **Google Sheets**.

Alur di app:

1. **Input link Google Sheets** dan pilih price list reseller (default: "Harga Reseller (-10%)").
2. **Bandingkan harga**: app membaca sheet, mencocokkan per **SKU**, lalu menampilkan ringkasan
   (berubah / sudah sama / SKU tidak ada di toko / baris tidak valid / SKU dobel).
3. **Preview**: tabel per varian berisi harga normal, **harga reseller sekarang** (tetap atau hasil
   diskon %), **harga reseller baru**, selisih, dan harga coret sekarang → baru. Setiap baris yang
   berubah bisa dicentang/dihapus centangnya.
4. **Update harga**: setelah konfirmasi, harga disimpan sebagai *fixed price* di price list
   (`priceListFixedPricesAdd`). Varian yang tidak ada di sheet tetap memakai diskon % lama.

Pengaman:
- Saat update, sheet dan harga Shopify dibaca ulang. Kalau ada yang berubah sejak preview, update
  dibatalkan dan diminta preview ulang.
- Harga reseller baru yang **lebih mahal dari harga normal** ditandai ⚠︎ dan tidak dicentang otomatis.

## Format Google Sheets

Baris pertama = judul kolom. Urutan kolom bebas, kolom lain diabaikan.

| SKU | Nama Produk (opsional) | Harga Reseller | Harga Coret (opsional) |
|---|---|---|---|
| SK-WR-WALK15-WH | Sepatu Prewalker Putih 12cm | Rp 99.000 | Rp 269.100 |

- Kolom SKU dikenali dari judul `SKU` / `Kode SKU` / `Kode`.
- Kolom harga dari `Harga Reseller` / `Reseller` / `Harga B2B` / `Harga`.
- Kolom harga coret (opsional) dari `Harga Coret`, `Harga Coret Reseller`, `Compare at`.
  Kalau tidak ada, harga coret reseller yang sekarang dipertahankan (dibuang bila tidak lebih
  tinggi dari harga baru).
- Format angka bebas: `99000`, `99.000`, `Rp 99.000`, `99,000`, `99000.00`.
- Sheet harus dibagikan **Anyone with the link → Viewer**. Tab yang dibaca mengikuti `gid=` di link
  (buka tab-nya dulu, lalu salin link dari address bar).

## Pasang app (sekali saja)

Butuh: Node.js 22.12+, akun Shopify yang jadi owner/staff toko `jkdwr1-bz.myshopify.com`,
dan Shopify CLI (`npm i -g @shopify/cli`).

```bash
cd shopify-apps/reseller-price-sync
npm install
npm run config:link     # login Shopify, pilih "Create new app", nama: Sync Harga Reseller
```

### Coba lokal (paling cepat)

```bash
npm run dev             # pilih toko jkdwr1-bz, CLI membuat tunnel + memasang app
```

Tekan `p` untuk membuka app di admin Shopify. App hanya jalan selama terminal ini terbuka.

### Jalan permanen (hosting)

App butuh server Node yang selalu hidup. Contoh dengan Render / Railway / Fly.io (pakai
`Dockerfile` yang sudah ada):

1. Deploy folder ini sebagai Docker web service, port `3000`.
2. Isi environment variable:
   - `SHOPIFY_API_KEY` dan `SHOPIFY_API_SECRET`: dari Dev Dashboard → app → Settings
     (atau `npm run env -- show`).
   - `SHOPIFY_APP_URL`: URL publik server, misal `https://sync-reseller.onrender.com`.
   - `SCOPES=read_products,write_products`
3. Ubah `application_url` dan `redirect_urls` di `shopify.app.toml` ke URL itu, lalu
   `npm run deploy` untuk mendaftarkan konfigurasi ke Shopify.
4. Di Dev Dashboard → app → Distribution, pilih **Custom distribution** untuk toko
   `jkdwr1-bz.myshopify.com`, buka link instalasinya, lalu **Install**.

Sesi disimpan di SQLite (`prisma/dev.sqlite`). Kalau disk server tidak permanen, sesi akan
dibuat ulang otomatis saat app dibuka, jadi aman.

## Pengembangan

```bash
npm test          # unit test baca sheet + logika perbandingan
npm run typecheck
npm run build
```

Kode utama:
- `app/lib/sheet.ts`: baca link, CSV, format Rupiah, deteksi kolom.
- `app/lib/compare.ts`: bandingkan sheet vs harga reseller (tanpa I/O).
- `app/lib/prices.server.ts`: query price list + mutation fixed price.
- `app/routes/app._index.tsx`: halaman app (Polaris web components).
