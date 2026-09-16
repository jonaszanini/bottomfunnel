"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Offer, Product, Merchant } from "@/lib/types";
import { RestrictionForm, RestrictionList } from "@/lib/compliance";
import { Button, Checkbox, Field, Input, Select, Table, Td, Textarea, Th, money } from "@/lib/ui";

export default function OffersPage() {
  const [offers, setOffers] = useState<Offer[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [merchants, setMerchants] = useState<Merchant[]>([]);
  const [selected, setSelected] = useState("");
  const [form, setForm] = useState({ name: "", productId: "", merchantId: "", affiliateUrl: "", commission: "75", currency: "USD" });
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
    if (!form.productId && p[0]) setForm((f) => ({ ...f, productId: p[0].id }));
    if (!selected && o[0]) setSelected(o[0].id);
  }
  useEffect(() => { load().catch(console.error); }, []);

  const offer = offers.find((x) => x.id === selected);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    await api("/offers", {
      method: "POST",
      body: JSON.stringify({
        productId: form.productId,
        merchantId: form.merchantId || null,
        name: form.name,
        affiliateUrl: form.affiliateUrl,
        commission: Number(form.commission),
        commissionType: "OneTime",
        currency: form.currency,
        status: "Active",
      }),
    });
    setForm((f) => ({ ...f, name: "", affiliateUrl: "" }));
    await load();
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
      <h1 className="text-2xl font-semibold">Offers</h1>
      <p className="text-sm text-zinc-500">Each ClickBank product has its own vendor nickname. Paste that hop link here — not on Networks.</p>
      <form onSubmit={onSubmit} className="grid gap-3 rounded border border-zinc-800 p-4 md:grid-cols-3">
        <Field label="Name"><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
        <Field label="Product">
          <Select value={form.productId} onChange={(e) => setForm({ ...form, productId: e.target.value })}>
            {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </Select>
        </Field>
        <Field label="Merchant">
          <Select value={form.merchantId} onChange={(e) => setForm({ ...form, merchantId: e.target.value })}>
            <option value="">None</option>
            {merchants.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </Select>
        </Field>
        <Field label="Affiliate URL">
          <Input value={form.affiliateUrl} onChange={(e) => setForm({ ...form, affiliateUrl: e.target.value })} placeholder="https://vendor.jzanini.hop.clickbank.net/" />
        </Field>
        <Field label="Commission"><Input type="number" step="0.01" value={form.commission} onChange={(e) => setForm({ ...form, commission: e.target.value })} /></Field>
        <div className="flex items-end"><Button type="submit">Add offer</Button></div>
      </form>
      <Table>
        <thead><tr><Th>Offer</Th><Th>Product</Th><Th>Commission</Th><Th>Compliance</Th><Th>Status</Th></tr></thead>
        <tbody>
          {offers.map((o) => (
            <tr key={o.id} onClick={() => setSelected(o.id)} className={selected === o.id ? "bg-zinc-900" : "cursor-pointer"}>
              <Td>{o.name}</Td>
              <Td>{o.productName}</Td>
              <Td>{money(o.commission)}</Td>
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
    </div>
  );
}

function yesNo(v: boolean) {
  return v ? "yes" : "no";
}
