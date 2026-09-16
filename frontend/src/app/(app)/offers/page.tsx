"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Offer, Product, Merchant } from "@/lib/types";
import { RestrictionForm, RestrictionList } from "@/lib/compliance";
import { Button, Checkbox, Field, Input, Select, Table, Td, Textarea, Th, money, PageHeader } from "@/lib/ui";

const CATEGORIES = ["Health", "Software", "Education", "Finance", "Home", "Fitness", "Business", "Other"];

type UnifiedCreated = {
  offerId: string;
  productId: string;
  merchantId: string;
  payoutAmount: number;
  payoutAmountCad: number;
  exchangeRateUsed: number;
  currency: string;
};

const emptyForm = {
  productName: "",
  category: "Health",
  merchantName: "",
  brandBiddingAllowed: false,
  directLinkingAllowed: false,
  payoutAmount: "75",
  currency: "USD",
  exchangeRateOverride: "",
  overrideRate: false,
  commissionType: "OneTime",
  affiliateUrl: "",
};

export default function OffersPage() {
  const [offers, setOffers] = useState<Offer[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [merchants, setMerchants] = useState<Merchant[]>([]);
  const [selected, setSelected] = useState("");
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [rate, setRate] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [created, setCreated] = useState<UnifiedCreated | null>(null);
  const [generating, setGenerating] = useState(false);
  const [override, setOverride] = useState({
    use: false, ppcAllowed: true, brandBiddingAllowed: false, brandPlusModifierAllowed: false,
    couponBiddingAllowed: false, directLinkingAllowed: false, policyNotes: "",
  });

  async function load() {
    const [o, p, m] = await Promise.all([
      api<Offer[]>("/offers"),
      api<Product[]>("/products"),
      api<Merchant[]>("/merchants"),
    ]);
    setOffers(o); setProducts(p); setMerchants(m);
    if (!selected && o[0]) setSelected(o[0].id);
  }
  useEffect(() => { load().catch(console.error); }, []);

  useEffect(() => {
    if (!open || form.currency === "CAD") {
      setRate(1);
      return;
    }
    api<{ from: string; to: string; rate: number }>(`/api/currency/rate?from=${form.currency}&to=CAD`)
      .then((r) => setRate(r.rate))
      .catch(() => setRate(1.38));
  }, [open, form.currency]);

  const offer = offers.find((x) => x.id === selected);
  const categories = useMemo(() => Array.from(new Set([...CATEGORIES, ...products.map((p) => p.category).filter(Boolean) as string[]])), [products]);
  const effectiveRate = form.overrideRate && Number(form.exchangeRateOverride) > 0 ? Number(form.exchangeRateOverride) : (rate ?? 1.38);
  const convertedCad = Number(form.payoutAmount || 0) * (form.currency === "CAD" ? 1 : effectiveRate);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    try {
      const result = await api<UnifiedCreated>("/offers/unified", {
        method: "POST",
        body: JSON.stringify({
          productName: form.productName,
          category: form.category,
          merchantName: form.merchantName,
          brandBiddingAllowed: form.brandBiddingAllowed,
          directLinkingAllowed: form.directLinkingAllowed,
          payoutAmount: Number(form.payoutAmount),
          currency: form.currency,
          exchangeRateOverride: form.overrideRate && Number(form.exchangeRateOverride) > 0 ? Number(form.exchangeRateOverride) : null,
          commissionType: form.commissionType,
          affiliateUrl: form.affiliateUrl || null,
        }),
      });
      setCreated(result);
      await load();
      setSelected(result.offerId);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not create offer");
    }
  }

  async function generateKeywords() {
    if (!created) return;
    setGenerating(true);
    setError("");
    try {
      await api(`/products/${created.productId}/keywords/generate`, { method: "POST" });
      closeModal();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Keyword generation failed");
    } finally {
      setGenerating(false);
    }
  }

  function closeModal() {
    setOpen(false);
    setCreated(null);
    setError("");
    setForm(emptyForm);
  }

  function onMerchantName(name: string) {
    setForm((f) => {
      const match = merchants.find((m) => m.name.toLowerCase() === name.toLowerCase());
      return match
        ? { ...f, merchantName: name, brandBiddingAllowed: match.brandBiddingAllowed, directLinkingAllowed: match.directLinkingAllowed }
        : { ...f, merchantName: name };
    });
  }

  async function saveOverride() {
    if (!offer) return;
    await api(`/offers/${offer.id}`, {
      method: "PUT",
      body: JSON.stringify({
        productId: offer.productId,
        merchantId: offer.merchantId ?? null,
        name: offer.name,
        affiliateUrl: offer.affiliateUrl,
        commission: offer.commission,
        commissionType: offer.commissionType,
        recurringCommission: offer.recurringCommission,
        averageOrderValue: offer.averageOrderValue,
        currency: offer.currency,
        country: offer.country,
        category: offer.category,
        status: offer.status,
        ppcAllowed: override.use ? override.ppcAllowed : null,
        brandBiddingAllowed: override.use ? override.brandBiddingAllowed : null,
        brandPlusModifierAllowed: override.use ? override.brandPlusModifierAllowed : null,
        couponBiddingAllowed: override.use ? override.couponBiddingAllowed : null,
        directLinkingAllowed: override.use ? override.directLinkingAllowed : null,
        policyNotes: override.policyNotes || null,
      }),
    });
    await load();
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Offers"
        summary="Create product, merchant, and payout in one step. Economics are scored in CAD$."
        purpose="This is the main onboarding screen: one affiliate offer you might promote, with payout converted to CAD$."
        does="Click New Offer to add product name, merchant, payout, currency, and hop link. After save, generate keywords and score without leaving the page."
        flow="Start here (or after connecting a network). Offers feed keywords, opportunities, landing pages, and campaigns."
        actions={<Button type="button" onClick={() => { setCreated(null); setOpen(true); }}>New Offer</Button>}
      />
      <Table>
        <thead><tr><Th>Offer</Th><Th>Product</Th><Th>Merchant</Th><Th>Payout</Th><Th>CAD$</Th><Th>Compliance</Th><Th>Status</Th></tr></thead>
        <tbody>
          {offers.map((o) => (
            <tr key={o.id} onClick={() => setSelected(o.id)} className={selected === o.id ? "bg-zinc-900" : "cursor-pointer"}>
              <Td>{o.name}</Td>
              <Td>{o.productName}</Td>
              <Td>{o.merchantName ?? "—"}</Td>
              <Td>{money(o.commission, o.currency || "USD")}</Td>
              <Td>{money(o.payoutAmountCad, "CAD")}</Td>
              <Td className={o.overallCompliance === "Prohibited" ? "text-red-400" : ""}>{o.overallCompliance}</Td>
              <Td>{o.status}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {offer ? (
        <section className="space-y-4 rounded border border-zinc-800 p-4">
          <h2 className="text-lg font-medium">Compliance — {offer.name}</h2>
          <p className="text-sm text-zinc-400">
            Resolved policy: PPC {yesNo(offer.resolvedPolicy.ppcAllowed)}, brand {yesNo(offer.resolvedPolicy.brandBiddingAllowed)},
            coupon {yesNo(offer.resolvedPolicy.couponBiddingAllowed)}, direct link {yesNo(offer.resolvedPolicy.directLinkingAllowed)}.
            Keyword generation uses this after the TOS check — not before.
          </p>
          <Checkbox
            label="Override merchant policy on this offer"
            checked={override.use}
            onChange={(e) => setOverride({ ...override, use: e.target.checked })}
          />
          {override.use ? (
            <div className="grid gap-2 md:grid-cols-2">
              <Checkbox label="PPC allowed" checked={override.ppcAllowed} onChange={(e) => setOverride({ ...override, ppcAllowed: e.target.checked })} />
              <Checkbox label="Brand bidding" checked={override.brandBiddingAllowed} onChange={(e) => setOverride({ ...override, brandBiddingAllowed: e.target.checked })} />
              <Checkbox label="Brand + modifier" checked={override.brandPlusModifierAllowed} onChange={(e) => setOverride({ ...override, brandPlusModifierAllowed: e.target.checked })} />
              <Checkbox label="Coupon bidding" checked={override.couponBiddingAllowed} onChange={(e) => setOverride({ ...override, couponBiddingAllowed: e.target.checked })} />
              <Checkbox label="Direct linking" checked={override.directLinkingAllowed} onChange={(e) => setOverride({ ...override, directLinkingAllowed: e.target.checked })} />
            </div>
          ) : null}
          <Field label="Policy notes"><Textarea rows={2} value={override.policyNotes} onChange={(e) => setOverride({ ...override, policyNotes: e.target.value })} /></Field>
          <Button type="button" onClick={saveOverride}>Save offer policy</Button>
          <RestrictionList items={offer.restrictions ?? []} onChanged={load} />
          <RestrictionForm path={`/offers/${offer.id}/restrictions`} onSaved={load} />
        </section>
      ) : null}

      {open ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
          <div className="max-h-[90vh] w-full max-w-lg overflow-auto rounded border border-zinc-800 bg-zinc-950 p-6">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-semibold">{created ? "Offer created" : "New Offer"}</h2>
              <button type="button" className="text-sm text-zinc-500 hover:text-zinc-300" onClick={closeModal}>Close</button>
            </div>
            {created ? (
              <div className="space-y-4">
                <p className="text-sm text-zinc-300">
                  Payout {money(created.payoutAmount, created.currency)} → <span className="text-emerald-400">{money(created.payoutAmountCad, "CAD")}</span>
                  {" "}at {created.exchangeRateUsed.toFixed(4)}.
                </p>
                {error ? <p className="text-sm text-red-400">{error}</p> : null}
                <Button type="button" onClick={generateKeywords} disabled={generating}>
                  {generating ? "Generating…" : "Generate Keywords & Score"}
                </Button>
              </div>
            ) : (
              <form onSubmit={onSubmit} className="space-y-4">
                <div className="grid gap-3 md:grid-cols-2">
                  <Field label="Product name">
                    <Input list="product-names" value={form.productName} onChange={(e) => setForm({ ...form, productName: e.target.value })} required />
                    <datalist id="product-names">{products.map((p) => <option key={p.id} value={p.name} />)}</datalist>
                  </Field>
                  <Field label="Category">
                    <Input list="categories" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
                    <datalist id="categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                  </Field>
                </div>
                <Field label="Merchant name">
                  <Input list="merchant-names" value={form.merchantName} onChange={(e) => onMerchantName(e.target.value)} required />
                  <datalist id="merchant-names">{merchants.map((m) => <option key={m.id} value={m.name} />)}</datalist>
                </Field>
                <div className="flex flex-wrap gap-4">
                  <Checkbox label="Brand bidding allowed" checked={form.brandBiddingAllowed} onChange={(e) => setForm({ ...form, brandBiddingAllowed: e.target.checked })} />
                  <Checkbox label="Direct linking allowed" checked={form.directLinkingAllowed} onChange={(e) => setForm({ ...form, directLinkingAllowed: e.target.checked })} />
                </div>
                <div className="grid gap-3 md:grid-cols-3">
                  <Field label="Payout amount">
                    <Input type="number" step="0.01" min="0" value={form.payoutAmount} onChange={(e) => setForm({ ...form, payoutAmount: e.target.value })} required />
                  </Field>
                  <Field label="Currency">
                    <Select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                      <option value="USD">USD</option>
                      <option value="CAD">CAD</option>
                      <option value="EUR">EUR</option>
                    </Select>
                  </Field>
                  <Field label="Commission type">
                    <Select value={form.commissionType} onChange={(e) => setForm({ ...form, commissionType: e.target.value })}>
                      <option value="OneTime">OneTime</option>
                      <option value="Recurring">Recurring</option>
                    </Select>
                  </Field>
                </div>
                {form.currency !== "CAD" ? (
                  <div className="rounded border border-zinc-800 bg-zinc-900 px-3 py-2 text-sm text-emerald-400">
                    ≈ CAD$ {convertedCad.toFixed(2)} (Rate: {effectiveRate.toFixed(4)})
                  </div>
                ) : null}
                {form.currency !== "CAD" ? (
                  <div className="space-y-2">
                    <Checkbox label="Override exchange rate" checked={form.overrideRate} onChange={(e) => setForm({ ...form, overrideRate: e.target.checked })} />
                    {form.overrideRate ? (
                      <Field label="CAD per 1 unit of payout currency">
                        <Input type="number" step="0.0001" min="0" value={form.exchangeRateOverride} onChange={(e) => setForm({ ...form, exchangeRateOverride: e.target.value })} />
                      </Field>
                    ) : null}
                  </div>
                ) : null}
                <Field label="Affiliate hop link / tracking URL">
                  <Input value={form.affiliateUrl} onChange={(e) => setForm({ ...form, affiliateUrl: e.target.value })} placeholder="https://vendor.jzanini.hop.clickbank.net/" />
                </Field>
                {error ? <p className="text-sm text-red-400">{error}</p> : null}
                <div className="flex justify-end gap-2">
                  <button type="button" className="rounded px-3 py-1.5 text-sm text-zinc-400 hover:text-zinc-200" onClick={closeModal}>Cancel</button>
                  <Button type="submit">Create offer</Button>
                </div>
              </form>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function yesNo(v: boolean) {
  return v ? "yes" : "no";
}
