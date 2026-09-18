"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Offer, OfferPolicyAuditResult, Product, Merchant, ProductCandidate, PagedProductCandidates, ClickBankFeedSyncResult, AuditCandidateResponse } from "@/lib/types";
import { RestrictionForm, RestrictionList } from "@/lib/compliance";
import { Button, Checkbox, Field, Input, Select, Table, Td, Textarea, Th, money, PageHeader } from "@/lib/ui";

const CATEGORIES = ["Health", "Software", "Education", "Finance", "Home", "Fitness", "Business", "Other"];

type UnifiedCreated = {
  offerId: string;
  productId: string;
  merchantId: string;
  payoutAmount: number;
  payoutAmountCad: number;
  exchangeRateUsed: number;
  currency: string;
};

const CLICKBANK_AFFILIATE = "jzanini";

const emptyForm = {
  providerKey: "clickbank",
  productName: "",
  category: "Health",
  merchantName: "",
  brandBiddingAllowed: false,
  directLinkingAllowed: false,
  payoutAmount: "",
  currency: "USD",
  exchangeRateOverride: "",
  overrideRate: false,
  commissionType: "OneTime",
  affiliateUrl: "",
  affiliatePageUrl: "",
  trafficRestrictions: "",
};

function clickbankHop(vendor: string) {
  const nickname = vendor.trim().toLowerCase().replace(/[^a-z0-9]/g, "");
  return nickname ? `https://${nickname}.${CLICKBANK_AFFILIATE}.hop.clickbank.net/` : "";
}

function clickbankHopPreview(vendor: string) {
  const nickname = vendor.trim().toLowerCase().replace(/[^a-z0-9]/g, "") || "{vendor}";
  return `https://${nickname}.${CLICKBANK_AFFILIATE}.hop.clickbank.net/?tid={click_id}`;
}

function networkCopy(providerKey: string) {
  if (providerKey === "clickbank") {
    return {
      payoutLabel: "Average Payout (USD)",
      payoutPlaceholder: "e.g. 165.68",
      payoutHint: "Enter the 'Avg $ Per Conv Total' from the bottom left of the marketplace card.",
      merchantLabel: "Vendor Nickname",
      merchantPlaceholder: "e.g. prodentim",
      merchantHint: "Use the vendor nickname from the marketplace card — the first hop subdomain, e.g. prodentim.",
      urlLabel: "HopLink Generator",
      urlPlaceholder: "https://prodentim.jzanini.hop.clickbank.net/",
      urlHint: "Paste the hop, or we build https://{vendor}.jzanini.hop.clickbank.net/?tid={click_id} from the vendor nickname.",
    };
  }
  if (providerKey === "digistore24") {
    return {
      payoutLabel: "Net Earnings Per Sale (USD)",
      payoutPlaceholder: "e.g. 69.29",
      payoutHint: "Enter the 'Net earnings/sale*' figure shown on the right side of the card.",
      merchantLabel: "Vendor Account",
      merchantPlaceholder: "e.g. soundview",
      merchantHint: "Enter the vendor account name shown on the Digistore24 marketplace card.",
      urlLabel: "Promolink",
      urlPlaceholder: "https://www.checkout-ds24.com/redir/...",
      urlHint: "Click the blue 'Promote now' button, copy your individual Promolink, and paste it here.",
    };
  }
  return {
    payoutLabel: "Payout Amount",
    payoutPlaceholder: "e.g. 75.00",
    payoutHint: "Commission you expect per sale, in the payout currency.",
    merchantLabel: "Merchant Name",
    merchantPlaceholder: "Merchant or vendor name",
    merchantHint: "The merchant or vendor you are promoting.",
    urlLabel: "Tracking URL (with {click_id})",
    urlPlaceholder: "https://example.com/hop?subid={click_id}",
    urlHint: "Paste the hop or tracking URL. Include {click_id} if the network substitutes it.",
  };
}

