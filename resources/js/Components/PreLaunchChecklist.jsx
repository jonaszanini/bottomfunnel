import React, { useState } from 'react';

/**
 * PreLaunchChecklist component
 *
 * Displays an informational pre-launch readiness checklist with green/red status indicators:
 * 1. Google Ads account linked and selected
 * 2. Conversion action created (or selected)
 * 3. Postback URL generated (with interactive "Copy URL" functionality)
 * 4. At least one Page linked to the Track
 *
 * This checklist is purely informational and never blocks saving or creating.
 */
export default function PreLaunchChecklist({
    accountLinked = false,
    accountSelected = false,
    accountName = '',
    accountCustomerId = '',
    conversionActionReady = false,
    conversionActionName = '',
    conversionMode = 'existing',
    postbackUrl = '',
    hasPages = false,
    pagesCount = 0,
    isNew = false,
}) {
    const [copied, setCopied] = useState(false);

    const handleCopy = async (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!postbackUrl) return;

        try {
            if (navigator?.clipboard?.writeText) {
                await navigator.clipboard.writeText(postbackUrl);
            } else {
                // Fallback for older browsers / environments
                const textarea = document.createElement('textarea');
                textarea.value = postbackUrl;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            setCopied(true);
            setTimeout(() => setCopied(false), 3000);
        } catch (err) {
            console.error('Failed to copy postback URL', err);
        }
    };

    // Calculate ready count
    const checks = [
        { id: 'account', ok: accountLinked && accountSelected },
        { id: 'conversion', ok: conversionActionReady },
        { id: 'postback', ok: Boolean(postbackUrl) },
        { id: 'pages', ok: hasPages || pagesCount > 0 },
    ];
    const readyCount = checks.filter((c) => c.ok).length;
    const totalChecks = checks.length;
    const allReady = readyCount === totalChecks;

    return (
        <section
            aria-label="Pre-launch checklist"
            data-testid="prelaunch-checklist"
            className="my-3 rounded-xl border border-theme bg-theme-surface/70 p-4 shadow-sm transition-all text-theme-primary"
        >
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-theme">
                <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--accentSubtleColor)] text-[var(--accentColor)]">
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M9 11l3 3L22 4" />
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                        </svg>
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-theme-primary tracking-tight">Pre-Launch Checklist</h3>
                            <span className="rounded-full bg-slate-500/10 px-2 py-0.5 text-[11px] font-medium text-theme-secondary border border-theme">
                                Informational Only
                            </span>
                        </div>
                        <p className="text-xs text-theme-muted">
                            Launch readiness review. Does not block saving or updating this Track.
                        </p>
                    </div>
                </div>

                {/* Score Pill */}
                <div className="flex items-center gap-1.5">
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border ${
                            allReady
                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                : readyCount >= 2
                                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                : 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/30'
                        }`}
                    >
                        <span className={`h-1.5 w-1.5 rounded-full ${allReady ? 'bg-emerald-500' : readyCount >= 2 ? 'bg-amber-500' : 'bg-rose-500'}`} />
                        <span>{readyCount} of {totalChecks} Ready</span>
                    </span>
                </div>
            </div>

            {/* Checklist Items */}
            <div className="mt-3 space-y-2.5">
                {/* 1. Google Ads account linked and selected */}
                <div
                    data-testid="check-google-account"
                    className={`flex items-start gap-3 rounded-lg p-2.5 border transition-colors ${
                        accountLinked && accountSelected
                            ? 'border-emerald-500/20 bg-emerald-500/5'
                            : 'border-rose-500/25 bg-rose-500/5'
                    }`}
                >
                    <div className="mt-0.5 shrink-0">
                        {accountLinked && accountSelected ? (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            </div>
                        ) : (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            </div>
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-theme-primary">
                                Google Ads account linked and selected
                            </span>
                            <span
                                className={`text-[11px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded ${
                                    accountLinked && accountSelected
                                        ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-rose-500/20 text-rose-700 dark:text-rose-300'
                                }`}
                            >
                                {accountLinked && accountSelected ? 'Ready' : 'Missing'}
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-theme-secondary">
                            {accountLinked && accountSelected ? (
                                <span>
                                    Connected to <strong className="text-theme-primary">{accountName || 'Account'}</strong>
                                    {accountCustomerId ? ` (${accountCustomerId})` : ''}
                                </span>
                            ) : !accountLinked ? (
                                <span className="text-rose-600 dark:text-rose-400">
                                    No Google Ads account linked to Flow Hub. Link an account in Google Ads settings.
                                </span>
                            ) : (
                                <span className="text-rose-600 dark:text-rose-400">
                                    Please select a Google Ads account from the dropdown above.
                                </span>
                            )}
                        </p>
                    </div>
                </div>

                {/* 2. Conversion action created */}
                <div
                    data-testid="check-conversion-action"
                    className={`flex items-start gap-3 rounded-lg p-2.5 border transition-colors ${
                        conversionActionReady
                            ? 'border-emerald-500/20 bg-emerald-500/5'
                            : 'border-rose-500/25 bg-rose-500/5'
                    }`}
                >
                    <div className="mt-0.5 shrink-0">
                        {conversionActionReady ? (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            </div>
                        ) : (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            </div>
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-theme-primary">
                                Conversion action created
                            </span>
                            <span
                                className={`text-[11px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded ${
                                    conversionActionReady
                                        ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-rose-500/20 text-rose-700 dark:text-rose-300'
                                }`}
                            >
                                {conversionActionReady ? 'Ready' : 'Missing'}
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-theme-secondary">
                            {conversionActionReady ? (
                                conversionMode === 'new' ? (
                                    <span>
                                        New action <strong className="text-theme-primary">"{conversionActionName}"</strong> will be created in Google Ads on save.
                                    </span>
                                ) : (
                                    <span>
                                        Action selected: <strong className="text-theme-primary">{conversionActionName || 'Configured'}</strong>
                                    </span>
                                )
                            ) : (
                                <span className="text-rose-600 dark:text-rose-400">
                                    Select an existing offline conversion action or enter a name to create one.
                                </span>
                            )}
                        </p>
                    </div>
                </div>

                {/* 3. Postback URL generated (and optionally "copied") */}
                <div
                    data-testid="check-postback-url"
                    className={`flex items-start gap-3 rounded-lg p-2.5 border transition-colors ${
                        postbackUrl
                            ? 'border-emerald-500/20 bg-emerald-500/5'
                            : 'border-rose-500/25 bg-rose-500/5'
                    }`}
                >
                    <div className="mt-0.5 shrink-0">
                        {postbackUrl ? (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            </div>
                        ) : (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            </div>
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-theme-primary">
                                Postback URL generated
                            </span>
                            <div className="flex items-center gap-1.5">
                                {copied && (
                                    <span className="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 animate-pulse">
                                        Copied! ✓
                                    </span>
                                )}
                                <span
                                    className={`text-[11px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded ${
                                        postbackUrl
                                            ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                                            : 'bg-rose-500/20 text-rose-700 dark:text-rose-300'
                                    }`}
                                >
                                    {postbackUrl ? 'Generated' : 'Missing'}
                                </span>
                            </div>
                        </div>

                        {postbackUrl ? (
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <code className="flex-1 min-w-[200px] truncate rounded bg-theme-input px-2 py-1 font-mono text-[11px] text-theme-primary border border-theme select-all">
                                    {postbackUrl}
                                </code>
                                <button
                                    type="button"
                                    onClick={handleCopy}
                                    data-testid="copy-postback-btn"
                                    className={`inline-flex items-center gap-1 rounded px-2.5 py-1 text-xs font-semibold transition-all shadow-2xs ${
                                        copied
                                            ? 'bg-emerald-600 text-white hover:bg-emerald-500'
                                            : 'bg-theme-surface border border-theme text-theme-primary hover:border-theme-hover hover:bg-theme-subtle'
                                    }`}
                                    title="Copy postback URL to clipboard"
                                >
                                    {copied ? (
                                        <>
                                            <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                            </svg>
                                            <span>Copied!</span>
                                        </>
                                    ) : (
                                        <>
                                            <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                                            </svg>
                                            <span>Copy URL</span>
                                        </>
                                    )}
                                </button>
                            </div>
                        ) : (
                            <p className="mt-0.5 text-xs text-rose-600 dark:text-rose-400">
                                Select an affiliate platform above to generate the postback URL.
                            </p>
                        )}
                        <p className="mt-1 text-[11px] text-theme-muted">
                            Paste this URL into your affiliate network (ClickBank, Digistore24, MaxWeb, etc.) webhook/instant notification settings.
                        </p>
                    </div>
                </div>

                {/* 4. At least one Page linked to the Track */}
                <div
                    data-testid="check-pages-linked"
                    className={`flex items-start gap-3 rounded-lg p-2.5 border transition-colors ${
                        hasPages || pagesCount > 0
                            ? 'border-emerald-500/20 bg-emerald-500/5'
                            : 'border-rose-500/25 bg-rose-500/5'
                    }`}
                >
                    <div className="mt-0.5 shrink-0">
                        {hasPages || pagesCount > 0 ? (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            </div>
                        ) : (
                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-white shadow-xs">
                                <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            </div>
                        )}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs font-semibold text-theme-primary">
                                At least one Page linked to Track
                            </span>
                            <span
                                className={`text-[11px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded ${
                                    hasPages || pagesCount > 0
                                        ? 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300'
                                        : 'bg-rose-500/20 text-rose-700 dark:text-rose-300'
                                }`}
                            >
                                {hasPages || pagesCount > 0 ? `${pagesCount} Linked` : '0 Linked'}
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-theme-secondary">
                            {hasPages || pagesCount > 0 ? (
                                <span>
                                    <strong className="text-theme-primary">{pagesCount}</strong> landing / presell page{pagesCount === 1 ? '' : 's'} linked to this Track.
                                </span>
                            ) : isNew ? (
                                <span className="text-rose-600 dark:text-rose-400">
                                    0 pages linked yet. (Informational: you can generate or assign landing pages in the Landing Pages section after creating this Track)
                                </span>
                            ) : (
                                <span className="text-rose-600 dark:text-rose-400">
                                    0 pages currently linked to this Track. Create or link a page in Landing Pages to direct ad traffic.
                                </span>
                            )}
                        </p>
                    </div>
                </div>
            </div>
        </section>
    );
}
