import React from 'react';
import { getPlatformBadgeClass } from '../../../utils/platform';

const COUNTRY_FLAGS = {
    US: '🇺🇸',
    CA: '🇨🇦',
    GB: '🇬🇧',
    UK: '🇬🇧',
    AU: '🇦🇺',
    NZ: '🇳🇿',
    IE: '🇮🇪',
    DE: '🇩🇪',
    FR: '🇫🇷',
    IT: '🇮🇹',
    ES: '🇪🇸',
    NL: '🇳🇱',
    BE: '🇧🇪',
    AT: '🇦🇹',
    CH: '🇨🇭',
    SE: '🇸🇪',
    NO: '🇳🇴',
    DK: '🇩🇰',
    FI: '🇫🇮',
    ZA: '🇿🇦',
    SG: '🇸🇬',
    BR: '🇧🇷',
    MX: '🇲🇽',
};

export default function OfferConstraintsSection({ product }) {
    if (!product) return null;

    const allowedGeos = Array.isArray(product.allowed_geos)
        ? product.allowed_geos
        : (product.allowed_geos ? String(product.allowed_geos).split(',').map((g) => g.trim().toUpperCase()).filter(Boolean) : []);

    const complianceRisk = (product.compliance_risk || 'low').toLowerCase();
    const isRestricted = complianceRisk === 'restricted';
    const complianceNotes = product.compliance_notes || '';
    const networkApprovalRequired = Boolean(product.network_approval_required);

    const payoutsByPlatform = Array.isArray(product.payouts_by_platform)
        ? product.payouts_by_platform.filter((row) => row && (row.platform || row.payout))
        : [];

    const getRiskBadge = (risk) => {
        switch (risk) {
            case 'restricted':
                return {
                    label: 'Restricted / Sensitive',
                    badgeClass: 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border-rose-500/40',
                    dotClass: 'bg-rose-500',
                    desc: 'Restricted or limited category in Google Ads (e.g., ED, testosterone, prescription parallels). Extra compliance screening required.',
                };
            case 'high':
                return {
                    label: 'High Risk',
                    badgeClass: 'bg-orange-500/20 text-orange-600 dark:text-orange-400 border-orange-500/40',
                    dotClass: 'bg-orange-500',
                    desc: 'Sensitive health or financial claims. Needs presell bridge pages and careful keyword targeting.',
                };
            case 'medium':
                return {
                    label: 'Medium Risk',
                    badgeClass: 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/40',
                    dotClass: 'bg-amber-500',
                    desc: 'Moderate regulatory scrutiny. Standard dietary supplement or health compliance rules apply.',
                };
            case 'low':
            default:
                return {
                    label: 'Low Risk',
                    badgeClass: 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border-emerald-500/40',
                    dotClass: 'bg-emerald-500',
                    desc: 'Unrestricted offer. Low risk of Google Ads policy flags or editorial rejections.',
                };
        }
    };

    const riskInfo = getRiskBadge(complianceRisk);

    return (
        <section
            aria-label="Offer constraints and go no-go decision"
            data-testid="offer-constraints-section"
            className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs space-y-5"
        >
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-theme pb-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-theme-subtle text-theme-accent border border-theme">
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        </svg>
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h3 className="font-bold text-base text-theme-primary">
                                Offer Constraints & Go / No-Go
                            </h3>
                            <span className="rounded-full bg-theme-subtle px-2 py-0.5 text-[11px] font-medium text-theme-secondary border border-theme">
                                Launch Viability
                            </span>
                        </div>
                        <p className="text-xs text-theme-muted">
                            Country permissions, network approval gates, Google Ads compliance risk, and cross-platform payouts.
                        </p>
                    </div>
                </div>

                {/* Status Badges */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border ${riskInfo.badgeClass}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${riskInfo.dotClass}`} />
                        <span>{riskInfo.label}</span>
                    </span>

                    {networkApprovalRequired ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <svg className="h-3.5 w-3.5 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            <span>Approval Required</span>
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <svg className="h-3.5 w-3.5 text-emerald-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                            </svg>
                            <span>Instant Network Approval</span>
                        </span>
                    )}
                </div>
            </div>

            {/* 4 Cards Grid */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {/* 1. Allowed Geos */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span className="flex items-center gap-1.5">
                                <span>Allowed Target Geos</span>
                            </span>
                            <span className="font-mono text-[11px] text-theme-muted">
                                {allowedGeos.length > 0 ? `${allowedGeos.length} Allowed` : 'All / Default'}
                            </span>
                        </div>

                        {allowedGeos.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5 mt-2">
                                {allowedGeos.map((geo) => (
                                    <span
                                        key={geo}
                                        className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-mono font-bold bg-theme-surface border border-theme text-theme-primary shadow-2xs"
                                    >
                                        <span>{COUNTRY_FLAGS[geo] || '🌐'}</span>
                                        <span>{geo}</span>
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-theme-muted mt-2 italic">
                                {product.geos && product.geos.length > 0
                                    ? `Defaults to research geos: ${product.geos.join(', ')}`
                                    : 'No geo restrictions specified (Global traffic permitted).'}
                            </p>
                        )}
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Only route ad traffic to these approved territories.
                    </div>
                </div>

                {/* 2. Google Ads Compliance Risk */}
                <div className={`rounded-xl border p-4 flex flex-col justify-between ${
                    isRestricted ? 'border-rose-500/30 bg-rose-500/5' : 'border-theme bg-theme-subtle/25'
                }`}>
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Google Ads Policy Risk</span>
                            <span className={`text-[11px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${riskInfo.badgeClass}`}>
                                {complianceRisk}
                            </span>
                        </div>

                        <p className="text-xs text-theme-secondary mt-1">
                            {riskInfo.desc}
                        </p>

                        {complianceNotes && (
                            <div className="mt-2.5 rounded-lg border border-theme bg-theme-surface/80 p-2 text-[11px] text-theme-primary whitespace-pre-wrap">
                                <strong className="text-theme-secondary block text-[10px] uppercase font-bold mb-0.5">Policy Notes:</strong>
                                {complianceNotes}
                            </div>
                        )}
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Sensitive categories need pre-landers and strict claim reviews.
                    </div>
                </div>

                {/* 3. Network Approval Required */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Affiliate Network Gate</span>
                            <span className={`text-[11px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded ${
                                networkApprovalRequired
                                    ? 'bg-amber-500/20 text-amber-700 dark:text-amber-300 border border-amber-500/30'
                                    : 'bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                            }`}>
                                {networkApprovalRequired ? 'Gated' : 'Open'}
                            </span>
                        </div>

                        <div className="mt-2 space-y-1">
                            <div className="flex items-center gap-2">
                                <span className={`h-2.5 w-2.5 rounded-full ${networkApprovalRequired ? 'bg-amber-500' : 'bg-emerald-500'}`} />
                                <span className="text-xs font-semibold text-theme-primary">
                                    {networkApprovalRequired ? 'Affiliate Manager Approval Required' : 'Instant Network Activation'}
                                </span>
                            </div>
                            <p className="text-xs text-theme-muted mt-1">
                                {networkApprovalRequired
                                    ? 'Requires applying or contacting the affiliate manager before generating active tracking links.'
                                    : 'Direct approval. Hoplinks or affiliate tracking links can be generated immediately.'}
                            </p>
                        </div>
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Check account status before creating Google Ads campaigns.
                    </div>
                </div>

                {/* 4. Payouts by Platform */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Payouts by Platform</span>
                            <span className="font-mono text-[11px] text-theme-muted">
                                {payoutsByPlatform.length > 0 ? `${payoutsByPlatform.length} Listed` : 'Single Platform'}
                            </span>
                        </div>

                        {payoutsByPlatform.length > 0 ? (
                            <div className="mt-2 space-y-1.5 max-h-36 overflow-y-auto pr-1">
                                {payoutsByPlatform.map((item, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between gap-2 rounded-lg border border-theme bg-theme-surface px-2.5 py-1 text-xs"
                                    >
                                        <span className={`capitalize font-semibold text-[11px] px-1.5 py-0.5 rounded border ${getPlatformBadgeClass(item.platform)}`}>
                                            {item.platform}
                                        </span>
                                        <span className="font-mono font-bold text-theme-primary">
                                            {item.currency || 'USD'} ${Number(item.payout || 0).toFixed(2)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="mt-2 space-y-1">
                                <div className="text-xs text-theme-primary font-bold">
                                    {product.payout_formatted}
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Primary network: <span className="capitalize font-medium text-theme-secondary">{product.platform || 'General'}</span>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Compare affiliate network margins and split test payout offers.
                    </div>
                </div>
            </div>
        </section>
    );
}
