// Membaca harga reseller dari Google Sheets (tanpa OAuth).
// Sheet harus dibagikan "Siapa saja yang memiliki link dapat melihat",
// lalu dibaca lewat endpoint export CSV milik Google.

export type SheetRef = { spreadsheetId: string; gid: string | null };

export type SheetRow = {
  /** Nomor baris di sheet (1 = header), untuk pesan error. */
  line: number;
  sku: string;
  price: number | null;
  compareAtPrice: number | null;
  /** Teks asli sel harga, ditampilkan bila tidak bisa dibaca. */
  rawPrice: string;
  rawCompareAt: string;
};

export type ParsedSheet = {
  headers: string[];
  skuColumn: string;
  priceColumn: string;
  compareAtColumn: string | null;
  rows: SheetRow[];
};

export class SheetError extends Error {}

/** Ambil ID spreadsheet + gid tab dari link Google Sheets apa pun. */
export function parseSheetUrl(input: string): SheetRef {
  const text = input.trim();
  const idMatch = text.match(/\/spreadsheets\/d\/([a-zA-Z0-9-_]{20,})/);
  let spreadsheetId = idMatch?.[1];
  // Boleh juga menempel ID-nya saja.
  if (!spreadsheetId && /^[a-zA-Z0-9-_]{30,}$/.test(text)) spreadsheetId = text;
  if (!spreadsheetId) {
    throw new SheetError(
      "Link Google Sheets tidak dikenali. Contoh: https://docs.google.com/spreadsheets/d/<ID>/edit#gid=0",
    );
  }
  const gidMatch = text.match(/[#&?]gid=(\d+)/);
  return { spreadsheetId, gid: gidMatch?.[1] ?? null };
}

export function csvExportUrl(ref: SheetRef): string {
  const base = `https://docs.google.com/spreadsheets/d/${ref.spreadsheetId}/export?format=csv`;
  return ref.gid ? `${base}&gid=${ref.gid}` : base;
}

export async function fetchSheetCsv(
  ref: SheetRef,
  fetchImpl: typeof fetch = fetch,
): Promise<string> {
  let res: Response;
  try {
    res = await fetchImpl(csvExportUrl(ref), { redirect: "follow" });
  } catch (err) {
    throw new SheetError(`Gagal menghubungi Google Sheets: ${String(err)}`);
  }
  const body = await res.text();
  const looksLikeHtml = /^\s*<(!doctype|html)/i.test(body);
  if (!res.ok || looksLikeHtml) {
    throw new SheetError(
      res.status === 404
        ? "Sheet tidak ditemukan. Cek lagi link-nya."
        : "Sheet tidak bisa dibaca. Pastikan aksesnya \"Siapa saja yang memiliki link dapat melihat\" (Share > General access > Anyone with the link).",
    );
  }
  return body;
}

/** Parser CSV sederhana (RFC 4180): kutip ganda, koma & baris baru di dalam kutip. */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = "";
  let quoted = false;
  const src = text.replace(/^﻿/, "");
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (quoted) {
      if (ch === '"') {
        if (src[i + 1] === '"') {
          cell += '"';
          i++;
        } else quoted = false;
      } else cell += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === ",") {
      row.push(cell);
      cell = "";
    } else if (ch === "\n" || ch === "\r") {
      if (ch === "\r" && src[i + 1] === "\n") i++;
      row.push(cell);
      rows.push(row);
      row = [];
      cell = "";
    } else cell += ch;
  }
  if (cell !== "" || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows;
}

/**
 * Baca angka harga Rupiah dari berbagai format:
 * "Rp 119.000", "119,000", "119000", "119.000,00", "119000.00", "Rp119.000,-".
 * Mengembalikan null bila kosong / tidak valid.
 */
export function parseIdr(raw: string): number | null {
  let s = raw.trim().replace(/^rp\.?/i, "").replace(/[\s ]/g, "");
  s = s.replace(/,-$/, "").replace(/\.-$/, "");
  if (!s) return null;
  if (!/^[0-9.,]+$/.test(s)) return null;

  const lastDot = s.lastIndexOf(".");
  const lastComma = s.lastIndexOf(",");
  let normalized: string;
  if (lastDot >= 0 && lastComma >= 0) {
    // Pemisah yang muncul terakhir = desimal.
    const dec = lastDot > lastComma ? "." : ",";
    const thou = dec === "." ? "," : ".";
    normalized = s.split(thou).join("").replace(dec, ".");
  } else if (lastDot >= 0 || lastComma >= 0) {
    const sep = lastDot >= 0 ? "." : ",";
    const parts = s.split(sep);
    const tail = parts[parts.length - 1];
    // "119.000" / "1.250.000" = ribuan. "119000.5" / "119000,50" = desimal.
    const isThousands =
      parts.length > 2 || (tail.length === 3 && parts[0].length <= 3);
    normalized = isThousands ? parts.join("") : parts.join(".");
  } else normalized = s;

  const n = Number(normalized);
  if (!Number.isFinite(n) || n < 0) return null;
  return Math.round(n * 100) / 100;
}

const norm = (h: string) => h.trim().toLowerCase().replace(/\s+/g, " ");

function findColumn(headers: string[], candidates: RegExp[]): number {
  for (const re of candidates) {
    const idx = headers.findIndex((h) => re.test(norm(h)));
    if (idx >= 0) return idx;
  }
  return -1;
}

/**
 * Ubah isi CSV jadi baris SKU + harga.
 * Kolom dikenali dari judul (tidak peka huruf besar/kecil):
 *  - SKU: "SKU", "Kode", "Kode SKU"
 *  - Harga reseller: "Harga Reseller", "Reseller", "Harga B2B", "Harga"
 *  - Harga coret (opsional): "Harga Coret Reseller", "Harga Coret", "Compare at"
 */
export function parseSheet(csv: string): ParsedSheet {
  const table = parseCsv(csv).filter((r) => r.some((c) => c.trim() !== ""));
  if (table.length < 2) {
    throw new SheetError("Sheet kosong atau hanya berisi judul kolom.");
  }
  const headers = table[0].map((h) => h.trim());

  const skuIdx = findColumn(headers, [/^sku$/, /^kode sku$/, /sku/, /^kode$/]);
  const compareIdx = findColumn(headers, [
    /coret.*reseller|reseller.*coret/,
    /coret/,
    /compare/,
  ]);
  const priceIdx = findColumn(
    headers.map((h, i) => (i === compareIdx ? "" : h)),
    [/^harga reseller$/, /reseller/, /b2b/, /^harga$/, /^price$/],
  );

  if (skuIdx < 0 || priceIdx < 0) {
    throw new SheetError(
      `Kolom tidak ditemukan. Baris pertama sheet harus berisi judul kolom "SKU" dan "Harga Reseller" (opsional "Harga Coret"). Judul yang terbaca: ${headers.join(", ") || "(kosong)"}`,
    );
  }

  const rows: SheetRow[] = [];
  table.slice(1).forEach((r, i) => {
    const sku = (r[skuIdx] ?? "").trim();
    const rawPrice = (r[priceIdx] ?? "").trim();
    const rawCompareAt = compareIdx >= 0 ? (r[compareIdx] ?? "").trim() : "";
    if (!sku && !rawPrice) return;
    rows.push({
      line: i + 2,
      sku,
      price: parseIdr(rawPrice),
      compareAtPrice: rawCompareAt ? parseIdr(rawCompareAt) : null,
      rawPrice,
      rawCompareAt,
    });
  });

  return {
    headers,
    skuColumn: headers[skuIdx],
    priceColumn: headers[priceIdx],
    compareAtColumn: compareIdx >= 0 ? headers[compareIdx] : null,
    rows,
  };
}
