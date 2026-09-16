"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { createPortal } from "react-dom";
import { useEffect, useId, useRef, useState } from "react";
import { clearToken, getToken } from "./api";

const NAV = [
  ["/guide", "How it works"],
  ["/networks", "Networks"],
  ["/offers", "Offers"],
  ["/keywords", "Keywords"],
  ["/opportunities", "Opportunities"],
  ["/landing-pages", "Landing pages"],
  ["/campaigns", "Campaigns"],
  ["/tracking", "Tracking"],
  ["/", "Dashboard"],
  ["/merchants", "Merchants"],
  ["/products", "Products"],
  ["/settings", "Settings"],
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();

  useEffect(() => {
    if (!getToken()) router.replace("/login");
  }, [router]);

  return (
    <div className="flex min-h-screen bg-zinc-950 text-zinc-100">
      <aside className="w-56 shrink-0 border-r border-zinc-800 bg-zinc-950 p-4">
        <div className="mb-8 text-xs font-semibold tracking-[0.2em] text-emerald-400">BOTTOM FUNNEL</div>
        <nav className="flex flex-col gap-1 text-sm">
          {NAV.map(([href, label]) => {
            const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
            return (
              <Link
                key={href}
                href={href}
                className={`rounded px-3 py-2 ${active ? "bg-zinc-800 text-white" : "text-zinc-400 hover:bg-zinc-900 hover:text-white"}`}
              >
                {label}
              </Link>
            );
          })}
        </nav>
        <button
          className="mt-10 px-3 text-left text-xs text-zinc-500 hover:text-zinc-300"
          onClick={() => {
            clearToken();
            router.replace("/login");
          }}
        >
          Sign out
        </button>
      </aside>
      <main className="flex-1 overflow-auto p-8">{children}</main>
    </div>
  );
}

export function PageHeader({
  title,
  summary,
  purpose,
  does,
  flow,
  actions,
}: {
  title: string;
  summary?: string;
  purpose: string;
  does: string;
  flow: string;
  actions?: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const panelId = useId();

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              aria-expanded={open}
              aria-controls={panelId}
              aria-label={`About ${title}`}
              onClick={() => setOpen((v) => !v)}
              className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold ${open ? "border-emerald-500 bg-emerald-500 text-zinc-950" : "border-zinc-600 text-zinc-400 hover:border-emerald-500 hover:text-emerald-400"}`}
            >
              i
            </button>
            <h1 className="text-2xl font-semibold">{title}</h1>
          </div>
          {summary ? <p className="mt-1 pl-8 text-sm text-zinc-500">{summary}</p> : null}
        </div>
        {actions ? <div className="shrink-0">{actions}</div> : null}
      </div>
      {open ? (
        <div id={panelId} className="rounded border border-zinc-800 bg-zinc-900 p-4 text-sm">
          <dl className="grid gap-3 md:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wide text-emerald-400">What this page is for</dt>
              <dd className="mt-1 text-zinc-300">{purpose}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-emerald-400">What you do here</dt>
              <dd className="mt-1 text-zinc-300">{does}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-emerald-400">Place in the flow</dt>
              <dd className="mt-1 text-zinc-300">{flow}</dd>
            </div>
          </dl>
        </div>
      ) : null}
    </div>
  );
}

export function money(n?: number, currency = "USD") {
  return new Intl.NumberFormat("en-CA", { style: "currency", currency }).format(n ?? 0);
}

export function Table({ children }: { children: React.ReactNode }) {
  return <div className="overflow-x-auto rounded border border-zinc-800"><table className="w-full text-left text-sm">{children}</table></div>;
}

export function Th({ children, className }: { children?: React.ReactNode; className?: string }) {
  return <th className={`border-b border-zinc-800 px-3 py-2 text-xs uppercase tracking-wide text-zinc-500 ${className ?? ""}`}>{children}</th>;
}

export function Td({ children, className }: { children?: React.ReactNode; className?: string }) {
  return <td className={`border-b border-zinc-900 px-3 py-2 align-top ${className ?? ""}`}>{children}</td>;
}

export function Button({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      {...props}
      className={`rounded bg-emerald-500 px-3 py-1.5 text-sm font-medium text-zinc-950 hover:bg-emerald-400 disabled:opacity-50 ${props.className ?? ""}`}
    >
      {children}
    </button>
  );
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      {...props}
      className={`w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 outline-none focus:border-emerald-500 ${props.className ?? ""}`}
    />
  );
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      {...props}
      className={`w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 ${props.className ?? ""}`}
    />
  );
}

export function InfoTip({ text }: { text: string }) {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const [pos, setPos] = useState({ top: 0, left: 0 });
  const tooltipId = useId();

  function show() {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect) {
      const width = 256;
      const left = Math.min(Math.max(8, rect.left - width / 2 + rect.width / 2), window.innerWidth - width - 8);
      setPos({ top: rect.bottom + 6, left });
    }
    setOpen(true);
  }

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        aria-label={text}
        aria-expanded={open}
        aria-describedby={open ? tooltipId : undefined}
        onMouseDown={(e) => e.preventDefault()}
        onMouseEnter={show}
        onMouseLeave={() => setOpen(false)}
        onFocus={show}
        onBlur={() => setOpen(false)}
        onClick={(e) => {
          e.preventDefault();
          show();
        }}
        className="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border border-zinc-600 text-[10px] leading-none text-zinc-400 hover:border-emerald-500 hover:text-emerald-400 focus:border-emerald-500 focus:text-emerald-400 focus:outline-none"
      >
        ?
      </button>
      {open
        ? createPortal(
            <span
              id={tooltipId}
              role="tooltip"
              style={{ top: pos.top, left: pos.left }}
              className="pointer-events-none fixed z-[80] w-64 rounded border border-zinc-700 bg-zinc-900 p-2 text-left text-xs font-normal leading-relaxed text-zinc-300 shadow-lg"
            >
              {text}
            </span>,
            document.body,
          )
        : null}
    </>
  );
}

export function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block text-xs text-zinc-400">
      <span className="mb-1 flex items-center gap-1.5">
        <span>{label}</span>
        {hint ? <InfoTip text={hint} /> : null}
      </span>
      {children}
    </label>
  );
}

export function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <textarea
      {...props}
      className={`w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 outline-none focus:border-emerald-500 ${props.className ?? ""}`}
    />
  );
}

export function Checkbox({ label, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className="flex items-center gap-2 text-sm text-zinc-300">
      <input type="checkbox" {...props} className="accent-emerald-500" />
      {label}
    </label>
  );
}
