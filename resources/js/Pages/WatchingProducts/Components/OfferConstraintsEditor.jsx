import React, { useState } from 'react';
import { Field, control, quiet } from '../../../Components/Layout';

const COMMON_COUNTRIES = [
    { code: 'US', flag: '🇺🇸', label: 'United States' },
    { code: 'CA', flag: '🇨🇦', label: 'Canada' },
    { code: 'GB', flag: '🇬🇧', label: 'United Kingdom' },
    { code: 'AU', flag: '🇦🇺', label: 'Australia' },
    { code: 'NZ', flag: '🇳🇿', label: 'New Zealand' },
    { code: 'IE', flag: '🇮🇪', label: 'Ireland' },
    { code: 'DE', flag: '🇩🇪', label: 'Germany' },
    { code: 'FR', flag: '🇫🇷', label: 'France' },
    { code: 'IT', flag: '🇮🇹', label: 'Italy' },
    { code: 'ES', flag: '🇪🇸', label: 'Spain' },
    { code: 'NL', flag: '🇳🇱', label: 'Netherlands' },
    { code: 'ZA', flag: '🇿🇦', label: 'South Africa' },
    { code: 'SG', flag: '🇸🇬', label: 'Singapore' },
    { code: 'BR', flag: '🇧🇷', label: 'Brazil' },
    { code: 'MX', flag: '🇲🇽', label: 'Mexico' },
];

const DEFAULT_PLATFORMS = [
    'ClickBank',
    'BuyGoods',
    'Digistore24',
    'MaxWeb',
    'SmartAdv',
    'Affplus',
    'Other',
];

const CURRENCIES = ['USD', 'CAD', 'EUR', 'GBP', 'AUD', 'BRL'];

