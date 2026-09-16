"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { PageHeader } from "@/lib/ui";

export default function SettingsPage() {
  const [me, setMe] = useState<{ email: string; organizationName: string; role: string } | null>(null);
  const [ai, setAi] = useState<{ configured: boolean } | null>(null);

  useEffect(() => {
    api<{ email: string; organizationName: string; role: string }>("/auth/me").then(setMe);
    api<{ configured: boolean }>("/ai/status").then(setAi);
  }, []);

  return (
    <div className="max-w-xl space-y-4">
      <PageHeader
        title="Settings"
        summary="Who you are signed in as, and whether AI explanations are configured."
        purpose="Account and organization context — not campaign workflow."
        does="Confirm email, org, role, and AI key status. Set Ai:ApiKey on the API if you want opportunity explanations."
        flow="Outside the campaign loop. You can work Offers → Keywords → Campaigns without changing anything here."
      />
      <div className="rounded border border-zinc-800 p-4 text-sm">
        <div>User: {me?.email}</div>
        <div>Organization: {me?.organizationName}</div>
        <div>Role: {me?.role}</div>
        <div className="mt-3">AI: {ai?.configured ? "configured" : "missing (set Ai:ApiKey in API config)"}</div>
      </div>
    </div>
  );
}
