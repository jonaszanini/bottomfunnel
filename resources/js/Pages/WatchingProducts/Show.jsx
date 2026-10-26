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
import { getPlatformBadgeClass, inferPlatformFromUrl } from '../../utils/platform';
import CampaignCreationModal from '../../Components/CampaignCreationModal';
import OfferConstraintsSection from './Components/OfferConstraintsSection';
import OfferConstraintsEditor from './Components/OfferConstraintsEditor';
import SearchVolumeSection from './Components/SearchVolumeSection';

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

export function getComponentScoreColor(score, max = 20) {
    if (score === null || score === undefined) {
        return {
            score: 0,
            max,
            pct: 0,
            hue: 0,
            band: 'red/orange',
            barClass: 'bg-rose-500',
            textClass: 'text-rose-500 dark:text-rose-400',
            barStyle: {
                width: '0%',
                backgroundColor: 'hsl(0, 85%, 45%)',
            },
            textStyle: {
                color: 'hsl(0, 85%, 42%)',
            },
        };
    }

    const numScore = Math.max(0, Number(score));
    const numMax = Math.max(1, Number(max));
    const pct = Math.max(0, Math.min(100, Math.round((numScore / numMax) * 100)));
    const hue = (pct / 100) * 120;

    let band = 'red/orange';
    let barClass = 'bg-rose-500';
    let textClass = 'text-rose-500 dark:text-rose-400';

    if (pct > 80) {
        band = 'green';
        barClass = 'bg-emerald-500';
        textClass = 'text-emerald-500 dark:text-emerald-400';
    } else if (pct > 60) {
        band = 'yellow-green';
        barClass = 'bg-lime-500';
        textClass = 'text-lime-500 dark:text-lime-400';
    } else if (pct > 30) {
        band = 'amber';
        barClass = 'bg-amber-500';
        textClass = 'text-amber-500 dark:text-amber-400';
    } else {
        band = 'red/orange';
        barClass = pct <= 15 ? 'bg-rose-500' : 'bg-orange-500';
        textClass = pct <= 15 ? 'text-rose-500 dark:text-rose-400' : 'text-orange-500 dark:text-orange-400';
    }

    return {
        score: numScore,
        max: numMax,
        pct,
        hue,
        band,
        barClass,
        textClass,
        barStyle: {
            width: `${pct}%`,
            backgroundColor: `hsl(${hue}, 85%, 45%)`,
        },
        textStyle: {
            color: `hsl(${hue}, 85%, 42%)`,
        },
    };
}

