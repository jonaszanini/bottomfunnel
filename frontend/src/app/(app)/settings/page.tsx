"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { api } from "@/lib/api";
import { Button, Field, Input, Select, PageHeader } from "@/lib/ui";

type AiProviderStatus = {
  provider: string;
  maskedApiKey?: string | null;
  defaultModel: string;
  isActive: boolean;
  configured: boolean;
  source?: string | null;
};

const PROVIDERS = [
  { value: "OpenAI", label: "OpenAI" },
  { value: "Anthropic", label: "Anthropic" },
  { value: "Gemini", label: "Google Gemini" },
];

const MODELS: Record<string, string[]> = {
  OpenAI: ["gpt-4o-mini", "gpt-4o", "gpt-4.1-mini"],
  Anthropic: ["claude-3-5-sonnet-latest", "claude-3-5-sonnet", "claude-3-5-haiku-latest", "claude-3-opus-latest"],
  Gemini: ["gemini-2.0-flash", "gemini-1.5-flash", "gemini-1.5-pro"],
};

export default function SettingsPage() {
  const [me, setMe] = useState<{ email: string; organizationName: string; role: string } | null>(null);
  const [providers, setProviders] = useState<AiProviderStatus[]>([]);
  const [provider, setProvider] = useState("OpenAI");
  const [apiKey, setApiKey] = useState("");
  const [model, setModel] = useState("gpt-4o-mini");
  const [showKey, setShowKey] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [test, setTest] = useState<{ ok: boolean; message: string } | null>(null);
  const [saveMessage, setSaveMessage] = useState("");

  const selected = useMemo(() => providers.find((p) => p.provider === provider), [providers, provider]);
  const models = MODELS[provider] ?? MODELS.OpenAI;

  useEffect(() => {
    api<{ email: string; organizationName: string; role: string }>("/auth/me").then(setMe);
    loadProviders().catch(console.error);
  }, []);

  async function loadProviders() {
    const list = await api<AiProviderStatus[]>("/api/settings/ai");
    setProviders(list);
    const current = list.find((p) => p.isActive) ?? list.find((p) => p.provider === provider) ?? list[0];
    if (!current) return;
    setProvider(current.provider);
    setApiKey(current.maskedApiKey ?? "");
    setModel(current.defaultModel);
  }

  function onProviderChange(next: string) {
    const current = providers.find((p) => p.provider === next);
    setProvider(next);
    setApiKey(current?.maskedApiKey ?? "");
    setModel(current?.defaultModel ?? (MODELS[next]?.[0] ?? "gpt-4o-mini"));
    setShowKey(false);
    setTest(null);
    setSaveMessage("");
  }

  async function onSave(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setSaveMessage("");
    setTest(null);
    try {
      const list = await api<AiProviderStatus[]>("/api/settings/ai", {
        method: "POST",
        body: JSON.stringify({ provider, apiKey, defaultModel: model, isActive: true }),
      });
      setProviders(list);
      const current = list.find((p) => p.provider === provider);
      if (current) setApiKey(current.maskedApiKey ?? "");
      setSaveMessage("Settings saved.");
    } catch (err) {
      setSaveMessage(err instanceof Error ? err.message : "Could not save settings.");
    } finally {
      setSaving(false);
    }
  }

  async function onTest() {
    setTesting(true);
    setTest(null);
    try {
      const result = await api<{ success: boolean; message: string }>("/api/settings/ai/test", {
        method: "POST",
        body: JSON.stringify({ provider, apiKey, defaultModel: model }),
      });
      setTest({ ok: result.success, message: result.message });
    } catch (err) {
      setTest({ ok: false, message: err instanceof Error ? err.message : "Connection failed." });
    } finally {
      setTesting(false);
    }
  }

  return (
    <div className="max-w-2xl space-y-6">
      <PageHeader
        title="Settings"
        summary="Account context and AI provider keys used for opportunity explanations."
        purpose="Account and organization context — not campaign workflow."
        does="Confirm who you are signed in as. Add an OpenAI, Anthropic, or Gemini key so explanations can call a model."
        flow="Outside the campaign loop. You can work Offers → Keywords → Campaigns without changing anything here."
      />
      <section className="rounded border border-zinc-800 p-4 text-sm">
        <div>User: {me?.email}</div>
        <div>Organization: {me?.organizationName}</div>
        <div>Role: {me?.role}</div>
      </section>

      <section className="rounded border border-zinc-800 p-4">
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-medium">AI Providers</h2>
            <p className="mt-1 text-sm text-zinc-500">
              Keys are encrypted at rest. Saving a provider makes it the active one for explanations.
            </p>
          </div>
          {selected?.configured ? (
            <span className="rounded-full border border-emerald-500/40 bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-400">
              {selected.source === "config" ? "From config" : "Configured"}
            </span>
          ) : (
            <span className="rounded-full border border-zinc-700 px-2 py-0.5 text-xs text-zinc-500">Not configured</span>
          )}
        </div>

        <form onSubmit={onSave} className="space-y-4">
          <Field label="Provider">
            <Select value={provider} onChange={(e) => onProviderChange(e.target.value)}>
              {PROVIDERS.map((item) => (
                <option key={item.value} value={item.value}>{item.label}</option>
              ))}
            </Select>
          </Field>
          <Field label="API key">
            <div className="flex gap-2">
              <Input
                type={showKey ? "text" : "password"}
                autoComplete="off"
                value={apiKey}
                onChange={(e) => setApiKey(e.target.value)}
                placeholder={provider === "OpenAI" ? "sk-..." : provider === "Anthropic" ? "sk-ant-..." : "AIza..."}
              />
              <button
                type="button"
                className="shrink-0 rounded border border-zinc-700 px-3 text-sm text-zinc-400 hover:border-zinc-500 hover:text-zinc-200"
                onClick={() => setShowKey((v) => !v)}
              >
                {showKey ? "Hide" : "Show"}
              </button>
            </div>
          </Field>
          <Field label="Default model">
            <Input list={`models-${provider}`} value={model} onChange={(e) => setModel(e.target.value)} />
            <datalist id={`models-${provider}`}>
              {models.map((item) => <option key={item} value={item} />)}
            </datalist>
          </Field>

          {test ? (
            <p className={`rounded border px-3 py-2 text-sm ${test.ok ? "border-emerald-500/40 bg-emerald-500/10 text-emerald-400" : "border-red-500/40 bg-red-500/10 text-red-400"}`}>
              {test.message}
            </p>
          ) : null}
          {saveMessage ? (
            <p className={`text-sm ${saveMessage === "Settings saved." ? "text-emerald-400" : "text-red-400"}`}>{saveMessage}</p>
          ) : null}

          <div className="flex flex-wrap justify-end gap-2">
            <button
              type="button"
              onClick={onTest}
              disabled={testing}
              className="rounded border border-zinc-700 px-3 py-1.5 text-sm text-zinc-300 hover:border-zinc-500 hover:text-white disabled:opacity-50"
            >
              {testing ? "Testing…" : "Test Connection"}
            </button>
            <Button type="submit" disabled={saving}>{saving ? "Saving…" : "Save Settings"}</Button>
          </div>
        </form>
      </section>
    </div>
  );
}
