import React, { useState } from 'react';
import { Field, button, control, quiet } from '../../../Components/Layout';

export default function OfferConfigForm({
    tracks = [],
    config,
    onChangeConfig,
    onBack,
    onGenerate,
    isGenerating,
    error,
}) {
    const [urlError, setUrlError] = useState('');

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

                <Field label="Tracking Pixels &amp; Scripts (Optional)">
                    <textarea
                        rows="3"
                        placeholder="<!-- Google Ads gtag, Meta Pixel, or UTM scripts -->"
                        className={`${control} font-mono text-xs`}
                        value={config.trackingCode}
                        onChange={(e) => onChangeConfig('trackingCode', e.target.value)}
                    />
                    <span className="text-xs text-theme-muted mt-1 block">
                        Injected safely into &lt;head&gt;. All outgoing clicks dynamically forward gclid, wbraid, gbraid, and utm_* tags.
                    </span>
                </Field>

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
