"use client";

import Link from "next/link";
import { PageHeader } from "@/lib/ui";

const STEPS = [
  {
    n: 1,
    href: "/networks",
    title: "Networks (optional)",
    you: "Save your ClickBank or Digistore24 API key and affiliate nickname.",
    why: "So conversion webhooks can credit you, and hop links can use your id.",
    skip: "Skip if you only paste hop links by hand. Affiliates cannot Sync the marketplace catalog.",
  },
  {
    n: 2,
    href: "/offers",
    title: "Create an Offer",
    you: "Use New Offer: product, merchant, payout, currency, hop link. Confirm the CAD$ conversion.",
    why: "This is the unit you promote. Scoring always uses CAD$, not the raw USD/EUR payout.",
    skip: "Do not bounce between Merchants → Products → Offers unless you are editing later.",
  },
  {
    n: 3,
    href: "/keywords",
    title: "Generate keywords",
    you: "From the offer success screen (or Products), click Generate Keywords & Score.",
    why: "Phrases are built from the product name and blocked by merchant/offer PPC and brand policy.",
    skip: "Keywords will not generate until an active offer exists.",
  },
  {
    n: 4,
    href: "/opportunities",
    title: "Pick a Test opportunity",
    you: "Open Opportunities. Only launch rows marked Test. Read Why on Investigate/Skip.",
    why: "Test needs profit in CAD$, enough search volume, and verified/restricted compliance.",
    skip: "Do not buy ads on Skip or on unverified compliance.",
  },
  {
    n: 5,
    href: "/landing-pages",
    title: "Publish a landing page",
    you: "Write disclosure, original evaluation, and a non-CTA section (who it’s for, pros, etc.). Publish.",
    why: "Google Ads must land on your page, not the merchant hop.",
    skip: "Publish is blocked without disclosure + body + one utility section.",
  },
  {
    n: 6,
    href: "/campaigns",
    title: "Create the campaign",
    you: "Attach the offer and published landing page, add ads, set Live. Ads use the /p/{slug} URL.",
    why: "The /t/{code} hop is only the on-page CTA after the visitor is already on your domain.",
    skip: "Do not put the ClickBank hop in Google Ads as the final URL.",
  },
  {
    n: 7,
    href: "/tracking",
    title: "Record spend and conversions",
    you: "Check clicks, add ad spend, confirm network postbacks (or enter a conversion by hand).",
    why: "Without spend and commissions, the dashboard cannot tell you if the campaign pays.",
    skip: null,
  },
  {
    n: 8,
    href: "/",
    title: "Decide on the Dashboard",
    you: "Read observed → reported ROAS and confidence. Scale or pause using the range, not last-click alone.",
    why: "Low attribution confidence must not auto-kill a campaign.",
    skip: null,
  },
];

const SUPPORT = [
  { href: "/merchants", title: "Merchants", text: "Edit vendor policy (PPC, brand, coupon, direct link) after the offer exists." },
  { href: "/products", title: "Products", text: "Catalog view. Prefer creating products from New Offer." },
  { href: "/settings", title: "Settings", text: "Account context and AI provider keys (OpenAI, Anthropic, Gemini)." },
];

