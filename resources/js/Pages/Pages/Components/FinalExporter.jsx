import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Field, button, control, quiet } from '../../../Components/Layout';

export default function FinalExporter({
    variation,
    productName,
    strategyType,
    language,
    tracks = [],
    selectedTrackId,
    onBackToEditor,
    onStartNew,
    onSaveToDb,
    isSaving,
}) {
    const [viewMode, setViewMode] = useState('preview'); // 'preview' | 'code'
    const [viewport, setViewport] = useState('desktop'); // 'desktop' | 'mobile'
    const [copied, setCopied] = useState(false);
    const [showSaveModal, setShowSaveModal] = useState(false);
    const [saveForm, setSaveForm] = useState({
        name: `${productName} Presell (${variation.id.toUpperCase()})`,
        track_id: selectedTrackId || '',
    });

    const compiledHtml = variation.compiledHtml || '';
    const byteSize = new Blob([compiledHtml]).size;
    const kbSize = (byteSize / 1024).toFixed(1);

    function handleCopy() {
        if (!compiledHtml) return;
        navigator.clipboard.writeText(compiledHtml).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        });
    }

    function handleDownload() {
        if (!compiledHtml) return;
        const blob = new Blob([compiledHtml], { type: 'text/html;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const safeName = productName.toLowerCase().replace(/[^a-z0-9]+/g, '-');
        a.href = url;
        a.download = `${safeName}-presell-${variation.id}.html`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function handleOpenWindow() {
        const win = window.open('', '_blank');
        if (win) {
            win.document.open();
            win.document.write(compiledHtml);
            win.document.close();
        }
    }

    function handleSaveSubmit(e) {
        e.preventDefault();
        onSaveToDb({
            name: saveForm.name,
            track_id: saveForm.track_id || null,
            page_type: strategyType,
            language: language,
            html: compiledHtml,
            variation: variation,
        });
        setShowSaveModal(false);
    }

    return (
        <div className="space-y-6">
            {/* Header & Stats */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme mb-1">
                        Step 5 of 5 &bull; Interactive Preview &amp; HTML Export
                    </span>
                    <h2 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                        Compiled Production Presell Page
                    </h2>
                    <p className="text-sm text-theme-secondary mt-1">
                        Self-contained single-file HTML5 document with inline CSS, attribution-forwarding JavaScript, and zero external dependencies.
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={handleCopy}
                        className={`${button} py-2 px-4 text-xs flex items-center gap-1.5 font-semibold shadow-xs`}
                    >
                        {copied ? (
                            <>
                                <span className="text-emerald-200">&check;</span>
                                <span>Copied to Clipboard!</span>
                            </>
                        ) : (
                            <>
                                <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                </svg>
                                <span>Copy Clean HTML</span>
                            </>
                        )}
                    </button>

                    <button
                        type="button"
                        onClick={handleDownload}
                        className={`${quiet} py-2 px-3 text-xs flex items-center gap-1.5`}
                        title="Download standalone index.html"
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="7 10 12 15 17 10" />
                            <line x1="12" y1="15" x2="12" y2="3" />
                        </svg>
                        <span>Download HTML</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setShowSaveModal(true)}
                        className={`${quiet} py-2 px-3 text-xs flex items-center gap-1.5 text-theme-accent border-theme-accent/50`}
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                            <polyline points="7 3 7 8 15 8" />
                        </svg>
                        <span>Save to Flow Hub</span>
                    </button>

                    <Link
                        href={`/copy?url=${encodeURIComponent(variation.affiliateUrl || '')}&product=${encodeURIComponent(productName || '')}&lang=${encodeURIComponent(language || 'en')}`}
                        className={`${quiet} py-2 px-3 text-xs flex items-center gap-1.5 text-indigo-600 dark:text-indigo-400 border-indigo-300 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 font-medium`}
                        title="Generate Google Ads Copy for this offer"
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M12 20h9" />
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                        </svg>
                        <span>Generate Ad Copy for this Offer</span>
                    </Link>
                </div>
            </div>

            {/* View Mode Bar: Preview vs Code + Viewport Toggles */}
            <div className="rounded-xl border border-theme bg-theme-surface p-2.5 shadow-xs flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    {/* View mode toggle */}
                    <div className="flex items-center rounded-lg border border-theme bg-theme-card p-1">
                        <button
                            type="button"
                            onClick={() => setViewMode('preview')}
                            className={`px-3 py-1 rounded-md text-xs font-medium transition-colors ${
                                viewMode === 'preview'
                                    ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary'
                            }`}
                        >
                            Interactive Live Preview
                        </button>
                        <button
                            type="button"
                            onClick={() => setViewMode('code')}
                            className={`px-3 py-1 rounded-md text-xs font-medium transition-colors ${
                                viewMode === 'code'
                                    ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary'
                            }`}
                        >
                            Compiled HTML Source
                        </button>
                    </div>

                    {/* Viewport toggle (only when previewing) */}
                    {viewMode === 'preview' && (
                        <div className="flex items-center rounded-lg border border-theme bg-theme-card p-1">
                            <button
                                type="button"
                                onClick={() => setViewport('desktop')}
                                className={`px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                    viewport === 'desktop'
                                        ? 'bg-theme-surface text-theme-primary shadow-xs'
                                        : 'text-theme-muted hover:text-theme-secondary'
                                }`}
                                title="Desktop 100% width"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <rect width="20" height="14" x="2" y="3" rx="2" />
                                    <line x1="8" y1="21" x2="16" y2="21" />
                                    <line x1="12" y1="17" x2="12" y2="21" />
                                </svg>
                                <span>Desktop</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setViewport('mobile')}
                                className={`px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                    viewport === 'mobile'
                                        ? 'bg-theme-surface text-theme-primary shadow-xs'
                                        : 'text-theme-muted hover:text-theme-secondary'
                                }`}
                                title="Mobile 375px viewport"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <rect width="14" height="20" x="5" y="2" rx="2" ry="2" />
                                    <path d="M12 18h.01" />
                                </svg>
                                <span>Mobile</span>
                            </button>
                        </div>
                    )}
                </div>

                {/* Quality & Footprint Specs */}
                <div className="flex items-center gap-3 text-xs text-theme-secondary">
                    <span className="inline-flex items-center gap-1">
                        <span className="w-2 h-2 rounded-full bg-emerald-500" />
                        Ultra-Light: <strong>{kbSize} KB</strong>
                    </span>
                    <span className="hidden sm:inline text-theme-muted">&bull;</span>
                    <span className="hidden sm:inline">
                        Anti-Footprint: <code className="text-theme-accent">{compiledHtml.match(/fp_[a-z0-9]+_/)?.[0] || 'fp_unique_'}</code>
                    </span>
                    <button
                        type="button"
                        onClick={handleOpenWindow}
                        className="text-theme-accent hover:underline flex items-center gap-1 text-xs font-medium"
                    >
                        <span>Open in New Tab</span>
                        <svg className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                            <polyline points="15 3 21 3 21 9" />
                            <line x1="10" y1="14" x2="21" y2="3" />
                        </svg>
                    </button>
                </div>
            </div>

            {/* Main Stage View */}
            {viewMode === 'preview' ? (
                <div className="rounded-xl border border-theme bg-stone-100 dark:bg-stone-900 p-4 min-h-[640px] flex items-center justify-center overflow-auto shadow-inner">
                    {viewport === 'desktop' ? (
                        <div className="w-full h-[640px] rounded-lg border border-theme bg-white shadow-md overflow-hidden">
                            <iframe
                                title="Desktop Live Preview"
                                srcDoc={compiledHtml}
                                sandbox="allow-scripts allow-forms"
                                className="w-full h-full border-0"
                            />
                        </div>
                    ) : (
                        /* Mobile Viewport Simulation Frame */
                        <div className="w-[375px] h-[667px] rounded-[36px] border-[10px] border-stone-800 bg-white shadow-2xl overflow-hidden flex flex-col relative">
                            {/* Phone Speaker Notch */}
                            <div className="w-32 h-4 bg-stone-800 self-center rounded-b-xl z-10 flex items-center justify-center">
                                <div className="w-10 h-1 bg-stone-600 rounded-full" />
                            </div>
                            <iframe
                                title="Mobile Live Preview"
                                srcDoc={compiledHtml}
                                sandbox="allow-scripts allow-forms"
                                className="w-full flex-1 border-0"
                            />
                            {/* Bottom Home Indicator */}
                            <div className="w-full h-4 bg-stone-800 flex items-center justify-center">
                                <div className="w-24 h-1 bg-stone-500 rounded-full" />
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                /* Monospace Clean Code Viewer */
                <div className="rounded-xl border border-theme bg-stone-950 text-stone-200 p-4 shadow-md font-mono text-xs overflow-auto max-h-[640px]">
                    <div className="flex items-center justify-between pb-3 mb-3 border-b border-stone-800 text-[11px] text-stone-400">
                        <span>Production Ready Standalone HTML</span>
                        <span>{compiledHtml.split('\n').length} lines &bull; {compiledHtml.length} characters</span>
                    </div>
                    <pre className="whitespace-pre-wrap break-all select-all">
                        {compiledHtml}
                    </pre>
                </div>
            )}

            {/* Bottom Actions */}
            <div className="flex items-center justify-between pt-4 border-t border-theme">
                <button
                    type="button"
                    onClick={onBackToEditor}
                    className={quiet}
                >
                    &larr; Back to Parameter Editor
                </button>

                <button
                    type="button"
                    onClick={onStartNew}
                    className={`${quiet} text-theme-accent`}
                >
                    + Generate Another Presell
                </button>
            </div>

            {/* Save to Flow Hub Modal */}
            {showSaveModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md rounded-xl border border-theme bg-theme-surface p-6 shadow-xl space-y-4">
                        <div className="flex items-center justify-between border-b border-theme pb-3">
                            <h3 className="text-lg font-bold text-theme-primary">
                                Save to Flow Hub Library
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowSaveModal(false)}
                                className="text-theme-muted hover:text-theme-primary text-xl font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        <form onSubmit={handleSaveSubmit} className="space-y-4">
                            <Field label="Page Name">
                                <input
                                    type="text"
                                    required
                                    className={control}
                                    value={saveForm.name}
                                    onChange={(e) => setSaveForm({ ...saveForm, name: e.target.value })}
                                />
                            </Field>

                            {tracks.length > 0 && (
                                <Field label="Link to Campaign Track (Optional)">
                                    <select
                                        className={control}
                                        value={saveForm.track_id}
                                        onChange={(e) => setSaveForm({ ...saveForm, track_id: e.target.value })}
                                    >
                                        <option value="">Standalone (No Track)</option>
                                        {tracks.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.product_name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <div className="flex items-center justify-end gap-2 pt-3 border-t border-theme">
                                <button
                                    type="button"
                                    onClick={() => setShowSaveModal(false)}
                                    className={quiet}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSaving}
                                    className={button}
                                >
                                    {isSaving ? 'Saving...' : 'Confirm & Save Page'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
