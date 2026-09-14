"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Merchant } from "@/lib/types";
import { RestrictionForm, RestrictionList } from "@/lib/compliance";
import { Button, Checkbox, Field, Input, Table, Td, Textarea, Th } from "@/lib/ui";

const emptyPolicy = {
  ppcAllowed: true,
  brandBiddingAllowed: false,
  brandPlusModifierAllowed: false,
  couponBiddingAllowed: false,
  directLinkingAllowed: false,
  trademarkNotes: "",
  adRestrictions: "",
  landingPageRestrictions: "",
  policyNotes: "",
};

export default function MerchantsPage() {
  const [items, setItems] = useState<Merchant[]>([]);
  const [selected, setSelected] = useState<string>("");
  const [name, setName] = useState("");
  const [website, setWebsite] = useState("");
  const [policy, setPolicy] = useState(emptyPolicy);

  async function load() {
    const list = await api<Merchant[]>("/merchants");
    setItems(list);
    const current = list.find((m) => m.id === selected) ?? list[0];
    if (current) {
      setSelected(current.id);
      setPolicy({
        ppcAllowed: current.ppcAllowed,
        brandBiddingAllowed: current.brandBiddingAllowed,
        brandPlusModifierAllowed: current.brandPlusModifierAllowed,
        couponBiddingAllowed: current.couponBiddingAllowed,
        directLinkingAllowed: current.directLinkingAllowed,
        trademarkNotes: current.trademarkNotes ?? "",
        adRestrictions: current.adRestrictions ?? "",
        landingPageRestrictions: current.landingPageRestrictions ?? "",
        policyNotes: current.policyNotes ?? "",
      });
    }
  }
  useEffect(() => { load().catch(console.error); }, []);

  const merchant = items.find((m) => m.id === selected);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    await api("/merchants", { method: "POST", body: JSON.stringify({ name, website, ...emptyPolicy }) });
    setName(""); setWebsite("");
    await load();
  }

  async function savePolicy() {
    if (!merchant) return;
    await api(`/merchants/${merchant.id}`, {
      method: "PUT",
      body: JSON.stringify({
        name: merchant.name,
        website: merchant.website,
        country: merchant.country,
        ...policy,
      }),
    });
    await load();
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">Merchants</h1>
      <form onSubmit={onSubmit} className="flex gap-3">
        <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} required /></Field>
        <Field label="Website"><Input value={website} onChange={(e) => setWebsite(e.target.value)} /></Field>
        <div className="flex items-end"><Button type="submit">Add</Button></div>
      </form>
      <Table>
        <thead><tr><Th>Name</Th><Th>PPC</Th><Th>Brand</Th><Th>Coupon</Th><Th>Direct link</Th></tr></thead>
        <tbody>
          {items.map((m) => (
            <tr key={m.id} onClick={() => { setSelected(m.id); setPolicy({
              ppcAllowed: m.ppcAllowed, brandBiddingAllowed: m.brandBiddingAllowed, brandPlusModifierAllowed: m.brandPlusModifierAllowed,
              couponBiddingAllowed: m.couponBiddingAllowed, directLinkingAllowed: m.directLinkingAllowed,
              trademarkNotes: m.trademarkNotes ?? "", adRestrictions: m.adRestrictions ?? "",
              landingPageRestrictions: m.landingPageRestrictions ?? "", policyNotes: m.policyNotes ?? "",
            }); }} className={selected === m.id ? "bg-zinc-900" : "cursor-pointer"}>
              <Td>{m.name}</Td>
              <Td>{m.ppcAllowed ? "Yes" : "No"}</Td>
              <Td>{m.brandBiddingAllowed ? "Yes" : "No"}</Td>
              <Td>{m.couponBiddingAllowed ? "Yes" : "No"}</Td>
              <Td>{m.directLinkingAllowed ? "Yes" : "No"}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {merchant ? (
        <section className="space-y-4 rounded border border-zinc-800 p-4">
          <h2 className="text-lg font-medium">Compliance — {merchant.name}</h2>
          <p className="text-sm text-zinc-500">Brand, coupon, and direct-link bidding default off. Opt in only after the affiliate TOS allows it.</p>
          <div className="grid gap-2 md:grid-cols-2">
            <Checkbox label="PPC allowed" checked={policy.ppcAllowed} onChange={(e) => setPolicy({ ...policy, ppcAllowed: e.target.checked })} />
            <Checkbox label="Brand bidding allowed" checked={policy.brandBiddingAllowed} onChange={(e) => setPolicy({ ...policy, brandBiddingAllowed: e.target.checked })} />
            <Checkbox label="Brand + modifier allowed" checked={policy.brandPlusModifierAllowed} onChange={(e) => setPolicy({ ...policy, brandPlusModifierAllowed: e.target.checked })} />
            <Checkbox label="Coupon bidding allowed" checked={policy.couponBiddingAllowed} onChange={(e) => setPolicy({ ...policy, couponBiddingAllowed: e.target.checked })} />
            <Checkbox label="Direct linking allowed" checked={policy.directLinkingAllowed} onChange={(e) => setPolicy({ ...policy, directLinkingAllowed: e.target.checked })} />
          </div>
          <Field label="Trademark notes"><Textarea rows={2} value={policy.trademarkNotes} onChange={(e) => setPolicy({ ...policy, trademarkNotes: e.target.value })} /></Field>
          <Field label="Ad restrictions"><Textarea rows={2} value={policy.adRestrictions} onChange={(e) => setPolicy({ ...policy, adRestrictions: e.target.value })} /></Field>
          <Field label="Landing page restrictions"><Textarea rows={2} value={policy.landingPageRestrictions} onChange={(e) => setPolicy({ ...policy, landingPageRestrictions: e.target.value })} /></Field>
          <Field label="Policy notes"><Textarea rows={2} value={policy.policyNotes} onChange={(e) => setPolicy({ ...policy, policyNotes: e.target.value })} /></Field>
          <Button type="button" onClick={savePolicy}>Save policy</Button>
          <h3 className="pt-2 font-medium">Restriction evidence</h3>
          <RestrictionList items={merchant.restrictions ?? []} onChanged={load} />
          <RestrictionForm path={`/merchants/${merchant.id}/restrictions`} onSaved={load} />
        </section>
      ) : null}
    </div>
  );
}
