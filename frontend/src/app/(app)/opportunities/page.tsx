"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Opportunity } from "@/lib/types";
import { Button, money, PageHeader, Table, Td, Th } from "@/lib/ui";

export default function OpportunitiesPage() {
  const [items, setItems] = useState<Opportunity[]>([]);
  const [openWhy, setOpenWhy] = useState<string | null>(null);
  const [analysis, setAnalysis] = useState<Record<string, string>>({});

  useEffect(() => {
    api<Opportunity[]>("/opportunities").then(setItems).catch(console.error);
  }, []);

  async function explain(id: string) {
    const result = await api<{ result: string }>(`/ai/analyze`, {
      method: "POST",
      body: JSON.stringify({ subjectType: "opportunity", subjectId: id, kind: "explain" }),
    });
    setAnalysis((a) => ({ ...a, [id]: result.result }));
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Opportunities"
        summary="Commercial value is not enough. TEST requires viability, verified compliance, and enough search volume."
        purpose="Rank keyword × offer combinations so you only buy traffic that can pay back in CAD$."
        does="Review Test / Investigate / Skip, profit range, and compliance. Open Why to see the scoring rationale before you launch a campaign."
        flow="After an offer exists and keywords are generated. Pick a Test row, then build a landing page and campaign for that keyword."
      />
      <Table>
        <thead>
          <tr>
            <Th>Rec</Th><Th>Product</Th><Th>Keyword</Th><Th>Commercial</Th><Th>Viability</Th>
            <Th>Compliance</Th><Th>Conf</Th><Th>Profit range</Th><Th></Th>
          </tr>
        </thead>
        <tbody>
          {items.map((o) => (
            <tr key={o.id} className={o.recommendation === "Skip" ? "opacity-70" : ""}>
              <Td className={o.recommendation === "Test" ? "text-emerald-400" : o.recommendation === "Skip" ? "text-red-400" : "text-amber-300"}>{o.recommendation}</Td>
              <Td>{o.productName}</Td>
              <Td>
                {o.keyword}
                {o.blockedReason ? <div className="text-xs text-red-400">{o.blockedReason}</div> : null}
              </Td>
              <Td>{o.commercialScore}</Td>
              <Td>{o.viabilityScore}</Td>
              <Td>{o.complianceScore}</Td>
              <Td>{o.confidence}%</Td>
              <Td className="text-xs">
                {money(o.expectedProfitConservative)} → {money(o.expectedProfitOptimistic)}
                <div className={o.expectedProfitBase < 0 ? "text-red-400" : "text-emerald-400"}>base {money(o.expectedProfitBase)}</div>
              </Td>
              <Td>
                <Button type="button" onClick={() => setOpenWhy(openWhy === o.id ? null : o.id)}>WHY</Button>
                <Button type="button" className="ml-2" onClick={() => explain(o.id)}>AI</Button>
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {items.filter((o) => openWhy === o.id).map((o) => (
        <pre key={o.id} className="whitespace-pre-wrap rounded border border-zinc-800 bg-zinc-900 p-4 text-sm text-zinc-300">{o.why}</pre>
      ))}
      {Object.entries(analysis).map(([id, text]) => (
        <pre key={id} className="whitespace-pre-wrap rounded border border-zinc-800 bg-zinc-900 p-4 text-sm text-zinc-300">{text}</pre>
      ))}
    </div>
  );
}
