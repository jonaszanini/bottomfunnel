import React, { useState } from 'react';
import { Field, button, control, quiet } from '../../../Components/Layout';

export default function OfferConfigForm({
    tracks = [],
    googleAdsAccounts = [],
    config,
    onChangeConfig,
    onBack,
    onGenerate,
    isGenerating,
    error,
}) {
    const [urlError, setUrlError] = useState('');

    const [selectedAccountId, setSelectedAccountId] = useState(() => {
        const initialTrack = tracks.find((t) => String(t.id) === String(config.trackId));
        if (initialTrack?.google_ads_account_id) return String(initialTrack.google_ads_account_id);
        const def = googleAdsAccounts.find((a) => a.is_default);
        return def ? String(def.id) : (googleAdsAccounts[0] ? String(googleAdsAccounts[0].id) : '');
    });

    const [selectedActionId, setSelectedActionId] = useState(() => {
        const initialTrack = tracks.find((t) => String(t.id) === String(config.trackId));
        return initialTrack?.conversion_action_id ? String(initialTrack.conversion_action_id) : '';
    });

    const [isFetchingActionTag, setIsFetchingActionTag] = useState(false);
    const [globalTagFeedback, setGlobalTagFeedback] = useState({ message: '', type: '' });
    const [actionTagFeedback, setActionTagFeedback] = useState({ message: '', type: '' });

    const activeAccount = googleAdsAccounts.find((a) => String(a.id) === String(selectedAccountId));
    const availableActions = activeAccount?.conversion_actions || activeAccount?.conversionActions || [];

    const languageOptions = [
        { code: 'en', label: 'English (United States, Global)' },
        { code: 'pt', label: 'Português (Brasil, Portugal)' },
        { code: 'es', label: 'Español (Latam, España)' },
        { code: 'de', label: 'Deutsch (Deutschland, DACH)' },
        { code: 'fr', label: 'Français (France, Canada)' },
        { code: 'it', label: 'Italiano (Italia)' },
    ];

    function handleTrackSelect(trackId) {
        if (!trackId) {
            onChangeConfig('trackId', '');
            return;
        }
        const track = tracks.find((t) => String(t.id) === String(trackId));
        if (track) {
            onChangeConfig('trackId', track.id);
            if (track.product_name) onChangeConfig('productName', track.product_name);
            if (track.affiliate_url) onChangeConfig('affiliateUrl', track.affiliate_url);
            if (track.language) onChangeConfig('language', track.language);
            if (track.google_ads_account_id) {
                setSelectedAccountId(String(track.google_ads_account_id));
            }
            if (track.conversion_action_id) {
                setSelectedActionId(String(track.conversion_action_id));
            }
        }
    }

    function handleInsertGlobalTag() {
        if (!selectedAccountId) {
            setGlobalTagFeedback({
                message: 'Please select a Google Ads account first.',
                type: 'error',
            });
            return;
        }

        const account = googleAdsAccounts.find((a) => String(a.id) === String(selectedAccountId));
        if (!account) {
            setGlobalTagFeedback({
                message: 'Selected Google Ads account could not be found.',
                type: 'error',
            });
            return;
        }

        const digits = String(account.customer_id || '').replace(/\D/g, '');
        if (!digits) {
            setGlobalTagFeedback({
                message: 'The selected account does not have a valid customer ID.',
                type: 'error',
            });
            return;
        }

        const tagSnippet = `<!-- Google tag (gtag.js) -->\n<script async src="https://www.googletagmanager.com/gtag/js?id=AW-${digits}"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n\n  gtag('config', 'AW-${digits}');\n</script>`;

        const current = (config.googleGlobalTag || '').trim();
        if (current && current.includes(`AW-${digits}`)) {
            setGlobalTagFeedback({
                message: 'Account global tag is already present in the field.',
                type: 'info',
            });
            return;
        }

        onChangeConfig('googleGlobalTag', tagSnippet);
        setGlobalTagFeedback({
            message: `Account global tag (AW-${digits}) inserted successfully.`,
            type: 'success',
        });
    }

    async function handleInsertActionTag() {
        if (!selectedAccountId) {
            setActionTagFeedback({
                message: 'Please select a Google Ads account first.',
                type: 'error',
            });
            return;
        }

        if (!selectedActionId || selectedActionId === 'default') {
            setActionTagFeedback({
                message: 'Please select a conversion action first.',
                type: 'error',
            });
            return;
        }

        setIsFetchingActionTag(true);
        setActionTagFeedback({ message: '', type: '' });

        try {
            const res = await fetch(`/google-ads/accounts/${selectedAccountId}/conversion-actions/${selectedActionId}/tag`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.message || 'Failed to fetch conversion action tag snippet.');
            }

            const snippet = (data.event_snippet || data.global_site_tag || '').trim();
            if (!snippet) {
                throw new Error('No conversion action tag snippet returned by Google Ads for this action.');
            }

            const current = (config.googleActionTag || '').trim();
            if (current && current.includes(snippet)) {
                setActionTagFeedback({
                    message: 'Conversion action tag is already present in the field.',
                    type: 'info',
                });
                return;
            }

            onChangeConfig('googleActionTag', snippet);
            setActionTagFeedback({
                message: data.action_name
                    ? `Conversion action tag for "${data.action_name}" inserted successfully.`
                    : 'Conversion action tag inserted successfully.',
                type: 'success',
            });
        } catch (err) {
            setActionTagFeedback({
                message: err.message || 'Error inserting conversion action tag.',
                type: 'error',
            });
        } finally {
            setIsFetchingActionTag(false);
        }
    }

    function handleUrlChange(value) {
        onChangeConfig('affiliateUrl', value);
        if (value && !/^https?:\/\/.+/i.test(value.trim())) {
            setUrlError('Please enter a valid destination URL beginning with http:// or https://');
        } else {
            setUrlError('');
        }
    }

    function handleSubmit(e) {
        e.preventDefault();
        if (!config.productName.trim()) {
            return;
        }
        if (!config.affiliateUrl.trim() || !/^https?:\/\/.+/i.test(config.affiliateUrl.trim())) {
            setUrlError('A valid affiliate destination URL starting with http:// or https:// is required.');
            return;
        }
        onGenerate();
    }

    return (
        <div className="max-w-3xl mx-auto space-y-6">
            <div className="text-center">
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme mb-2">
                    Step 2 of 5 &bull; Offer Configuration &amp; Attribution
                </span>
                <h2 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                    Configure Offer Parameters &amp; Tracking
                </h2>
                <p className="mt-2 text-sm text-theme-secondary">
                    Define the destination link, regional language, and conversion tracking tags. Our compiler will automatically bind incoming UTMs and click IDs.
                </p>
            </div>

            {error && (
                <div className="rounded-lg border border-[var(--dangerBorderColor)] bg-[var(--dangerBackgroundColor)] p-4 text-sm text-[var(--dangerColor)] flex items-start gap-3 shadow-xs">
                    <svg className="w-5 h-5 shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                    </svg>
                    <div>
                        <strong className="font-semibold block">Generation Alert</strong>
                        <span>{error}</span>
                    </div>
                </div>
            )}

            <form onSubmit={handleSubmit} className="rounded-xl border border-theme bg-theme-surface p-6 shadow-sm space-y-5">
                {tracks.length > 0 && (
                    <div className="p-3.5 rounded-lg border border-theme bg-theme-card/60 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                        <div className="text-xs">
                            <strong className="text-theme-primary block font-semibold">Pre-fill from an Active Track (Optional)</strong>
                            <span className="text-theme-muted">Auto-populates product name, affiliate link, and language settings.</span>
                        </div>
                        <select
                            className={`${control} sm:w-64 text-xs`}
                            value={config.trackId || ''}
                            onChange={(e) => handleTrackSelect(e.target.value)}
                        >
                            <option value="">Manual Standalone Offer</option>
                            {tracks.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.product_name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Product, Brand, or Offer Name *">
                        <input
                            type="text"
                            required
                            placeholder="e.g., Fimavyn Metabolic Support"
                            className={control}
                            value={config.productName}
                            onChange={(e) => onChangeConfig('productName', e.target.value)}
                        />
                        <span className="text-xs text-theme-muted mt-1 block">
                            Used for headline generation, browser title, and semantic context.
                        </span>
                    </Field>

                    <Field label="Target Offer Language *">
                        <select
                            className={control}
                            value={config.language}
                            onChange={(e) => onChangeConfig('language', e.target.value)}
                        >
                            {languageOptions.map((opt) => (
                                <option key={opt.code} value={opt.code}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                        <span className="text-xs text-theme-muted mt-1 block">
                            Applies localized mental triggers, copy, and date formats.
                        </span>
                    </Field>
                </div>

                <Field label="Affiliate Destination URL *" error={urlError}>
                    <input
                        type="url"
                        required
                        placeholder="https://hop.clickbank.net/?aff=myid&vendor=seller"
                        className={control}
                        value={config.affiliateUrl}
                        onChange={(e) => handleUrlChange(e.target.value)}
                    />
                    <span className="text-xs text-theme-muted mt-1 block">
                        Direct merchant destination link (Hotmart, ClickBank, Digistore24, MaxWeb). Bound into all CTAs.
                    </span>
                </Field>

                <div className="space-y-4 pt-1">
                    <div className="border-b border-theme pb-2">
                        <label className="text-sm font-semibold text-theme-primary block">
                            Tracking Pixels &amp; Scripts
                        </label>
                        <span className="text-xs text-theme-muted">
                            Configure Google Ads tracking tags and additional third-party scripts. Injected safely into the page &lt;head&gt;.
                        </span>
                    </div>

                    {/* 1. Account Global Tag */}
                    <div className="p-4 rounded-xl border border-theme bg-theme-surface space-y-3">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <label className="text-sm font-semibold text-theme-primary flex items-center gap-1.5">
                                    <span>1. Account Global Tag</span>
                                    <span className="text-[11px] font-normal text-theme-muted">(gtag.js)</span>
                                </label>
                                <span className="text-xs text-theme-muted block">
                                    Standard Google tag injected into &lt;head&gt;. Built using the account customer ID (AW-XXXXXXXX).
                                </span>
                            </div>

                            {googleAdsAccounts.length > 0 ? (
                                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                                    <select
                                        className="py-1 px-2 text-xs rounded border border-theme bg-theme-surface text-theme-primary"
                                        value={selectedAccountId}
                                        onChange={(e) => {
                                            setSelectedAccountId(e.target.value);
                                            setSelectedActionId('');
                                        }}
                                        title="Choose Google Ads account"
                                    >
                                        <option value="">Select Google Ads Account...</option>
                                        {googleAdsAccounts.map((acc) => (
                                            <option key={acc.id} value={acc.id}>
                                                {acc.name} ({acc.customer_id})
                                            </option>
                                        ))}
                                    </select>

                                    <button
                                        type="button"
                                        onClick={handleInsertGlobalTag}
                                        disabled={!selectedAccountId}
                                        className="px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--accentColor)] text-white hover:opacity-90 shadow-2xs transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Insert standard gtag.js using account customer ID (no API call)"
                                    >
                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                        </svg>
                                        <span>Insert account global tag</span>
                                    </button>
                                </div>
                            ) : (
                                <span className="text-xs text-theme-muted italic">No linked Google Ads accounts found.</span>
                            )}
                        </div>

                        {globalTagFeedback.message && (
                            <div
                                className={`p-2 rounded text-xs flex items-center justify-between gap-2 ${
                                    globalTagFeedback.type === 'success'
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800'
                                        : globalTagFeedback.type === 'error'
                                        ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300 border border-red-300 dark:border-red-800'
                                        : 'bg-sky-50 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300 border border-sky-300 dark:border-sky-800'
                                }`}
                            >
                                <span>{globalTagFeedback.message}</span>
                                <button
                                    type="button"
                                    onClick={() => setGlobalTagFeedback({ message: '', type: '' })}
                                    className="text-xs opacity-70 hover:opacity-100 cursor-pointer"
                                >
                                    &times;
                                </button>
                            </div>
                        )}

                        <textarea
                            rows="3"
                            placeholder="<!-- Google tag (gtag.js) AW-XXXXXXXX -->"
                            className={`${control} font-mono text-xs`}
                            value={config.googleGlobalTag || ''}
                            onChange={(e) => onChangeConfig('googleGlobalTag', e.target.value)}
                        />
                    </div>

                    {/* 2. Conversion Action Tag (Optional) */}
                    <div className="p-4 rounded-xl border border-theme bg-theme-surface space-y-3">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                            <div>
                                <label className="text-sm font-semibold text-theme-primary flex items-center gap-1.5">
                                    <span>2. Conversion Action Tag</span>
                                    <span className="text-[11px] font-normal text-theme-muted">(Optional)</span>
                                </label>
                                <span className="text-xs text-theme-muted block">
                                    Specific conversion event snippet fetched via Google Ads API.
                                </span>
                            </div>

                            {googleAdsAccounts.length > 0 && (
                                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                                    <select
                                        className="py-1 px-2 text-xs rounded border border-theme bg-theme-surface text-theme-primary max-w-[200px]"
                                        value={selectedActionId}
                                        onChange={(e) => setSelectedActionId(e.target.value)}
                                        disabled={!selectedAccountId || availableActions.length === 0}
                                        title="Choose conversion action"
                                    >
                                        <option value="">
                                            {!selectedAccountId
                                                ? 'Select account first'
                                                : availableActions.length === 0
                                                ? 'No conversion actions'
                                                : 'Select conversion action...'}
                                        </option>
                                        {availableActions.map((act) => (
                                            <option key={act.id} value={act.id}>
                                                {act.name}
                                            </option>
                                        ))}
                                    </select>

                                    <button
                                        type="button"
                                        onClick={handleInsertActionTag}
                                        disabled={!selectedAccountId || !selectedActionId || isFetchingActionTag}
                                        className="px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--accentColor)] text-white hover:opacity-90 shadow-2xs transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                        title="Fetch and insert Google Ads conversion event tag snippet"
                                    >
                                        {isFetchingActionTag ? (
                                            <>
                                                <svg className="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                </svg>
                                                <span>Fetching...</span>
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                                </svg>
                                                <span>Insert conversion action tag</span>
                                            </>
                                        )}
                                    </button>
                                </div>
                            )}
                        </div>

                        {actionTagFeedback.message && (
                            <div
                                className={`p-2 rounded text-xs flex items-center justify-between gap-2 ${
                                    actionTagFeedback.type === 'success'
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800'
                                        : actionTagFeedback.type === 'error'
                                        ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300 border border-red-300 dark:border-red-800'
                                        : 'bg-sky-50 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300 border border-sky-300 dark:border-sky-800'
                                }`}
                            >
                                <span>{actionTagFeedback.message}</span>
                                <button
                                    type="button"
                                    onClick={() => setActionTagFeedback({ message: '', type: '' })}
                                    className="text-xs opacity-70 hover:opacity-100 cursor-pointer"
                                >
                                    &times;
                                </button>
                            </div>
                        )}

                        <textarea
                            rows="3"
                            placeholder="<!-- Event snippet for conversion action -->"
                            className={`${control} font-mono text-xs`}
                            value={config.googleActionTag || ''}
                            onChange={(e) => onChangeConfig('googleActionTag', e.target.value)}
                        />
                    </div>

                    {/* 3. Additional Scripts */}
                    <div className="p-4 rounded-xl border border-theme bg-theme-surface space-y-3">
                        <div>
                            <label className="text-sm font-semibold text-theme-primary flex items-center gap-1.5">
                                <span>3. Additional Scripts</span>
                                <span className="text-[11px] font-normal text-theme-muted">(Optional)</span>
                            </label>
                            <span className="text-xs text-theme-muted block">
                                Free-text area for any other tracking scripts (Meta Pixel, TikTok Pixel, UTM scripts, etc.).
                            </span>
                        </div>

                        <textarea
                            rows="3"
                            placeholder="<!-- Meta Pixel, TikTok, or custom head scripts -->"
                            className={`${control} font-mono text-xs`}
                            value={config.trackingCode}
                            onChange={(e) => onChangeConfig('trackingCode', e.target.value)}
                        />
                        <span className="text-xs text-theme-muted block">
                            Injected safely into &lt;head&gt;. All outgoing clicks dynamically forward gclid, wbraid, gbraid, and utm_* tags.
                        </span>
                    </div>
                </div>

                <div className="pt-2 border-t border-theme">
                    <div className="flex items-center justify-between p-3 rounded-lg bg-theme-subtle border border-theme">
                        <div className="pr-4">
                            <span className="text-sm font-semibold text-theme-primary block">
                                High Quality Score SEO Markup
                            </span>
                            <span className="text-xs text-theme-secondary">
                                Generates Open Graph metadata, semantic H1/H2 landmarks, schema.org attributes, and index tags to lower Google Ads CPC.
                            </span>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={config.seoEnabled}
                            onClick={() => onChangeConfig('seoEnabled', !config.seoEnabled)}
                            className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--accentFocusRing)] ${
                                config.seoEnabled ? 'bg-[var(--accentColor)]' : 'bg-stone-300 dark:bg-stone-700'
                            }`}
                        >
                            <span
                                className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out ${
                                    config.seoEnabled ? 'translate-x-5' : 'translate-x-0'
                                }`}
                            />
                        </button>
                    </div>
                </div>

                <div className="flex items-center justify-between pt-4">
                    <button
                        type="button"
                        onClick={onBack}
                        disabled={isGenerating}
                        className={quiet}
                    >
                        &larr; Back to Strategy
                    </button>

                    <button
                        type="submit"
                        disabled={isGenerating || !config.productName || !config.affiliateUrl}
                        className={`${button} px-6 py-2.5 flex items-center gap-2 font-semibold`}
                    >
                        {isGenerating ? (
                            <>
                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <span>Generating 3 Variations...</span>
                            </>
                        ) : (
                            <>
                                <span>Generate 3 Variations</span>
                                <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
                                </svg>
                            </>
                        )}
                    </button>
                </div>
            </form>
        </div>
    );
}
