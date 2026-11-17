import { createHash } from "node:crypto";
import { useEffect, useMemo, useState } from "react";
import type {
  ActionFunctionArgs,
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import { useFetcher, useLoaderData } from "react-router";
import { useAppBridge } from "@shopify/app-bridge-react";
import { boundary } from "@shopify/shopify-app-react-router/server";

import { authenticate } from "../shopify.server";
import {
  SheetError,
  fetchSheetCsv,
  parseSheet,
  parseSheetUrl,
} from "../lib/sheet.ts";
import { buildPreview, type Preview, type PreviewRow } from "../lib/compare.ts";
import {
  applyFixedPrices,
  listPriceLists,
  loadPriceListPrices,
  type ApplyResult,
} from "../lib/prices.server.ts";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin } = await authenticate.admin(request);
  const priceLists = await listPriceLists(admin.graphql);
  const preferred =
    priceLists.find((p) => /reseller/i.test(`${p.name} ${p.catalogTitle}`)) ??
    priceLists[0];
  return { priceLists, defaultPriceListId: preferred?.id ?? "" };
};

type ActionData =
  | { intent: "error"; message: string }
  | {
      intent: "preview";
      fingerprint: string;
      currency: string;
      sheetInfo: {
        skuColumn: string;
        priceColumn: string;
        compareAtColumn: string | null;
      };
      preview: Preview;
    }
  | {
      intent: "apply";
      result: ApplyResult;
      attempted: number;
    };

async function computePreview(
  graphql: Parameters<typeof listPriceLists>[0],
  sheetUrl: string,
  priceListId: string,
) {
  const ref = parseSheetUrl(sheetUrl);
  const csv = await fetchSheetCsv(ref);
  const sheet = parseSheet(csv);
  const { currency, variants } = await loadPriceListPrices(graphql, priceListId);
  const preview = buildPreview(sheet.rows, variants);
  // Sidik jari isi sheet + harga Shopify saat preview. Saat "Update" dihitung
  // ulang; kalau berbeda, admin diminta preview ulang supaya yang diupdate
  // sama persis dengan yang dilihat.
  const fingerprint = createHash("sha256")
    .update(priceListId)
    .update(csv)
    .update(
      JSON.stringify(
        preview.rows.map((r) => [r.variantId, r.currentPrice, r.currentCompareAt]),
      ),
    )
    .digest("hex");
  return { sheet, currency, preview, fingerprint };
}

export const action = async ({
  request,
}: ActionFunctionArgs): Promise<ActionData> => {
  const { admin } = await authenticate.admin(request);
  const form = await request.formData();
  const intent = String(form.get("intent") ?? "preview");
  const sheetUrl = String(form.get("sheetUrl") ?? "");
  const priceListId = String(form.get("priceListId") ?? "");

  try {
    if (!priceListId) throw new SheetError("Pilih price list dulu.");
    const { sheet, currency, preview, fingerprint } = await computePreview(
      admin.graphql,
      sheetUrl,
      priceListId,
    );

    if (intent === "preview") {
      return {
        intent: "preview",
        fingerprint,
        currency,
        sheetInfo: {
          skuColumn: sheet.skuColumn,
          priceColumn: sheet.priceColumn,
          compareAtColumn: sheet.compareAtColumn,
        },
        preview,
      };
    }

    if (form.get("fingerprint") !== fingerprint) {
      return {
        intent: "error",
        message:
          "Isi sheet atau harga di Shopify berubah sejak preview. Klik \"Bandingkan harga\" lagi lalu cek ulang sebelum update.",
      };
    }
    const selected = new Set<string>(
      JSON.parse(String(form.get("variantIds") ?? "[]")) as string[],
    );
    const items = preview.rows
      .filter((r) => r.changed && selected.has(r.variantId))
      .map((r) => ({
        variantId: r.variantId,
        price: r.newPrice,
        compareAtPrice: r.newCompareAt,
      }));
    if (items.length === 0) {
      return { intent: "error", message: "Tidak ada harga yang dipilih untuk diupdate." };
    }
    const result = await applyFixedPrices(
      admin.graphql,
      priceListId,
      currency,
      items,
    );
    return { intent: "apply", result, attempted: items.length };
  } catch (err) {
    const message =
      err instanceof SheetError
        ? err.message
        : `Terjadi kesalahan: ${err instanceof Error ? err.message : String(err)}`;
    if (!(err instanceof SheetError)) console.error(err);
    return { intent: "error", message };
  }
};