export default function OffersPage() {
  const [offers, setOffers] = useState<Offer[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [merchants, setMerchants] = useState<Merchant[]>([]);
  const [selected, setSelected] = useState("");
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [rate, setRate] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [created, setCreated] = useState<UnifiedCreated | null>(null);
  const [generating, setGenerating] = useState(false);
  const [override, setOverride] = useState({
    use: false, ppcAllowed: true, brandBiddingAllowed: false, brandPlusModifierAllowed: false,
    couponBiddingAllowed: false, directLinkingAllowed: false, policyNotes: "",
  });
  const [audit, setAudit] = useState<OfferPolicyAuditResult | null>(null);
  const [auditing, setAuditing] = useState(false);
  const [addingNegatives, setAddingNegatives] = useState(false);
  const [auditError, setAuditError] = useState("");
  const [auditOpen, setAuditOpen] = useState(true);
  const [refreshingSignals, setRefreshingSignals] = useState(false);
  const [tab, setTab] = useState<"active" | "discovery">("active");
  const [candidates, setCandidates] = useState<ProductCandidate[]>([]);
  const [candidateTotal, setCandidateTotal] = useState(0);
  const [candidateSearch, setCandidateSearch] = useState("");
  const [candidateStatus, setCandidateStatus] = useState("");
  const [candidateBusy, setCandidateBusy] = useState("");
  const [syncing, setSyncing] = useState(false);
  const [discoveryError, setDiscoveryError] = useState("");
  const [promoteCandidateId, setPromoteCandidateId] = useState("");

  async function load() {
    const [o, p, m] = await Promise.all([
      api<Offer[]>("/offers"),
      api<Product[]>("/products"),
      api<Merchant[]>("/merchants"),
    ]);
    setOffers(o); setProducts(p); setMerchants(m);
    if (!selected && o[0]) setSelected(o[0].id);
  }

  async function loadCandidates() {
    const params = new URLSearchParams({ page: "1", pageSize: "100" });
    if (candidateStatus) params.set("status", candidateStatus);
    if (candidateSearch.trim()) params.set("search", candidateSearch.trim());
    const result = await api<PagedProductCandidates>(`/candidates?${params}`);
    const items = Array.isArray(result?.items) ? result.items : [];
    setCandidates(items);
    setCandidateTotal(typeof result?.total === "number" ? result.total : items.length);
  }

  useEffect(() => { load().catch(console.error); }, []);

  useEffect(() => {
    if (!selected) {
      setAudit(null);
      setAuditError("");
      return;
    }
    setAuditError("");
    api<OfferPolicyAuditResult | undefined>(`/offers/${selected}/audit`)
      .then((result) => setAudit(result ?? null))
      .catch(() => setAudit(null));
  }, [selected]);

  useEffect(() => {
    if (tab !== "discovery") return;
    setDiscoveryError("");
    loadCandidates().catch((err) => setDiscoveryError(err instanceof Error ? err.message : "Could not load candidates"));
  }, [tab, candidateStatus, candidateSearch]);

  useEffect(() => {
    if (!open || form.currency === "CAD") {
      setRate(1);
      return;
    }
    api<{ from: string; to: string; rate: number }>(`/api/currency/rate?from=${form.currency}&to=CAD`)
      .then((r) => setRate(r.rate))
      .catch(() => setRate(1.38));
  }, [open, form.currency]);

  const offer = offers.find((x) => x.id === selected);
  const categories = useMemo(() => Array.from(new Set([...CATEGORIES, ...products.map((p) => p.category).filter(Boolean) as string[]])), [products]);
  const copy = networkCopy(form.providerKey);
  const effectiveRate = form.overrideRate && Number(form.exchangeRateOverride) > 0 ? Number(form.exchangeRateOverride) : (rate ?? 1.38);
  const convertedCad = Number(form.payoutAmount || 0) * (form.currency === "CAD" ? 1 : effectiveRate);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError("");
    try {
      const result = promoteCandidateId
        ? await api<UnifiedCreated>(`/candidates/${promoteCandidateId}/promote`, {
            method: "POST",
            body: JSON.stringify({
              payoutAmount: Number(form.payoutAmount),
              currency: form.currency,
              affiliateUrl: form.affiliateUrl || null,
              brandBiddingAllowed: form.brandBiddingAllowed,
              directLinkingAllowed: form.directLinkingAllowed,
              trafficRestrictions: form.trafficRestrictions.trim() || null,
            }),
          })
        : await api<UnifiedCreated>("/offers/unified", {
            method: "POST",
            body: JSON.stringify({
              productName: form.productName,
              category: form.category,
              merchantName: form.merchantName,
              brandBiddingAllowed: form.brandBiddingAllowed,
              directLinkingAllowed: form.directLinkingAllowed,
              payoutAmount: Number(form.payoutAmount),
              currency: form.currency,
              exchangeRateOverride: form.overrideRate && Number(form.exchangeRateOverride) > 0 ? Number(form.exchangeRateOverride) : null,
              commissionType: form.commissionType,
              affiliateUrl: form.affiliateUrl || null,
              baseAffiliateUrl: form.affiliateUrl || null,
              affiliatePageUrl: form.affiliatePageUrl || null,
              providerKey: form.providerKey,
              trafficRestrictions: form.trafficRestrictions.trim() || null,
            }),
          });
      setCreated(result);
      await load();
      setSelected(result.offerId);
      if (promoteCandidateId) {
        await loadCandidates().catch(() => undefined);
        setTab("active");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not create offer");
    }
  }

  async function generateKeywords() {
    if (!created) return;
    setGenerating(true);
    setError("");
    try {
      await api(`/products/${created.productId}/keywords/generate`, { method: "POST" });
      closeModal();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Keyword generation failed");
    } finally {
      setGenerating(false);
    }
  }

  function closeModal() {
    setOpen(false);
    setCreated(null);
    setError("");
    setForm(emptyForm);
    setPromoteCandidateId("");
  }

  async function auditCandidate(candidate: ProductCandidate) {
    setCandidateBusy(candidate.id);
    setDiscoveryError("");
    try {
      const result = await api<AuditCandidateResponse>(`/candidates/${candidate.id}/audit`, { method: "POST" });
      setCandidates((rows) => rows.map((row) => row.id === result.candidate.id ? result.candidate : row));
    } catch (err) {
      setDiscoveryError(err instanceof Error ? err.message : "Candidate audit failed.");
    } finally {
      setCandidateBusy("");
    }
  }

  function openPromote(candidate: ProductCandidate) {
    setPromoteCandidateId(candidate.id);
    setCreated(null);
    setError("");
    setForm({
      ...emptyForm,
      providerKey: candidate.providerKey || "clickbank",
      productName: candidate.title,
      category: candidate.category || "Health",
      merchantName: candidate.vendor || candidate.externalId,
      affiliateUrl: candidate.providerKey === "clickbank" ? clickbankHop(candidate.vendor || candidate.externalId) : "",
      affiliatePageUrl: candidate.affiliatePageUrl || "",
    });
    setOpen(true);
  }

  async function syncMarketplace() {
    setSyncing(true);
    setDiscoveryError("");
    try {
      const result = await api<ClickBankFeedSyncResult>("/candidates/sync", { method: "POST" });
      await loadCandidates();
      setDiscoveryError(`Synced Digistore24 marketplace: ${result.inserted} new / ${result.updated} updated (skipped ${result.skipped} of ${result.examined}).`);
    } catch (err) {
      setDiscoveryError(err instanceof Error ? err.message : "Marketplace sync failed.");
    } finally {
      setSyncing(false);
    }
  }

  function onMerchantName(name: string) {
    setForm((f) => {
      const match = merchants.find((m) => m.name.toLowerCase() === name.toLowerCase());
      const next = match
        ? { ...f, merchantName: name, brandBiddingAllowed: match.brandBiddingAllowed, directLinkingAllowed: match.directLinkingAllowed, affiliatePageUrl: f.affiliatePageUrl || match.website || "" }
        : { ...f, merchantName: name };
      if (f.providerKey === "clickbank") {
        const previousHop = clickbankHop(f.merchantName);
        if (!f.affiliateUrl || f.affiliateUrl === previousHop) next.affiliateUrl = clickbankHop(name);
      }
      return next;
    });
  }

  function onProviderChange(providerKey: string) {
    setForm((f) => {
      const next = {
        ...f,
        providerKey,
        currency: providerKey === "manual" ? f.currency : "USD",
      };
      if (providerKey === "clickbank" && (!f.affiliateUrl || f.affiliateUrl === clickbankHop(f.merchantName))) {
        next.affiliateUrl = clickbankHop(f.merchantName);
      }
      return next;
    });
  }

  async function saveOverride() {
    if (!offer) return;
    await api(`/offers/${offer.id}`, {
      method: "PUT",
      body: JSON.stringify({
        productId: offer.productId,
        merchantId: offer.merchantId ?? null,
        name: offer.name,
        affiliateUrl: offer.affiliateUrl,
        commission: offer.commission,
        commissionType: offer.commissionType,
        recurringCommission: offer.recurringCommission,
        averageOrderValue: offer.averageOrderValue,
        currency: offer.currency,
        country: offer.country,
        category: offer.category,
        status: offer.status,
        ppcAllowed: override.use ? override.ppcAllowed : null,
        brandBiddingAllowed: override.use ? override.brandBiddingAllowed : null,
        brandPlusModifierAllowed: override.use ? override.brandPlusModifierAllowed : null,
        couponBiddingAllowed: override.use ? override.couponBiddingAllowed : null,
        directLinkingAllowed: override.use ? override.directLinkingAllowed : null,
        policyNotes: override.policyNotes || null,
      }),
    });
    await load();
  }

  async function runAudit() {
    if (!offer) return;
    setAuditing(true);
    setAuditError("");
    try {
      const result = await api<OfferPolicyAuditResult>(`/offers/${offer.id}/audit`, { method: "POST" });
      setAudit(result);
      setAuditOpen(true);
      await load();
    } catch (err) {
      setAuditError(err instanceof Error ? err.message : "Policy audit failed.");
    } finally {
      setAuditing(false);
    }
  }

  async function refreshMarketSignals() {
    if (!offer) return;
    setRefreshingSignals(true);
    setAuditError("");
    try {
      await api<Offer>(`/offers/${offer.id}/market-signals`, { method: "POST" });
      await load();
    } catch (err) {
      setAuditError(err instanceof Error ? err.message : "Market signals refresh failed.");
    } finally {
      setRefreshingSignals(false);
    }
  }

  async function addNegatives() {
    if (!offer || !audit?.mandatoryNegativeKeywords.length) return;
    setAddingNegatives(true);
    setAuditError("");
    try {
      await api(`/offers/${offer.id}/audit/negatives`, {
        method: "POST",
        body: JSON.stringify({ phrases: audit.mandatoryNegativeKeywords }),
      });
      await load();
    } catch (err) {
      setAuditError(err instanceof Error ? err.message : "Could not add negative keywords.");
    } finally {
      setAddingNegatives(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Offers"
        summary="Create product, merchant, and payout in one step. Economics are scored in CAD$."
        purpose="This is the main onboarding screen: one affiliate offer you might promote, with payout converted to CAD$."
        does="Click New Offer to add product name, merchant, payout, currency, and hop link. After save, generate keywords and score without leaving the page."
        flow="Start here (or after connecting a network). Offers feed keywords, opportunities, landing pages, and campaigns."
        actions={<Button type="button" onClick={() => { setCreated(null); setPromoteCandidateId(""); setForm(emptyForm); setOpen(true); }}>New Offer</Button>}
      />
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => setTab("active")}
          className={`rounded px-3 py-1.5 text-sm ${tab === "active" ? "bg-zinc-800 text-white" : "text-zinc-400 hover:text-zinc-200"}`}
        >
          Active Offers
        </button>
        <button
          type="button"
          onClick={() => { setTab("discovery"); loadCandidates().catch((err) => setDiscoveryError(err instanceof Error ? err.message : "Could not load candidates")); }}
          className={`rounded px-3 py-1.5 text-sm ${tab === "discovery" ? "bg-zinc-800 text-white" : "text-zinc-400 hover:text-zinc-200"}`}
        >
          Discovery Inbox
        </button>
      </div>
      {tab === "discovery" ? (
        <DiscoveryInbox
          candidates={candidates}
          total={candidateTotal}
          search={candidateSearch}
          status={candidateStatus}
          busyId={candidateBusy}
          syncing={syncing}
          error={discoveryError}
          onSearch={setCandidateSearch}
          onStatus={setCandidateStatus}
          onAudit={auditCandidate}
          onPromote={openPromote}
          onSync={syncMarketplace}
        />
      ) : (
      <>
      <Table>
        <thead><tr><Th>Offer</Th><Th>Product</Th><Th>Merchant</Th><Th>Payout</Th><Th>CAD$</Th><Th>Momentum</Th><Th>TrustScore</Th><Th>Policy</Th><Th>Compliance</Th><Th>Status</Th></tr></thead>
        <tbody>
          {offers.map((o) => (
            <tr key={o.id} onClick={() => setSelected(o.id)} className={selected === o.id ? "bg-zinc-900" : "cursor-pointer"}>
              <Td>{o.name}</Td>
              <Td>{o.productName}</Td>
              <Td>{o.merchantName ?? "—"}</Td>
              <Td>{money(o.commission, o.currency || "USD")}</Td>
              <Td>{money(o.payoutAmountCad, "CAD")}</Td>
              <Td><MomentumBadge direction={o.searchTrend?.direction} change={o.searchTrend?.ninetyDayChangePercent} /></Td>
              <Td><TrustScorePill rating={o.reputation?.trustpilotRating} reviews={o.reputation?.totalReviewsCount} risk={o.reputation?.refundRisk} /></Td>
              <Td><PolicyRiskBadge score={o.latestAudit?.riskScore} level={o.latestAudit?.overallRisk} /></Td>
              <Td className={o.overallCompliance === "Prohibited" ? "text-red-400" : ""}>{o.overallCompliance}</Td>
              <Td>{o.status}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      {offer ? (
        <section className="space-y-4 rounded border border-zinc-800 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-lg font-medium">Compliance — {offer.name}</h2>
            <Button type="button" onClick={refreshMarketSignals} disabled={refreshingSignals}>
              {refreshingSignals ? "Refreshing…" : "Refresh market signals"}
            </Button>
          </div>
          {offer.reputation?.recurringComplaints?.length ? (
            <p className="text-sm text-zinc-400">
              Trustpilot complaints: {offer.reputation.recurringComplaints.join(" · ")}
            </p>
          ) : null}
          <p className="text-sm text-zinc-400">
            Resolved policy: PPC {yesNo(offer.resolvedPolicy.ppcAllowed)}, brand {yesNo(offer.resolvedPolicy.brandBiddingAllowed)},
            coupon {yesNo(offer.resolvedPolicy.couponBiddingAllowed)}, direct link {yesNo(offer.resolvedPolicy.directLinkingAllowed)}.
            Keyword generation uses this after the TOS check — not before.
          </p>
          <Checkbox
            label="Override merchant policy on this offer"
            checked={override.use}
            onChange={(e) => setOverride({ ...override, use: e.target.checked })}
          />
          {override.use ? (
            <div className="grid gap-2 md:grid-cols-2">
              <Checkbox label="PPC allowed" checked={override.ppcAllowed} onChange={(e) => setOverride({ ...override, ppcAllowed: e.target.checked })} />
              <Checkbox label="Brand bidding" checked={override.brandBiddingAllowed} onChange={(e) => setOverride({ ...override, brandBiddingAllowed: e.target.checked })} />
              <Checkbox label="Brand + modifier" checked={override.brandPlusModifierAllowed} onChange={(e) => setOverride({ ...override, brandPlusModifierAllowed: e.target.checked })} />
              <Checkbox label="Coupon bidding" checked={override.couponBiddingAllowed} onChange={(e) => setOverride({ ...override, couponBiddingAllowed: e.target.checked })} />
              <Checkbox label="Direct linking" checked={override.directLinkingAllowed} onChange={(e) => setOverride({ ...override, directLinkingAllowed: e.target.checked })} />
            </div>
          ) : null}
          <Field label="Policy notes"><Textarea rows={2} value={override.policyNotes} onChange={(e) => setOverride({ ...override, policyNotes: e.target.value })} /></Field>
          <Button type="button" onClick={saveOverride}>Save offer policy</Button>
          <div className="space-y-3 rounded border border-zinc-800 p-3">
            <div className="flex flex-wrap items-center gap-3">
              <Button type="button" onClick={runAudit} disabled={auditing}>
                {auditing ? "Auditing…" : "AI Policy Audit"}
              </Button>
              {audit ? <PolicyRiskBadge score={audit.riskScore} level={audit.overallRisk} /> : null}
              {audit ? (
                <button type="button" className="text-sm text-zinc-400 hover:text-zinc-200" onClick={() => setAuditOpen((v) => !v)}>
                  {auditOpen ? "Hide details" : "Show details"}
                </button>
              ) : null}
            </div>
            {auditing ? <p className="text-sm text-zinc-400">Reading the sales page and scoring Google Ads policy risk…</p> : null}
            {auditError ? <p className="text-sm text-red-400">{auditError}</p> : null}
            {audit && auditOpen ? (
              <div className="space-y-3 text-sm">
                <p className="text-zinc-300">{audit.executiveSummary}</p>
                <p className="text-zinc-500">
                  Risk score {audit.riskScore}/100. Brand bidding {audit.allowsBrandBidding ? "allowed" : "not allowed"} per vendor rules.
                </p>
                <div>
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-medium text-zinc-200">Mandatory negative keywords</h3>
                    <Button
                      type="button"
                      onClick={addNegatives}
                      disabled={addingNegatives || audit.mandatoryNegativeKeywords.length === 0}
                    >
                      {addingNegatives ? "Adding…" : "Add to Negative Lists"}
                    </Button>
                  </div>
                  {audit.mandatoryNegativeKeywords.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {audit.mandatoryNegativeKeywords.map((phrase) => (
                        <span key={phrase} className="rounded bg-zinc-800 px-2 py-0.5 text-xs text-zinc-200">{phrase}</span>
                      ))}
                    </div>
                  ) : (
                    <p className="text-zinc-500">No required negatives found in the affiliate terms.</p>
                  )}
                </div>
                <div>
                  <h3 className="mb-2 font-medium text-zinc-200">Flagged policy violations</h3>
                  {audit.violations.length ? (
                    <ul className="space-y-2">
                      {audit.violations.map((v, i) => (
                        <li key={`${v.triggerPhrase}-${i}`} className="rounded border border-zinc-800 bg-zinc-900 p-2">
                          <div className="flex flex-wrap gap-2 text-xs">
                            <span className="text-red-400">{v.severity}</span>
                            <span className="text-zinc-400">{v.policyCategory}</span>
                          </div>
                          {v.triggerPhrase ? <p className="mt-1 font-medium text-zinc-100">“{v.triggerPhrase}”</p> : null}
                          <p className="mt-1 text-zinc-400">{v.explanation}</p>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="text-zinc-500">No policy violations flagged.</p>
                  )}
                </div>
                {audit.approvedAngles.length ? (
                  <div>
                    <h3 className="mb-1 font-medium text-zinc-200">Approved angles</h3>
                    <ul className="list-disc pl-5 text-zinc-400">{audit.approvedAngles.map((a) => <li key={a}>{a}</li>)}</ul>
                  </div>
                ) : null}
                {audit.disallowedAngles.length ? (
                  <div>
                    <h3 className="mb-1 font-medium text-zinc-200">Disallowed angles</h3>
                    <ul className="list-disc pl-5 text-red-300">{audit.disallowedAngles.map((a) => <li key={a}>{a}</li>)}</ul>
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
          <RestrictionList items={offer.restrictions ?? []} onChanged={load} />
          <RestrictionForm path={`/offers/${offer.id}/restrictions`} onSaved={load} />
        </section>
      ) : null}
      </>
      )}

      {open ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
          <div className="max-h-[90vh] w-full max-w-xl overflow-auto rounded border border-zinc-800 bg-zinc-950 p-6">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-semibold">{created ? "Offer created" : promoteCandidateId ? "Promote to Offer" : "New Offer"}</h2>
              <button type="button" className="text-sm text-zinc-500 hover:text-zinc-300" onClick={closeModal}>Close</button>
            </div>
            {created ? (
              <div className="space-y-4">
                <p className="text-sm text-zinc-300">
                  Payout {money(created.payoutAmount, created.currency)} → <span className="text-emerald-400">{money(created.payoutAmountCad, "CAD")}</span>
                  {" "}at {created.exchangeRateUsed.toFixed(4)}.
                </p>
                {error ? <p className="text-sm text-red-400">{error}</p> : null}
                <Button type="button" onClick={generateKeywords} disabled={generating}>
                  {generating ? "Generating…" : "Generate Keywords & Score"}
                </Button>
              </div>
            ) : (
              <form onSubmit={onSubmit} className="space-y-4">
                <Field label="Affiliate Network">
                  <Select value={form.providerKey} onChange={(e) => onProviderChange(e.target.value)}>
                    <option value="clickbank">ClickBank</option>
                    <option value="digistore24">Digistore24</option>
                    <option value="manual">Manual</option>
                  </Select>
                </Field>
                <div className="grid gap-3 md:grid-cols-2">
                  <Field label="Product name">
                    <Input list="product-names" value={form.productName} onChange={(e) => setForm({ ...form, productName: e.target.value })} required />
                    <datalist id="product-names">{products.map((p) => <option key={p.id} value={p.name} />)}</datalist>
                  </Field>
                  <Field label="Category">
                    <Input list="categories" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
                    <datalist id="categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                  </Field>
                </div>
                <Field label={copy.merchantLabel} hint={copy.merchantHint}>
                  <Input list="merchant-names" value={form.merchantName} onChange={(e) => onMerchantName(e.target.value)} placeholder={copy.merchantPlaceholder} required />
                  <datalist id="merchant-names">{merchants.map((m) => <option key={m.id} value={m.name} />)}</datalist>
                </Field>
                <div className="flex flex-wrap gap-4">
                  <Checkbox label="Brand bidding allowed" checked={form.brandBiddingAllowed} onChange={(e) => setForm({ ...form, brandBiddingAllowed: e.target.checked })} />
                  <Checkbox label="Direct linking allowed" checked={form.directLinkingAllowed} onChange={(e) => setForm({ ...form, directLinkingAllowed: e.target.checked })} />
                </div>
                <div className="grid gap-3 md:grid-cols-3">
                  <Field label={copy.payoutLabel} hint={copy.payoutHint}>
                    <Input type="number" step="0.01" min="0" value={form.payoutAmount} onChange={(e) => setForm({ ...form, payoutAmount: e.target.value })} placeholder={copy.payoutPlaceholder} required />
                  </Field>
                  <Field label="Currency">
                    <Select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                      <option value="USD">USD</option>
                      <option value="CAD">CAD</option>
                      <option value="EUR">EUR</option>
                    </Select>
                  </Field>
                  <Field label="Commission type">
                    <Select value={form.commissionType} onChange={(e) => setForm({ ...form, commissionType: e.target.value })}>
                      <option value="OneTime">OneTime</option>
                      <option value="Recurring">Recurring</option>
                    </Select>
                  </Field>
                </div>
                {form.currency !== "CAD" ? (
                  <div className="rounded border border-zinc-800 bg-zinc-900 px-3 py-2 text-sm text-emerald-400">
                    ≈ CAD$ {convertedCad.toFixed(2)} (Rate: {effectiveRate.toFixed(4)})
                  </div>
                ) : null}
                {form.currency !== "CAD" ? (
                  <div className="space-y-2">
                    <Checkbox label="Override exchange rate" checked={form.overrideRate} onChange={(e) => setForm({ ...form, overrideRate: e.target.checked })} />
                    {form.overrideRate ? (
                      <Field label="CAD per 1 unit of payout currency">
                        <Input type="number" step="0.0001" min="0" value={form.exchangeRateOverride} onChange={(e) => setForm({ ...form, exchangeRateOverride: e.target.value })} />
                      </Field>
                    ) : null}
                  </div>
                ) : null}
                <Field label={copy.urlLabel} hint={copy.urlHint}>
                  <Input value={form.affiliateUrl} onChange={(e) => setForm({ ...form, affiliateUrl: e.target.value })} placeholder={copy.urlPlaceholder} />
                  {form.providerKey === "clickbank" ? (
                    <p className="mt-1 font-mono text-[11px] text-zinc-500">Preview: {clickbankHopPreview(form.merchantName)}</p>
                  ) : null}
                </Field>
                <Field label="Affiliate Support URL" hint="Check this page to verify if the vendor prohibits trademark bidding on Google search.">
                  <Input value={form.affiliatePageUrl} onChange={(e) => setForm({ ...form, affiliatePageUrl: e.target.value })} placeholder="https://" />
                </Field>
                <Field
                  label="Traffic Restrictions & Affiliate Rules (Optional)"
                  hint="Paste the vendor's traffic guidelines so the AI can automatically extract negative keywords, check brand bidding rules, and verify Google Ads safety."
                >
                  <Textarea
                    rows={5}
                    value={form.trafficRestrictions}
                    onChange={(e) => setForm({ ...form, trafficRestrictions: e.target.value })}
                    placeholder="Paste vendor rules, PPC restrictions, negative keywords, or forbidden angles here..."
                  />
                </Field>
                {error ? <p className="text-sm text-red-400">{error}</p> : null}
                <div className="flex justify-end gap-2">
                  <button type="button" className="rounded px-3 py-1.5 text-sm text-zinc-400 hover:text-zinc-200" onClick={closeModal}>Cancel</button>
                  <Button type="submit">{promoteCandidateId ? "Promote to offer" : "Create offer"}</Button>
                </div>
              </form>
            )}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function yesNo(v: boolean) {
  return v ? "yes" : "no";
}

function PolicyRiskBadge({ score, level }: { score?: number; level?: string }) {
  if (score == null) {
    return <span className="text-zinc-500">—</span>;
  }
  const tone = score < 30
    ? "bg-emerald-500/15 text-emerald-400"
    : score < 60
      ? "bg-amber-400/15 text-amber-300"
      : "bg-red-500/15 text-red-400";
  return (
    <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${tone}`}>
      {level ?? riskLabel(score)} · {score}
    </span>
  );
}

function riskLabel(score: number) {
  if (score < 30) return "Low";
  if (score < 60) return "Moderate";
  if (score < 85) return "Severe";
  return "Critical";
}

function MomentumBadge({ direction, change }: { direction?: string; change?: number }) {
  if (!direction || direction === "Unknown") {
    return <span className="text-zinc-500">—</span>;
  }
  const tone = direction === "Rising"
    ? "bg-emerald-500/15 text-emerald-400"
    : direction === "Declining"
      ? "bg-red-500/15 text-red-400"
      : "bg-zinc-700/40 text-zinc-300";
  const label = change == null ? direction : `${direction} ${change > 0 ? "+" : ""}${change.toFixed(0)}%`;
  return (
    <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${tone}`} title="90-day search volume momentum">
      {label}
    </span>
  );
}

function TrustScorePill({ rating, reviews, risk }: { rating?: number | null; reviews?: number; risk?: string }) {
  if (rating == null) {
    return <span className="text-zinc-500">—</span>;
  }
  const tone = rating >= 4
    ? "bg-emerald-500/15 text-emerald-400"
    : rating >= 2.5
      ? "bg-amber-400/15 text-amber-300"
      : "bg-red-500/15 text-red-400";
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}
      title={risk ? `Trustpilot refund risk: ${risk}` : "Trustpilot rating"}
    >
      {rating.toFixed(1)}{reviews ? ` · ${reviews}` : ""}
    </span>
  );
}

function DiscoveryInbox({
  candidates,
  total,
  search,
  status,
  busyId,
  syncing,
  error,
  onSearch,
  onStatus,
  onAudit,
  onPromote,
  onSync,
}: {
  candidates: ProductCandidate[];
  total: number;
  search: string;
  status: string;
  busyId: string;
  syncing: boolean;
  error: string;
  onSearch: (value: string) => void;
  onStatus: (value: string) => void;
  onAudit: (candidate: ProductCandidate) => void;
  onPromote: (candidate: ProductCandidate) => void;
  onSync: () => void;
}) {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <Field label="Search">
          <Input value={search} onChange={(e) => onSearch(e.target.value)} placeholder="Title or vendor nickname" />
        </Field>
        <Field label="Status">
          <Select value={status} onChange={(e) => onStatus(e.target.value)}>
            <option value="">All</option>
            <option value="PendingReview">Pending review</option>
            <option value="Qualified">Qualified</option>
            <option value="Disqualified">Disqualified</option>
            <option value="Promoted">Promoted</option>
          </Select>
        </Field>
        <Button type="button" onClick={onSync} disabled={syncing}>
          {syncing ? "Syncing…" : "Sync Digistore24 Marketplace"}
        </Button>
        <span className="text-xs text-zinc-500">{total} staged candidates · uses the API key saved on Networks</span>
      </div>
      {error ? <p className="text-sm text-amber-300">{error}</p> : null}
      <Table>
        <thead>
          <tr>
            <Th>Title</Th>
            <Th>Vendor</Th>
            <Th>Gravity</Th>
            <Th>Network</Th>
            <Th>Status</Th>
            <Th>Discovered</Th>
            <Th></Th>
          </tr>
        </thead>
        <tbody>
          {candidates.length === 0 ? (
            <tr>
              <Td colSpan={7}>
                <span className="text-zinc-500">No candidates yet. Save a Digistore24 API key on Networks, then Sync Digistore24 Marketplace.</span>
              </Td>
            </tr>
          ) : candidates.map((candidate) => (
            <tr key={candidate.id}>
              <Td>
                <div className="font-medium text-zinc-100">{candidate.title}</div>
                {candidate.category ? <div className="text-xs text-zinc-500">{candidate.category}</div> : null}
              </Td>
              <Td className="font-mono text-xs">{candidate.vendor || candidate.externalId}</Td>
              <Td>{candidate.gravity.toFixed(1)}</Td>
              <Td>{networkLabel(candidate.providerKey)}</Td>
              <Td><CandidateStatusBadge status={candidate.status} reason={candidate.disqualificationReason} /></Td>
              <Td>{new Date(candidate.discoveredAt).toLocaleDateString()}</Td>
              <Td>
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    onClick={() => onAudit(candidate)}
                    disabled={busyId === candidate.id || candidate.status === "Promoted"}
                  >
                    {busyId === candidate.id ? "Auditing…" : "Audit"}
                  </Button>
                  {candidate.status === "Qualified" ? (
                    <Button type="button" onClick={() => onPromote(candidate)}>Promote to Offer</Button>
                  ) : null}
                </div>
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </div>
  );
}

function networkLabel(providerKey: string) {
  if (providerKey === "clickbank") return "ClickBank";
  if (providerKey === "digistore24") return "Digistore24";
  return providerKey;
}

function CandidateStatusBadge({ status, reason }: { status: string; reason?: string | null }) {
  const tone = status === "Qualified"
    ? "bg-emerald-500/15 text-emerald-400"
    : status === "Disqualified"
      ? "bg-red-500/15 text-red-400"
      : status === "Promoted"
        ? "bg-sky-500/15 text-sky-300"
        : "bg-zinc-700/40 text-zinc-300";
  const label = status === "PendingReview" ? "Pending review" : status;
  return (
    <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${tone}`} title={reason ?? undefined}>
      {label}
    </span>
  );
}
