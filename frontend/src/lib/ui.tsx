"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { clearToken, getToken } from "./api";

const NAV = [
  ["/", "Dashboard"],
  ["/opportunities", "Opportunities"],
  ["/offers", "Offers"],
  ["/products", "Products"],
  ["/merchants", "Merchants"],
  ["/keywords", "Keywords"],
  ["/campaigns", "Campaigns"],
  ["/landing-pages", "Landing pages"],
  ["/tracking", "Tracking"],
  ["/networks", "Networks"],
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

export function money(n?: number) {
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(n ?? 0);
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

export function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="block text-xs text-zinc-400">
      <span className="mb-1 block">{label}</span>
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
