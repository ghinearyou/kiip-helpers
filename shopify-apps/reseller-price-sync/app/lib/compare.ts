// Logika perbandingan harga sheet vs harga reseller di Shopify (tanpa I/O).
import type { SheetRow } from "./sheet.ts";

export type VariantPrice = {
  variantId: string;
  sku: string;
  productTitle: string;
  variantTitle: string;
  /** Harga normal (retail) varian. */
  basePrice: number;
  baseCompareAt: number | null;
  /** Harga di price list reseller saat ini. */
  currentPrice: number;
  currentCompareAt: number | null;
  /** FIXED = harga tetap per SKU, RELATIVE = hasil diskon % price list. */
  origin: "FIXED" | "RELATIVE";
};

export type PreviewRow = VariantPrice & {
  line: number;
  newPrice: number;
  newCompareAt: number | null;
  changed: boolean;
  /** Harga reseller baru lebih mahal dari harga normal. */
  aboveRetail: boolean;
};

export type Preview = {
  rows: PreviewRow[];
  notFound: SheetRow[];
  invalid: SheetRow[];
  duplicateInSheet: string[];
  duplicateInStore: string[];
  totals: {
    sheetRows: number;
    changed: number;
    unchanged: number;
  };
};

export const skuKey = (sku: string) => sku.trim().toUpperCase();

const sameMoney = (a: number | null, b: number | null) =>
  a === null || b === null ? a === b : Math.abs(a - b) < 0.005;

export function buildPreview(
  sheetRows: SheetRow[],
  variants: VariantPrice[],
): Preview {
  const bySku = new Map<string, VariantPrice[]>();
  for (const v of variants) {
    if (!v.sku.trim()) continue;
    const key = skuKey(v.sku);
    bySku.set(key, [...(bySku.get(key) ?? []), v]);
  }

  const invalid: SheetRow[] = [];
  const notFound: SheetRow[] = [];
  const seen = new Map<string, number>();
  const duplicateInSheet = new Set<string>();
  const duplicateInStore = new Set<string>();

  // Baris terakhir menang bila SKU muncul lebih dari sekali di sheet.
  const lastRowBySku = new Map<string, SheetRow>();
  for (const r of sheetRows) {
    if (!r.sku || r.price === null || r.price <= 0) {
      invalid.push(r);
      continue;
    }
    const key = skuKey(r.sku);
    seen.set(key, (seen.get(key) ?? 0) + 1);
    if ((seen.get(key) ?? 0) > 1) duplicateInSheet.add(r.sku);
    lastRowBySku.set(key, r);
  }

  const rows: PreviewRow[] = [];
  for (const [key, r] of lastRowBySku) {
    const matches = bySku.get(key);
    if (!matches) {
      notFound.push(r);
      continue;
    }
    if (matches.length > 1) duplicateInStore.add(r.sku);
    for (const v of matches) {
      const newPrice = r.price as number;
      // Harga coret: pakai kolom sheet bila ada; kalau tidak, pertahankan
      // harga coret reseller sekarang. Hanya dipakai bila > harga baru.
      const candidate =
        r.compareAtPrice !== null ? r.compareAtPrice : v.currentCompareAt;
      const newCompareAt =
        candidate !== null && candidate > newPrice ? candidate : null;
      const currentCompareShown =
        v.currentCompareAt !== null && v.currentCompareAt > v.currentPrice
          ? v.currentCompareAt
          : null;
      const changed =
        !sameMoney(v.currentPrice, newPrice) ||
        !sameMoney(currentCompareShown, newCompareAt);
      rows.push({
        ...v,
        line: r.line,
        newPrice,
        newCompareAt,
        changed,
        aboveRetail: newPrice > v.basePrice,
      });
    }
  }

  rows.sort(
    (a, b) =>
      Number(b.changed) - Number(a.changed) ||
      a.productTitle.localeCompare(b.productTitle) ||
      a.sku.localeCompare(b.sku),
  );

  const changed = rows.filter((r) => r.changed).length;
  return {
    rows,
    notFound,
    invalid,
    duplicateInSheet: [...duplicateInSheet],
    duplicateInStore: [...duplicateInStore],
    totals: {
      sheetRows: sheetRows.length,
      changed,
      unchanged: rows.length - changed,
    },
  };
}

export function chunk<T>(items: T[], size: number): T[][] {
  const out: T[][] = [];
  for (let i = 0; i < items.length; i += size) out.push(items.slice(i, i + size));
  return out;
}
