import React, { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import GoogleAdsAccountSelect from './GoogleAdsAccountSelect';
import PreLaunchChecklist from './PreLaunchChecklist';

const COMMON_COUNTRIES = [
    { code: 'US', label: 'United States', flag: '🇺🇸' },
    { code: 'CA', label: 'Canada', flag: '🇨🇦' },
    { code: 'GB', label: 'United Kingdom', flag: '🇬🇧' },
    { code: 'AU', label: 'Australia', flag: '🇦🇺' },
    { code: 'NZ', label: 'New Zealand', flag: '🇳🇿' },
    { code: 'IE', label: 'Ireland', flag: '🇮🇪' },
    { code: 'DE', label: 'Germany', flag: '🇩🇪' },
    { code: 'FR', label: 'France', flag: '🇫🇷' },
    { code: 'IT', label: 'Italy', flag: '🇮🇹' },
    { code: 'ES', label: 'Spain', flag: '🇪🇸' },
    { code: 'NL', label: 'Netherlands', flag: '🇳🇱' },
    { code: 'BE', label: 'Belgium', flag: '🇧🇪' },
    { code: 'AT', label: 'Austria', flag: '🇦🇹' },
    { code: 'CH', label: 'Switzerland', flag: '🇨🇭' },
    { code: 'SE', label: 'Sweden', flag: '🇸🇪' },
    { code: 'NO', label: 'Norway', flag: '🇳🇴' },
    { code: 'DK', label: 'Denmark', flag: '🇩🇰' },
    { code: 'FI', label: 'Finland', flag: '🇫🇮' },
    { code: 'ZA', label: 'South Africa', flag: '🇿🇦' },
    { code: 'SG', label: 'Singapore', flag: '🇸🇬' },
];

const LANGUAGES = [
    { code: 'en', label: 'English (en)' },
    { code: 'es', label: 'Spanish (es)' },
    { code: 'fr', label: 'French (fr)' },
    { code: 'de', label: 'German (de)' },
    { code: 'it', label: 'Italian (it)' },
    { code: 'pt', label: 'Portuguese (pt)' },
    { code: 'nl', label: 'Dutch (nl)' },
];

const AD_COPY_PRESETS = [
    {
        id: 'blank',
        name: 'Leave blank (No RSA / configure later in Google Ads)',
        getHeadlines: () => [],
        getDescriptions: () => [],
    },
    {
        id: 'official',
        name: 'Ad Copy Studio: Official Brand & Direct Set',
        getHeadlines: (pName) => [
            `Official ${pName ? pName.slice(0, 20) : 'Store'} Direct`.slice(0, 30),
            '100% Authentic Formula',
            'Order From Official Site',
            'Exclusive Web Discounts',
            'Fast & Discreet Shipping',
        ],
        getDescriptions: (pName) => [
            `Order ${pName ? pName.slice(0, 30) : 'direct'} from the official distributor. Secure 256-bit encrypted checkout.`.slice(0, 90),
            'Claim your exclusive multi-bottle bundle discount today. 100% money back guarantee.',
        ],
    },
    {
        id: 'discount',
        name: 'Ad Copy Studio: Discount & Savings Offer Set',
        getHeadlines: (pName) => [
            'Up To 50% Off Today Only',
            `Official ${pName ? pName.slice(0, 15) : 'Offer'} Sale`.slice(0, 30),
            'Limited Time Web Savings',
            'Free Shipping On Bundles',
            'Claim Your Discount Now',
        ],
        getDescriptions: () => [
            'Huge savings on multi-packs today only. Direct manufacturer supply while stock lasts.',
            'Backed by a full 60-day money-back guarantee. Order now with free expedited shipping.',
        ],
    },
    {
        id: 'review',
        name: 'Ad Copy Studio: Customer Review & Authority Set',
        getHeadlines: (pName) => [
            `Real ${pName ? pName.slice(0, 15) : 'Product'} Reviews`.slice(0, 30),
            'Top Rated Health Formula',
            'See Verified User Results',
            '100% Satisfaction Record',
            'Doctor Formulated Quality',
        ],
        getDescriptions: () => [
            'Discover why thousands of customers rate this formula 5 stars. Read verified buyer feedback.',
            'High potency natural ingredients with zero fillers. Full refund guarantee on every order.',
        ],
    },
];

export default function CampaignCreationModal({
    isOpen,
    onClose,
    onSuccess,
    product = null,
    accounts = [],
    defaultAccountId = null,
    negativeKeywords = [],
}) {
    if (!isOpen) return null;

    const hasAccounts = accounts && accounts.length > 0;
    const defaultAccId = defaultAccountId || accounts.find((a) => a.is_default)?.id || (hasAccounts ? accounts[0].id : '');

    // Form fields state
    const [accountId, setAccountId] = useState(defaultAccId);
    const [campaignName, setCampaignName] = useState(product ? `${product.name} - Search` : '');
    const [budgetCad, setBudgetCad] = useState('20.00');
    const [biddingStrategy, setBiddingStrategy] = useState('MANUAL_CPC');
    const [cpcBidCad, setCpcBidCad] = useState('1.50');

    // Networks
    const [targetSearchNetwork, setTargetSearchNetwork] = useState(true);
    const [targetContentNetwork, setTargetContentNetwork] = useState(false);

    // Geos
    const initialGeos = React.useMemo(() => {
        if (!product || !product.geos) return ['US'];
        if (Array.isArray(product.geos)) return product.geos.map((g) => g.toUpperCase());
        return String(product.geos).split(',').map((g) => g.trim().toUpperCase()).filter(Boolean);
    }, [product]);

    const [selectedGeos, setSelectedGeos] = useState(initialGeos);

    // Language
    const [language, setLanguage] = useState('en');

    // Dates
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');

    // Ad Group
    const [adGroupName, setAdGroupName] = useState(product ? `${product.name} - Exact` : 'Search Ad Group 1');

    // Keywords
    const initialKeywordsText = React.useMemo(() => {
        if (!product?.name) return '';
        const name = product.name.trim();
        return `${name}\nbuy ${name}\n${name} official\n${name} reviews`;
    }, [product]);

    const [keywordsText, setKeywordsText] = useState(initialKeywordsText);
    const [keywordMatchType, setKeywordMatchType] = useState('EXACT');

    // Final URL
    const [finalUrl, setFinalUrl] = useState(product?.sales_page_url || product?.affiliate_link || '');

    // Negative Keywords
    const [attachNegatives, setAttachNegatives] = useState(negativeKeywords.length > 0);
    const [showNegativesPreview, setShowNegativesPreview] = useState(false);

    // Ad Copy Studio RSA
    const [selectedPresetId, setSelectedPresetId] = useState('blank');
    const [rsaHeadlines, setRsaHeadlines] = useState([]);
    const [rsaDescriptions, setRsaDescriptions] = useState([]);

    // Target CVR for unit economics (1.0% to 3.0%)
    const [targetCvrPct, setTargetCvrPct] = useState(2.0);

    // Submission & Errors
    const [submitting, setSubmitting] = useState(false);
    const [errorBanner, setErrorBanner] = useState('');
    const [validationErrors, setValidationErrors] = useState({});

    // Unit Economics computation
    const payoutCad = React.useMemo(() => {
        if (!product) return null;
        if (product.payout_cad && Number(product.payout_cad) > 0) return Number(product.payout_cad);
        if (product.payout_amount && Number(product.payout_amount) > 0) return Number(product.payout_amount);
        return null;
    }, [product]);

    const breakEvenCpa = payoutCad ? payoutCad.toFixed(2) : null;
    const suggestedMaxCpc = payoutCad ? (payoutCad * (targetCvrPct / 100)).toFixed(2) : null;

    // Handle Preset Change
    function handlePresetSelect(presetId) {
        setSelectedPresetId(presetId);
        const preset = AD_COPY_PRESETS.find((p) => p.id === presetId);
        if (preset) {
            setRsaHeadlines(preset.getHeadlines(product?.name || ''));
            setRsaDescriptions(preset.getDescriptions(product?.name || ''));
        }
    }

    // Toggle Geo
    function toggleGeo(code) {
        if (selectedGeos.includes(code)) {
            setSelectedGeos(selectedGeos.filter((c) => c !== code));
        } else {
            setSelectedGeos([...selectedGeos, code]);
        }
    }

    // Client-side Validation
    function validateForm() {
        const errors = {};

        if (!accountId) {
            errors.account = 'Please select a Google Ads account.';
        }

        if (!campaignName.trim()) {
            errors.name = 'Campaign name is required.';
        }

        const budgetNum = Number(budgetCad);
        if (isNaN(budgetNum) || budgetNum <= 0) {
            errors.budget = 'Please enter a valid daily budget greater than 0.';
        }

        if (selectedGeos.length === 0) {
            errors.geos = 'Please select at least one target country.';
        }

        const keywordsList = keywordsText.split('\n').map((k) => k.trim()).filter(Boolean);
        if (keywordsList.length > 0 && !adGroupName.trim()) {
            errors.adGroupName = 'Ad Group Name is required when keywords are provided.';
        }

        // RSA validation if filled
        const validHeadlines = rsaHeadlines.map((h) => h.trim()).filter(Boolean);
        const validDescriptions = rsaDescriptions.map((d) => d.trim()).filter(Boolean);
        const hasAnyRsa = validHeadlines.length > 0 || validDescriptions.length > 0;

        if (hasAnyRsa) {
            if (validHeadlines.length < 3) {
                errors.rsa = 'Responsive Search Ads require at least 3 headlines (minimum 3).';
            }
            if (validDescriptions.length < 2) {
                errors.rsa = 'Responsive Search Ads require at least 2 descriptions (minimum 2).';
            }
            if (!finalUrl.trim() || !/^https?:\/\//i.test(finalUrl.trim())) {
                errors.finalUrl = 'A valid Final URL (http:// or https://) is required to attach an Ad.';
            }

            const overHeadline = validHeadlines.find((h) => h.length > 30);
            if (overHeadline) {
                errors.rsa = `Headline "${overHeadline}" exceeds the 30-character limit.`;
            }

            const overDescription = validDescriptions.find((d) => d.length > 90);
            if (overDescription) {
                errors.rsa = `Description exceeds the 90-character limit.`;
            }
        }

        if (endDate && startDate && endDate < startDate) {
            errors.endDate = 'End date cannot be earlier than start date.';
        }

        setValidationErrors(errors);
        return Object.keys(errors).length === 0;
    }

    // Handle Submit
    async function handleSubmit(e) {
        e.preventDefault();
        setErrorBanner('');

        if (!validateForm()) {
            setErrorBanner('Please resolve the highlighted validation errors before proceeding.');
            return;
        }

        setSubmitting(true);

        const budgetMicros = Math.round(Number(budgetCad || 10) * 1_000_000);
        const cpcBidMicros = Math.round(Number(cpcBidCad || 1.5) * 1_000_000);
        const keywordsList = keywordsText.split('\n').map((k) => k.trim()).filter(Boolean);
        const cleanHeadlines = rsaHeadlines.map((h) => h.trim()).filter(Boolean);
        const cleanDescriptions = rsaDescriptions.map((d) => d.trim()).filter(Boolean);

        const payload = {
            account_id: Number(accountId),
            name: campaignName.trim(),
            budget_amount_micros: budgetMicros,
            bidding_strategy: biddingStrategy,
            target_search_network: targetSearchNetwork,
            target_content_network: targetContentNetwork,
            language: language,
            geos: selectedGeos,
            start_date: startDate || undefined,
            end_date: endDate || undefined,
            negative_keywords: attachNegatives ? negativeKeywords : [],
            ad_group_name: adGroupName.trim() || undefined,
            cpc_bid_micros: cpcBidMicros,
            keywords: keywordsList.length > 0 ? keywordsList : undefined,
            keyword_match_type: keywordMatchType,
            final_url: finalUrl.trim() || undefined,
            headlines: cleanHeadlines.length >= 3 ? cleanHeadlines : undefined,
            descriptions: cleanDescriptions.length >= 2 ? cleanDescriptions : undefined,
        };

        try {
            const res = await fetch('/internal/google-ads/campaigns', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(payload),
            });

            const result = await res.json();
            if (!res.ok) {
                throw new Error(result.error || result.message || 'Failed to create Google Ads campaign.');
            }

            if (onSuccess) {
                onSuccess(result.data);
            }
            onClose();
        } catch (err) {
            setErrorBanner(err.message || 'An error occurred while creating the campaign.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-xs overflow-y-auto">
            <div className="relative w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl border border-slate-700/80 bg-slate-900 shadow-2xl overflow-hidden my-auto">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-800 bg-slate-900/95 sticky top-0 z-20">
                    <div className="flex items-center gap-3">
                        <div className="p-2 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h3 className="text-base font-bold text-white">Create Google Ads Campaign</h3>
                                <span className="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                    Status: PAUSED
                                </span>
                            </div>
                            <p className="text-xs text-slate-400">
                                {product ? `Pre-filled from "${product.name}"` : 'Configure search campaign targeting, ad group, keywords, and ads'}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Body Content */}
                <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-6 space-y-6">
                    {/* Error Banner */}
                    {errorBanner && (
                        <div className="p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-300 text-xs flex items-start gap-2.5">
                            <svg className="w-4 h-4 shrink-0 text-red-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{errorBanner}</span>
                        </div>
                    )}

                    {/* Linked Account Warning */}
                    {!hasAccounts ? (
                        <div className="p-5 rounded-xl border border-amber-500/30 bg-amber-500/10 text-slate-200">
                            <div className="flex items-start gap-3">
                                <svg className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div className="space-y-2">
                                    <h4 className="text-sm font-semibold text-amber-300">No Google Ads Account Linked</h4>
                                    <p className="text-xs text-slate-300">
                                        You must link a Google Ads account before you can launch campaigns directly from Flow Hub.
                                    </p>
                                    <div className="pt-1">
                                        <Link
                                            href="/google-ads/connect"
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-500 hover:bg-amber-400 text-slate-950 transition-colors shadow-xs"
                                        >
                                            Link Google Ads Account &rarr;
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div>
                            <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                                Google Ads Account *
                            </label>
                            <GoogleAdsAccountSelect
                                accounts={accounts}
                                value={accountId}
                                onChange={(val) => setAccountId(val)}
                                required
                            />
                            {validationErrors.account && (
                                <p className="text-[11px] text-red-400 mt-1">{validationErrors.account}</p>
                            )}
                        </div>
                    )}

                    {/* Section 1: Campaign Basics */}
                    <div className="space-y-4 pt-2 border-t border-slate-800">
                        <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-400 flex items-center gap-2">
                            <span>1. Campaign Settings</span>
                        </h4>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Campaign Name *
                                </label>
                                <input
                                    type="text"
                                    value={campaignName}
                                    onChange={(e) => setCampaignName(e.target.value)}
                                    placeholder="e.g. Fimavyn Blood Sugar - Search"
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                    required
                                />
                                {validationErrors.name && (
                                    <p className="text-[11px] text-red-400 mt-1">{validationErrors.name}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Bidding Strategy
                                </label>
                                <select
                                    value={biddingStrategy}
                                    onChange={(e) => setBiddingStrategy(e.target.value)}
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                >
                                    <option value="MANUAL_CPC">Manual CPC (Full Bid Control)</option>
                                    <option value="MAXIMIZE_CLICKS">Maximize Clicks (Automated Traffic)</option>
                                    <option value="MAXIMIZE_CONVERSIONS">Maximize Conversions</option>
                                    <option value="TARGET_CPA">Target CPA</option>
                                    <option value="TARGET_ROAS">Target ROAS</option>
                                </select>
                            </div>
                        </div>

                        {/* Networks */}
                        <div className="p-3 bg-slate-950/60 rounded-xl border border-slate-800/80 space-y-2">
                            <span className="text-xs font-medium text-slate-300 block mb-1">Networks</span>
                            <div className="flex flex-wrap items-center gap-6 text-xs text-slate-300">
                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={targetSearchNetwork}
                                        onChange={(e) => setTargetSearchNetwork(e.target.checked)}
                                        className="rounded border-slate-700 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span>Include Google Search Partners</span>
                                </label>

                                <label className="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={targetContentNetwork}
                                        onChange={(e) => setTargetContentNetwork(e.target.checked)}
                                        className="rounded border-slate-700 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span className="text-slate-400">Include Google Display Network (off by default)</span>
                                </label>
                            </div>
                        </div>

                        {/* Schedule (Dates) */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Start Date (optional)
                                </label>
                                <input
                                    type="date"
                                    value={startDate}
                                    onChange={(e) => setStartDate(e.target.value)}
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    End Date (optional)
                                </label>
                                <input
                                    type="date"
                                    value={endDate}
                                    onChange={(e) => setEndDate(e.target.value)}
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                />
                                {validationErrors.endDate && (
                                    <p className="text-[11px] text-red-400 mt-1">{validationErrors.endDate}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Section 2: Unit Economics & Budget */}
                    <div className="space-y-4 pt-2 border-t border-slate-800">
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-2">
                                <span>2. Budget & Unit Economics</span>
                            </h4>
                            <span className="text-[10px] text-emerald-300/80 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20 font-medium">
                                Suggested Baseline (Not Enforced)
                            </span>
                        </div>

                        {/* Unit Economics Callout */}
                        {payoutCad ? (
                            <div className="p-4 rounded-xl border border-emerald-500/20 bg-emerald-950/20 space-y-3">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                    <div className="p-3 rounded-lg bg-slate-900/80 border border-emerald-500/10">
                                        <div className="text-slate-400 font-medium">Break-even CPA</div>
                                        <div className="text-lg font-bold text-white mt-0.5">
                                            ${breakEvenCpa} <span className="text-xs font-normal text-emerald-400">CAD</span>
                                        </div>
                                        <p className="text-[11px] text-slate-400 mt-1">
                                            1 sale at this cost pays for itself exactly (0% profit).
                                        </p>
                                    </div>

                                    <div className="p-3 rounded-lg bg-slate-900/80 border border-emerald-500/10">
                                        <div className="text-slate-400 font-medium flex items-center justify-between">
                                            <span>Suggested Max CPC</span>
                                            <span className="text-[11px] text-emerald-400 font-mono">@{targetCvrPct.toFixed(1)}% CVR</span>
                                        </div>
                                        <div className="text-lg font-bold text-emerald-300 mt-0.5 flex items-center gap-2">
                                            <span>${suggestedMaxCpc} CAD</span>
                                            <button
                                                type="button"
                                                onClick={() => setCpcBidCad(suggestedMaxCpc)}
                                                className="text-[10px] px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 transition-colors font-semibold"
                                                title="Apply this suggested CPC to Default CPC Bid"
                                            >
                                                Apply to Bid
                                            </button>
                                        </div>
                                        <p className="text-[11px] text-slate-400 mt-1">
                                            Formula: Payout × Target CVR ({payoutCad} × {(targetCvrPct / 100).toFixed(3)}).
                                        </p>
                                    </div>
                                </div>

                                {/* Target CVR Selector chips */}
                                <div className="flex flex-wrap items-center gap-2 pt-1 text-xs text-slate-300">
                                    <span className="text-slate-400 text-[11px]">Estimate at CVR:</span>
                                    {[1.0, 1.5, 2.0, 2.5, 3.0].map((cvr) => {
                                        const cpcEst = (payoutCad * (cvr / 100)).toFixed(2);
                                        const isActive = targetCvrPct === cvr;
                                        return (
                                            <button
                                                key={cvr}
                                                type="button"
                                                onClick={() => {
                                                    setTargetCvrPct(cvr);
                                                    setCpcBidCad(cpcEst);
                                                }}
                                                className={`px-2 py-1 rounded text-[11px] font-mono transition-colors ${
                                                    isActive
                                                        ? 'bg-emerald-500 text-slate-950 font-bold'
                                                        : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                                                }`}
                                            >
                                                {cvr.toFixed(1)}% (${cpcEst})
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : (
                            <div className="p-3 rounded-lg bg-slate-950 border border-slate-800 text-xs text-slate-400">
                                No commission payout detected for this product. Set budget and bid manually below.
                            </div>
                        )}

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Daily Budget (CAD$) *
                                </label>
                                <div className="relative">
                                    <span className="absolute left-3 top-2.5 text-slate-500 text-sm">$</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="1"
                                        value={budgetCad}
                                        onChange={(e) => setBudgetCad(e.target.value)}
                                        className="w-full pl-7 pr-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                        required
                                    />
                                </div>
                                {validationErrors.budget && (
                                    <p className="text-[11px] text-red-400 mt-1">{validationErrors.budget}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Default Max CPC Bid (CAD$)
                                </label>
                                <div className="relative">
                                    <span className="absolute left-3 top-2.5 text-slate-500 text-sm">$</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        value={cpcBidCad}
                                        onChange={(e) => setCpcBidCad(e.target.value)}
                                        className="w-full pl-7 pr-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Section 3: Targeting (Geos & Language) */}
                    <div className="space-y-4 pt-2 border-t border-slate-800">
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-400">
                                3. Target Countries & Language
                            </h4>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setSelectedGeos(['US', 'CA', 'GB', 'AU', 'NZ'])}
                                    className="text-[10px] text-indigo-400 hover:underline"
                                >
                                    Tier 1 (5)
                                </button>
                                <span className="text-slate-600">&bull;</span>
                                <button
                                    type="button"
                                    onClick={() => setSelectedGeos(COMMON_COUNTRIES.map((c) => c.code))}
                                    className="text-[10px] text-indigo-400 hover:underline"
                                >
                                    Select All
                                </button>
                                <span className="text-slate-600">&bull;</span>
                                <button
                                    type="button"
                                    onClick={() => setSelectedGeos([])}
                                    className="text-[10px] text-slate-400 hover:underline"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>

                        {/* Country Multi-Select Chips */}
                        <div className="space-y-1.5">
                            <div className="flex flex-wrap gap-1.5 max-h-36 overflow-y-auto p-2 rounded-xl bg-slate-950 border border-slate-800">
                                {COMMON_COUNTRIES.map((c) => {
                                    const isSelected = selectedGeos.includes(c.code);
                                    return (
                                        <button
                                            key={c.code}
                                            type="button"
                                            onClick={() => toggleGeo(c.code)}
                                            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-all ${
                                                isSelected
                                                    ? 'bg-indigo-600 text-white shadow-xs'
                                                    : 'bg-slate-900 text-slate-400 hover:bg-slate-800 hover:text-slate-200 border border-slate-800'
                                            }`}
                                        >
                                            <span>{c.flag}</span>
                                            <span>{c.code}</span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="text-[11px] text-slate-400">
                                Selected ({selectedGeos.length}): {selectedGeos.join(', ') || 'None'}
                            </div>
                            {validationErrors.geos && (
                                <p className="text-[11px] text-red-400">{validationErrors.geos}</p>
                            )}
                        </div>

                        {/* Language */}
                        <div className="w-full sm:w-1/2">
                            <label className="block text-xs font-medium text-slate-300 mb-1">
                                Target Language
                            </label>
                            <select
                                value={language}
                                onChange={(e) => setLanguage(e.target.value)}
                                className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                            >
                                {LANGUAGES.map((l) => (
                                    <option key={l.code} value={l.code}>{l.label}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Section 4: Ad Group & Keywords */}
                    <div className="space-y-4 pt-2 border-t border-slate-800">
                        <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-400">
                            4. Ad Group & Keywords
                        </h4>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Ad Group Name *
                                </label>
                                <input
                                    type="text"
                                    value={adGroupName}
                                    onChange={(e) => setAdGroupName(e.target.value)}
                                    placeholder="e.g. Fimavyn - Exact Match"
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                />
                                {validationErrors.adGroupName && (
                                    <p className="text-[11px] text-red-400 mt-1">{validationErrors.adGroupName}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-300 mb-1">
                                    Keyword Match Type
                                </label>
                                <select
                                    value={keywordMatchType}
                                    onChange={(e) => setKeywordMatchType(e.target.value)}
                                    className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                                >
                                    <option value="EXACT">EXACT [keyword] (Highest Intent)</option>
                                    <option value="PHRASE">PHRASE "keyword" (Moderate Reach)</option>
                                    <option value="BROAD">BROAD keyword (Maximum Reach)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <label className="text-xs font-medium text-slate-300">
                                    Keywords (One per line)
                                </label>
                                <span className="text-[11px] text-slate-400">
                                    {keywordsText.split('\n').filter((k) => k.trim()).length} keywords
                                </span>
                            </div>
                            <textarea
                                rows={4}
                                value={keywordsText}
                                onChange={(e) => setKeywordsText(e.target.value)}
                                placeholder="enter keywords, one per line..."
                                className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs font-mono text-white focus:outline-none focus:border-indigo-500"
                            />
                        </div>

                        {/* Negative Keywords Attachment */}
                        {negativeKeywords && negativeKeywords.length > 0 && (
                            <div className="p-3 rounded-xl bg-slate-950/70 border border-slate-800 space-y-2">
                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2 cursor-pointer text-xs text-slate-300 font-medium">
                                        <input
                                            type="checkbox"
                                            checked={attachNegatives}
                                            onChange={(e) => setAttachNegatives(e.target.checked)}
                                            className="rounded border-slate-700 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span>
                                            Attach {negativeKeywords.length} Product Negative Keywords to Campaign
                                        </span>
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setShowNegativesPreview(!showNegativesPreview)}
                                        className="text-[11px] text-indigo-400 hover:underline"
                                    >
                                        {showNegativesPreview ? 'Hide List' : 'Preview List'}
                                    </button>
                                </div>

                                {showNegativesPreview && (
                                    <div className="max-h-28 overflow-y-auto p-2 rounded-lg bg-slate-900 border border-slate-800 text-[11px] font-mono text-slate-400 flex flex-wrap gap-1.5">
                                        {negativeKeywords.map((kw, i) => (
                                            <span key={i} className="px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">
                                                -{kw}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Section 5: Responsive Search Ad (Ad Copy Studio) */}
                    <div className="space-y-4 pt-2 border-t border-slate-800">
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-indigo-400 flex items-center gap-2">
                                <span>5. Responsive Search Ad (Optional)</span>
                            </h4>
                            <span className="text-[10px] text-slate-400">
                                Powered by Ad Copy Studio
                            </span>
                        </div>

                        {/* Final URL */}
                        <div>
                            <label className="block text-xs font-medium text-slate-300 mb-1">
                                Final URL (Landing Page or Presell Page)
                            </label>
                            <input
                                type="url"
                                value={finalUrl}
                                onChange={(e) => setFinalUrl(e.target.value)}
                                placeholder="https://example.com/offer"
                                className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                            />
                            {validationErrors.finalUrl && (
                                <p className="text-[11px] text-red-400 mt-1">{validationErrors.finalUrl}</p>
                            )}
                        </div>

                        {/* Pick Preset */}
                        <div>
                            <label className="block text-xs font-medium text-slate-300 mb-1">
                                Pick Ad Copy Studio Headline / Description Set
                            </label>
                            <select
                                value={selectedPresetId}
                                onChange={(e) => handlePresetSelect(e.target.value)}
                                className="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500"
                            >
                                {AD_COPY_PRESETS.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        </div>

                        {validationErrors.rsa && (
                            <p className="text-[11px] text-red-400">{validationErrors.rsa}</p>
                        )}

                        {/* Interactive Headlines & Descriptions if preset active */}
                        {selectedPresetId !== 'blank' && (
                            <div className="space-y-4 p-4 rounded-xl bg-slate-950 border border-slate-800">
                                {/* Headlines */}
                                <div>
                                    <div className="flex items-center justify-between mb-2">
                                        <label className="text-xs font-semibold text-slate-300">
                                            Headlines ({rsaHeadlines.length} / 15 &bull; Max 30 chars each &bull; Min 3)
                                        </label>
                                        <button
                                            type="button"
                                            disabled={rsaHeadlines.length >= 15}
                                            onClick={() => setRsaHeadlines([...rsaHeadlines, ''])}
                                            className="text-[11px] text-indigo-400 hover:underline"
                                        >
                                            + Add Headline
                                        </button>
                                    </div>
                                    <div className="space-y-2">
                                        {rsaHeadlines.map((h, idx) => {
                                            const len = h.length;
                                            const isOver = len > 30;
                                            return (
                                                <div key={idx} className="flex items-center gap-2">
                                                    <span className="text-[11px] font-mono text-slate-500 w-5">#{idx + 1}</span>
                                                    <input
                                                        type="text"
                                                        value={h}
                                                        onChange={(e) => {
                                                            const updated = [...rsaHeadlines];
                                                            updated[idx] = e.target.value;
                                                            setRsaHeadlines(updated);
                                                        }}
                                                        placeholder="Headline text (max 30 characters)"
                                                        className={`flex-1 px-3 py-1.5 bg-slate-900 border rounded-lg text-xs text-white focus:outline-none ${
                                                            isOver ? 'border-red-500 text-red-300' : 'border-slate-700'
                                                        }`}
                                                    />
                                                    <span className={`text-[11px] font-mono w-10 text-right ${isOver ? 'text-red-400 font-bold' : 'text-slate-400'}`}>
                                                        {len}/30
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setRsaHeadlines(rsaHeadlines.filter((_, i) => i !== idx))}
                                                        className="text-slate-500 hover:text-red-400 text-xs px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>

                                {/* Descriptions */}
                                <div>
                                    <div className="flex items-center justify-between mb-2">
                                        <label className="text-xs font-semibold text-slate-300">
                                            Descriptions ({rsaDescriptions.length} / 4 &bull; Max 90 chars each &bull; Min 2)
                                        </label>
                                        <button
                                            type="button"
                                            disabled={rsaDescriptions.length >= 4}
                                            onClick={() => setRsaDescriptions([...rsaDescriptions, ''])}
                                            className="text-[11px] text-indigo-400 hover:underline"
                                        >
                                            + Add Description
                                        </button>
                                    </div>
                                    <div className="space-y-2">
                                        {rsaDescriptions.map((d, idx) => {
                                            const len = d.length;
                                            const isOver = len > 90;
                                            return (
                                                <div key={idx} className="flex items-center gap-2">
                                                    <span className="text-[11px] font-mono text-slate-500 w-5">#{idx + 1}</span>
                                                    <input
                                                        type="text"
                                                        value={d}
                                                        onChange={(e) => {
                                                            const updated = [...rsaDescriptions];
                                                            updated[idx] = e.target.value;
                                                            setRsaDescriptions(updated);
                                                        }}
                                                        placeholder="Description text (max 90 characters)"
                                                        className={`flex-1 px-3 py-1.5 bg-slate-900 border rounded-lg text-xs text-white focus:outline-none ${
                                                            isOver ? 'border-red-500 text-red-300' : 'border-slate-700'
                                                        }`}
                                                    />
                                                    <span className={`text-[11px] font-mono w-10 text-right ${isOver ? 'text-red-400 font-bold' : 'text-slate-400'}`}>
                                                        {len}/90
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setRsaDescriptions(rsaDescriptions.filter((_, i) => i !== idx))}
                                                        className="text-slate-500 hover:text-red-400 text-xs px-1"
                                                    >
                                                        &times;
                                                    </button>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Pre-Launch Checklist (Informational only, does not block creation) */}
                    <div className="px-1 py-2">
                        <PreLaunchChecklist
                            accountLinked={hasAccounts}
                            accountSelected={Boolean(accountId)}
                            accountName={accounts.find((a) => String(a.id) === String(accountId))?.name || ''}
                            accountCustomerId={accounts.find((a) => String(a.id) === String(accountId))?.customer_id || ''}
                            conversionActionReady={Boolean(product?.tracks?.[0]?.conversion_action_id)}
                            conversionActionName={product?.tracks?.[0]?.conversion_action_name || (product?.tracks?.[0] ? 'Offline Conversion Action' : '')}
                            conversionMode="existing"
                            postbackUrl={product?.tracks?.[0]?.postback_url || ''}
                            hasPages={Boolean(product?.tracks?.[0] && (product.tracks[0].pages_count || 0) > 0)}
                            pagesCount={product?.tracks?.[0]?.pages_count || 0}
                            isNew={!product?.tracks?.[0]}
                        />
                    </div>

                    {/* Footer Actions */}
                    <div className="pt-4 border-t border-slate-800 flex items-center justify-end gap-3 sticky bottom-0 bg-slate-900/95 py-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            disabled={submitting || !hasAccounts}
                            className="px-5 py-2 rounded-xl text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white transition-all shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                        >
                            {submitting ? (
                                <>
                                    <svg className="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                    </svg>
                                    <span>Creating Campaign...</span>
                                </>
                            ) : (
                                <span>Create Search Campaign (PAUSED)</span>
                            )}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
