"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Campaign } from "@/lib/types";
import { Button, Field, Input, Select, Table, Td, Th, money, PageHeader } from "@/lib/ui";

type Click = { id: string; campaignId: string; trackingCode: string; destinationUrl: string; occurredAt: string };
type Conversion = { id: string; clickId?: string; campaignId?: string; providerKey: string; eventType: string; provenance?: string; amount: number; commission: number; occurredAt: string };

export default function TrackingPage() {
  const [clicks, setClicks] = useState<Click[]>([]);
  const [conversions, setConversions] = useState<Conversion[]>([]);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [cost, setCost] = useState({ campaignId: "", amount: "" });
  const [conv, setConv] = useState({ campaignId: "", commission: "", amount: "" });

  async function load() {
    const [cl, co, ca] = await Promise.all([
      api<Click[]>("/tracking/clicks"),
      api<Conversion[]>("/tracking/conversions"),
      api<Campaign[]>("/campaigns"),
    ]);
    setClicks(cl); setConversions(co); setCampaigns(ca);
    if (!cost.campaignId && ca[0]) {
      setCost((c) => ({ ...c, campaignId: ca[0].id }));
      setConv((c) => ({ ...c, campaignId: ca[0].id }));
    }
  }
  useEffect(() => { load().catch(console.error); }, []);

  async function addCost(e: FormEvent) {
    e.preventDefault();
    await api("/tracking/costs", { method: "POST", body: JSON.stringify({ campaignId: cost.campaignId, amount: Number(cost.amount), currency: "USD" }) });
    await load();
  }
  async function addConversion(e: FormEvent) {
    e.preventDefault();
    await api("/tracking/conversions", {
      method: "POST",
      body: JSON.stringify({ campaignId: conv.campaignId, commission: Number(conv.commission), amount: Number(conv.amount || conv.commission), currency: "USD", eventType: "Sale" }),
    });
    await load();
  }

  return (
    <div className="space-y-8">
      <PageHeader
        title="Tracking"
        summary="Clicks, conversions, and ad spend for live campaigns — the evidence behind dashboard ROAS."
        purpose="Record what happened after traffic ran: hops, commissions (including ClickBank INS), and what you spent."
        does="Review clicks and conversions, add spend, and enter a manual conversion if a network postback is missing."
        flow="After campaigns are live. This data is what the Dashboard uses for observed vs reported ROAS. Do not skip it if you want kill/scale decisions."
      />
      <div className="grid gap-4 md:grid-cols-2">
        <form onSubmit={addCost} className="space-y-3 rounded border border-zinc-800 p-4">
          <h2 className="font-medium">Add ad spend</h2>
          <Field label="Campaign">
            <Select value={cost.campaignId} onChange={(e) => setCost({ ...cost, campaignId: e.target.value })}>
              {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <Field label="Amount"><Input type="number" step="0.01" value={cost.amount} onChange={(e) => setCost({ ...cost, amount: e.target.value })} /></Field>
          <Button type="submit">Save cost</Button>
        </form>
        <form onSubmit={addConversion} className="space-y-3 rounded border border-zinc-800 p-4">
          <h2 className="font-medium">Add conversion</h2>
          <Field label="Campaign">
            <Select value={conv.campaignId} onChange={(e) => setConv({ ...conv, campaignId: e.target.value })}>
              {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <Field label="Commission"><Input type="number" step="0.01" value={conv.commission} onChange={(e) => setConv({ ...conv, commission: e.target.value })} /></Field>
          <Button type="submit">Save conversion</Button>
        </form>
      </div>
      <section>
        <h2 className="mb-3 font-medium">Clicks</h2>
        <Table>
          <thead><tr><Th>When</Th><Th>Code</Th><Th>Destination</Th></tr></thead>
          <tbody>{clicks.map((c) => <tr key={c.id}><Td>{new Date(c.occurredAt).toLocaleString()}</Td><Td>{c.trackingCode}</Td><Td className="max-w-md truncate">{c.destinationUrl}</Td></tr>)}</tbody>
        </Table>
      </section>
      <section>
        <h2 className="mb-3 font-medium">Conversions</h2>
        <Table>
          <thead><tr><Th>When</Th><Th>Provider</Th><Th>Type</Th><Th>Provenance</Th><Th>Commission</Th></tr></thead>
          <tbody>{conversions.map((c) => <tr key={c.id}><Td>{new Date(c.occurredAt).toLocaleString()}</Td><Td>{c.providerKey}</Td><Td>{c.eventType}</Td><Td>{c.provenance ?? "—"}</Td><Td>{money(c.commission)}</Td></tr>)}</tbody>
        </Table>
      </section>
    </div>
  );
}
