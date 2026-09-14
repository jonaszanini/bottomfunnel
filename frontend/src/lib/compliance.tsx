"use client";

import { FormEvent, useState } from "react";
import { api } from "@/lib/api";
import { ComplianceRestriction } from "@/lib/types";
import { Button, Field, Input, Select, Textarea } from "@/lib/ui";

const KINDS = ["Ppc", "Brand", "BrandPlusModifier", "Coupon", "DirectLink", "TrademarkInAd", "LandingPage"];
const STATES = ["Unknown", "Researching", "Verified", "Restricted", "Prohibited"];
const CONF = ["Low", "Medium", "High"];

export function RestrictionList({
  items,
  onChanged,
}: {
  items: ComplianceRestriction[];
  onChanged: () => Promise<void>;
}) {
  if (!items.length) return <p className="text-sm text-zinc-500">No restriction records yet.</p>;
  return (
    <ul className="space-y-2 text-sm">
      {items.map((r) => (
        <li key={r.id} className="rounded border border-zinc-800 p-3">
          <div className="flex items-center justify-between gap-3">
            <div>
              <span className="font-medium">{r.kind}</span>{" "}
              <span className={r.state === "Prohibited" ? "text-red-400" : r.state === "Verified" ? "text-emerald-400" : "text-amber-300"}>
                {r.state}
              </span>
              <span className="ml-2 text-zinc-500">{r.confidence}</span>
            </div>
            <button
              type="button"
              className="text-xs text-zinc-500 hover:text-red-400"
              onClick={async () => {
                await api(`/restrictions/${r.id}`, { method: "DELETE" });
                await onChanged();
              }}
            >
              Remove
            </button>
          </div>
          {r.source ? <p className="mt-1 text-zinc-400">Source: {r.source}</p> : null}
          {r.evidence ? <p className="mt-1 text-zinc-500">{r.evidence}</p> : null}
        </li>
      ))}
    </ul>
  );
}

export function RestrictionForm({ path, onSaved }: { path: string; onSaved: () => Promise<void> }) {
  const [kind, setKind] = useState("Brand");
  const [state, setState] = useState("Unknown");
  const [source, setSource] = useState("");
  const [evidence, setEvidence] = useState("");
  const [confidence, setConfidence] = useState("Low");

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    await api(path, {
      method: "POST",
      body: JSON.stringify({ kind, state, source, evidence, confidence }),
    });
    setSource("");
    setEvidence("");
    await onSaved();
  }

  return (
    <form onSubmit={onSubmit} className="grid gap-3 md:grid-cols-2">
      <Field label="Kind">
        <Select value={kind} onChange={(e) => setKind(e.target.value)}>
          {KINDS.map((k) => <option key={k}>{k}</option>)}
        </Select>
      </Field>
      <Field label="State">
        <Select value={state} onChange={(e) => setState(e.target.value)}>
          {STATES.map((s) => <option key={s}>{s}</option>)}
        </Select>
      </Field>
      <Field label="Source"><Input value={source} onChange={(e) => setSource(e.target.value)} placeholder="Affiliate TOS, program page…" /></Field>
      <Field label="Confidence">
        <Select value={confidence} onChange={(e) => setConfidence(e.target.value)}>
          {CONF.map((c) => <option key={c}>{c}</option>)}
        </Select>
      </Field>
      <div className="md:col-span-2">
        <Field label="Evidence"><Textarea rows={3} value={evidence} onChange={(e) => setEvidence(e.target.value)} /></Field>
      </div>
      <div><Button type="submit">Save restriction</Button></div>
    </form>
  );
}
