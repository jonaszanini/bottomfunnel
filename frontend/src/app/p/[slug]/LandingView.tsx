"use client";

import { useEffect, useMemo, useState } from "react";
import { publicApi } from "@/lib/api";
import { LandingPage } from "@/lib/types";

export function LandingView({ page }: { page: LandingPage }) {
  const [ctaUrl, setCtaUrl] = useState<string | null>(null);
  const [ctaError, setCtaError] = useState("");

  const query = useMemo(() => {
    if (typeof window === "undefined") return { gclid: "", keywordId: "" };
    const params = new URLSearchParams(window.location.search);
    return { gclid: params.get("gclid") ?? "", keywordId: params.get("keywordId") ?? "" };
  }, []);

  useEffect(() => {
    const sessionKey = localStorage.getItem("bf_sid") ?? crypto.randomUUID().replace(/-/g, "");
    localStorage.setItem("bf_sid", sessionKey);

    fetch(`${publicApi}/public/landing-pages/visits`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        slug: page.slug,
        sessionKey,
        gclid: query.gclid || null,
        keywordId: query.keywordId || null,
        userAgent: navigator.userAgent,
        referrer: document.referrer || null,
        device: /Mobi|Android/i.test(navigator.userAgent) ? "mobile" : "desktop",
      }),
    })
      .then(async (res) => {
        if (!res.ok) throw new Error("Visit capture failed");
        return res.json() as Promise<{ ctaUrl?: string; trackingCode?: string }>;
      })
      .then((body) => {
        if (body.ctaUrl) setCtaUrl(`${publicApi}${body.ctaUrl}`);
        else if (page.ctaUrl) setCtaUrl(`${publicApi}${page.ctaUrl}`);
      })
      .catch(() => setCtaError("Offer link is not ready yet."));
  }, [page.slug, page.ctaUrl, query.gclid, query.keywordId]);

  return (
    <article className="mx-auto max-w-2xl px-6 py-16">
      {page.disclosure ? (
        <p className="mb-8 rounded border border-amber-900/60 bg-amber-950/40 p-3 text-sm text-amber-100">{page.disclosure}</p>
      ) : null}
      <h1 className="text-4xl font-semibold tracking-tight">{page.headline}</h1>
      {page.subheadline ? <p className="mt-4 text-lg text-zinc-400">{page.subheadline}</p> : null}
      {page.body ? <p className="mt-8 whitespace-pre-wrap text-zinc-300">{page.body}</p> : null}

      {page.whoItsFor ? (
        <section className="mt-8">
          <h2 className="text-lg font-medium">Who it&apos;s for</h2>
          <p className="mt-2 whitespace-pre-wrap text-zinc-400">{page.whoItsFor}</p>
        </section>
      ) : null}
      {page.whoItsNotFor ? (
        <section className="mt-6">
          <h2 className="text-lg font-medium">Who it&apos;s not for</h2>
          <p className="mt-2 whitespace-pre-wrap text-zinc-400">{page.whoItsNotFor}</p>
        </section>
      ) : null}

      {(page.pros?.length || page.cons?.length) ? (
        <div className="mt-8 grid gap-6 md:grid-cols-2">
          <div>
            <h2 className="text-lg font-medium">Pros</h2>
            <ul className="mt-2 space-y-1 text-zinc-300">{page.pros.map((p) => <li key={p}>• {p}</li>)}</ul>
          </div>
          <div>
            <h2 className="text-lg font-medium">Cons</h2>
            <ul className="mt-2 space-y-1 text-zinc-300">{page.cons.map((p) => <li key={p}>• {p}</li>)}</ul>
          </div>
        </div>
      ) : null}

      {page.benefits?.length ? (
        <ul className="mt-8 space-y-2 text-zinc-200">
          {page.benefits.map((b) => <li key={b}>• {b}</li>)}
        </ul>
      ) : null}
      {page.comparison ? <p className="mt-6 text-zinc-400">{page.comparison}</p> : null}
      {page.alternatives ? (
        <section className="mt-8">
          <h2 className="text-lg font-medium">Alternatives</h2>
          <p className="mt-2 whitespace-pre-wrap text-zinc-400">{page.alternatives}</p>
        </section>
      ) : null}

      {ctaUrl ? (
        <a href={ctaUrl} className="mt-10 inline-flex rounded bg-emerald-500 px-6 py-3 font-medium text-zinc-950">
          {page.ctaText}
        </a>
      ) : (
        <p className="mt-10 text-sm text-zinc-500">{ctaError || "CTA will appear once a live campaign is attached."}</p>
      )}

      <div className="mt-12 space-y-4">
        {page.faqs.map((faq) => (
          <div key={faq.question}>
            <h2 className="font-medium">{faq.question}</h2>
            <p className="text-zinc-400">{faq.answer}</p>
          </div>
        ))}
      </div>
    </article>
  );
}
