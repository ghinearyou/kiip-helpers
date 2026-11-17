import { test } from "node:test";
import assert from "node:assert/strict";
import { parseCsv, parseIdr, parseSheet, parseSheetUrl, csvExportUrl } from "./sheet.ts";

test("parseSheetUrl reads id and gid", () => {
  const ref = parseSheetUrl(
    "https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=123456",
  );
  assert.equal(ref.spreadsheetId, "1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789");
  assert.equal(ref.gid, "123456");
  assert.match(csvExportUrl(ref), /export\?format=csv&gid=123456$/);
  assert.equal(parseSheetUrl("https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit?usp=sharing").gid, null);
  assert.throws(() => parseSheetUrl("https://example.com"));
});

test("parseIdr handles Indonesian and plain formats", () => {
  assert.equal(parseIdr("Rp 119.000"), 119000);
  assert.equal(parseIdr("Rp119.000,-"), 119000);
  assert.equal(parseIdr("1.250.000"), 1250000);
  assert.equal(parseIdr("119,000"), 119000);
  assert.equal(parseIdr("119000"), 119000);
  assert.equal(parseIdr("119000.00"), 119000);
  assert.equal(parseIdr("119.000,50"), 119000.5);
  assert.equal(parseIdr("1,250,000.00"), 1250000);
  assert.equal(parseIdr(""), null);
  assert.equal(parseIdr("abc"), null);
});

test("parseCsv handles quotes, commas and CRLF", () => {
  assert.deepEqual(parseCsv('a,b\r\n"x, y","Rp 1.000"\r\n"he said ""hi""",2'), [
    ["a", "b"],
    ["x, y", "Rp 1.000"],
    ['he said "hi"', "2"],
  ]);
});

test("parseSheet detects columns and keeps line numbers", () => {
  const csv = [
    "Nama Produk,SKU,Harga Normal,Harga Reseller,Harga Coret Reseller",
    'Sepatu,SK-1,"Rp 119.000","Rp 99.000","Rp 250.000"',
    ",,,,",
    "Botol,SK-2,50000,45.000,",
    "Kosong,SK-3,50000,,",
  ].join("\n");
  const s = parseSheet(csv);
  assert.equal(s.skuColumn, "SKU");
  assert.equal(s.priceColumn, "Harga Reseller");
  assert.equal(s.compareAtColumn, "Harga Coret Reseller");
  assert.equal(s.rows.length, 3);
  assert.deepEqual(
    s.rows.map((r) => [r.line, r.sku, r.price, r.compareAtPrice]),
    [
      [2, "SK-1", 99000, 250000],
      [3, "SK-2", 45000, null],
      [4, "SK-3", null, null],
    ],
  );
});

test("parseSheet errors without required headers", () => {
  assert.throws(() => parseSheet("Nama,Harga Normal\nA,1"), /Kolom tidak ditemukan/);
});
