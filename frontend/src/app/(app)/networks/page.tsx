"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { StoreConnection, StoreProvider } from "@/lib/types";
import { Button, Field, Input, Table, Td, Th } from "@/lib/ui";

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

  async function sync(id: string) {
    await api(`/store-connections/${id}/sync`, { method: "POST" });
    setMessage("Sync queued.");
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">Networks</h1>
      <p className="text-sm text-zinc-500">
        Connect your account here (API key + your nickname). Vendor/site nicknames are per product — paste each hop link on Offers.
        ClickBank &quot;Seller site nickname&quot; is only if you sell on ClickBank and want to sync your own catalog.
      </p>
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
          {message ? <p className="text-sm text-zinc-400">{message}</p> : null}
        </form>
      ) : null}
      <Table>
        <thead><tr><Th>Provider</Th><Th>Status</Th><Th>Last sync</Th><Th></Th></tr></thead>
        <tbody>
          {connections.map((c) => (
            <tr key={c.id}>
              <Td>{c.displayName}</Td>
              <Td>{c.status}</Td>
              <Td>{c.lastSyncedAt ? new Date(c.lastSyncedAt).toLocaleString() : "—"}</Td>
              <Td><Button type="button" onClick={() => sync(c.id)}>Sync products</Button></Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
