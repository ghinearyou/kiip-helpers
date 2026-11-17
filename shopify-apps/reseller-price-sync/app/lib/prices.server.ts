// Baca & tulis harga reseller lewat Shopify Admin GraphQL API.
import type { VariantPrice } from "./compare.ts";
import { chunk } from "./compare.ts";

type GraphqlFn = (
  query: string,
  options?: { variables?: Record<string, unknown> },
) => Promise<Response>;

export type PriceListOption = {
  id: string;
  name: string;
  currency: string;
  catalogTitle: string | null;
  catalogStatus: string | null;
  adjustment: string | null;
  fixedPricesCount: number;
};

async function run<T>(
  graphql: GraphqlFn,
  query: string,
  variables?: Record<string, unknown>,
): Promise<T> {
  const res = await graphql(query, { variables });
  const json = (await res.json()) as { data?: T; errors?: unknown };
  if (json.errors || !json.data) {
    throw new Error(`Shopify API error: ${JSON.stringify(json.errors)}`);
  }
  return json.data;
}

export async function listPriceLists(
  graphql: GraphqlFn,
): Promise<PriceListOption[]> {
  const data = await run<{
    priceLists: {
      nodes: Array<{
        id: string;
        name: string;
        currency: string;
        fixedPricesCount: number;
        catalog: { title: string; status: string } | null;
        parent: { adjustment: { type: string; value: number } } | null;
      }>;
    };
  }>(
    graphql,
    `#graphql
    query PriceLists {
      priceLists(first: 50) {
        nodes {
          id
          name
          currency
          fixedPricesCount
          catalog { title status }
          parent { adjustment { type value } }
        }
      }
    }`,
  );
  return data.priceLists.nodes.map((p) => ({
    id: p.id,
    name: p.name,
    currency: p.currency,
    fixedPricesCount: p.fixedPricesCount,
    catalogTitle: p.catalog?.title ?? null,
    catalogStatus: p.catalog?.status ?? null,
    adjustment: p.parent
      ? `${p.parent.adjustment.type === "PERCENTAGE_DECREASE" ? "-" : "+"}${p.parent.adjustment.value}%`
      : null,
  }));
}

const toNum = (v: string | null | undefined) =>
  v === null || v === undefined || v === "" ? null : Number(v);

/**
 * Ambil harga reseller saat ini untuk SEMUA varian di price list
 * (harga tetap/FIXED maupun hasil diskon %/RELATIVE).
 */
export async function loadPriceListPrices(
  graphql: GraphqlFn,
  priceListId: string,
): Promise<{ currency: string; variants: VariantPrice[] }> {
  const variants: VariantPrice[] = [];
  let after: string | null = null;
  let currency = "IDR";
  for (let page = 0; page < 200; page++) {
    const data: {
      priceList: {
        currency: string;
        prices: {
          pageInfo: { hasNextPage: boolean; endCursor: string | null };
          nodes: Array<{
            originType: "FIXED" | "RELATIVE";
            price: { amount: string };
            compareAtPrice: { amount: string } | null;
            variant: {
              id: string;
              sku: string | null;
              title: string;
              price: string;
              compareAtPrice: string | null;
              product: { title: string };
            };
          }>;
        };
      } | null;
    } = await run(
      graphql,
      `#graphql
      query PriceListPrices($id: ID!, $after: String) {
        priceList(id: $id) {
          currency
          prices(first: 250, after: $after) {
            pageInfo { hasNextPage endCursor }
            nodes {
              originType
              price { amount }
              compareAtPrice { amount }
              variant {
                id
                sku
                title
                price
                compareAtPrice
                product { title }
              }
            }
          }
        }
      }`,
      { id: priceListId, after },
    );
    if (!data.priceList) throw new Error("Price list tidak ditemukan.");
    currency = data.priceList.currency;
    for (const n of data.priceList.prices.nodes) {
      variants.push({
        variantId: n.variant.id,
        sku: n.variant.sku ?? "",
        productTitle: n.variant.product.title,
        variantTitle: n.variant.title,
        basePrice: Number(n.variant.price),
        baseCompareAt: toNum(n.variant.compareAtPrice),
        currentPrice: Number(n.price.amount),
        currentCompareAt: toNum(n.compareAtPrice?.amount),
        origin: n.originType,
      });
    }
    const { hasNextPage, endCursor } = data.priceList.prices.pageInfo;
    if (!hasNextPage) break;
    after = endCursor;
  }
  return { currency, variants };
}

export type ApplyInput = {
  variantId: string;
  price: number;
  compareAtPrice: number | null;
};

export type ApplyResult = {
  updated: number;
  errors: Array<{ variantId?: string; message: string }>;
};

/** Simpan harga sebagai harga tetap (fixed price) di price list. */
export async function applyFixedPrices(
  graphql: GraphqlFn,
  priceListId: string,
  currency: string,
  items: ApplyInput[],
): Promise<ApplyResult> {
  const result: ApplyResult = { updated: 0, errors: [] };
  const money = (n: number) => ({ amount: n.toFixed(2), currencyCode: currency });

  for (const batch of chunk(items, 100)) {
    const data = await run<{
      priceListFixedPricesAdd: {
        prices: Array<{ variant: { id: string } }> | null;
        userErrors: Array<{ field: string[] | null; message: string }>;
      };
    }>(
      graphql,
      `#graphql
      mutation AddFixedPrices($priceListId: ID!, $prices: [PriceListPriceInput!]!) {
        priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {
          prices { variant { id } }
          userErrors { field message }
        }
      }`,
      {
        priceListId,
        prices: batch.map((i) => ({
          variantId: i.variantId,
          price: money(i.price),
          ...(i.compareAtPrice !== null
            ? { compareAtPrice: money(i.compareAtPrice) }
            : {}),
        })),
      },
    );
    const payload = data.priceListFixedPricesAdd;
    result.updated += payload.prices?.length ?? 0;
    for (const e of payload.userErrors) {
      // field contoh: ["prices", "3", "price"] → varian ke-3 di batch ini.
      const idx = e.field?.[0] === "prices" ? Number(e.field[1]) : NaN;
      result.errors.push({
        variantId: Number.isInteger(idx) ? batch[idx]?.variantId : undefined,
        message: e.message,
      });
    }
  }
  return result;
}