export default function Show({
    product,
    tracks = [],
    score_history = [],
    negative_keywords = [],
    campaign_guidance = {},
    google_ads_accounts = [],
    default_account_id = null,
    serpapi_usage = {},
    options = {},
}) {
    const [toastMessage, setToastMessage] = useState('');
    const [toastType, setToastType] = useState('success');

    const resolveTab = (tab) => {
        if (tab === 'campaign') return 'guidance';
        if (['overview', 'negatives', 'guidance', 'history'].includes(tab)) return tab;
        return 'overview';
    };

    const [activeTab, setActiveTabState] = useState(() => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            const tabParam = params.get('tab');
            if (tabParam) return resolveTab(tabParam);
        }
        return 'overview';
    });

    const setActiveTab = (tab) => {
        const resolved = resolveTab(tab);
        setActiveTabState(resolved);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', resolved);
            window.history.replaceState({}, '', url.toString());
        }
    };

    React.useEffect(() => {
        const handlePopState = () => {
            if (typeof window !== 'undefined') {
                const params = new URLSearchParams(window.location.search);
                const tabParam = params.get('tab');
                if (tabParam) setActiveTabState(resolveTab(tabParam));
            }
        };
        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, []);

    const [selectedSourceFilter, setSelectedSourceFilter] = useState('all');
    const [isAddingNegative, setIsAddingNegative] = useState(false);
    const [isGeneratingCampaign, setIsGeneratingCampaign] = useState(false);
    const [isEditingProduct, setIsEditingProduct] = useState(false);
    const [showPlatformInfo, setShowPlatformInfo] = useState(false);

    // Campaign creation modal state (direct Google Ads API modal)
    const [showCampaignModal, setShowCampaignModal] = useState(false);

    const generatedNegatives = useMemo(() => {
        return (negative_keywords || [])
            .filter((kw) => kw.enabled !== false)
            .map((kw) => kw.term);
    }, [negative_keywords]);

    const createCampaignUrl = useMemo(() => {
        const todayStr = new Date().toISOString().slice(0, 10);
        const geoStr = (Array.isArray(product.allowed_geos) && product.allowed_geos.length > 0)
            ? product.allowed_geos.join('-')
            : (Array.isArray(product.geos) && product.geos.length > 0 ? product.geos.join('-') : 'US');
        const strategyStr = product.strategy || 'bof_direct';
        const campaignName = `${product.name} - ${geoStr} - ${strategyStr} - ${todayStr}`;
        const enabledNegatives = (negative_keywords || [])
            .filter((kw) => kw.enabled !== false)
            .map((kw) => kw.term)
            .join('\n');
        const geosParam = (Array.isArray(product.allowed_geos) && product.allowed_geos.length > 0)
            ? product.allowed_geos.join(',')
            : (Array.isArray(product.geos) && product.geos.length > 0 ? product.geos.join(',') : '');

        const params = new URLSearchParams({
            name: campaignName,
            watched_product_id: String(product.id),
            strategy: strategyStr,
            geos: geosParam,
            negatives: enabledNegatives,
        });

        return `/tracks/create?${params.toString()}`;
    }, [product, negative_keywords]);

    const handleOpenCreateCampaign = () => {
        setShowCampaignModal(true);
    };

    // Negative form
    const negativeForm = useForm({
        term: '',
        match_type: 'broad',
    });

    // Product edit form
    const editForm = useForm({
        name: product.name || '',
        platform: product.platform || (Array.isArray(product.platforms) && product.platforms[0]) || 'Unknown',
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
        trends_trend: product.trends_trend || 'unknown',
        trends_7d: product.trends_7d !== null ? product.trends_7d : '',
        trends_30d: product.trends_30d !== null ? product.trends_30d : '',
        trends_90d: product.trends_90d !== null ? product.trends_90d : '',
        gravity: product.gravity !== null ? product.gravity : '',
        cancel_rate: product.cancel_rate !== null ? product.cancel_rate : '',
        listing_url: product.listing_url || '',
        sales_page_url: product.sales_page_url || '',
        affiliate_link: product.affiliate_link || '',
        notes: product.notes || '',
        approval_required: Boolean(product.approval_required),
        approval_status: product.approval_status || (product.approval_required ? 'yes' : 'unknown'),
        network_approval_status: product.network_approval_status || (product.network_approval_required ? 'yes' : 'unknown'),
        strategy_phase2: product.strategy_phase2 || '',
        strategy_switch_trigger: product.strategy_switch_trigger || '',
        recheck_at: product.recheck_at || '',
        promotion_criteria: product.promotion_criteria || '',
        allowed_geos: Array.isArray(product.allowed_geos) ? product.allowed_geos : [],
        search_volume_keyword: product.search_volume_keyword || '',
        compliance_risk: product.compliance_risk || 'not_assessed',
        compliance_notes: product.compliance_notes || '',
        network_approval_required: Boolean(product.network_approval_required),
        payouts_by_platform: Array.isArray(product.payouts_by_platform) ? product.payouts_by_platform : [],
        spend_amount: product.spend_amount || 0,
        sales: product.sales || 0,
        checkouts: product.checkouts || 0,
        commission_earned: product.commission_earned || 0,
    });

    const handleUrlFieldChange = (field, value) => {
        editForm.setData((prev) => {
            const next = { ...prev, [field]: value };
            if (!prev.platform || prev.platform === 'Unknown' || prev.platform === '') {
                const suggested = inferPlatformFromUrl(value);
                if (suggested) {
                    next.platform = suggested;
                }
            }
            return next;
        });
    };

    const handleUrlPaste = (field, e) => {
        const pastedText = e.clipboardData?.getData('text') || '';
        if (pastedText && (!editForm.data.platform || editForm.data.platform === 'Unknown' || editForm.data.platform === '')) {
            const suggested = inferPlatformFromUrl(pastedText);
            if (suggested) {
                editForm.setData((prev) => ({
                    ...prev,
                    [field]: pastedText,
                    platform: suggested,
                }));
            }
        }
    };

    const showToast = (msg, type = 'success') => {
        setToastType(type);
        setToastMessage(msg);
        setTimeout(() => setToastMessage(''), type === 'error' ? 6000 : 3000);
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

    const handleGenerateCampaignNegatives = () => {
        if (quotaExceeded) {
            showToast('SerpApi monthly quota exceeded (250/250). Cannot perform new SERP analysis.');
            return;
        }

        setIsGeneratingCampaign(true);
        router.post(
            `/watching-products/${product.id}/negatives/generate-campaign`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    showToast('Campaign negatives generated successfully!');
                    setIsGeneratingCampaign(false);
                },
                onError: () => {
                    showToast('Failed to generate campaign negatives.');
                    setIsGeneratingCampaign(false);
                },
                onFinish: () => {
                    setIsGeneratingCampaign(false);
                },
            }
        );
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

    const hasCampaignNegatives = useMemo(() => {
        return negative_keywords.some((kw) =>
            ['competitor', 'homonym', 'serp_intruder'].includes(kw.source)
        );
    }, [negative_keywords]);

    const campaignNegativesCount = useMemo(() => {
        return negative_keywords.filter((kw) =>
            ['competitor', 'homonym', 'serp_intruder'].includes(kw.source)
        ).length;
    }, [negative_keywords]);

    const quotaExceeded = useMemo(() => {
        const used = serpapi_usage?.used_this_month ?? 0;
        const total = serpapi_usage?.total_limit ?? 250;
        return used >= total;
    }, [serpapi_usage]);

    const scoreHsl = getScoreHsl(product.score);
    const bofComp = getComponentScoreColor(product.score_breakdown?.bof_name, 20);
    const demandComp = getComponentScoreColor(
        product.score_flags?.dead_post_peak ? 0 : product.score_breakdown?.demand,
        20
    );
    const payoutComp = getComponentScoreColor(product.score_breakdown?.payout, 15);
    const marginComp = getComponentScoreColor(product.score_breakdown?.margin_headroom, 10);
    const pageTypeComp = getComponentScoreColor(product.score_breakdown?.page_type, 10);
    const brandComp = getComponentScoreColor(product.score_breakdown?.brand_bidding, 10);
    const geoComp = getComponentScoreColor(product.score_breakdown?.geo, 5);
    const compComp = getComponentScoreColor(product.score_breakdown?.competition, 5);
    const freshComp = getComponentScoreColor(product.score_breakdown?.freshness, 5);

    return (
        <Layout>
            <Head title={`${product.name} – Watching Products`} />

            {/* Toast Notification */}
            {toastMessage && (
                <div role={toastType === 'error' ? 'alert' : 'status'} className={`fixed bottom-5 right-5 z-50 flex items-center gap-2 rounded-xl border bg-theme-surface px-4 py-3 text-sm font-medium shadow-xl max-w-[calc(100vw-2.5rem)] ${toastType === 'error' ? 'border-rose-500/40 text-rose-600 dark:text-rose-400' : 'border-emerald-500/40 text-emerald-600 dark:text-emerald-400 animate-bounce'}`}>
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
                    <Link
                        href={createCampaignUrl}
                        className="px-3 py-1.5 rounded-lg border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 text-xs font-semibold text-sky-600 dark:text-sky-400 transition-colors flex items-center gap-1.5 cursor-pointer shadow-2xs"
                        title="Create Campaign in Track form pre-filled with name, targeting, and negatives"
                    >
                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        <span>Create Campaign</span>
                    </Link>

                    <button
                        type="button"
                        onClick={handleOpenCreateCampaign}
                        className="px-2.5 py-1.5 rounded-lg border border-theme bg-theme-surface hover:bg-theme-subtle text-xs font-medium text-theme-secondary hover:text-theme-primary transition-colors flex items-center gap-1 cursor-pointer shadow-2xs"
                        title="Direct Google Ads API Campaign modal"
                    >
                        <span>API Modal</span>
                    </button>

                    <Link
                        href={`/tracks/create?watched_product_id=${product.id}`}
                        className="px-3 py-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 text-xs font-semibold text-emerald-600 dark:text-emerald-400 transition-colors flex items-center gap-1.5 cursor-pointer shadow-2xs"
                        title="Create a new Track for this product"
                    >
                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        <span>Create Track</span>
                    </Link>

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
                            <span className="rounded-md border border-theme bg-theme-subtle px-2 py-0.5 text-xs font-semibold text-theme-secondary uppercase">
                                {product.status_label || product.status}
                            </span>
                            {(() => {
                                const primary = product.platform || (Array.isArray(product.platforms) && product.platforms[0]) || 'Unknown';
                                const otherPlatforms = (Array.isArray(product.platforms) ? product.platforms : []).filter((p) => p !== primary);
                                return (
                                    <div className="inline-flex items-center gap-1">
                                        <span className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${getPlatformBadgeClass(primary)}`}>
                                            {primary}
                                        </span>
                                        {otherPlatforms.length > 0 && (
                                            <span
                                                className="rounded-md border border-theme bg-theme-subtle px-1.5 py-0.5 text-xs font-semibold text-theme-muted cursor-help"
                                                title={`Also on: ${otherPlatforms.join(', ')}`}
                                            >
                                                +{otherPlatforms.length}
                                            </span>
                                        )}
                                    </div>
                                );
                            })()}
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
                            {((product.tracks && product.tracks.length > 0) || (tracks && tracks.length > 0)) && (
                                <span className="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                    Testing in Track ({(product.tracks || tracks).length})
                                </span>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-3 text-xs text-theme-muted">
                            <span>Status: <strong className="text-theme-primary uppercase">{product.status_label}</strong></span>
                            <span>•</span>
                            <span>Platform: <strong className="text-theme-primary">{product.platform || 'Unknown'}</strong></span>
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

                            <Field
                                label={
                                    <div className="flex items-center gap-1.5">
                                        <span>Platform</span>
                                        <button
                                            type="button"
                                            onClick={() => setShowPlatformInfo((prev) => !prev)}
                                            className="text-theme-muted hover:text-theme-accent p-0.5 rounded cursor-pointer transition-colors"
                                            title="Click for platform guidance"
                                            aria-label="Platform guidance"
                                        >
                                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                <circle cx="12" cy="12" r="10" />
                                                <line x1="12" y1="16" x2="12" y2="12" />
                                                <line x1="12" y1="8" x2="12.01" y2="8" />
                                            </svg>
                                        </button>
                                        {showPlatformInfo && (
                                            <div className="relative">
                                                <div className="absolute left-0 bottom-full mb-1 z-30 w-64 rounded-lg border border-theme bg-theme-surface p-2.5 text-[11px] font-normal normal-case text-theme-secondary shadow-lg">
                                                    <div className="flex justify-between items-start mb-1 font-semibold text-theme-primary">
                                                        <span>Platform / Marketplace</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowPlatformInfo(false)}
                                                            className="text-theme-muted hover:text-theme-primary text-xs"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                    Primary affiliate network or marketplace where this product was found. Auto-suggested when pasting sales page or affiliate links.
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                }
                                error={editForm.errors.platform}
                            >
                                <select
                                    className={control}
                                    value={editForm.data.platform || 'Unknown'}
                                    onChange={(e) => editForm.setData('platform', e.target.value)}
                                >
                                    {(options?.platforms || []).map((p) => (
                                        <option key={p} value={p}>{p}</option>
                                    ))}
                                </select>
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
                                    onChange={(e) => handleUrlFieldChange('sales_page_url', e.target.value)}
                                    onPaste={(e) => handleUrlPaste('sales_page_url', e)}
                                />
                            </Field>

                            <Field label="Affiliate Link" error={editForm.errors.affiliate_link}>
                                <input
                                    type="url"
                                    className={control}
                                    value={editForm.data.affiliate_link}
                                    onChange={(e) => handleUrlFieldChange('affiliate_link', e.target.value)}
                                    onPaste={(e) => handleUrlPaste('affiliate_link', e)}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Demand Trend (Google Trends)" error={editForm.errors.trends_trend}>
                                <select
                                    className={control}
                                    value={editForm.data.trends_trend}
                                    onChange={(e) => editForm.setData('trends_trend', e.target.value)}
                                >
                                    {options.trends_trends?.map((t) => (
                                        <option key={t.value} value={t.value}>{t.label}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Trends 7d" error={editForm.errors.trends_7d}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    className={control}
                                    value={editForm.data.trends_7d}
                                    onChange={(e) => editForm.setData('trends_7d', e.target.value)}
                                    placeholder="0 - 100"
                                />
                            </Field>

                            <Field label="Trends 30d" error={editForm.errors.trends_30d}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    className={control}
                                    value={editForm.data.trends_30d}
                                    onChange={(e) => editForm.setData('trends_30d', e.target.value)}
                                    placeholder="0 - 100"
                                />
                            </Field>

                            <Field label="Trends 90d" error={editForm.errors.trends_90d}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    className={control}
                                    value={editForm.data.trends_90d}
                                    onChange={(e) => editForm.setData('trends_90d', e.target.value)}
                                    placeholder="0 - 100"
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

                        {/* Offer Constraints & Go / No-Go Fields */}
                        <OfferConstraintsEditor
                            data={editForm.data}
                            setData={editForm.setData}
                            errors={editForm.errors}
                            options={options}
                        />

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
                    {!hasCampaignNegatives ? (
                        <span className="rounded-full bg-amber-500/20 text-amber-500 text-[10px] px-1.5 py-0.5 font-medium" title="Base seeds only. Campaign negatives not generated yet.">
                            Base
                        </span>
                    ) : (
                        <span className="rounded-full bg-emerald-500/20 text-emerald-400 text-[10px] px-1.5 py-0.5 font-medium" title="Full campaign negatives active">
                            Campaign
                        </span>
                    )}
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
                    {/* Offer Constraints & Go / No-Go Decision Card */}
                    <OfferConstraintsSection product={product} />

                    {/* Google Keyword Planner Search Volume Section */}
                    <SearchVolumeSection product={product} onToast={showToast} />

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
                                    <span className={`font-mono font-bold ${bofComp.textClass}`} style={bofComp.textStyle}>
                                        {bofComp.score} / {bofComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${bofComp.barClass}`}
                                        style={bofComp.barStyle}
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
                                    <span className={`font-mono font-bold ${demandComp.textClass}`} style={demandComp.textStyle}>
                                        {demandComp.score} / {demandComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${demandComp.barClass}`}
                                        style={demandComp.barStyle}
                                    />
                                </div>
                                <div className="text-[11px] text-theme-muted space-y-0.5">
                                    <div>
                                        7d: <span className="font-semibold text-theme-primary">{product.trends_7d ?? '—'}</span> • 30d: <span className="font-semibold text-theme-primary">{product.trends_30d ?? '—'}</span> • 90d: <span className="font-semibold text-theme-primary">{product.trends_90d ?? '—'}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span>Trend:</span>
                                        <span className={`font-semibold ${product.score_flags?.dead_post_peak ? 'text-rose-500 font-bold' : 'text-theme-primary'}`}>
                                            {(() => {
                                                const hasTrendsVolume = (Number(product.trends_7d || 0) > 0)
                                                    || (Number(product.trends_30d || 0) > 0)
                                                    || (Number(product.trends_90d || 0) > 0);
                                                if (!hasTrendsVolume && (product.trends_trend === 'stable' || !product.trends_trend || product.trends_trend === 'unknown')) {
                                                    return 'Unknown';
                                                }
                                                return product.trends_trend_label || (product.trends_trend ? String(product.trends_trend) : 'Unknown');
                                            })()}
                                        </span>
                                        {product.flowspy_traffic_trend === 'rising' && (
                                            <span className="text-[10px] text-emerald-500 font-medium">
                                                • FlowSpy Rising Boost
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-[10px] text-theme-muted">
                                        Scoring: Rising (20) • Stable (16) • Falling (10) • Unknown (8)
                                    </div>
                                    {product.score_flags?.dead_post_peak && (
                                        <div className="text-[10px] text-rose-500 font-semibold">
                                            ⚠️ Hard Gate: Cap 20 (post-peak collapse)
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Payout */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/30 p-3.5 space-y-1.5">
                                <div className="flex justify-between text-xs font-semibold">
                                    <span className="text-theme-secondary">Payout Model</span>
                                    <span className={`font-mono font-bold ${payoutComp.textClass}`} style={payoutComp.textStyle}>
                                        {payoutComp.score} / {payoutComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${payoutComp.barClass}`}
                                        style={payoutComp.barStyle}
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
                                    <span className={`font-mono font-bold ${marginComp.textClass}`} style={marginComp.textStyle}>
                                        {marginComp.score} / {marginComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${marginComp.barClass}`}
                                        style={marginComp.barStyle}
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
                                    <span className={`font-mono font-bold ${pageTypeComp.textClass}`} style={pageTypeComp.textStyle}>
                                        {pageTypeComp.score} / {pageTypeComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${pageTypeComp.barClass}`}
                                        style={pageTypeComp.barStyle}
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
                                    <span className={`font-mono font-bold ${brandComp.textClass}`} style={brandComp.textStyle}>
                                        {brandComp.score} / {brandComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${brandComp.barClass}`}
                                        style={brandComp.barStyle}
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
                                    <span className={`font-mono font-bold ${geoComp.textClass}`} style={geoComp.textStyle}>
                                        {geoComp.score} / {geoComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${geoComp.barClass}`}
                                        style={geoComp.barStyle}
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
                                    <span className={`font-mono font-bold ${compComp.textClass}`} style={compComp.textStyle}>
                                        {compComp.score} / {compComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${compComp.barClass}`}
                                        style={compComp.barStyle}
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
                                    <span className={`font-mono font-bold ${freshComp.textClass}`} style={freshComp.textStyle}>
                                        {freshComp.score} / {freshComp.max}
                                    </span>
                                </div>
                                <div className="w-full bg-theme-input rounded-full h-2 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all ${freshComp.barClass}`}
                                        style={freshComp.barStyle}
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
                                        <div className="rounded-lg bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 p-2.5 text-xs w-full">
                                            <div className="font-bold flex items-center gap-1.5">
                                                <span>⚠️ Dead Post Peak Demand (Cap 20)</span>
                                            </div>
                                            <div className="text-[11px] text-rose-700 dark:text-rose-300 mt-0.5">
                                                {product.score_flags.dead_post_peak_audit?.reason || `Demand collapsed post-peak (7d: ${product.trends_7d ?? 0}, 30d: ${product.trends_30d ?? 0}, 90d: ${product.trends_90d ?? 0}). Hard gate caps overall score at 20.`}
                                            </div>
                                        </div>
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

                    {/* Linked Tracks Section */}
                    <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                            <div>
                                <h3 className="font-bold text-base text-theme-primary flex items-center gap-2">
                                    <span>Linked Tracks</span>
                                    <span className="rounded-full bg-theme-subtle px-2 py-0.5 text-xs font-mono text-theme-secondary">
                                        {(product.tracks || tracks || []).length}
                                    </span>
                                </h3>
                                <p className="text-xs text-theme-muted mt-0.5">
                                    Active campaigns, landing pages, and tracking setups currently testing this product.
                                </p>
                            </div>
                            <div className="flex items-center gap-2 self-start sm:self-auto">
                                <button
                                    type="button"
                                    onClick={handleOpenCreateCampaign}
                                    className="px-3 py-1.5 rounded-lg border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 text-xs font-semibold text-sky-600 dark:text-sky-400 transition-colors flex items-center gap-1.5 cursor-pointer"
                                    title="Create Google Ads campaign for this product"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                    <span>Create Campaign</span>
                                </button>
                                <Link
                                    href={`/tracks/create?watched_product_id=${product.id}`}
                                    className="px-3 py-1.5 rounded-lg border border-theme bg-theme-subtle hover:bg-theme-subtle/80 text-xs font-semibold text-theme-primary transition-colors flex items-center gap-1.5 cursor-pointer"
                                >
                                    <svg className="w-3.5 h-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                        <line x1="12" y1="5" x2="12" y2="19" />
                                        <line x1="5" y1="12" x2="19" y2="12" />
                                    </svg>
                                    <span>Create Track</span>
                                </Link>
                            </div>
                        </div>

                        {(!product.tracks || product.tracks.length === 0) && (!tracks || tracks.length === 0) ? (
                            <div className="rounded-xl border border-dashed border-theme p-6 text-center">
                                <p className="text-xs text-theme-muted">Not linked to any Track yet.</p>
                                <p className="text-[11px] text-theme-muted mt-1">
                                    Launch a live test by connecting this watched product to an offer track with postback and Google Ads attribution.
                                </p>
                                <div className="mt-3 flex items-center justify-center gap-2">
                                    <button
                                        type="button"
                                        onClick={handleOpenCreateCampaign}
                                        className="px-3 py-1.5 rounded-lg border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 text-xs font-semibold text-sky-600 dark:text-sky-400 transition-colors flex items-center gap-1.5 cursor-pointer"
                                    >
                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                            <path d="M12 5v14M5 12h14" />
                                        </svg>
                                        <span>Create Campaign</span>
                                    </button>
                                    <Link
                                        href={`/tracks/create?watched_product_id=${product.id}`}
                                        className={`${button} text-xs !py-1 !px-2.5`}
                                    >
                                        Create Track from this product
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <div className="divide-y divide-theme rounded-xl border border-theme overflow-hidden">
                                {(product.tracks || tracks).map((track) => (
                                    <div
                                        key={track.id}
                                        className="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-theme-surface hover:bg-theme-subtle/30 transition-colors text-xs"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/tracks/${track.id}`}
                                                    className="font-bold text-sm text-theme-primary hover:text-theme-accent transition-colors"
                                                >
                                                    {track.name}
                                                </Link>
                                                <span className="rounded-md border border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 uppercase">
                                                    {track.status || 'Active'}
                                                </span>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2 text-theme-muted">
                                                <span>Platform: <strong className="text-theme-secondary">{track.platform}</strong></span>
                                                <span>•</span>
                                                <span>Strategy: <strong className="text-theme-secondary">{track.strategy}</strong></span>
                                                {track.created_at_formatted && (
                                                    <>
                                                        <span>•</span>
                                                        <span>Created: <strong className="text-theme-secondary">{track.created_at_formatted}</strong></span>
                                                    </>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-3 self-end sm:self-auto">
                                            {track.account_name ? (
                                                <div className="text-right">
                                                    <span className="inline-flex items-center gap-1.5 rounded-md border border-theme bg-theme-subtle/70 px-2 py-0.5 text-xs text-theme-secondary">
                                                        <span className="font-medium text-theme-primary">{track.account_name}</span>
                                                        {track.account_customer_id && (
                                                            <span className="font-mono text-theme-muted text-[11px]">({track.account_customer_id})</span>
                                                        )}
                                                    </span>
                                                </div>
                                            ) : (
                                                <span className="text-theme-muted text-xs">No Google Ads account</span>
                                            )}
                                            <Link
                                                href={`/tracks/${track.id}`}
                                                className="px-2.5 py-1 rounded-md border border-theme bg-theme-surface hover:bg-theme-subtle text-xs font-semibold text-theme-primary transition-colors"
                                            >
                                                Open Track →
                                            </Link>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* TAB 2: NEGATIVE KEYWORD MANAGER */}
            {activeTab === 'negatives' && (
                <div className="space-y-4">
                    {/* Status Badge Banner */}
                    <div className="flex flex-wrap items-center justify-between gap-3 px-1">
                        <div className="flex flex-wrap items-center gap-2">
                            {hasCampaignNegatives ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-500">
                                    <span className="h-2 w-2 rounded-full bg-emerald-400" />
                                    Campaign Negatives Ready ({campaignNegativesCount} terms)
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-500">
                                    <span className="h-2 w-2 rounded-full bg-amber-400 animate-pulse" />
                                    Campaign negatives not generated yet (Base seeds only)
                                </span>
                            )}
                            <span className="text-xs text-theme-muted">
                                {hasCampaignNegatives
                                    ? 'SERP competitors, homonyms, and intruders populated.'
                                    : 'Light mode active: base seeds & qualifier rule. Run generation when launching campaigns.'}
                            </span>
                        </div>

                        {quotaExceeded && (
                            <span className="text-xs font-medium text-rose-500 bg-rose-500/10 border border-rose-500/20 px-2.5 py-1 rounded-lg">
                                ⚠️ SerpApi quota ceiling reached ({serpapi_usage?.used_this_month ?? 0}/{serpapi_usage?.total_limit ?? 250})
                            </span>
                        )}
                    </div>

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
                                onClick={handleGenerateCampaignNegatives}
                                disabled={isGeneratingCampaign || quotaExceeded}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all shadow-2xs ${
                                    quotaExceeded
                                        ? 'border border-rose-500/30 bg-rose-500/10 text-rose-400 cursor-not-allowed opacity-60'
                                        : 'border border-amber-500/40 bg-amber-500/15 text-amber-500 hover:bg-amber-500/25 active:scale-95 cursor-pointer'
                                }`}
                                title={quotaExceeded ? `SerpApi quota exceeded (${serpapi_usage?.used_this_month ?? 0}/${serpapi_usage?.total_limit ?? 250})` : 'Run full pass: extract competitor, homonym, and intruder negative keywords from Google SERP'}
                            >
                                {isGeneratingCampaign ? (
                                    <>
                                        <span className="h-3 w-3 animate-spin rounded-full border-2 border-amber-500 border-t-transparent" />
                                        <span>Extracting Negatives...</span>
                                    </>
                                ) : (
                                    <>
                                        <span>⚡</span>
                                        <span>Generate campaign negatives</span>
                                    </>
                                )}
                            </button>

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

            {/* Campaign Creation Modal */}
            <CampaignCreationModal
                isOpen={showCampaignModal}
                onClose={() => setShowCampaignModal(false)}
                product={product}
                accounts={google_ads_accounts}
                defaultAccountId={default_account_id}
                negativeKeywords={generatedNegatives}
                onSuccess={(data) => {
                    showToast(`Campaign "${data.name || product.name}" created in Google Ads (PAUSED)!`);
                    router.reload({ only: ['product'] });
                }}
            />
        </Layout>
    );
}