export default function GuidePage() {
  return (
    <div className="space-y-10">
      <PageHeader
        title="How it works"
        summary="The whole app is one loop: pick an offer, score keywords in CAD$, send Google Ads to your landing page, then judge results on the dashboard."
        purpose="A map of Bottom Funnel so you always know the next screen — not a second dashboard."
        does="Read the operator path (you) and the visitor path (the click). Follow the numbered steps in order the first time."
        flow="This page sits outside the loop. Start at step 2 (Offers) unless you need Networks first."
      />

      <section className="space-y-4">
        <h2 className="text-lg font-medium">The two paths</h2>
        <p className="max-w-3xl text-sm text-zinc-400">
          You work left to right in the top row. The visitor never sees your admin screens — they hit the landing page, then the hop.
        </p>
        <FlowDiagram />
      </section>

      <section className="space-y-4">
        <h2 className="text-lg font-medium">Do this, in order</h2>
        <ol className="space-y-3">
          {STEPS.map((step) => (
            <li key={step.n} className="rounded border border-zinc-800 bg-zinc-900 p-4">
              <div className="flex flex-wrap items-baseline gap-3">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500 text-sm font-semibold text-zinc-950">{step.n}</span>
                <Link href={step.href} className="text-base font-medium text-emerald-400 hover:text-emerald-300">{step.title}</Link>
              </div>
              <dl className="mt-3 grid gap-3 pl-10 text-sm md:grid-cols-3">
                <div>
                  <dt className="text-xs uppercase tracking-wide text-zinc-500">You do</dt>
                  <dd className="mt-1 text-zinc-300">{step.you}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase tracking-wide text-zinc-500">Why</dt>
                  <dd className="mt-1 text-zinc-300">{step.why}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase tracking-wide text-zinc-500">{step.skip ? "Do not" : "Note"}</dt>
                  <dd className="mt-1 text-zinc-300">{step.skip ?? "Required once traffic is live."}</dd>
                </div>
              </dl>
            </li>
          ))}
        </ol>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-medium">Supporting screens</h2>
        <div className="grid gap-3 md:grid-cols-3">
          {SUPPORT.map((item) => (
            <Link key={item.href} href={item.href} className="rounded border border-zinc-800 p-4 hover:border-zinc-600">
              <div className="font-medium text-zinc-100">{item.title}</div>
              <p className="mt-1 text-sm text-zinc-400">{item.text}</p>
            </Link>
          ))}
        </div>
      </section>
    </div>
  );
}

function FlowDiagram() {
  return (
    <div className="space-y-5 rounded border border-zinc-800 bg-zinc-950 p-4">
      <FlowRow
        title="You (operator)"
        nodes={[
          { href: "/networks", label: "1. Networks", hint: "optional" },
          { href: "/offers", label: "2. Offer", hint: "start here", accent: true },
          { href: "/keywords", label: "3. Keywords" },
          { href: "/opportunities", label: "4. Test?" },
          { href: "/landing-pages", label: "5. Landing page" },
          { href: "/campaigns", label: "6. Campaign" },
          { href: "/tracking", label: "7. Tracking" },
          { href: "/", label: "8. Dashboard" },
        ]}
      />
      <FlowRow
        title="The visitor (money path)"
        nodes={[
          { label: "Google Ad", hint: "final URL = your page" },
          { href: "/landing-pages", label: "/p/slug", hint: "landing page", accent: true },
          { href: "/tracking", label: "/t/code", hint: "tracked CTA" },
          { href: "/offers", label: "Hop link", hint: "ClickBank / merchant" },
          { href: "/tracking", label: "Webhook", hint: "INS / postback" },
          { href: "/", label: "Dashboard", hint: "CAD$ results" },
        ]}
      />
      <p className="text-sm text-zinc-500">
        Ads never go straight to the hop. If the visitor skips your page, you lose disclosure, tracking, and the ability to judge the campaign.
      </p>
    </div>
  );
}

function FlowRow({
  title,
  nodes,
}: {
  title: string;
  nodes: { href?: string; label: string; hint?: string; accent?: boolean }[];
}) {
  return (
    <div>
      <p className="mb-2 text-xs uppercase tracking-wide text-emerald-400">{title}</p>
      <div className="flex flex-wrap items-center gap-1.5">
        {nodes.map((node, i) => (
          <span key={node.label} className="flex items-center gap-1.5">
            {i > 0 ? <Arrow /> : null}
            <Node {...node} />
          </span>
        ))}
      </div>
    </div>
  );
}

function Node({ href, label, hint, accent }: { href?: string; label: string; hint?: string; accent?: boolean }) {
  const className = `inline-flex h-11 min-w-[7.5rem] flex-col justify-center rounded border px-2.5 py-1 text-left text-xs ${accent ? "border-emerald-500 bg-zinc-900 text-emerald-400" : "border-zinc-800 bg-zinc-900 text-zinc-200"}`;
  const inner = (
    <>
      <span className="font-medium leading-tight">{label}</span>
      {hint ? <span className="text-[10px] leading-tight text-zinc-500">{hint}</span> : null}
    </>
  );
  if (!href) return <div className={className}>{inner}</div>;
  return <Link href={href} className={`${className} hover:border-emerald-500`}>{inner}</Link>;
}

function Arrow() {
  return <span className="hidden px-1 text-emerald-500 md:inline" aria-hidden>→</span>;
}
