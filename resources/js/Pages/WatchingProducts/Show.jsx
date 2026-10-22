import React, { useState, useMemo } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';
import {
    ResponsiveContainer,
    LineChart,
    Line,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
} from 'recharts';

export function getScoreHsl(score) {
    if (score === null || score === undefined) {
        return {
            val: 0,
            hue: 0,
            band: 'none',
            style: {
                backgroundColor: 'rgba(150, 150, 150, 0.12)',
                borderColor: 'rgba(150, 150, 150, 0.25)',
                color: 'currentColor',
            },
        };
    }
    const val = Math.max(0, Math.min(100, Math.round(Number(score))));
    const hue = (val / 100) * 120;

    let band = 'red';
    if (val > 75) band = 'green';
    else if (val > 50) band = 'yellow-green';
    else if (val > 20) band = 'amber';

    return {
        val,
        hue,
        band,
        style: {
            backgroundColor: `hsla(${hue}, 85%, 45%, 0.16)`,
            borderColor: `hsla(${hue}, 80%, 45%, 0.4)`,
            color: `hsl(${hue}, 85%, 42%)`,
        },
    };
}

export default function Show({
    product,
    score_history = [],
    negative_keywords = [],
    campaign_guidance = {},
    serpapi_usage = {},
    options = {},
}) {
    const [toastMessage, setToastMessage] = useState('');
    const [activeTab, setActiveTab] = useState('overview'); // overview, negatives, guidance, history
    const [selectedSourceFilter, setSelectedSourceFilter] = useState('all');
    const [isAddingNegative, setIsAddingNegative] = useState(false);
    const [isEditingProduct, setIsEditingProduct] = useState(false);

    // Negative form
    const negativeForm = useForm({
        term: '',
        match_type: 'broad',
    });

    // Product edit form
    const editForm = useForm({
        name: product.name || '',
        status: product.status || 'watch',
        product_kind: product.product_kind || 'nutra',
        esteira: Boolean(product.esteira),
        restricted_category: Boolean(product.restricted_category),
        payout_amount: product.payout_amount !== null ? product.payout_amount : '',
        payout_currency: product.payout_currency || 'USD',
        payout_model: product.payout_model || 'cpa_fixed',
        payout_notes: product.payout_notes || '',
        page_type: product.page_type || 'TSL',
        brand_bidding: product.brand_bidding || 'unknown',
        true_bof: product.true_bof || 'ambiguous',
        bof_translation: Boolean(product.bof_translation),
        bof_vpn_serp: Boolean(product.bof_vpn_serp),
        bof_image_match_pct: product.bof_image_match_pct || 0,
        bof_qualifier: product.bof_qualifier || '',
        bof_homonym: Boolean(product.bof_homonym),
        gravity: product.gravity !== null ? product.gravity : '',
        cancel_rate: product.cancel_rate !== null ? product.cancel_rate : '',
        listing_url: product.listing_url || '',
        sales_page_url: product.sales_page_url || '',
        affiliate_link: product.affiliate_link || '',
        notes: product.notes || '',
        approval_required: Boolean(product.approval_required),
        spend_amount: product.spend_amount || 0,
        sales: product.sales || 0,
        checkouts: product.checkouts || 0,
        commission_earned: product.commission_earned || 0,
    });

    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(''), 3000);
    };

    const handleCopy = (text, label) => {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            showToast(`Copied ${label} to clipboard!`);
        });
    };

    const handleCopyAllNegatives = () => {
        const enabledKeywords = negative_keywords.filter((k) => k.enabled);
        if (enabledKeywords.length === 0) {
            showToast('No enabled negative keywords to copy.');
            return;
        }
        const text = enabledKeywords.map((k) => k.formatted_term).join('\n');
        handleCopy(text, `${enabledKeywords.length} Google Ads Negative Keywords`);
    };

    const handleRefreshNow = () => {
        if (confirm(`Refresh SERP data and rescore ${product.name} now? (Uses 2 SerpApi calls)`)) {
            router.post(`/watching-products/${product.id}/refresh-now`, {}, {
                preserveScroll: true,
                onSuccess: () => showToast('SERP data refreshed successfully!'),
            });
        }
    };

    const handleStrategyChange = (newStrategy) => {
        if (confirm(`Change strategy to ${newStrategy}? This will recompute default negative keyword rules.`)) {
            router.post(`/watching-products/${product.id}/strategy`, {
                strategy: newStrategy,
            }, {
                preserveScroll: true,
                onSuccess: () => showToast(`Strategy updated to ${newStrategy}. Rules recalculated.`),
            });
        }
    };

    const handleToggleNegative = (neg) => {
        router.patch(`/watching-products/${product.id}/negatives/${neg.id}`, {
            enabled: !neg.enabled,
        }, {
            preserveScroll: true,
        });
    };

    const handleChangeMatchType = (neg, matchType) => {
        router.patch(`/watching-products/${product.id}/negatives/${neg.id}`, {
            match_type: matchType,
        }, {
            preserveScroll: true,
        });
    };

    const handleDeleteNegative = (neg) => {
        if (confirm(`Remove negative keyword '${neg.term}'?`)) {
            router.delete(`/watching-products/${product.id}/negatives/${neg.id}`, {
                preserveScroll: true,
            });
        }
    };

    const handleAddNegativeSubmit = (e) => {
        e.preventDefault();
        negativeForm.post(`/watching-products/${product.id}/negatives`, {
            preserveScroll: true,
            onSuccess: () => {
                negativeForm.reset();
                setIsAddingNegative(false);
                showToast('Negative keyword added.');
            },
        });
    };

    const handleEditProductSubmit = (e) => {
        e.preventDefault();
        editForm.put(`/watching-products/${product.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditingProduct(false);
                showToast('Product updated successfully!');
            },
        });
    };

    const filteredNegatives = useMemo(() => {
        if (selectedSourceFilter === 'all') return negative_keywords;
        return negative_keywords.filter((k) => k.source === selectedSourceFilter);
    }, [negative_keywords, selectedSourceFilter]);

    const scoreHsl = getScoreHsl(product.score);

    return (
        <Layout>
            <Head title={`${product.name} – Watching Products`} />

            {/* Toast Notification */}
            {toastMessage && (
                <div className="fixed bottom-5 right-5 z-50 flex items-center gap-2 rounded-xl border border-emerald-500/40 bg-theme-surface px-4 py-3 text-sm font-medium text-emerald-600 dark:text-emerald-400 shadow-xl animate-bounce">
                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                        <polyline points="22 4 12 14.01 9 11.01" />
                    </svg>
                    <span>{toastMessage}</span>
                </div>
            )}

            {/* Breadcrumb & Navigation */}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-xs text-theme-muted">
                    <Link href="/watching-products" className="hover:text-theme-primary transition-colors flex items-center gap-1">
                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <polyline points="15 18 9 12 15 6" />
                        </svg>
                        Watching Products
                    </Link>
                    <span>/</span>
                    <span className="text-theme-primary font-medium truncate max-w-[200px] sm:max-w-md">{product.name}</span>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={handleRefreshNow}
                        className="px-3 py-1.5 rounded-lg border border-theme bg-theme-surface hover:bg-theme-subtle text-xs font-semibold text-theme-primary transition-colors flex items-center gap-1.5 cursor-pointer shadow-2xs"
                        title={`SerpApi calls used this month: ${serpapi_usage.used_this_month}/${serpapi_usage.total_limit}`}
                    >
                        <svg className="w-3.5 h-3.5 text-theme-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67" />
                        </svg>
                        <span>Refresh now ({serpapi_usage.used_this_month}/{serpapi_usage.total_limit})</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setIsEditingProduct(!isEditingProduct)}
                        className={`${button} text-xs !py-1.5 !px-3`}
                    >
                        {isEditingProduct ? 'Close Editor' : 'Edit Product'}
                    </button>
                </div>
            </div>

            {/* Product Hero Header */}
            <div className="mb-6 rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-theme-primary">
                                {product.name}
                            </h1>
                            <span className="rounded-md border border-theme bg-theme-subtle px-2 py-0.5 text-xs font-semibold text-theme-secondary uppercase">
                                {product.product_kind_label}
                            </span>
                            {product.esteira && (
                                <span className="rounded-md border border-indigo-500/30 bg-indigo-500/10 px-2 py-0.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                    Esteira
                                </span>
                            )}
                            {product.restricted_category && (
                                <span className="rounded-md border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">
                                    Restricted Category
                                </span>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-3 text-xs text-theme-muted">
                            <span>Status: <strong className="text-theme-primary uppercase">{product.status_label}</strong></span>
                            <span>•</span>
                            <span>Page Type: <strong className="text-theme-primary">{product.page_type || 'General'}</strong></span>
                            <span>•</span>
                            <span>Last Refreshed: <strong className="text-theme-primary">{product.last_refreshed_formatted || 'Never'}</strong></span>
                            <span>•</span>
                            <span>Last Reviewed: <strong className="text-theme-primary">{product.last_reviewed_formatted || 'Never'}</strong></span>
                        </div>
                    </div>

                    {/* Rank & Score Pill */}
                    <div className="flex items-center gap-3 shrink-0">
                        {product.rank !== null && (
                            <div className="text-center rounded-xl border border-theme bg-theme-subtle px-3 py-2">
                                <div className="text-[10px] uppercase font-bold tracking-wider text-theme-muted">Rank</div>
                                <div className="text-xl font-extrabold text-theme-accent">#{product.rank}</div>
                            </div>
                        )}

                        <div
                            style={scoreHsl.style}
                            className="text-center rounded-xl border px-4 py-2 shadow-sm min-w-[90px]"
                        >
                            <div className="text-[10px] uppercase font-bold tracking-wider opacity-80">Potential</div>
                            <div className="text-2xl font-black">
                                {product.score !== null ? product.score : 'N/A'}
                            </div>
                            <div className="text-[9px] uppercase font-semibold opacity-75">{scoreHsl.band}</div>
                        </div>
                    </div>
                </div>

                {/* Strategy Bar */}
                <div className="mt-4 pt-4 border-t border-theme flex flex-wrap items-center justify-between gap-3 text-xs">
                    <div className="flex items-center gap-2">
                        <span className="text-theme-muted font-medium">Recommended Strategy:</span>
                        <select
                            value={product.strategy || 'bof_direct'}
                            onChange={(e) => handleStrategyChange(e.target.value)}
                            className="rounded-lg border border-theme bg-theme-input px-2.5 py-1 text-xs font-bold text-theme-accent focus:outline-none focus:border-theme-accent cursor-pointer"
                        >
                            {options.strategies?.map((st) => (
                                <option key={st.value} value={st.value}>{st.label}</option>
                            ))}
                        </select>
                        <span className="rounded bg-theme-subtle px-1.5 py-0.5 text-[10px] text-theme-muted font-mono uppercase">
                            {product.strategy_source}
                        </span>
                    </div>

                    <div className="text-theme-secondary italic max-w-xl">
                        "{product.strategy_reason}"
                    </div>
                </div>
            </div>

            {/* Campaign Global Alarm Alert if triggered */}
            {campaign_guidance?.global_alarm?.triggered && (
                <div className="mb-6 rounded-xl border border-rose-500/50 bg-rose-500/10 p-4 text-xs text-rose-700 dark:text-rose-300 flex items-start gap-3">
                    <svg className="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                    <div>
                        <div className="font-bold text-sm">GLOBAL CAMPAIGN ALARM ACTIVE</div>
                        <p className="mt-0.5">{campaign_guidance.global_alarm.reason}</p>
                    </div>
                </div>
            )}

            {/* Product Edit Modal / Accordion */}
            {isEditingProduct && (
                <div className="mb-6 rounded-2xl border border-theme-accent/40 bg-theme-surface p-5 sm:p-6 shadow-md">
                    <div className="flex items-center justify-between mb-4 border-b border-theme pb-3">
                        <h3 className="font-bold text-base text-theme-primary">Edit Product Parameters</h3>
                        <button
                            type="button"
                            onClick={() => setIsEditingProduct(false)}
                            className="text-xs text-theme-muted hover:text-theme-primary cursor-pointer"
                        >
                            ✕ Cancel
                        </button>
                    </div>

                    <form onSubmit={handleEditProductSubmit} className="space-y-4 text-xs">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Product Name" error={editForm.errors.name}>
                                <input
                                    type="text"
                                    className={control}
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                />
                            </Field>

                            <Field label="Status" error={editForm.errors.status}>
                                <select
                                    className={control}
                                    value={editForm.data.status}
                                    onChange={(e) => editForm.setData('status', e.target.value)}
                                >
                                    {options.statuses?.map((s) => (
                                        <option key={s.value} value={s.value}>{s.label}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Payout Amount" error={editForm.errors.payout_amount}>
                                <input
                                    type="number"
                                    step="0.01"
                                    className={control}
                                    value={editForm.data.payout_amount}
                                    onChange={(e) => editForm.setData('payout_amount', e.target.value)}
                                    placeholder="100.00"
                                />
                            </Field>

                            <Field label="Payout Currency" error={editForm.errors.payout_currency}>
                                <select
                                    className={control}
                                    value={editForm.data.payout_currency}
                                    onChange={(e) => editForm.setData('payout_currency', e.target.value)}
                                >
                                    {options.currencies?.map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Payout Model" error={editForm.errors.payout_model}>
                                <select
                                    className={control}
                                    value={editForm.data.payout_model}
                                    onChange={(e) => editForm.setData('payout_model', e.target.value)}
                                >
                                    {options.payout_models?.map((m) => (
                                        <option key={m.value} value={m.value}>{m.label}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Page Type" error={editForm.errors.page_type}>
                                <input
                                    type="text"
                                    className={control}
                                    value={editForm.data.page_type}
                                    onChange={(e) => editForm.setData('page_type', e.target.value)}
                                    placeholder="TSL, DTC, VSL, Quiz..."
                                />
                            </Field>

                            <Field label="Brand Bidding" error={editForm.errors.brand_bidding}>
                                <select
                                    className={control}
                                    value={editForm.data.brand_bidding}
                                    onChange={(e) => editForm.setData('brand_bidding', e.target.value)}
                                >
                                    {options.brand_bidding?.map((b) => (
                                        <option key={b.value} value={b.value}>{b.label}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="True BoF Triad" error={editForm.errors.true_bof}>
                                <select
                                    className={control}
                                    value={editForm.data.true_bof}
                                    onChange={(e) => editForm.setData('true_bof', e.target.value)}
                                >
                                    {options.true_bof?.map((t) => (
                                        <option key={t.value} value={t.value}>{t.label}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="BoF Compound Qualifier" error={editForm.errors.bof_qualifier}>
                                <input
                                    type="text"
                                    className={control}
                                    value={editForm.data.bof_qualifier}
                                    onChange={(e) => editForm.setData('bof_qualifier', e.target.value)}
                                    placeholder="e.g. Supplement, Drops (12 pts)"
                                />
                            </Field>

                            <Field label="Image Match % (min. 90% for 20 pts)" error={editForm.errors.bof_image_match_pct}>
                                <input
                                    type="number"
                                    min="0"
                                    max="100"
                                    className={control}
                                    value={editForm.data.bof_image_match_pct}
                                    onChange={(e) => editForm.setData('bof_image_match_pct', e.target.value)}
                                    placeholder="0 - 100"
                                />
                            </Field>

                            <Field label="Sales Page URL" error={editForm.errors.sales_page_url}>
                                <input
                                    type="url"
                                    className={control}
                                    value={editForm.data.sales_page_url}
                                    onChange={(e) => editForm.setData('sales_page_url', e.target.value)}
                                />
                            </Field>

                            <Field label="Affiliate Link" error={editForm.errors.affiliate_link}>
                                <input
                                    type="url"
                                    className={control}
                                    value={editForm.data.affiliate_link}
                                    onChange={(e) => editForm.setData('affiliate_link', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="flex flex-wrap gap-4 pt-2">
                            <label className="flex items-center gap-2 cursor-pointer" title="Auto-detected or manual override: name in target language without translation issues">
                                <input
                                    type="checkbox"
                                    className="rounded border-theme"
                                    checked={editForm.data.bof_translation}
                                    onChange={(e) => editForm.setData('bof_translation', e.target.checked)}
                                />
                                <span className="text-theme-secondary font-medium">BoF Translation Passes</span>
                            </label>

                            <label className="flex items-center gap-2 cursor-pointer" title="Auto-detected via CA/VPN SERP: brand appears in organic or ads results with commercial intent">
                                <input
                                    type="checkbox"
                                    className="rounded border-theme"
                                    checked={editForm.data.bof_vpn_serp}
                                    onChange={(e) => editForm.setData('bof_vpn_serp', e.target.checked)}
                                />
                                <span className="text-theme-secondary font-medium">BoF VPN SERP Passes</span>
                            </label>

                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-theme"
                                    checked={editForm.data.bof_homonym}
                                    onChange={(e) => editForm.setData('bof_homonym', e.target.checked)}
                                />
                                <span className="text-rose-600 dark:text-rose-400 font-bold">Same-Niche Homonym (Score 0)</span>
                            </label>

                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-theme"
                                    checked={editForm.data.restricted_category}
                                    onChange={(e) => editForm.setData('restricted_category', e.target.checked)}
                                />
                                <span className="text-amber-600 dark:text-amber-400 font-medium">Restricted Category (Cap 50)</span>
                            </label>
                        </div>

                        <div className="flex justify-end gap-2 pt-3 border-t border-theme">
                            <button
                                type="button"
                                onClick={() => setIsEditingProduct(false)}
                                className={quiet}
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={editForm.processing}
                                className={button}
                            >
                                {editForm.processing ? 'Saving...' : 'Save & Rescore'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Tabs Navigation */}
            <div className="mb-6 flex border-b border-theme text-sm font-semibold">
                <button
                    type="button"
                    onClick={() => setActiveTab('overview')}
                    className={`pb-3 px-4 border-b-2 transition-colors cursor-pointer ${
                        activeTab === 'overview'
                            ? 'border-theme-accent text-theme-accent'
                            : 'border-transparent text-theme-muted hover:text-theme-primary'
                    }`}
                >
                    Score Breakdown & Raw Inputs
                </button>
                <button
                    type="button"
                    onClick={() => setActiveTab('negatives')}
                    className={`pb-3 px-4 border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 ${
                        activeTab === 'negatives'
                            ? 'border-theme-accent text-theme-accent'
                            : 'border-transparent text-theme-muted hover:text-theme-primary'
                    }`}
                >
                    <span>Negative Keywords</span>
                    <span className="rounded-full bg-theme-subtle px-1.5 py-0.2 text-[11px] font-mono">
                        {negative_keywords.length}
                    </span>
                </button>
                <button
                    type="button"
                    onClick={() => setActiveTab('guidance')}
                    className={`pb-3 px-4 border-b-2 transition-colors cursor-pointer ${
                        activeTab === 'guidance'
                            ? 'border-theme-accent text-theme-accent'
                            : 'border-transparent text-theme-muted hover:text-theme-primary'
                    }`}
                >
                    Campaign Guidance & Stop-Loss
                </button>
                <button
                    type="button"
                    onClick={() => setActiveTab('history')}
                    className={`pb-3 px-4 border-b-2 transition-colors cursor-pointer ${
                        activeTab === 'history'
                            ? 'border-theme-accent text-theme-accent'
                            : 'border-transparent text-theme-muted hover:text-theme-primary'
                    }`}
                >
                    Score History ({score_history.length})
                </button>
            </div>

            {/* TAB 1: OVERVIEW & BREAKDOWN */}
            {activeTab === 'overview' && (
                <div className="space-y-6">
                    {/* Score Component Bars Card */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
                        <h3 className="font-bold text-base text-theme-primary mb-4 flex items-center justify-between">
                            <span>Score Component Breakdown (0-100)</span>
                            <span className="text-xs text-theme-muted font-normal">Weights configured in config/watching.php</span>
                        </h3>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {/* BoF Name */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">BoF Name Triad</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.bof_name ?? 0} / 20</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.bof_name ?? 0) / 20) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted space-y-0.5">
                                    <div>
                                        Images: <span className="font-semibold text-theme-primary">{product.bof_image_match_pct}%</span> (min. 90%) • Trans: <span className={product.bof_translation ? 'text-emerald-500 font-medium' : 'text-rose-500 font-medium'}>{product.bof_translation ? '✓ Pass' : '✗ Fail'}</span> • VPN: <span className={product.bof_vpn_serp ? 'text-emerald-500 font-medium' : 'text-rose-500 font-medium'}>{product.bof_vpn_serp ? '✓ Pass' : '✗ Fail'}</span>
                                    </div>
                                    {product.bof_qualifier && (
                                        <div className="text-amber-500">
                                            Qualifier: &quot;{product.bof_qualifier}&quot; (12 pts partial credit)
                                        </div>
                                    )}
                                    {product.bof_homonym && (
                                        <div className="text-rose-500 font-semibold">
                                            Homonym detected (0 pts)
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Demand */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Demand & Trends</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.demand ?? 0} / 20</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.demand ?? 0) / 20) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Trends 30d: {product.trends_30d ?? 0}, Trend: {product.trends_trend_label || 'Stable'}
                                </div>
                            </div>

                            {/* Payout */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Payout Model</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.payout ?? 0} / 15</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.payout ?? 0) / 15) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    {product.payout_formatted} (CAD ${product.payout_cad})
                                </div>
                            </div>

                            {/* Margin Headroom */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Margin Headroom</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.margin_headroom ?? 0} / 10</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.margin_headroom ?? 0) / 10) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Formula: Payout × {product.est_cvr} CVR − Brand CPC
                                </div>
                            </div>

                            {/* Page Type */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Page Type & Funnel</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.page_type ?? 0} / 10</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.page_type ?? 0) / 10) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Type: {product.page_type || 'Standard'}
                                </div>
                            </div>

                            {/* Brand Bidding */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Brand Bidding</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.brand_bidding ?? 0} / 10</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.brand_bidding ?? 0) / 10) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Status: {product.brand_bidding_label}
                                </div>
                            </div>

                            {/* Geo */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Geo Coverage (CA/US/AU)</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.geo ?? 0} / 5</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.geo ?? 0) / 5) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Geos: {product.geos?.join(', ') || 'Global'}
                                </div>
                            </div>

                            {/* Competition */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Competition Density</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.competition ?? 0} / 5</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.competition ?? 0) / 5) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Advertisers: {product.advertiser_count}
                                </div>
                            </div>

                            {/* Freshness */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Freshness & Approval</span>
                                    <span className="font-mono text-theme-accent">{product.score_breakdown?.freshness ?? 0} / 5</span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-emerald-500 h-2 rounded-full transition-all"
                                        style={{ width: `${((product.score_breakdown?.freshness ?? 0) / 5) * 100}%` }}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted">
                                    Approval: {product.approval_required ? 'Required (-2)' : 'Instant'}
                                </div>
                            </div>
                        </div>

                        {/* Flags and Gates Hit */}
                        {product.score_flags && Object.keys(product.score_flags).length > 0 && (
                            <div className="mt-5 pt-4 border-t border-theme">
                                <div className="text-xs font-bold text-theme-secondary uppercase tracking-wider mb-2">
                                    Active Flags & Hard Gates
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {product.score_flags.false_bof && (
                                        <span className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2.5 py-1 text-xs font-bold flex items-center gap-1.5">
                                            <span>🚫 False BoF (Hard Discard: Score 0)</span>
                                        </span>
                                    )}
                                    {product.score_flags.payout_below_floor && (
                                        <span className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2.5 py-1 text-xs font-semibold">
                                            ⚠️ Payout Below US$20 Floor (Cap 20)
                                        </span>
                                    )}
                                    {product.score_flags.pure_vsl_or_iframe && (
                                        <span className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2.5 py-1 text-xs font-semibold">
                                            ⚠️ Pure VSL / iFrame Funnel (Cap 20)
                                        </span>
                                    )}
                                    {product.score_flags.dead_post_peak && (
                                        <span className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2.5 py-1 text-xs font-semibold">
                                            ⚠️ Dead Post Peak Demand (Cap 20)
                                        </span>
                                    )}
                                    {product.score_flags.high_cancel_rate && (
                                        <span className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2.5 py-1 text-xs font-semibold">
                                            ⚠️ Cancellation Rate ≥ 25% (Cap 20)
                                        </span>
                                    )}
                                    {product.score_flags.restricted_category && (
                                        <span className="rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/30 px-2.5 py-1 text-xs font-semibold">
                                            🔒 Restricted Category (Cap 50)
                                        </span>
                                    )}
                                    {product.score_flags.compound_keyword_needed && (
                                        <span className="rounded-lg bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 border border-indigo-500/30 px-2.5 py-1 text-xs font-semibold">
                                            🔑 Compound Keyword Needed ({product.bof_qualifier})
                                        </span>
                                    )}
                                    {product.score_flags.bb_risk && (
                                        <span className="rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/30 px-2.5 py-1 text-xs font-semibold">
                                            ⚡ Brand Bidding Prohibited / High Risk
                                        </span>
                                    )}
                                    {product.score_flags.vendor_bids_own_brand && (
                                        <span className="rounded-lg bg-purple-500/20 text-purple-700 dark:text-purple-400 border border-purple-500/30 px-2.5 py-1 text-xs font-semibold">
                                            🏢 Vendor Bids on Own Brand
                                        </span>
                                    )}
                                    {product.score_flags.audit_false_bof && (
                                        <span className="rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/30 px-2.5 py-1 text-xs font-semibold">
                                            🔍 Audit False BoF (Low CPC)
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Raw Inputs Overview */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
                        <h3 className="font-bold text-base text-theme-primary mb-4">Raw Product Inputs</h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                            <div className="border border-theme rounded-xl p-3 bg-theme-subtle/20">
                                <div className="text-theme-muted font-medium">Original Payout</div>
                                <div className="text-base font-bold text-theme-primary mt-1">{product.payout_formatted}</div>
                                <div className="text-[11px] text-theme-muted">Model: {product.payout_model || 'Standard'}</div>
                            </div>

                            <div className="border border-theme rounded-xl p-3 bg-theme-subtle/20">
                                <div className="text-theme-muted font-medium">CAD Converted Cache</div>
                                <div className="text-base font-bold text-theme-primary mt-1">CA${Number(product.payout_cad).toFixed(2)}</div>
                                <div className="text-[11px] text-theme-muted">USD: ${Number(product.payout_usd).toFixed(2)}</div>
                            </div>

                            <div className="border border-theme rounded-xl p-3 bg-theme-subtle/20">
                                <div className="text-theme-muted font-medium">ClickBank Gravity</div>
                                <div className="text-base font-bold text-theme-primary mt-1">{product.gravity ?? 'N/A'}</div>
                                <div className="text-[11px] text-theme-muted">Cancel Rate: {product.cancel_rate ? `${product.cancel_rate}%` : 'N/A'}</div>
                            </div>

                            <div className="border border-theme rounded-xl p-3 bg-theme-subtle/20">
                                <div className="text-theme-muted font-medium">Advertiser Competition</div>
                                <div className="text-base font-bold text-theme-primary mt-1">{product.advertiser_count} Advertisers</div>
                                <div className="text-[11px] text-theme-muted">Vendor bids: {product.vendor_bids_brand ? 'Yes' : 'No'}</div>
                            </div>
                        </div>

                        {product.notes && (
                            <div className="mt-4 p-3 rounded-xl border border-theme bg-theme-subtle/30 text-xs">
                                <div className="font-semibold text-theme-secondary mb-1">Internal Notes:</div>
                                <p className="text-theme-muted whitespace-pre-wrap">{product.notes}</p>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* TAB 2: NEGATIVE KEYWORD MANAGER */}
            {activeTab === 'negatives' && (
                <div className="space-y-4">
                    {/* Actions and Export Bar */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-4 shadow-xs flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-xs font-semibold text-theme-muted">Filter by source:</span>
                            <select
                                value={selectedSourceFilter}
                                onChange={(e) => setSelectedSourceFilter(e.target.value)}
                                className="rounded-lg border border-theme bg-theme-input px-2.5 py-1 text-xs text-theme-primary focus:outline-none focus:border-theme-accent cursor-pointer"
                            >
                                <option value="all">All Sources ({negative_keywords.length})</option>
                                {options.sources?.map((src) => (
                                    <option key={src.value} value={src.value}>{src.label}</option>
                                ))}
                            </select>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setIsAddingNegative(!isAddingNegative)}
                                className={`${button} text-xs !py-1.5 !px-3`}
                            >
                                {isAddingNegative ? 'Cancel' : '+ Add Negative'}
                            </button>

                            <button
                                type="button"
                                onClick={handleCopyAllNegatives}
                                className="px-3 py-1.5 rounded-lg border border-theme bg-theme-surface hover:bg-theme-subtle text-xs font-semibold text-theme-primary transition-colors flex items-center gap-1.5 cursor-pointer shadow-2xs"
                                title="Copy all enabled negatives in Google Ads format to clipboard"
                            >
                                <svg className="w-3.5 h-3.5 text-theme-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                                </svg>
                                <span>Copy for Google Ads</span>
                            </button>

                            <a
                                href={`/watching-products/${product.id}/negatives/export`}
                                download
                                className="px-3 py-1.5 rounded-lg border border-theme bg-theme-surface hover:bg-theme-subtle text-xs font-semibold text-theme-primary transition-colors flex items-center gap-1.5 cursor-pointer shadow-2xs"
                            >
                                <svg className="w-3.5 h-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>
                                <span>Export CSV</span>
                            </a>
                        </div>
                    </div>

                    {/* Add Keyword Form Drawer */}
                    {isAddingNegative && (
                        <div className="rounded-xl border border-theme-accent/40 bg-theme-surface p-4 shadow-sm animate-fadeIn">
                            <form onSubmit={handleAddNegativeSubmit} className="flex flex-wrap items-end gap-3 text-xs">
                                <div className="flex-1 min-w-[200px]">
                                    <label className="block font-medium text-theme-secondary mb-1">Negative Term</label>
                                    <input
                                        type="text"
                                        className={control}
                                        placeholder="e.g. login, review, phone number..."
                                        value={negativeForm.data.term}
                                        onChange={(e) => negativeForm.setData('term', e.target.value)}
                                        required
                                    />
                                </div>

                                <div className="w-36">
                                    <label className="block font-medium text-theme-secondary mb-1">Match Type</label>
                                    <select
                                        className={control}
                                        value={negativeForm.data.match_type}
                                        onChange={(e) => negativeForm.setData('match_type', e.target.value)}
                                    >
                                        <option value="broad">Broad</option>
                                        <option value="phrase">Phrase (" ")</option>
                                        <option value="exact">Exact ([ ])</option>
                                    </select>
                                </div>

                                <button
                                    type="submit"
                                    disabled={negativeForm.processing}
                                    className={`${button} !py-2`}
                                >
                                    {negativeForm.processing ? 'Adding...' : 'Add Term'}
                                </button>
                            </form>
                        </div>
                    )}

                    {/* Keywords Table */}
                    <div className="overflow-hidden rounded-2xl border border-theme bg-theme-surface shadow-xs">
                        <table className="min-w-full divide-y divide-theme text-left text-xs">
                            <thead className="bg-theme-subtle/50 text-theme-muted font-semibold uppercase tracking-wider">
                                <tr>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Term</th>
                                    <th className="px-4 py-3">Match Type</th>
                                    <th className="px-4 py-3">Google Ads Syntax</th>
                                    <th className="px-4 py-3">Source</th>
                                    <th className="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme">
                                {filteredNegatives.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="p-8 text-center text-theme-muted">
                                            No negative keywords found matching this filter.
                                        </td>
                                    </tr>
                                ) : (
                                    filteredNegatives.map((kw) => (
                                        <tr key={kw.id} className="hover:bg-theme-subtle/30 transition-colors">
                                            {/* Toggle Enabled */}
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <button
                                                    type="button"
                                                    onClick={() => handleToggleNegative(kw)}
                                                    className={`w-8 h-4 rounded-full transition-colors relative cursor-pointer ${
                                                        kw.enabled ? 'bg-emerald-500' : 'bg-stone-400 dark:bg-stone-700'
                                                    }`}
                                                    title={kw.enabled ? 'Enabled' : 'Disabled'}
                                                >
                                                    <span
                                                        className={`w-3 h-3 rounded-full bg-white absolute top-0.5 transition-transform ${
                                                            kw.enabled ? 'left-4.5' : 'left-0.5'
                                                        }`}
                                                    />
                                                </button>
                                            </td>

                                            {/* Term */}
                                            <td className="px-4 py-2.5 font-medium text-theme-primary">
                                                <span className={!kw.enabled ? 'line-through opacity-50' : ''}>
                                                    {kw.term}
                                                </span>
                                                {kw.manually_overridden && (
                                                    <span className="ml-2 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-1 py-0.2 text-[9px]">
                                                        Manual
                                                    </span>
                                                )}
                                            </td>

                                            {/* Match Type */}
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <select
                                                    value={kw.match_type}
                                                    onChange={(e) => handleChangeMatchType(kw, e.target.value)}
                                                    className="rounded border border-theme bg-theme-input px-2 py-0.5 text-xs text-theme-secondary focus:outline-none cursor-pointer"
                                                >
                                                    <option value="broad">Broad</option>
                                                    <option value="phrase">Phrase</option>
                                                    <option value="exact">Exact</option>
                                                </select>
                                            </td>

                                            {/* Google Ads Syntax */}
                                            <td className="px-4 py-2.5 whitespace-nowrap font-mono text-theme-accent">
                                                {kw.formatted_term}
                                            </td>

                                            {/* Source */}
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <span className="rounded bg-theme-subtle border border-theme px-2 py-0.5 text-[10px] text-theme-secondary font-medium">
                                                    {kw.source_label}
                                                </span>
                                            </td>

                                            {/* Delete */}
                                            <td className="px-4 py-2.5 whitespace-nowrap text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => handleDeleteNegative(kw)}
                                                    className="text-theme-muted hover:text-rose-600 p-1 rounded transition-colors cursor-pointer"
                                                    title="Delete keyword"
                                                >
                                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* TAB 3: CAMPAIGN GUIDANCE */}
            {activeTab === 'guidance' && (
                <div className="grid gap-6 md:grid-cols-2">
                    {/* Target CPA & Daily Budget */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs space-y-4">
                        <h3 className="font-bold text-base text-theme-primary">CPA & Budget Parameters</h3>

                        <div className="rounded-xl border border-theme bg-theme-subtle/30 p-4 space-y-1">
                            <div className="text-xs text-theme-muted font-medium">Target CPA (50% - 60% of commission)</div>
                            <div className="text-xl font-bold text-theme-primary">
                                {campaign_guidance?.target_cpa?.recommended_range_usd || 'N/A'}
                            </div>
                            <div className="text-xs text-theme-muted">
                                Allowed Range (40-70%): {campaign_guidance?.target_cpa?.allowed_range_usd}
                            </div>
                        </div>

                        <div className="rounded-xl border border-theme bg-theme-subtle/30 p-4 space-y-1">
                            <div className="text-xs text-theme-muted font-medium">Daily Budget (100% - 120% of Target CPA)</div>
                            <div className="text-xl font-bold text-theme-accent">
                                {campaign_guidance?.daily_budget?.recommended_range_usd || 'N/A'}
                            </div>
                            <div className="text-xs text-theme-muted">
                                Scales safely with 1 sale per day testing volume.
                            </div>
                        </div>
                    </div>

                    {/* Stop-Loss Rules & Status */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs space-y-4">
                        <h3 className="font-bold text-base text-theme-primary">Stop-Loss Rules & Status</h3>

                        <div className="rounded-xl border border-theme p-4 space-y-2 bg-theme-subtle/20">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold text-theme-secondary">Current Stop-Loss Status</span>
                                <span className={`rounded-md px-2 py-0.5 text-xs font-bold uppercase ${
                                    campaign_guidance?.stop_loss?.status === 'stop_loss_breached'
                                        ? 'bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30'
                                        : 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30'
                                }`}>
                                    {campaign_guidance?.stop_loss?.status?.replace(/_/g, ' ') || 'ACTIVE'}
                                </span>
                            </div>

                            <p className="text-xs text-theme-secondary font-medium">
                                {campaign_guidance?.stop_loss?.message}
                            </p>

                            <div className="pt-2 border-t border-theme text-xs text-theme-muted space-y-1">
                                <div>• <strong>0 Sales, 0 Checkouts:</strong> Cut at 50% commission (${(Number(product.payout_usd) * 0.5).toFixed(2)})</div>
                                <div>• <strong>0 Sales, with Checkouts:</strong> Cut at 100% commission (${Number(product.payout_usd).toFixed(2)})</div>
                                <div>• <strong>1 Sale:</strong> Stop at 70% of commission earned</div>
                                <div>• <strong>Scaled (2+):</strong> Optimize at 60% ROI; Pause if ROI falls under 25-30%</div>
                            </div>
                        </div>

                        {/* Current Campaign Performance */}
                        <div className="grid grid-cols-3 gap-2 text-center text-xs">
                            <div className="rounded-lg border border-theme p-2 bg-theme-subtle/40">
                                <div className="text-[10px] text-theme-muted uppercase">Spend</div>
                                <div className="font-bold text-theme-primary mt-0.5">${Number(product.spend_amount).toFixed(2)}</div>
                            </div>
                            <div className="rounded-lg border border-theme p-2 bg-theme-subtle/40">
                                <div className="text-[10px] text-theme-muted uppercase">Sales</div>
                                <div className="font-bold text-theme-primary mt-0.5">{product.sales}</div>
                            </div>
                            <div className="rounded-lg border border-theme p-2 bg-theme-subtle/40">
                                <div className="text-[10px] text-theme-muted uppercase">Checkouts</div>
                                <div className="font-bold text-theme-primary mt-0.5">{product.checkouts}</div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* TAB 4: SCORE HISTORY CHART */}
            {activeTab === 'history' && (
                <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
                    <h3 className="font-bold text-base text-theme-primary mb-4 flex items-center justify-between">
                        <span>Score Progression Over Time</span>
                        <span className="text-xs text-theme-muted font-normal">Records snapshot each weekly refresh or rescore</span>
                    </h3>

                    {score_history.length === 0 ? (
                        <div className="p-8 text-center text-theme-muted text-xs">
                            No history records logged yet. New entries are created on weekly refresh.
                        </div>
                    ) : (
                        <div className="h-72 w-full pt-4">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={score_history}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="rgba(150, 150, 150, 0.15)" />
                                    <XAxis dataKey="date" stroke="#888" fontSize={11} />
                                    <YAxis domain={[0, 100]} stroke="#888" fontSize={11} />
                                    <Tooltip
                                        contentStyle={{
                                            backgroundColor: '#1c1917',
                                            borderColor: '#44403c',
                                            borderRadius: '8px',
                                            color: '#fff',
                                            fontSize: '12px',
                                        }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="score"
                                        stroke="#10b981"
                                        strokeWidth={2.5}
                                        dot={{ r: 4, fill: '#10b981' }}
                                        activeDot={{ r: 6 }}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>
            )}
        </Layout>
    );
}
