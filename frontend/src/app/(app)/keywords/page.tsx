"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Keyword, Product } from "@/lib/types";
import { money, Select, Table, Td, Th, PageHeader } from "@/lib/ui";

export default function KeywordsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [productId, setProductId] = useState("");
  const [items, setItems] = useState<Keyword[]>([]);

  useEffect(() => {
    api<Product[]>("/products").then((p) => {
      setProducts(p);
      if (p[0]) setProductId(p[0].id);
    });
  }, []);

  useEffect(() => {
    if (!productId) return;
    api<Keyword[]>(`/keywords?productId=${productId}`).then(setItems).catch(console.error);
  }, [productId]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Keywords"
        summary="Bottom-of-funnel phrases generated from the product name and the offer's compliance policy."
        purpose="See which search terms you can buy, which are blocked, and the default CPC/intent used for scoring."
        does="Pick a product, review phrases, blocked reasons, volume risk, and compliance. Metrics here feed opportunity scores."
        flow="After an offer exists and you generate keywords (from Offers or Products). Next stop: Opportunities."
      />
      <Select value={productId} onChange={(e) => setProductId(e.target.value)} className="max-w-sm">
        {products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
      </Select>
      <Table>
        <thead><tr><Th>Phrase</Th><Th>Template</Th><Th>Intent</Th><Th>Volume risk</Th><Th>Compliance</Th><Th>CPC</Th></tr></thead>
        <tbody>
          {items.map((k) => (
            <tr key={k.id} className={k.blockedReason ? "opacity-60" : ""}>
              <Td>
                {k.phrase}
                {k.blockedReason ? <div className="text-xs text-red-400">{k.blockedReason}</div> : null}
              </Td>
              <Td>{k.template}</Td>
              <Td>{k.intent} ({k.intentScore})</Td>
              <Td>{k.lowSearchVolumeRisk}{k.searchVolume != null ? ` · ${k.searchVolume}` : ""}</Td>
              <Td>{k.complianceStatus}</Td>
              <Td>{k.estimatedCpc != null ? money(k.estimatedCpc) : "—"}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}
