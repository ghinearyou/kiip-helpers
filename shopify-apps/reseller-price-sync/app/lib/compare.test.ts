import { test } from "node:test";
import assert from "node:assert/strict";
import { buildPreview, type VariantPrice } from "./compare.ts";
import type { SheetRow } from "./sheet.ts";

const v = (o: Partial<VariantPrice>): VariantPrice => ({
  variantId: "gid://shopify/ProductVariant/1",
  sku: "SK-1",
  productTitle: "Sepatu",
  variantTitle: "Putih",
  basePrice: 119000,
  baseCompareAt: 299000,
  currentPrice: 107100,
  currentCompareAt: 269100,
  origin: "RELATIVE",
  ...o,
});
const row = (o: Partial<SheetRow>): SheetRow => ({
  line: 2, sku: "SK-1", price: 100000, compareAtPrice: null, rawPrice: "", rawCompareAt: "", ...o,
});

test("changed price keeps current reseller compare-at when sheet has none", () => {
  const p = buildPreview([row({})], [v({})]);
  assert.equal(p.totals.changed, 1);
  assert.equal(p.rows[0].newPrice, 100000);
  assert.equal(p.rows[0].newCompareAt, 269100);
  assert.equal(p.rows[0].aboveRetail, false);
});

test("same price and compare-at is unchanged; SKU match is case-insensitive", () => {
  const p = buildPreview([row({ sku: "sk-1 ", price: 107100 })], [v({})]);
  assert.equal(p.totals.changed, 0);
  assert.equal(p.totals.unchanged, 1);
});

test("compare-at from sheet is used, dropped when not above price", () => {
  const p = buildPreview(
    [row({ compareAtPrice: 250000 }), row({ sku: "SK-2", price: 50000, compareAtPrice: 40000 })],
    [v({}), v({ variantId: "v2", sku: "SK-2", currentPrice: 45000, currentCompareAt: null })],
  );
  const byId = Object.fromEntries(p.rows.map((r) => [r.variantId, r]));
  assert.equal(byId["gid://shopify/ProductVariant/1"].newCompareAt, 250000);
  assert.equal(byId.v2.newCompareAt, null);
});

test("reports not found, invalid, duplicates, above retail", () => {
  const p = buildPreview(
    [
      row({ line: 2, price: 90000 }),
      row({ line: 3, price: 130000 }),
      row({ line: 4, sku: "NOPE" }),
      row({ line: 5, sku: "SK-9", price: null, rawPrice: "x" }),
      row({ line: 6, sku: "" }),
    ],
    [v({}), v({ variantId: "dup", sku: "SK-1" }), v({ variantId: "no-sku", sku: "" })],
  );
  assert.deepEqual(p.duplicateInSheet, ["SK-1"]);
  assert.deepEqual(p.duplicateInStore, ["SK-1"]);
  assert.deepEqual(p.notFound.map((r) => r.sku), ["NOPE"]);
  assert.equal(p.invalid.length, 2);
  assert.equal(p.rows.length, 2);
  assert.ok(p.rows.every((r) => r.newPrice === 130000 && r.aboveRetail));
});
