import React from 'react';
import { button } from '../../../Components/Layout';

export default function StrategySelector({ strategies, selectedStrategy, onSelectStrategy, onNext }) {
    const strategyIcons = {
        cookie_popup: (
            <svg className="w-8 h-8 text-teal-600 dark:text-teal-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                <path d="m9 12 2 2 4-4" />
            </svg>
        ),
        country_select: (
            <svg className="w-8 h-8 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" />
                <path d="M2 12h20" />
            </svg>
        ),
        flash_sale: (
            <svg className="w-8 h-8 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
            </svg>
        ),
        ai_express: (
            <svg className="w-8 h-8 text-indigo-600 dark:text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z" />
                <path d="M5 3v4" />
                <path d="M3 5h4" />
                <path d="M19 17v4" />
                <path d="M17 19h4" />
            </svg>
        ),
    };

    return (
        <div className="space-y-6">
            <div className="text-center max-w-2xl mx-auto">
                <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme mb-2">
                    Step 1 of 5 &bull; Funnel Target Strategy
                </span>
                <h2 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                    Select Your Presell Architecture
                </h2>
                <p className="mt-2 text-sm text-theme-secondary">
                    Choose the landing page format engineered for your Google Ads campaign buyer intent, geo targeting, and compliance level.
                </p>
            </div>

            <div className="grid gap-5 md:grid-cols-2">
                {strategies.map((strat) => {
                    const isSelected = selectedStrategy === strat.id;
                    return (
                        <div
                            key={strat.id}
                            onClick={() => onSelectStrategy(strat.id)}
                            className={`group relative rounded-xl border p-5 transition-all duration-200 cursor-pointer flex flex-col justify-between ${
                                isSelected
                                    ? 'border-theme-accent ring-2 ring-[var(--accentFocusRing)] bg-theme-surface shadow-md'
                                    : 'border-theme bg-theme-surface hover:border-theme-hover hover:shadow-xs'
                            }`}
                        >
                            <div>
                                <div className="flex items-start justify-between gap-3 mb-3">
                                    <div className="p-2.5 rounded-lg bg-theme-subtle border border-theme">
                                        {strategyIcons[strat.id] || strategyIcons.flash_sale}
                                    </div>
                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-theme-card border border-theme text-theme-secondary">
                                        {strat.badge}
                                    </span>
                                </div>

                                <h3 className="text-lg font-semibold text-theme-primary group-hover:text-theme-accent transition-colors">
                                    {strat.name}
                                </h3>
                                <p className="text-xs font-medium text-theme-accent mt-0.5">
                                    {strat.subtitle}
                                </p>
                                <p className="text-sm text-theme-secondary mt-2 leading-relaxed">
                                    {strat.description}
                                </p>

                                <div className="mt-4 pt-3 border-t border-theme/60">
                                    <span className="text-xs font-semibold text-theme-muted uppercase tracking-wider block mb-2">
                                        Engineered Capabilities
                                    </span>
                                    <ul className="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-xs text-theme-secondary">
                                        {(strat.features || []).map((feat, idx) => (
                                            <li key={idx} className="flex items-center gap-1.5">
                                                <span className="text-teal-500 font-bold">&check;</span>
                                                <span>{feat}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>

                            <div className="mt-5 flex items-center justify-between pt-2">
                                <span className={`text-xs font-medium ${isSelected ? 'text-theme-accent font-semibold' : 'text-theme-muted'}`}>
                                    {isSelected ? '✓ Strategy Selected' : 'Click to select'}
                                </span>
                                <div className={`w-5 h-5 rounded-full border flex items-center justify-center transition-colors ${
                                    isSelected
                                        ? 'border-theme-accent bg-[var(--accentColor)] text-white'
                                        : 'border-theme bg-theme-card'
                                }`}>
                                    {isSelected && <span className="text-xs font-bold leading-none">&bull;</span>}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex justify-end pt-4">
                <button
                    type="button"
                    onClick={onNext}
                    disabled={!selectedStrategy}
                    className={`${button} px-6 py-2.5 text-base flex items-center gap-2`}
                >
                    <span>Configure Offer &amp; Tracking</span>
                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12" />
                        <polyline points="12 5 19 12 12 19" />
                    </svg>
                </button>
            </div>
        </div>
    );
}
