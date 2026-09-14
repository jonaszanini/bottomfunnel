"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Campaign, Offer, Keyword, LandingPage } from "@/lib/types";
import { Button, Field, Input, Select, Table, Td, Th } from "@/lib/ui";

export default function CampaignsPage() {
  const [items, setItems] = useState<Campaign[]>([]);
  const [offers, setOffers] = useState<Offer[]>([]);
  const [pages, setPages] = useState<LandingPage[]>([]);
  const [name, setName] = useState("");
  const [offerId, setOfferId] = useState("");
  const [landingPageId, setLandingPageId] = useState("");
  const [headline, setHeadline] = useState("");
  const [selected, setSelected] = useState<string>("");
  const [error, setError] = useState("");

  async function load() {
    const [c, o, p] = await Promise.all([
      api<Campaign[]>("/campaigns"),
      api<Offer[]>("/offers"),
      api<LandingPage[]>("/landing-pages"),
    ]);
    setItems(c); setOffers(o); setPages(p);
    if (!offerId && o[0]) setOfferId(o[0].id);
  }
  useEffect(() => { load().catch(console.error); }, []);

  const origin = typeof window !== "undefined" ? window.location.origin : "";

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    const offer = offers.find((x) => x.id === offerId);
    const keywords = offer ? await api<Keyword[]>(`/keywords?productId=${offer.productId}`) : [];
    try {
      await api("/campaigns", {
        method: "POST",
        body: JSON.stringify({
          name,
          offerId,
          productId: offer?.productId,
          landingPageId: landingPageId || null,
          status: "Draft",
          keywordIds: keywords.filter((k) => !k.blockedReason).map((k) => k.id),
        }),
      });
      setName("");
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create campaign");
    }
  }

  async function setLive(campaign: Campaign) {
    setError("");
    try {
      await api(`/campaigns/${campaign.id}`, {
        method: "PUT",
        body: JSON.stringify({
          name: campaign.name,
          offerId: campaign.offerId,
          productId: campaign.productId,
          landingPageId: campaign.landingPageId ?? (landingPageId || null),
          country: campaign.country,
          status: "Live",
          keywordIds: campaign.keywordIds,
        }),
      });
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Cannot go live");
    }
  }

  async function addAd() {
    if (!selected || !headline) return;
    const campaign = items.find((c) => c.id === selected);
    await api(`/campaigns/${selected}/ads`, {
      method: "POST",
      body: JSON.stringify({
        headline,
        isActive: true,
        finalUrl: campaign?.googleFinalUrl ? `${origin}${campaign.googleFinalUrl}` : undefined,
      }),
    });
    setHeadline("");
    await load();
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">Campaigns</h1>
      <p className="text-sm text-zinc-500">Google Ads final URL is the published landing page. The `/t/` hop is only the on-page CTA after the visitor is already on your domain.</p>
      {error ? <p className="text-sm text-red-400">{error}</p> : null}
      <form onSubmit={onSubmit} className="grid gap-3 rounded border border-zinc-800 p-4 md:grid-cols-3">
        <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} required /></Field>
        <Field label="Offer">
          <Select value={offerId} onChange={(e) => setOfferId(e.target.value)}>
            {offers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </Select>
        </Field>
        <Field label="Landing page">
          <Select value={landingPageId} onChange={(e) => setLandingPageId(e.target.value)}>
            <option value="">None (draft only)</option>
            {pages.map((p) => <option key={p.id} value={p.id}>{p.name} {p.isPublished ? "(published)" : "(draft)"}</option>)}
          </Select>
        </Field>
        <div className="flex items-end"><Button type="submit">Create campaign</Button></div>
      </form>
      <Table>
        <thead><tr><Th>Name</Th><Th>Status</Th><Th>Google final URL</Th><Th>CTA hop</Th><Th></Th></tr></thead>
        <tbody>
          {items.map((c) => (
            <tr key={c.id} onClick={() => setSelected(c.id)} className={selected === c.id ? "bg-zinc-900" : ""}>
              <Td>{c.name}</Td>
              <Td>{c.status}</Td>
              <Td>
                {c.googleFinalUrl
                  ? <code className="text-xs text-emerald-400">{origin}{c.googleFinalUrl}</code>
                  : <span className="text-xs text-zinc-500">Attach a published landing page</span>}
              </Td>
              <Td className="text-xs text-zinc-500">On-page only{c.ctaHopUrl ? ` · ${c.ctaHopUrl}` : ""}</Td>
              <Td>
                {c.status !== "Live"
                  ? <Button type="button" onClick={() => setLive(c)}>Set Live</Button>
                  : <span className="text-xs text-emerald-400">Live</span>}
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {selected ? (
        <div className="flex gap-3">
          <Field label="Ad headline"><Input value={headline} onChange={(e) => setHeadline(e.target.value)} /></Field>
          <div className="flex items-end"><Button type="button" onClick={addAd}>Add ad</Button></div>
        </div>
      ) : null}
    </div>
  );
}
