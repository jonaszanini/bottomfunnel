"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

export default function SettingsPage() {
  const [me, setMe] = useState<{ email: string; organizationName: string; role: string } | null>(null);
  const [ai, setAi] = useState<{ configured: boolean } | null>(null);

  useEffect(() => {
    api<{ email: string; organizationName: string; role: string }>("/auth/me").then(setMe);
    api<{ configured: boolean }>("/ai/status").then(setAi);
  }, []);

  return (
    <div className="max-w-xl space-y-4">
      <h1 className="text-2xl font-semibold">Settings</h1>
      <div className="rounded border border-zinc-800 p-4 text-sm">
        <div>User: {me?.email}</div>
        <div>Organization: {me?.organizationName}</div>
        <div>Role: {me?.role}</div>
        <div className="mt-3">AI: {ai?.configured ? "configured" : "missing (set Ai:ApiKey in API config)"}</div>
      </div>
    </div>
  );
}