const idr = (n: number | null) =>
  n === null
    ? "-"
    : new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
      }).format(n);

function diffText(r: PreviewRow) {
  const d = r.newPrice - r.currentPrice;
  if (Math.abs(d) < 0.005) return "0";
  const pct = r.currentPrice ? ` (${((d / r.currentPrice) * 100).toFixed(1)}%)` : "";
  return `${d > 0 ? "+" : "−"}${idr(Math.abs(d))}${pct}`;
}

export default function Index() {
  const { priceLists, defaultPriceListId } = useLoaderData<typeof loader>();
  const previewFetcher = useFetcher<ActionData>();
  const applyFetcher = useFetcher<ActionData>();
  const shopify = useAppBridge();

  const [sheetUrl, setSheetUrl] = useState("");
  const [priceListId, setPriceListId] = useState(defaultPriceListId);
  const [onlyChanged, setOnlyChanged] = useState(true);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirming, setConfirming] = useState(false);

  const previewData =
    previewFetcher.data?.intent === "preview" ? previewFetcher.data : null;
  const previewError =
    previewFetcher.data?.intent === "error" ? previewFetcher.data.message : null;
  const applyData = applyFetcher.data;

  const isPreviewing = previewFetcher.state !== "idle";
  const isApplying = applyFetcher.state !== "idle";

  // Setelah preview baru: pilih semua yang berubah, kecuali yang di atas harga normal.
  useEffect(() => {
    if (!previewData) return;
    setSelected(
      new Set(
        previewData.preview.rows
          .filter((r) => r.changed && !r.aboveRetail)
          .map((r) => r.variantId),
      ),
    );
    setConfirming(false);
  }, [previewData]);

  // Setelah update sukses: toast + bandingkan ulang supaya tabel menampilkan harga terbaru.
  useEffect(() => {
    if (applyData?.intent !== "apply") return;
    const { updated } = applyData.result;
    shopify.toast.show(`${updated} harga reseller diupdate`);
    setConfirming(false);
    runPreview();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [applyData]);

  const rows = useMemo(
    () =>
      previewData
        ? previewData.preview.rows.filter((r) => !onlyChanged || r.changed)
        : [],
    [previewData, onlyChanged],
  );
  const selectedCount = previewData
    ? previewData.preview.rows.filter((r) => r.changed && selected.has(r.variantId))
        .length
    : 0;

  const runPreview = (url = sheetUrl, listId = priceListId) => {
    previewFetcher.submit(
      { intent: "preview", sheetUrl: url, priceListId: listId },
      { method: "post" },
    );
  };

  const runApply = () => {
    if (!previewData) return;
    applyFetcher.submit(
      {
        intent: "apply",
        sheetUrl,
        priceListId,
        fingerprint: previewData.fingerprint,
        variantIds: JSON.stringify([...selected]),
      },
      { method: "post" },
    );
  };

  const toggle = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const changedRows = previewData?.preview.rows.filter((r) => r.changed) ?? [];
  const allSelected =
    changedRows.length > 0 && changedRows.every((r) => selected.has(r.variantId));

  return (
    <s-page heading="Sync Harga Reseller">
      <s-section heading="1. Sumber harga">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            // Ambil nilai langsung dari form (komponen Polaris ikut FormData).
            const data = new FormData(e.currentTarget);
            const url = String(data.get("sheetUrl") ?? "").trim();
            const listId = String(data.get("priceListId") ?? priceListId);
            setSheetUrl(url);
            setPriceListId(listId);
            runPreview(url, listId);
          }}
        >
          <s-stack gap="base">
            <s-text-field
              label="Link Google Sheets"
              name="sheetUrl"
              placeholder="https://docs.google.com/spreadsheets/d/.../edit#gid=0"
              details='Sheet harus bisa dibuka siapa saja yang punya link (Share > Anyone with the link > Viewer). Baris pertama berisi judul kolom "SKU" dan "Harga Reseller", opsional "Harga Coret". Tab yang dibaca mengikuti gid di link.'
              required
            />
            <s-select
              label="Price list reseller"
              name="priceListId"
            >
              {priceLists.map((p) => (
                <s-option
                  key={p.id}
                  value={p.id}
                  defaultSelected={p.id === defaultPriceListId}
                >
                  {`${p.name}${p.catalogTitle ? ` · katalog ${p.catalogTitle}` : ""}${p.adjustment ? ` · ${p.adjustment}` : ""} · ${p.fixedPricesCount} harga tetap`}
                </s-option>
              ))}
            </s-select>
            <s-stack direction="inline" gap="base">
              <s-button
                variant="primary"
                type="submit"
                loading={isPreviewing}
              >
                Bandingkan harga
              </s-button>
            </s-stack>
          </s-stack>
        </form>
        {previewError && (
          <s-banner tone="critical" heading="Belum bisa membandingkan">
            {previewError}
          </s-banner>
        )}
        {applyData?.intent === "error" && (
          <s-banner tone="critical" heading="Update dibatalkan">
            {applyData.message}
          </s-banner>
        )}
        {applyData?.intent === "apply" && (
          <s-banner
            tone={applyData.result.errors.length ? "warning" : "success"}
            heading={`${applyData.result.updated} dari ${applyData.attempted} harga reseller berhasil diupdate`}
          >
            {applyData.result.errors.length > 0 && (
              <s-unordered-list>
                {applyData.result.errors.slice(0, 20).map((e, i) => (
                  <s-list-item key={i}>
                    {e.variantId ? `${e.variantId}: ` : ""}
                    {e.message}
                  </s-list-item>
                ))}
              </s-unordered-list>
            )}
          </s-banner>
        )}
      </s-section>

      {previewData && (
        <>
          <s-section heading="2. Ringkasan">
            <s-stack gap="small">
              <s-paragraph>
                Kolom terbaca: SKU = "{previewData.sheetInfo.skuColumn}", harga
                = "{previewData.sheetInfo.priceColumn}"
                {previewData.sheetInfo.compareAtColumn
                  ? `, harga coret = "${previewData.sheetInfo.compareAtColumn}"`
                  : ", harga coret = (tidak ada, harga coret reseller sekarang dipertahankan)"}
                .
              </s-paragraph>
              <s-stack direction="inline" gap="small">
                <s-badge tone="info">
                  {`${previewData.preview.totals.sheetRows} baris di sheet`}
                </s-badge>
                <s-badge tone="warning">
                  {`${previewData.preview.totals.changed} harga berubah`}
                </s-badge>
                <s-badge tone="success">
                  {`${previewData.preview.totals.unchanged} sudah sama`}
                </s-badge>
                {previewData.preview.notFound.length > 0 && (
                  <s-badge tone="critical">
                    {`${previewData.preview.notFound.length} SKU tidak ada di toko`}
                  </s-badge>
                )}
                {previewData.preview.invalid.length > 0 && (
                  <s-badge tone="critical">
                    {`${previewData.preview.invalid.length} baris tidak valid`}
                  </s-badge>
                )}
              </s-stack>

              {previewData.preview.notFound.length > 0 && (
                <s-banner tone="warning" heading="SKU di sheet yang tidak ada di toko (dilewati)">
                  {previewData.preview.notFound
                    .slice(0, 50)
                    .map((r) => `${r.sku} (baris ${r.line})`)
                    .join(", ")}
                  {previewData.preview.notFound.length > 50 ? ", …" : ""}
                </s-banner>
              )}
              {previewData.preview.invalid.length > 0 && (
                <s-banner tone="warning" heading="Baris yang SKU atau harganya kosong / tidak valid (dilewati)">
                  {previewData.preview.invalid
                    .slice(0, 50)
                    .map(
                      (r) =>
                        `baris ${r.line}${r.sku ? ` ${r.sku}` : ""}: "${r.rawPrice}"`,
                    )
                    .join(", ")}
                </s-banner>
              )}
              {previewData.preview.duplicateInSheet.length > 0 && (
                <s-banner tone="warning" heading="SKU dobel di sheet (yang dipakai baris paling bawah)">
                  {previewData.preview.duplicateInSheet.join(", ")}
                </s-banner>
              )}
              {previewData.preview.duplicateInStore.length > 0 && (
                <s-banner tone="info" heading="SKU dipakai lebih dari 1 varian di toko (semua varian ikut diupdate)">
                  {previewData.preview.duplicateInStore.join(", ")}
                </s-banner>
              )}
              {changedRows.some((r) => r.aboveRetail) && (
                <s-banner tone="warning" heading="Ada harga reseller baru di atas harga normal">
                  Baris ini tidak dicentang otomatis. Centang manual kalau memang
                  disengaja.
                </s-banner>
              )}
            </s-stack>
          </s-section>

          <s-section heading="3. Preview harga">
            <s-stack gap="base">
              <s-stack direction="inline" gap="base">
                <s-button onClick={() => setOnlyChanged((v) => !v)}>
                  {onlyChanged
                    ? "Tampilkan semua SKU di sheet"
                    : "Tampilkan hanya yang berubah"}
                </s-button>
                {changedRows.length > 0 && (
                  <s-button
                    variant="tertiary"
                    onClick={() =>
                      setSelected(
                        allSelected
                          ? new Set()
                          : new Set(changedRows.map((r) => r.variantId)),
                      )
                    }
                  >
                    {allSelected ? "Batalkan semua centang" : "Centang semua"}
                  </s-button>
                )}
              </s-stack>

              {rows.length === 0 ? (
                <s-paragraph>
                  Tidak ada perubahan harga. Semua harga di sheet sudah sama dengan
                  harga reseller sekarang.
                </s-paragraph>
              ) : (
                <s-table variant="auto">
                  <s-table-header-row>
                    <s-table-header>Update</s-table-header>
                    <s-table-header listSlot="primary">Produk</s-table-header>
                    <s-table-header listSlot="secondary">SKU</s-table-header>
                    <s-table-header format="currency">Harga normal</s-table-header>
                    <s-table-header format="currency">Reseller sekarang</s-table-header>
                    <s-table-header format="currency">Reseller baru</s-table-header>
                    <s-table-header format="numeric">Selisih</s-table-header>
                    <s-table-header format="currency">Coret sekarang → baru</s-table-header>
                  </s-table-header-row>
                  <s-table-body>
                    {rows.map((r) => (
                      <s-table-row key={r.variantId}>
                        <s-table-cell>
                          {r.changed ? (
                            <input
                              type="checkbox"
                              aria-label={`Update ${r.sku}`}
                              checked={selected.has(r.variantId)}
                              onChange={() => toggle(r.variantId)}
                            />
                          ) : (
                            <s-badge tone="success">Sama</s-badge>
                          )}
                        </s-table-cell>
                        <s-table-cell>
                          {r.productTitle}
                          {r.variantTitle && r.variantTitle !== "Default Title"
                            ? ` · ${r.variantTitle}`
                            : ""}
                        </s-table-cell>
                        <s-table-cell>{r.sku}</s-table-cell>
                        <s-table-cell>{idr(r.basePrice)}</s-table-cell>
                        <s-table-cell>
                          {idr(r.currentPrice)}{" "}
                          <s-text color="subdued">
                            {r.origin === "FIXED" ? "(tetap)" : "(diskon %)"}
                          </s-text>
                        </s-table-cell>
                        <s-table-cell>
                          {idr(r.newPrice)}
                          {r.aboveRetail ? " ⚠︎" : ""}
                        </s-table-cell>
                        <s-table-cell>{diffText(r)}</s-table-cell>
                        <s-table-cell>
                          {idr(
                            r.currentCompareAt !== null &&
                              r.currentCompareAt > r.currentPrice
                              ? r.currentCompareAt
                              : null,
                          )}{" "}
                          → {idr(r.newCompareAt)}
                        </s-table-cell>
                      </s-table-row>
                    ))}
                  </s-table-body>
                </s-table>
              )}
            </s-stack>
          </s-section>

          {changedRows.length > 0 && (
            <s-section heading="4. Update harga">
              {!confirming ? (
                <s-button
                  variant="primary"
                  disabled={selectedCount === 0}
                  onClick={() => setConfirming(true)}
                >
                  {`Update ${selectedCount} harga reseller`}
                </s-button>
              ) : (
                <s-banner tone="warning" heading={`Update ${selectedCount} harga reseller sekarang?`}>
                  <s-stack gap="base">
                    <s-paragraph>
                      Harga ini disimpan sebagai harga tetap di price list
                      terpilih dan langsung berlaku untuk reseller. Varian yang
                      tidak ada di sheet tetap memakai harga lamanya.
                    </s-paragraph>
                    <s-stack direction="inline" gap="base">
                      <s-button
                        variant="primary"
                        loading={isApplying}
                        onClick={runApply}
                      >
                        Ya, update sekarang
                      </s-button>
                      <s-button
                        disabled={isApplying}
                        onClick={() => setConfirming(false)}
                      >
                        Batal
                      </s-button>
                    </s-stack>
                  </s-stack>
                </s-banner>
              )}
            </s-section>
          )}
        </>
      )}
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
