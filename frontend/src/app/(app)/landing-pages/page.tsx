"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { LandingPage, Offer } from "@/lib/types";
import { Button, Field, Input, Select, Table, Td, Textarea, Th } from "@/lib/ui";

export default function LandingPagesPage() {
  const [items, setItems] = useState<LandingPage[]>([]);
  const [offers, setOffers] = useState<Offer[]>([]);
  const [error, setError] = useState("");
  const [form, setForm] = useState({
    name: "", slug: "", headline: "", subheadline: "", ctaText: "See current offer", offerId: "",
    benefits: "", body: "", disclosure: "This page contains affiliate links. I may earn a commission if you buy, at no extra cost to you.",
    whoItsFor: "", whoItsNotFor: "", pros: "", cons: "", alternatives: "", comparison: "",
  });

  async function load() {
    const [p, o] = await Promise.all([api<LandingPage[]>("/landing-pages"), api<Offer[]>("/offers")]);
    setItems(p); setOffers(o);
  }
  useEffect(() => { load().catch(console.error); }, []);

  const origin = typeof window !== "undefined" ? window.location.origin : "";

  async function save(isPublished: boolean) {
    setError("");
    await api("/landing-pages", {
      method: "POST",
      body: JSON.stringify({
        name: form.name,
        slug: form.slug,
        headline: form.headline,
        subheadline: form.subheadline || null,
        ctaText: form.ctaText,
        offerId: form.offerId || null,
        benefits: form.benefits.split("\n").filter(Boolean),
        body: form.body,
        disclosure: form.disclosure,
        whoItsFor: form.whoItsFor || null,
        whoItsNotFor: form.whoItsNotFor || null,
        pros: form.pros.split("\n").filter(Boolean),
        cons: form.cons.split("\n").filter(Boolean),
        alternatives: form.alternatives || null,
        comparison: form.comparison || null,
        faqs: [],
        isPublished,
      }),
    });
    await load();
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    try {
      await save(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Save failed");
    }
  }

  async function publish() {
    try {
      await save(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Publish blocked");
    }
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">Landing pages</h1>
      <p className="text-sm text-zinc-500">Google Ads must land here. Publish requires an affiliate disclosure, original evaluation, and at least one non-CTA section.</p>
      {error ? <p className="text-sm text-red-400">{error}</p> : null}
      <form onSubmit={onSubmit} className="grid gap-3 rounded border border-zinc-800 p-4 md:grid-cols-2">
        <Field label="Name"><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
        <Field label="Slug"><Input value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} required /></Field>
        <Field label="Headline"><Input value={form.headline} onChange={(e) => setForm({ ...form, headline: e.target.value })} required /></Field>
        <Field label="CTA"><Input value={form.ctaText} onChange={(e) => setForm({ ...form, ctaText: e.target.value })} /></Field>
        <Field label="Offer">
          <Select value={form.offerId} onChange={(e) => setForm({ ...form, offerId: e.target.value })}>
            <option value="">None</option>
            {offers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </Select>
        </Field>
        <Field label="Subheadline"><Input value={form.subheadline} onChange={(e) => setForm({ ...form, subheadline: e.target.value })} /></Field>
        <div className="md:col-span-2">
          <Field label="Affiliate disclosure"><Textarea rows={2} value={form.disclosure} onChange={(e) => setForm({ ...form, disclosure: e.target.value })} /></Field>
        </div>
        <div className="md:col-span-2">
          <Field label="Original evaluation"><Textarea rows={6} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} /></Field>
        </div>
        <Field label="Who it's for"><Textarea rows={3} value={form.whoItsFor} onChange={(e) => setForm({ ...form, whoItsFor: e.target.value })} /></Field>
        <Field label="Who it's not for"><Textarea rows={3} value={form.whoItsNotFor} onChange={(e) => setForm({ ...form, whoItsNotFor: e.target.value })} /></Field>
        <Field label="Pros (one per line)"><Textarea rows={3} value={form.pros} onChange={(e) => setForm({ ...form, pros: e.target.value })} /></Field>
        <Field label="Cons (one per line)"><Textarea rows={3} value={form.cons} onChange={(e) => setForm({ ...form, cons: e.target.value })} /></Field>
        <Field label="Benefits (one per line)"><Textarea rows={3} value={form.benefits} onChange={(e) => setForm({ ...form, benefits: e.target.value })} /></Field>
        <Field label="Alternatives"><Textarea rows={3} value={form.alternatives} onChange={(e) => setForm({ ...form, alternatives: e.target.value })} /></Field>
        <div className="md:col-span-2">
          <Field label="Pricing / comparison"><Textarea rows={3} value={form.comparison} onChange={(e) => setForm({ ...form, comparison: e.target.value })} /></Field>
        </div>
        <div className="flex gap-3">
          <Button type="submit">Save draft</Button>
          <Button type="button" onClick={publish}>Publish</Button>
        </div>
      </form>
      <Table>
        <thead><tr><Th>Name</Th><Th>Google final URL</Th><Th>Published</Th></tr></thead>
        <tbody>
          {items.map((p) => (
            <tr key={p.id}>
              <Td>{p.name}</Td>
              <Td>
                <a className="text-emerald-400" href={`/p/${p.slug}`} target="_blank">{origin}/p/{p.slug}</a>
              </Td>
              <Td>{p.isPublished ? "Yes" : "Draft"}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
