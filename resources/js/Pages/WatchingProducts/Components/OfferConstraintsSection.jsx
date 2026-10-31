import React, { useState } from 'react';
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

    const [showPromotionInfo, setShowPromotionInfo] = useState(false);
    const [showApprovalInfo, setShowApprovalInfo] = useState(false);

    const allowedGeos = Array.isArray(product.allowed_geos)
        ? product.allowed_geos
        : (product.allowed_geos ? String(product.allowed_geos).split(',').map((g) => g.trim().toUpperCase()).filter(Boolean) : []);

    const complianceRisk = (product.compliance_risk || 'not_assessed').toLowerCase();
    const isRestricted = complianceRisk === 'restricted';
    const complianceNotes = product.compliance_notes || '';

    // Tri-state approval statuses
    const approvalStatus = product.approval_status || (product.approval_required ? 'yes' : 'unknown');
    const networkApprovalStatus = product.network_approval_status || (product.network_approval_required ? 'yes' : 'unknown');

    const payoutsByPlatform = Array.isArray(product.payouts_by_platform)
        ? product.payouts_by_platform.filter((row) => row && (row.platform || row.amount || row.payout))
        : [];

    // CAD conversion helper: Use product's known fx ratio or 1.38 default fallback
    const cadPerUsd = (product.payout_amount && product.payout_cad && Number(product.payout_amount) > 0)
        ? (Number(product.payout_cad) / Number(product.payout_amount))
        : 1.38;

    const convertToCad = (amount, currency) => {
        const num = Number(amount || 0);
        if (num <= 0) return 0;
        const cur = String(currency || 'USD').toUpperCase();
        if (cur === 'CAD') return num;
        if (cur === 'USD') return num * cadPerUsd;
        return num * 1.38;
    };

    const getRiskBadge = (risk) => {
        switch (risk) {
            case 'not_assessed':
                return {
                    label: 'Not Assessed',
                    badgeClass: 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border-slate-500/30',
                    dotClass: 'bg-slate-400',
                    desc: 'Offer has not been screened for Google Ads policy restrictions yet.',
                };
            case 'restricted':
                return {
                    label: 'Restricted / Sensitive',
                    badgeClass: 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border-rose-500/40',
                    dotClass: 'bg-rose-500',
                    desc: 'Restricted category in Google Ads (e.g., ED, testosterone). Strict policy compliance required.',
                };
            case 'high':
                return {
                    label: 'High Risk',
                    badgeClass: 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border-rose-500/40',
                    dotClass: 'bg-rose-500',
                    desc: 'Aggressive health/financial claims. Needs presell bridge pages and strict claim scrubbing.',
                };
            case 'medium':
                return {
                    label: 'Medium Risk',
                    badgeClass: 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/40',
                    dotClass: 'bg-amber-500',
                    desc: 'Moderate regulatory scrutiny. Standard dietary supplement compliance rules apply.',
                };
            case 'low':
                return {
                    label: 'Low Risk',
                    badgeClass: 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border-emerald-500/40',
                    dotClass: 'bg-emerald-500',
                    desc: 'Unrestricted offer. Low risk of Google Ads policy flags or editorial rejections.',
                };
            default:
                return {
                    label: 'Not Assessed',
                    badgeClass: 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border-slate-500/30',
                    dotClass: 'bg-slate-400',
                    desc: 'Not assessed for Google Ads compliance.',
                };
        }
    };

    const riskInfo = getRiskBadge(complianceRisk);

    const getApprovalStatusBadge = (status, label) => {
        switch (status) {
            case 'yes':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        <span>{label}: Approval Required</span>
                    </span>
                );
            case 'no':
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        <span>{label}: Open / Instant</span>
                    </span>
                );
            case 'unknown':
            default:
                return (
                    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border border-slate-500/30 bg-slate-500/10 text-slate-500 dark:text-slate-400">
                        <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                        <span>{label}: Unknown</span>
                    </span>
                );
        }
    };

    const getAffiliateAppBadge = (status, label) => {
        const s = status || 'not_requested';
        let style = 'border-slate-500/30 bg-slate-500/10 text-slate-500 dark:text-slate-400';
        let dot = 'bg-slate-400';
        let defaultLabel = 'Não solicitado';

        if (s === 'pending') {
            style = 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400';
            dot = 'bg-amber-500';
            defaultLabel = 'Pendente';
        } else if (s === 'approved') {
            style = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
            dot = 'bg-emerald-500';
            defaultLabel = 'Aprovado';
        } else if (s === 'rejected') {
            style = 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400';
            dot = 'bg-rose-500';
            defaultLabel = 'Rejeitado';
        }

        const text = label || defaultLabel;

        return (
            <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border ${style}`}>
                <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />
                <span>Affiliate App: {text}</span>
            </span>
        );
    };

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
                                Offer Constraints &amp; Launch Viability
                            </h3>
                            <span className="rounded-full bg-theme-subtle px-2 py-0.5 text-[11px] font-medium text-theme-secondary border border-theme">
                                Go / No-Go
                            </span>
                        </div>
                        <p className="text-xs text-theme-muted">
                            Country permissions, policy screening, phase strategy transitions, and multi-tier payouts.
                        </p>
                    </div>
                </div>

                {/* Status Badges */}
                <div className="flex flex-wrap items-center gap-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold border ${riskInfo.badgeClass}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${riskInfo.dotClass}`} />
                        <span>{riskInfo.label}</span>
                    </span>

                    {getApprovalStatusBadge(approvalStatus, 'Vendor')}
                    {getApprovalStatusBadge(networkApprovalStatus, 'Network')}
                    {getAffiliateAppBadge(product.affiliate_application_status, product.affiliate_application_status_label)}

                    {product.recheck_at && (
                        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold border ${
                            product.is_recheck_overdue
                                ? 'border-rose-500/40 bg-rose-500/15 text-rose-600 dark:text-rose-400 animate-pulse font-bold'
                                : 'border-theme bg-theme-subtle text-theme-secondary'
                        }`}>
                            <span>📅 Recheck: {product.recheck_at_formatted || product.recheck_at}</span>
                            {product.is_recheck_overdue && <span>(Overdue)</span>}
                        </span>
                    )}
                </div>
            </div>

            {/* Constraints & Strategy Grid */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {/* 1. Allowed Geos */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Allowed Target Geos</span>
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
                                {complianceRisk.replace(/_/g, ' ')}
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
                        {complianceRisk === 'not_assessed'
                            ? 'Screen this offer before committing ad spend.'
                            : 'Sensitive categories need pre-landers and strict claim reviews.'}
                    </div>
                </div>

                {/* 3. Approvals Tri-State */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Approval Status</span>
                            <button
                                type="button"
                                onClick={() => setShowApprovalInfo(!showApprovalInfo)}
                                className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] text-theme-muted hover:text-theme-accent cursor-pointer"
                                title="Click for info on tri-state approval"
                            >
                                ⓘ
                            </button>
                        </div>

                        <div className="mt-2 space-y-2 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-theme-secondary">Vendor Approval:</span>
                                <span className="font-semibold text-theme-primary capitalize">
                                    {product.approval_status_label || approvalStatus}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-theme-secondary">Network Gate:</span>
                                <span className="font-semibold text-theme-primary capitalize">
                                    {product.network_approval_status_label || networkApprovalStatus}
                                </span>
                            </div>
                            <div className="pt-2 border-t border-theme/60 space-y-1">
                                <div className="flex items-center justify-between">
                                    <span className="text-theme-secondary">Affiliate Application:</span>
                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold border ${
                                        product.affiliate_application_status === 'pending'
                                            ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                            : product.affiliate_application_status === 'approved'
                                            ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                                            : product.affiliate_application_status === 'rejected'
                                            ? 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30'
                                            : 'bg-slate-500/15 text-slate-500 dark:text-slate-400 border-slate-500/30'
                                    }`}>
                                        {product.affiliate_application_status_label || 'Não solicitado'}
                                    </span>
                                </div>
                                {product.affiliate_application_requested_at && (
                                    <div className="flex items-center justify-between text-[11px]">
                                        <span className="text-theme-muted">Application Date:</span>
                                        <span className="font-mono text-theme-secondary">
                                            {product.affiliate_application_requested_at_formatted || product.affiliate_application_requested_at}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </div>

                        {showApprovalInfo && (
                            <div className="mt-3 rounded-lg border border-theme bg-theme-surface p-2.5 text-[11px] text-theme-muted space-y-1">
                                <p><strong>Unknown:</strong> Neutral in scoring (not yet confirmed).</p>
                                <p><strong>Yes:</strong> Gated. Penalty applied until approval confirmed.</p>
                                <p><strong>No:</strong> Explicitly confirmed open link.</p>
                            </div>
                        )}
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Unknown counts as neither approved nor blocked.
                    </div>
                </div>

                {/* 4. Phase-2 Strategy Transition */}
                <div className="rounded-xl border border-theme bg-theme-subtle/25 p-4 flex flex-col justify-between">
                    <div>
                        <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1.5">
                            <span>Phase Strategy</span>
                            {product.strategy_phase2 ? (
                                <span className="rounded-md border border-indigo-500/30 bg-indigo-500/10 px-1.5 py-0.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400">
                                    P1 → P2
                                </span>
                            ) : (
                                <span className="text-[11px] font-mono text-theme-muted">Single Phase</span>
                            )}
                        </div>

                        <div className="mt-2 space-y-1.5 text-xs">
                            <div className="flex items-center justify-between">
                                <span className="text-theme-secondary">P1 Strategy:</span>
                                <span className="font-bold text-theme-primary uppercase">
                                    {product.strategy_label || product.strategy || 'Default'}
                                </span>
                            </div>
                            {product.strategy_phase2 && (
                                <div className="flex items-center justify-between">
                                    <span className="text-theme-secondary">P2 Strategy:</span>
                                    <span className="font-bold text-indigo-600 dark:text-indigo-400 uppercase">
                                        {product.strategy_phase2_label || product.strategy_phase2}
                                    </span>
                                </div>
                            )}
                            {product.strategy_switch_trigger && (
                                <div className="mt-1.5 rounded-lg border border-theme bg-theme-surface/80 p-2 text-[11px] text-theme-primary">
                                    <strong className="text-theme-secondary block text-[10px] uppercase font-bold mb-0.5">Switch Trigger:</strong>
                                    {product.strategy_switch_trigger}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="mt-3 pt-2.5 border-t border-theme/60 text-[11px] text-theme-muted">
                        Automates the transition from testing to scaling strategy.
                    </div>
                </div>
            </div>

            {/* Promote / Demote Criteria Card */}
            {product.promotion_criteria && (
                <div className="rounded-xl border border-theme bg-theme-subtle/20 p-4">
                    <div className="flex items-center justify-between text-xs font-semibold text-theme-secondary mb-1">
                        <span className="flex items-center gap-1.5">
                            <svg className="w-4 h-4 text-theme-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M16 16l3-8 3 8c-.87.06-1.7.21-2.5.42L18 20l-1.5-3.58c-.8-.21-1.63-.36-2.5-.42zM2 16l3-8 3 8c-.87.06-1.7.21-2.5.42L4 20l-1.5-3.58c-.8-.21-1.63-.36-2.5-.42z" />
                                <path d="M7 21h10M12 3v18" />
                            </svg>
                            <span className="font-bold text-theme-primary text-sm">Promotion &amp; Demote / Kill Criteria</span>
                        </span>
                        <button
                            type="button"
                            onClick={() => setShowPromotionInfo(!showPromotionInfo)}
                            className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] text-theme-muted hover:text-theme-accent cursor-pointer"
                            title="Click for promotion criteria explanation"
                        >
                            ⓘ
                        </button>
                    </div>

                    <p className="text-xs text-theme-primary whitespace-pre-wrap mt-2">
                        {product.promotion_criteria}
                    </p>

                    {showPromotionInfo && (
                        <div className="mt-3 rounded-lg border border-theme bg-theme-surface p-3 text-xs text-theme-secondary space-y-1">
                            <div className="font-semibold text-theme-primary flex items-center justify-between">
                                <span>Lifecycle Stage Transition Rules</span>
                                <button
                                    type="button"
                                    onClick={() => setShowPromotionInfo(false)}
                                    className="text-theme-muted hover:text-theme-primary cursor-pointer text-xs"
                                >
                                    ✕
                                </button>
                            </div>
                            <ul className="text-[11px] text-theme-muted list-disc list-inside space-y-0.5">
                                <li><strong>WATCH → TEST:</strong> Potential Score &gt; 70, verified offer page, and targeting set.</li>
                                <li><strong>TEST → SCALE:</strong> Consistent profit across 2+ conversions with positive headroom.</li>
                                <li><strong>KILL / PAUSE:</strong> 0 sales at 100% commission spend, sudden listing expiration, or policy violation.</li>
                            </ul>
                        </div>
                    )}
                </div>
            )}

            {/* Payouts by Platform & Geo Tier Table */}
            <div className="rounded-xl border border-theme bg-theme-subtle/15 p-4 space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <h4 className="font-bold text-sm text-theme-primary">Payout Tiers by Platform &amp; GEO</h4>
                        <p className="text-xs text-theme-muted">
                            CAD conversion calculated via FX engine. Geo-matched tiers are prioritized during scoring.
                        </p>
                    </div>
                    <span className="text-xs font-mono text-theme-secondary">
                        {payoutsByPlatform.length > 0 ? `${payoutsByPlatform.length} Tier(s)` : 'Primary Only'}
                    </span>
                </div>

                {payoutsByPlatform.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-xs divide-y divide-theme">
                            <thead className="bg-theme-canvas/60 text-theme-secondary uppercase font-semibold">
                                <tr>
                                    <th className="px-3 py-2">Platform</th>
                                    <th className="px-3 py-2">GEO / Tier</th>
                                    <th className="px-3 py-2">Payout</th>
                                    <th className="px-3 py-2">Model</th>
                                    <th className="px-3 py-2">CAD Equivalent</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme bg-theme-surface">
                                {payoutsByPlatform.map((item, idx) => {
                                    const amount = Number(item.amount ?? item.payout ?? 0);
                                    const cur = item.currency || 'USD';
                                    const cadVal = convertToCad(amount, cur);
                                    return (
                                        <tr key={idx} className="hover:bg-theme-subtle/30">
                                            <td className="px-3 py-2 font-medium">
                                                <span className={`capitalize font-semibold text-[11px] px-2 py-0.5 rounded border ${getPlatformBadgeClass(item.platform)}`}>
                                                    {item.platform}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 font-mono font-medium text-theme-primary">
                                                {item.geo_tier ? (
                                                    <span className="inline-flex items-center gap-1 rounded bg-theme-subtle px-1.5 py-0.5 border border-theme">
                                                        <span>{COUNTRY_FLAGS[item.geo_tier.toUpperCase()] || '🌐'}</span>
                                                        <span>{item.geo_tier}</span>
                                                    </span>
                                                ) : (
                                                    <span className="text-theme-muted italic">Global / Default</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 font-mono font-bold text-theme-primary">
                                                {cur} ${amount.toFixed(2)}
                                            </td>
                                            <td className="px-3 py-2 font-mono uppercase text-theme-secondary">
                                                {item.model || 'CPA'}
                                            </td>
                                            <td className="px-3 py-2 font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                                CA$ {cadVal.toFixed(2)}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="flex items-center justify-between rounded-lg border border-theme bg-theme-surface p-3 text-xs">
                        <div>
                            <span className="text-theme-secondary">Primary Offer Payout: </span>
                            <strong className="text-theme-primary font-mono text-sm">{product.payout_formatted}</strong>
                            <span className="text-theme-muted ml-2">({product.platform || 'General'})</span>
                        </div>
                        <div className="font-mono text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                            CA$ {Number(product.payout_cad || 0).toFixed(2)}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