export default function OfferConstraintsEditor({
    data,
    setData,
    errors = {},
    options = {},
}) {
    const [customGeoInput, setCustomGeoInput] = useState('');
    const [showApprovalInfo, setShowApprovalInfo] = useState(false);
    const [showAffiliateAppInfo, setShowAffiliateAppInfo] = useState(false);
    const [showPromotionInfo, setShowPromotionInfo] = useState(false);
    const [showVolumeKeywordInfo, setShowVolumeKeywordInfo] = useState(false);

    // Normalize allowed_geos to array
    const allowedGeos = React.useMemo(() => {
        if (Array.isArray(data.allowed_geos)) return data.allowed_geos;
        if (typeof data.allowed_geos === 'string' && data.allowed_geos.trim()) {
            return data.allowed_geos.split(',').map((g) => g.trim().toUpperCase()).filter(Boolean);
        }
        return [];
    }, [data.allowed_geos]);

    const toggleGeo = (code) => {
        const uppercaseCode = code.trim().toUpperCase();
        if (!uppercaseCode) return;

        let next;
        if (allowedGeos.includes(uppercaseCode)) {
            next = allowedGeos.filter((g) => g !== uppercaseCode);
        } else {
            next = [...allowedGeos, uppercaseCode];
        }
        setData('allowed_geos', next);
    };

    const handleAddCustomGeo = (e) => {
        e.preventDefault();
        if (!customGeoInput.trim()) return;

        const newCodes = customGeoInput
            .split(',')
            .map((g) => g.trim().toUpperCase())
            .filter((g) => g.length > 0 && !allowedGeos.includes(g));

        if (newCodes.length > 0) {
            setData('allowed_geos', [...allowedGeos, ...newCodes]);
        }
        setCustomGeoInput('');
    };

    // Repeatable platform payouts
    const payouts = Array.isArray(data.payouts_by_platform) ? data.payouts_by_platform : [];

    const handleAddPayoutRow = () => {
        const next = [
            ...payouts,
            { platform: 'ClickBank', geo_tier: '', amount: '', currency: 'USD', model: 'cpa' },
        ];
        setData('payouts_by_platform', next);
    };

    const handleUpdatePayoutRow = (index, field, value) => {
        const next = payouts.map((row, idx) => {
            if (idx === index) {
                const updated = { ...row, [field]: value };
                if (field === 'amount') {
                    updated.payout = value;
                }
                if (field === 'payout') {
                    updated.amount = value;
                }
                return updated;
            }
            return row;
        });
        setData('payouts_by_platform', next);
    };

    const handleRemovePayoutRow = (index) => {
        const next = payouts.filter((_, idx) => idx !== index);
        setData('payouts_by_platform', next);
    };

    return (
        <fieldset className="rounded-xl border border-theme bg-theme-subtle/10 p-4 space-y-4">
            <legend className="text-xs font-bold text-theme-primary px-1.5 flex items-center gap-1.5">
                <svg className="w-3.5 h-3.5 text-theme-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                <span>Offer Constraints, Strategy & Go / No-Go Decision</span>
            </legend>

            {/* 1. Allowed Geos (Country Codes Multi-Select) */}
            <div className="space-y-2">
                <div className="flex items-center justify-between">
                    <label className="block text-xs font-semibold text-theme-secondary">
                        1. Allowed Geos (Target Country Multi-Select)
                    </label>
                    <span className="text-[11px] font-mono text-theme-muted">
                        {allowedGeos.length} selected
                    </span>
                </div>

                {/* Country chips quick toggle */}
                <div className="flex flex-wrap gap-1.5 p-2 rounded-lg border border-theme bg-theme-input/40">
                    {COMMON_COUNTRIES.map((c) => {
                        const isSelected = allowedGeos.includes(c.code);
                        return (
                            <button
                                key={c.code}
                                type="button"
                                onClick={() => toggleGeo(c.code)}
                                className={`inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-mono font-semibold transition-all cursor-pointer ${
                                    isSelected
                                        ? 'bg-theme-accent text-white shadow-xs'
                                        : 'bg-theme-surface border border-theme text-theme-secondary hover:border-theme-hover hover:text-theme-primary'
                                }`}
                                title={`${c.label} (${c.code})`}
                            >
                                <span>{c.flag}</span>
                                <span>{c.code}</span>
                                {isSelected && <span className="text-[10px] ml-0.5">✓</span>}
                            </button>
                        );
                    })}
                </div>

                {/* Selected chips and custom input */}
                <div className="flex flex-wrap items-center gap-2">
                    {allowedGeos.filter((g) => !COMMON_COUNTRIES.some((c) => c.code === g)).map((customCode) => (
                        <span
                            key={customCode}
                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-mono font-bold bg-theme-accent text-white shadow-2xs"
                        >
                            <span>{customCode}</span>
                            <button
                                type="button"
                                onClick={() => toggleGeo(customCode)}
                                className="text-white/80 hover:text-white ml-0.5 cursor-pointer text-xs"
                            >
                                ✕
                            </button>
                        </span>
                    ))}

                    <div className="flex items-center gap-1.5 flex-1 min-w-[200px]">
                        <input
                            type="text"
                            placeholder="Add other ISO country codes (e.g. SE, NO, ZA)..."
                            className={`${control} text-xs uppercase font-mono !py-1`}
                            value={customGeoInput}
                            onChange={(e) => setCustomGeoInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    handleAddCustomGeo(e);
                                }
                            }}
                        />
                        <button
                            type="button"
                            onClick={handleAddCustomGeo}
                            className={`${quiet} text-xs !py-1 !px-2.5 shrink-0`}
                        >
                            + Add
                        </button>
                    </div>
                </div>
                {errors.allowed_geos && (
                    <span className="text-xs text-[var(--dangerColor)]">{errors.allowed_geos}</span>
                )}
            </div>

            {/* 2. Compliance Risk & Tri-state Approvals */}
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label="2. Google Ads Compliance Risk" error={errors.compliance_risk}>
                    <select
                        className={control}
                        value={data.compliance_risk || 'not_assessed'}
                        onChange={(e) => setData('compliance_risk', e.target.value)}
                    >
                        <option value="not_assessed">Not Assessed (Default)</option>
                        <option value="low">Low Risk (Standard policies)</option>
                        <option value="medium">Medium Risk (Moderate health claims)</option>
                        <option value="high">High Risk (Aggressive claims / needs pre-landers)</option>
                        <option value="restricted">Restricted (Google Ads sensitive / ED / testosterone)</option>
                    </select>
                </Field>

                <Field
                    label={
                        <span className="flex items-center justify-between">
                            <span>Product Approval Required</span>
                            <button
                                type="button"
                                onClick={() => setShowApprovalInfo(!showApprovalInfo)}
                                className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] text-theme-muted hover:text-theme-accent cursor-pointer"
                                title="Click for info on tri-state approval"
                            >
                                ⓘ
                            </button>
                        </span>
                    }
                    error={errors.approval_status}
                >
                    <select
                        className={control}
                        value={data.approval_status || 'unknown'}
                        onChange={(e) => setData('approval_status', e.target.value)}
                    >
                        <option value="unknown">Unknown (Not Assessed / Neutral)</option>
                        <option value="yes">Yes (Requires Vendor Approval)</option>
                        <option value="no">No (Direct / Open)</option>
                    </select>
                </Field>

                <Field
                    label="Network Approval Status"
                    error={errors.network_approval_status}
                >
                    <select
                        className={control}
                        value={data.network_approval_status || 'unknown'}
                        onChange={(e) => setData('network_approval_status', e.target.value)}
                    >
                        <option value="unknown">Unknown (Unconfirmed)</option>
                        <option value="yes">Yes (Network Approval Required)</option>
                        <option value="no">No (Open / Instant Link)</option>
                    </select>
                </Field>
            </div>

            {/* Affiliate Application Status & Date */}
            <div className="grid gap-4 sm:grid-cols-2 pt-2 border-t border-theme/60">
                <Field
                    label={
                        <span className="flex items-center justify-between">
                            <span>Affiliate Application Status</span>
                            <button
                                type="button"
                                onClick={() => setShowAffiliateAppInfo(!showAffiliateAppInfo)}
                                className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] text-theme-muted hover:text-theme-accent cursor-pointer"
                                title="Click for info on Affiliate Application Status"
                            >
                                ⓘ
                            </button>
                        </span>
                    }
                    error={errors.affiliate_application_status}
                >
                    <select
                        className={control}
                        value={data.affiliate_application_status || 'not_requested'}
                        onChange={(e) => setData('affiliate_application_status', e.target.value)}
                    >
                        <option value="not_requested">Não solicitado</option>
                        <option value="pending">Pendente</option>
                        <option value="approved">Aprovado</option>
                        <option value="rejected">Rejeitado</option>
                    </select>
                </Field>

                <Field label="Application Date" error={errors.affiliate_application_requested_at}>
                    <input
                        type="date"
                        className={control}
                        value={data.affiliate_application_requested_at || ''}
                        onChange={(e) => setData('affiliate_application_requested_at', e.target.value || null)}
                    />
                </Field>
            </div>

            {/* Click-to-open ⓘ Popover for Affiliate Application Status */}
            {showAffiliateAppInfo && (
                <div className="rounded-lg border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-sm space-y-1">
                    <div className="font-semibold text-theme-primary flex items-center justify-between">
                        <span>Affiliate Application Status</span>
                        <button
                            type="button"
                            onClick={() => setShowAffiliateAppInfo(false)}
                            className="text-theme-muted hover:text-theme-primary cursor-pointer"
                        >
                            ✕
                        </button>
                    </div>
                    <p className="text-[11px] text-theme-muted">
                        Status do SEU pedido de afiliação na network. Diferente de Network Approval Status, que só indica se a oferta exige aprovação.
                    </p>
                </div>
            )}

            {/* Click-to-open ⓘ Popover for Tri-state approval */}
            {showApprovalInfo && (
                <div className="rounded-lg border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-sm space-y-1">
                    <div className="font-semibold text-theme-primary flex items-center justify-between">
                        <span>Why Tri-State Approval?</span>
                        <button
                            type="button"
                            onClick={() => setShowApprovalInfo(false)}
                            className="text-theme-muted hover:text-theme-primary cursor-pointer"
                        >
                            ✕
                        </button>
                    </div>
                    <p className="text-[11px] text-theme-muted">
                        The previous checkbox could only be true or false, so unconfirmed products were stored as false even when approval wasn't explicitly checked.
                        With tri-state, <strong>Unknown</strong> is neutral in scoring, <strong>Yes</strong> applies a freshness penalty, and <strong>No</strong> confirms instantaneous routing.
                    </p>
                </div>
            )}

            {/* Compliance Notes */}
            <Field label="Compliance & Policy Notes (Optional)" error={errors.compliance_notes}>
                <textarea
                    rows="2"
                    className={control}
                    placeholder="Specific landing page rules, prohibited keywords (e.g. cure, hormone, ED), or required disclaimer requirements..."
                    value={data.compliance_notes || ''}
                    onChange={(e) => setData('compliance_notes', e.target.value)}
                />
            </Field>

            {/* Phase 2 Strategy & Recheck Date Grid */}
            <div className="grid gap-4 sm:grid-cols-3 pt-1 border-t border-theme/60">
                <Field label="Phase-2 Strategy (Nullable)" error={errors.strategy_phase2}>
                    <select
                        className={control}
                        value={data.strategy_phase2 || ''}
                        onChange={(e) => setData('strategy_phase2', e.target.value || null)}
                    >
                        <option value="">None / Not Set</option>
                        {options.strategies?.map((st) => (
                            <option key={st.value} value={st.value}>{st.label}</option>
                        ))}
                    </select>
                </Field>

                <Field label="Strategy Switch Trigger" error={errors.strategy_switch_trigger}>
                    <input
                        type="text"
                        className={control}
                        placeholder="e.g. Switch to review after 3 sales or when brand CPC < $1.50"
                        value={data.strategy_switch_trigger || ''}
                        onChange={(e) => setData('strategy_switch_trigger', e.target.value)}
                    />
                </Field>

                <Field label="Recheck Date" error={errors.recheck_at}>
                    <input
                        type="date"
                        className={control}
                        value={data.recheck_at || ''}
                        onChange={(e) => setData('recheck_at', e.target.value || null)}
                    />
                </Field>
            </div>

            {/* Promote / Demote Criteria */}
            <Field
                label={
                    <span className="flex items-center justify-between">
                        <span>Promote / Demote Criteria</span>
                        <button
                            type="button"
                            onClick={() => setShowPromotionInfo(!showPromotionInfo)}
                            className="inline-flex items-center justify-center w-4 h-4 rounded-full border border-theme text-[10px] text-theme-muted hover:text-theme-accent cursor-pointer"
                            title="Click for promotion criteria explanation"
                        >
                            ⓘ
                        </button>
                    </span>
                }
                error={errors.promotion_criteria}
            >
                <textarea
                    rows="2"
                    className={control}
                    placeholder="Criteria to move WATCH → TEST → SCALE (e.g., test 30 days, promote if ROI > 60%, kill if 0 sales at 100% payout spend)..."
                    value={data.promotion_criteria || ''}
                    onChange={(e) => setData('promotion_criteria', e.target.value)}
                />
            </Field>

            {/* Click-to-open ⓘ Popover for Promotion Criteria */}
            {showPromotionInfo && (
                <div className="rounded-lg border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-sm space-y-1">
                    <div className="font-semibold text-theme-primary flex items-center justify-between">
                        <span>WATCH → TEST → SCALE Guidelines</span>
                        <button
                            type="button"
                            onClick={() => setShowPromotionInfo(false)}
                            className="text-theme-muted hover:text-theme-primary cursor-pointer"
                        >
                            ✕
                        </button>
                    </div>
                    <ul className="text-[11px] text-theme-muted list-disc list-inside space-y-0.5">
                        <li><strong>WATCH → TEST:</strong> Potential Score &gt; 70, no hard gates, affiliate link active, and approved geos defined.</li>
                        <li><strong>TEST → SCALE:</strong> At least 2 verified sales with ROI &ge; 50% and stable CPA.</li>
                        <li><strong>DEMOTE / KILL:</strong> 0 sales at 100% commission spend (or 50% spend if 0 checkouts), listing down, or policy warning.</li>
                    </ul>
                </div>
            )}

            {/* Volume Keyword Override */}
            <Field
                label={
                    <span className="flex items-center gap-1.5">
                        <span>Volume Keyword (Keyword Planner)</span>
                        <button
                            type="button"
                            onClick={() => setShowVolumeKeywordInfo(!showVolumeKeywordInfo)}
                            className="inline-flex items-center justify-center w-4 h-4 rounded-full bg-theme-subtle text-theme-muted hover:text-theme-accent text-[11px] font-bold cursor-pointer transition-colors"
                            title="Click for Volume Keyword info"
                        >
                            ⓘ
                        </button>
                    </span>
                }
                error={errors.search_volume_keyword}
            >
                <input
                    type="text"
                    maxLength={191}
                    className={control}
                    placeholder="Defaults to product name if blank"
                    value={data.search_volume_keyword || ''}
                    onChange={(e) => setData('search_volume_keyword', e.target.value)}
                />
            </Field>

            {/* Click-to-open ⓘ Popover for Volume Keyword */}
            {showVolumeKeywordInfo && (
                <div className="rounded-lg border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-sm space-y-1">
                    <div className="font-semibold text-theme-primary flex items-center justify-between">
                        <span>Keyword Planner Target Keyword</span>
                        <button
                            type="button"
                            onClick={() => setShowVolumeKeywordInfo(false)}
                            className="text-theme-muted hover:text-theme-primary cursor-pointer"
                        >
                            ✕
                        </button>
                    </div>
                    <p className="text-[11px] text-theme-muted">
                        Overrides the exact phrase sent to Google Ads Keyword Planner for monthly search volume calculations. If blank, Flow Hub queries the exact product name.
                    </p>
                </div>
            )}

            {/* 4. Payouts by GEO / Platform / Tier (Repeatable Rows) */}
            <div className="space-y-2 pt-1 border-t border-theme/60">
                <div className="flex items-center justify-between">
                    <div>
                        <span className="block text-xs font-semibold text-theme-secondary">
                            Payouts by Platform &amp; GEO Tier
                        </span>
                        <span className="text-[11px] text-theme-muted">
                            Rows without a GEO tier are treated as global/default tier.
                        </span>
                    </div>

                    <button
                        type="button"
                        onClick={handleAddPayoutRow}
                        className="px-2.5 py-1 rounded text-xs font-semibold border border-theme bg-theme-surface hover:bg-theme-subtle text-theme-accent transition-colors flex items-center gap-1 cursor-pointer"
                    >
                        <span>+ Add Payout Tier</span>
                    </button>
                </div>

                {payouts.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-theme p-3 text-center text-xs text-theme-muted">
                        No multi-tier payouts added yet. (Uses primary offer payout above by default).
                        <button
                            type="button"
                            onClick={handleAddPayoutRow}
                            className="ml-2 font-semibold text-theme-accent hover:underline cursor-pointer"
                        >
                            + Add first payout tier
                        </button>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {payouts.map((row, idx) => (
                            <div
                                key={idx}
                                className="grid grid-cols-1 sm:grid-cols-12 gap-2 p-2 rounded-lg border border-theme bg-theme-surface items-center text-xs"
                            >
                                {/* Platform */}
                                <div className="sm:col-span-3">
                                    <input
                                        type="text"
                                        list={`platform-list-${idx}`}
                                        className={control}
                                        placeholder="Platform (e.g. ClickBank)"
                                        value={row.platform || ''}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'platform', e.target.value)}
                                    />
                                    <datalist id={`platform-list-${idx}`}>
                                        {DEFAULT_PLATFORMS.map((p) => (
                                            <option key={p} value={p} />
                                        ))}
                                    </datalist>
                                </div>

                                {/* GEO Tier */}
                                <div className="sm:col-span-3">
                                    <input
                                        type="text"
                                        className={control}
                                        placeholder="GEO / Tier (e.g. US, INTL, Tier1)"
                                        value={row.geo_tier || ''}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'geo_tier', e.target.value)}
                                        title="Free label (e.g. US, INTL, Tier1). Leave blank for default/global."
                                    />
                                </div>

                                {/* Payout amount */}
                                <div className="sm:col-span-2">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className={control}
                                        placeholder="Amount"
                                        value={row.amount ?? row.payout ?? ''}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'amount', e.target.value)}
                                    />
                                </div>

                                {/* Currency */}
                                <div className="sm:col-span-2">
                                    <select
                                        className={control}
                                        value={row.currency || 'USD'}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'currency', e.target.value)}
                                    >
                                        {CURRENCIES.map((cur) => (
                                            <option key={cur} value={cur}>{cur}</option>
                                        ))}
                                    </select>
                                </div>

                                {/* Model */}
                                <div className="sm:col-span-1">
                                    <select
                                        className={control}
                                        value={row.model || 'cpa'}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'model', e.target.value)}
                                        title="Payout Model"
                                    >
                                        <option value="cpa">CPA</option>
                                        <option value="revshare">Rev</option>
                                    </select>
                                </div>

                                {/* Remove Button */}
                                <div className="sm:col-span-1 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={() => handleRemovePayoutRow(idx)}
                                        className="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-500/10 rounded transition-colors cursor-pointer"
                                        title="Remove this tier"
                                    >
                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <line x1="18" y1="6" x2="6" y2="18" />
                                            <line x1="6" y1="6" x2="18" y2="18" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </fieldset>
    );
}
