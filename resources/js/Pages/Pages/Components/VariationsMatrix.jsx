import React from 'react';
import { button, quiet } from '../../../Components/Layout';

export default function VariationsMatrix({
    variations = [],
    selectedVariationId,
    onSelectVariation,
    onQuickEdit,
    onDirectExport,
    onBack,
}) {
    const angleBadges = {
        v1: { label: 'Urgency & Scarcity Angle', color: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' },
        v2: { label: 'Social Proof & Authority Angle', color: 'bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border-blue-200 dark:border-blue-800' },
        v3: { label: 'Direct Benefit & Guarantee Angle', color: 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-800' },
    };

    return (
        <div className="space-y-6">
            <div className="text-center max-w-2xl mx-auto">
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme mb-2">
                    Step 3 of 5 &bull; Variations Preview Matrix
                </span>
                <h2 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                    Compare Generated Variations
                </h2>
                <p className="mt-2 text-sm text-theme-secondary">
                    Review 3 distinct visual styles and psychological angles. Each version features a unique Google Ads auction hash footprint and tailored layout tokens.
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                {variations.map((v, index) => {
                    const isSelected = selectedVariationId === v.id;
                    const badge = angleBadges[v.id] || angleBadges.v1;
                    const theme = v.themeConfig || {};
                    const params = v.editableParams || {};

                    return (
                        <div
                            key={v.id}
                            className={`rounded-xl border flex flex-col justify-between transition-all duration-200 overflow-hidden ${
                                isSelected
                                    ? 'border-theme-accent ring-2 ring-[var(--accentFocusRing)] bg-theme-surface shadow-lg'
                                    : 'border-theme bg-theme-surface hover:border-theme-hover hover:shadow-md'
                            }`}
                        >
                            {/* Card Top / Header */}
                            <div className="p-4 border-b border-theme bg-theme-card/60">
                                <div className="flex items-center justify-between gap-2 mb-2">
                                    <span className="text-xs font-bold uppercase tracking-wider text-theme-primary">
                                        Version {index + 1} ({v.id.toUpperCase()})
                                    </span>
                                    <span className={`text-[11px] font-medium px-2 py-0.5 rounded-full border ${badge.color}`}>
                                        {badge.label}
                                    </span>
                                </div>

                                <div className="flex items-center gap-2 mt-2">
                                    <span className="text-xs text-theme-muted">Theme Tokens:</span>
                                    <div className="flex items-center gap-1.5">
                                        <div
                                            className="w-4 h-4 rounded-full border border-black/10 shadow-2xs"
                                            style={{ backgroundColor: theme.primaryColor || '#0f766e' }}
                                            title={`Primary: ${theme.primaryColor}`}
                                        />
                                        <div
                                            className="w-4 h-4 rounded-full border border-black/10 shadow-2xs"
                                            style={{ backgroundColor: theme.accentColor || '#14b8a6' }}
                                            title={`Accent: ${theme.accentColor}`}
                                        />
                                        <div
                                            className="w-4 h-4 rounded-full border border-black/10 shadow-2xs"
                                            style={{ backgroundColor: theme.bgColor || '#f8fafc' }}
                                            title={`Background: ${theme.bgColor}`}
                                        />
                                    </div>
                                    <span className="text-[11px] font-mono text-theme-muted ml-auto">
                                        {params.behaviorType?.replace('_', ' ')}
                                    </span>
                                </div>
                            </div>

                            {/* Card Content & Mini Live Iframe Preview */}
                            <div className="p-4 space-y-3 flex-1 flex flex-col">
                                <div>
                                    <h4 className="text-sm font-semibold text-theme-primary line-clamp-2">
                                        {params.headline || 'Presell Headline'}
                                    </h4>
                                    <p className="text-xs text-theme-secondary mt-1 line-clamp-2">
                                        {params.subheadline || 'Subheadline and angle copy...'}
                                    </p>
                                </div>

                                {/* Mini Iframe Container with Responsive Scaling */}
                                <div className="relative w-full h-56 rounded-lg border border-theme bg-white overflow-hidden shadow-2xs mt-2">
                                    <iframe
                                        title={`Preview ${v.id}`}
                                        srcDoc={v.compiledHtml}
                                        sandbox="allow-scripts"
                                        className="w-[200%] h-[200%] transform origin-top-left scale-50 pointer-events-none select-none border-0"
                                    />
                                    <div className="absolute inset-0 bg-transparent" />
                                </div>

                                <div className="flex items-center justify-between text-xs pt-1">
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-theme-subtle border border-theme text-theme-primary font-medium">
                                        ⚡ Discount: <strong>{params.discountPercentage || '50% OFF'}</strong>
                                    </span>
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-theme-subtle border border-theme text-theme-primary font-medium">
                                        ⏳ Timer: <strong>{params.timerMinutes || 11} min</strong>
                                    </span>
                                </div>
                            </div>

                            {/* Card Footer Actions */}
                            <div className="p-4 border-t border-theme bg-theme-card/30 flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => onQuickEdit(v.id)}
                                    className={`${button} flex-1 py-2 text-xs flex items-center justify-center gap-1.5`}
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
                                        <path d="m15 5 4 4" />
                                    </svg>
                                    <span>Quick Parameter Edit</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => onDirectExport(v.id)}
                                    className={`${quiet} py-2 px-3 text-xs flex items-center justify-center`}
                                    title="View & Export HTML"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <polyline points="9 18 15 12 9 6" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex items-center justify-between pt-4">
                <button
                    type="button"
                    onClick={onBack}
                    className={quiet}
                >
                    &larr; Back to Offer Config
                </button>

                <p className="text-xs text-theme-muted">
                    Click <strong>Quick Parameter Edit</strong> to fine-tune headlines, timers, or triggers without code changes.
                </p>
            </div>
        </div>
    );
}
