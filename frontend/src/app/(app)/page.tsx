"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { api } from "@/lib/api";
import { Dashboard } from "@/lib/types";
import { money, PageHeader, Table, Td, Th } from "@/lib/ui";

export default function DashboardPage() {
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api<Dashboard>("/analytics/dashboard").then(setData).catch((e) => setError(e.message));
  }, []);

  if (error) {
    return (
      <div className="space-y-8">
        <DashboardHeader />
        <p className="text-red-400">{error}</p>
      </div>
    );
  }
  if (!data) {
    return (
      <div className="space-y-8">
        <DashboardHeader />
        <p className="text-zinc-500">Loading…</p>
      </div>
    );
  }

  const cards = [
    ["Landing visits", String(data.landingVisits)],
    ["Affiliate clicks", String(data.affiliateClicks)],
    ["Reported commission", money(data.reportedCommission)],
    ["Attributable commission", money(data.attributableCommission)],
    ["Attribution confidence", `${data.attributionConfidencePercent}%`],
    ["Observed → reported ROAS", `${data.observedRoas.toFixed(2)} → ${data.reportedRoas.toFixed(2)}`],
    ["Profit (reported)", money(data.profit)],
    ["ROI (reported)", `${data.roiPercent.toFixed(0)}%`],
  ];

  return (
    <div className="space-y-8">
      <DashboardHeader />
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {cards.map(([label, value]) => (
          <div key={label} className="rounded border border-zinc-800 bg-zinc-900 p-4">
            <div className="text-xs uppercase tracking-wide text-zinc-500">{label}</div>
            <div className="mt-2 text-xl font-semibold">{value}</div>
          </div>
        ))}
      </div>
      <section>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-medium">Top opportunities</h2>
          <Link href="/opportunities" className="text-sm text-emerald-400">All</Link>
        </div>
        <Table>
          <thead><tr><Th>#</Th><Th>Product</Th><Th>Keyword</Th><Th>Commercial</Th><Th>Viability</Th><Th>Range</Th><Th></Th></tr></thead>
          <tbody>
            {data.topOpportunities.map((o, i) => (
              <tr key={o.id}>
                <Td>{i + 1}</Td>
                <Td>{o.productName}</Td>
                <Td>{o.keyword}</Td>
                <Td>{o.commercialScore}</Td>
                <Td>{o.viabilityScore}</Td>
                <Td>{money(o.expectedProfitConservative)} → {money(o.expectedProfitOptimistic)}</Td>
                <Td className="text-emerald-400">{o.recommendation}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </section>
      <section>
        <h2 className="mb-3 text-lg font-medium">Campaigns (ROAS range)</h2>
        <Table>
          <thead><tr><Th>Campaign</Th><Th>Spend</Th><Th>Observed</Th><Th>Reported</Th><Th>Confidence</Th><Th>Kill?</Th></tr></thead>
          <tbody>
            {data.topCampaigns.map((c) => (
              <tr key={c.campaignId}>
                <Td>{c.name}</Td>
                <Td>{money(c.spend)}</Td>
                <Td>{c.observedRoas.toFixed(2)}</Td>
                <Td>{c.reportedRoas.toFixed(2)}</Td>
                <Td>{c.attributionConfidence}%</Td>
                <Td className="text-xs text-zinc-400">{c.insufficientConfidence ? "Do not auto-kill" : "Range only"}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </section>
    </div>
  );
}

function DashboardHeader() {
  return (
    <PageHeader
      title="Dashboard"
      summary="Last-click is not truth. Kill/scale uses the observed → reported range; low confidence does not auto-kill."
      purpose="The cockpit for whether live campaigns are actually making money after tracking noise."
      does="Read visits, affiliate clicks, reported vs attributable commission, ROAS range, and top opportunities. Do not kill a campaign only because last-click looks weak."
      flow="Last step. After offers, keywords, landing pages, campaigns, and tracking have data, this is where you decide to scale or pause."
    />
  );
}
