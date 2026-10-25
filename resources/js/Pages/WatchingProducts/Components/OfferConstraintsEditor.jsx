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
            { platform: 'ClickBank', payout: '', currency: 'USD' },
        ];
        setData('payouts_by_platform', next);
    };

    const handleUpdatePayoutRow = (index, field, value) => {
        const next = payouts.map((row, idx) => {
            if (idx === index) {
                return { ...row, [field]: value };
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
                <span>Offer Constraints & Go / No-Go Decision</span>
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

            {/* 2. Compliance Risk & Notes */}
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="2. Google Ads Compliance Risk" error={errors.compliance_risk}>
                    <select
                        className={control}
                        value={data.compliance_risk || 'low'}
                        onChange={(e) => setData('compliance_risk', e.target.value)}
                    >
                        <option value="low">Low Risk (Standard policies)</option>
                        <option value="medium">Medium Risk (Moderate health claims)</option>
                        <option value="high">High Risk (Aggressive claims / needs pre-landers)</option>
                        <option value="restricted">Restricted (Google Ads sensitive / ED / testosterone)</option>
                    </select>
                </Field>

                <div className="flex items-center pt-5">
                    {/* 3. Network Approval Required */}
                    <label className="flex items-start gap-2.5 cursor-pointer p-2.5 rounded-lg border border-theme bg-theme-surface hover:bg-theme-subtle/50 transition-colors w-full">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-theme text-theme-accent focus:ring-theme-accent"
                            checked={Boolean(data.network_approval_required)}
                            onChange={(e) => setData('network_approval_required', e.target.checked)}
                        />
                        <div>
                            <span className="font-semibold text-xs text-theme-primary block">
                                3. Requires approval on affiliate network
                            </span>
                            <span className="text-[11px] text-theme-muted block">
                                Gated offer requiring affiliate manager approval before live traffic routing.
                            </span>
                        </div>
                    </label>
                </div>
            </div>

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

            {/* 4. Payouts by Platform (Repeatable Rows) */}
            <div className="space-y-2 pt-1 border-t border-theme/60">
                <div className="flex items-center justify-between">
                    <div>
                        <span className="block text-xs font-semibold text-theme-secondary">
                            4. Payouts by Platform (Multi-Platform Payouts)
                        </span>
                        <span className="text-[11px] text-theme-muted">
                            Record specific offer payout and currency for each network (ClickBank, BuyGoods, Digistore24, MaxWeb, etc.)
                        </span>
                    </div>

                    <button
                        type="button"
                        onClick={handleAddPayoutRow}
                        className="px-2.5 py-1 rounded text-xs font-semibold border border-theme bg-theme-surface hover:bg-theme-subtle text-theme-accent transition-colors flex items-center gap-1 cursor-pointer"
                    >
                        <span>+ Add Network Payout</span>
                    </button>
                </div>

                {payouts.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-theme p-3 text-center text-xs text-theme-muted">
                        No platform-specific payouts added yet. (Uses primary offer payout above by default).
                        <button
                            type="button"
                            onClick={handleAddPayoutRow}
                            className="ml-2 font-semibold text-theme-accent hover:underline cursor-pointer"
                        >
                            + Add first network row
                        </button>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {payouts.map((row, idx) => (
                            <div
                                key={idx}
                                className="flex flex-wrap sm:flex-nowrap items-center gap-2 p-2 rounded-lg border border-theme bg-theme-surface"
                            >
                                {/* Platform selection */}
                                <div className="w-full sm:w-1/3">
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

                                {/* Payout amount */}
                                <div className="w-1/2 sm:w-1/3">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className={control}
                                        placeholder="Payout amount"
                                        value={row.payout ?? ''}
                                        onChange={(e) => handleUpdatePayoutRow(idx, 'payout', e.target.value)}
                                    />
                                </div>

                                {/* Currency */}
                                <div className="w-1/3 sm:w-1/4">
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

                                {/* Remove Button */}
                                <button
                                    type="button"
                                    onClick={() => handleRemovePayoutRow(idx)}
                                    className="p-1.5 text-rose-500 hover:text-rose-700 hover:bg-rose-500/10 rounded transition-colors cursor-pointer shrink-0"
                                    title="Remove this platform payout row"
                                >
                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <line x1="18" y1="6" x2="6" y2="18" />
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                    </svg>
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </fieldset>
    );
}
