"use client";

import { FormEvent, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Product } from "@/lib/types";
import { Button, Field, Input, Table, Td, Th, PageHeader } from "@/lib/ui";

export default function ProductsPage() {
  const [items, setItems] = useState<Product[]>([]);
  const [name, setName] = useState("");
  const [category, setCategory] = useState("");

  const [error, setError] = useState("");

  async function load() { setItems(await api<Product[]>("/products")); }
  useEffect(() => { load().catch(console.error); }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    await api("/products", { method: "POST", body: JSON.stringify({ name, category, currency: "USD" }) });
    setName(""); setCategory("");
    await load();
  }

  async function generate(id: string) {
    setError("");
    try {
      await api(`/products/${id}/keywords/generate`, { method: "POST" });
      alert("Keywords generated and scored from offer compliance.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Keyword generation failed");
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Products"
        summary="Keywords are generated only after an active offer exists, using that offer's PPC/brand/coupon policy."
        purpose="The catalog of things you promote. Each product is the parent for keywords and landing pages."
        does="Add or review products, then generate keywords once an active offer is attached. Prefer New Offer on Offers unless you need a product-only record."
        flow="Usually created from Offers. Next: generate keywords, then score opportunities."
      />
      {error ? <p className="text-sm text-red-400">{error}</p> : null}
      <form onSubmit={onSubmit} className="flex gap-3">
        <Field label="Name"><Input value={name} onChange={(e) => setName(e.target.value)} required /></Field>
        <Field label="Category"><Input value={category} onChange={(e) => setCategory(e.target.value)} /></Field>
        <div className="flex items-end"><Button type="submit">Add</Button></div>
      </form>
      <Table>
        <thead><tr><Th>Name</Th><Th>Category</Th><Th>Provider</Th><Th></Th></tr></thead>
        <tbody>
          {items.map((p) => (
            <tr key={p.id}>
              <Td>{p.name}</Td>
              <Td>{p.category}</Td>
              <Td>{p.providerKey ?? "manual"}</Td>
              <Td><Button type="button" onClick={() => generate(p.id)}>Generate keywords</Button></Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
