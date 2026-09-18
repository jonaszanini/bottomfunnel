"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { StoreConnection, StoreProvider } from "@/lib/types";
import { Button, Field, Input, Table, Td, Th, PageHeader } from "@/lib/ui";

export default function NetworksPage() {
  const [providers, setProviders] = useState<StoreProvider[]>([]);
  const [connections, setConnections] = useState<StoreConnection[]>([]);
  const [selected, setSelected] = useState<StoreProvider | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [message, setMessage] = useState("");

  async function load() {
    const [p, c] = await Promise.all([
      api<StoreProvider[]>("/store-connections/providers"),
      api<StoreConnection[]>("/store-connections"),
    ]);
    setProviders(p);
    setConnections(c);
    if (!selected && p[0]) setSelected(p[0]);
  }
  useEffect(() => { load().catch(console.error); }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!selected) return;
    setMessage("");
    try {
      await api("/store-connections", {
        method: "POST",
        body: JSON.stringify({ providerKey: selected.providerKey, displayName: selected.displayName, credentials: values }),
      });
      setMessage("Connected.");
      await load();
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Failed");
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Networks"
        summary="Credential and postback setup for ClickBank and Digistore24. Marketplace discovery is not done here."
        purpose="Enter Affiliate ID to auto-generate hop links, API key to authenticate marketplace discovery, and IPN / webhook passphrase to verify incoming sale postbacks."
        does="Save those credentials. Digistore24 marketplace sync runs from Offers → Discovery Inbox and uses the API key saved here."
        flow="Connect a network, then open Offers → Discovery Inbox to sync the Digistore24 marketplace, or create an offer by hand."
      />
      <div className="flex gap-2">
        {providers.map((p) => (
          <button
            key={p.providerKey}
            type="button"
            onClick={() => { setSelected(p); setValues({}); }}
            className={`rounded px-3 py-1.5 text-sm ${selected?.providerKey === p.providerKey ? "bg-emerald-500 text-zinc-950" : "bg-zinc-800"}`}
          >
            {p.displayName}
          </button>
        ))}
      </div>
      {selected ? (
        <form onSubmit={onSubmit} className="max-w-lg space-y-3 rounded border border-zinc-800 p-4">
          {selected.credentialSchema.map((field) => (
            <Field key={field.key} label={`${field.label}${field.required ? " *" : ""}`}>
              <Input
                type={field.secret ? "password" : "text"}
                placeholder={field.placeholder}
                value={values[field.key] ?? ""}
                onChange={(e) => setValues({ ...values, [field.key]: e.target.value })}
                required={field.required}
              />
            </Field>
          ))}
          {selected.credentialSchema.length === 0 ? <p className="text-sm text-zinc-500">No credentials required for manual URLs.</p> : null}
          <Button type="submit">Save connection</Button>
        </form>
      ) : null}
      {message ? <p className="text-sm text-zinc-400">{message}</p> : null}
      <Table>
        <thead><tr><Th>Provider</Th><Th>Status</Th></tr></thead>
        <tbody>
          {connections.map((c) => (
            <tr key={c.id}>
              <Td>{c.displayName}</Td>
              <Td>{c.status}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
