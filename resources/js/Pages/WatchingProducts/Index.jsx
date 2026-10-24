import React, { useState, useMemo } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

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

export default function Index({ products, filters, metrics, options }) {
    const [search, setSearch] = useState(filters.search || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [selectedStrategy, setSelectedStrategy] = useState(filters.strategy || 'all');
    const [selectedBrandBidding, setSelectedBrandBidding] = useState(filters.brand_bidding || 'all');
    const [selectedTrueBof, setSelectedTrueBof] = useState(filters.true_bof || 'all');
    const [selectedPlatform, setSelectedPlatform] = useState(filters.platform || 'all');

    // Slide-over drawer / modal state
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState(null);
    const [deleteConfirmProduct, setDeleteConfirmProduct] = useState(null);
    const [scoreModalProduct, setScoreModalProduct] = useState(null);

    // Toast notification state
    const [toastMessage, setToastMessage] = useState('');

    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(''), 2500);
    };

    const handleCopyText = (text, label) => {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            showToast(`Copied ${label} to clipboard!`);
        });
    };

    // Table Sorting State (Default sort: Rank ASC)
    const [sortConfig, setSortConfig] = useState({ key: 'rank', direction: 'asc' });

    const handleSort = (key) => {
        setSortConfig((prev) => {
            if (prev.key === key) {
                return {
                    key,
                    direction: prev.direction === 'asc' ? 'desc' : 'asc',
                };
            }
            return {
                key,
                direction: 'asc',
            };
        });
    };

    const sortedProducts = useMemo(() => {
        if (!sortConfig.key) return products;

        return [...products].sort((a, b) => {
            let aVal = a[sortConfig.key];
            let bVal = b[sortConfig.key];

            if (sortConfig.key === 'rank') {
                const aRank = a.rank !== null && a.rank !== undefined ? Number(a.rank) : 99999;
                const bRank = b.rank !== null && b.rank !== undefined ? Number(b.rank) : 99999;
                return sortConfig.direction === 'asc' ? aRank - bRank : bRank - aRank;
            } else if (sortConfig.key === 'score') {
                const aScore = a.score !== null && a.score !== undefined ? Number(a.score) : -1;
                const bScore = b.score !== null && b.score !== undefined ? Number(b.score) : -1;
                return sortConfig.direction === 'asc' ? aScore - bScore : bScore - aScore;
            } else if (sortConfig.key === 'platforms') {
                aVal = Array.isArray(a.platforms) ? a.platforms.join(', ') : (a.platforms || '');
                bVal = Array.isArray(b.platforms) ? b.platforms.join(', ') : (b.platforms || '');
            } else if (sortConfig.key === 'geos') {
                aVal = Array.isArray(a.geos) ? a.geos.join(', ') : (a.geos || '');
                bVal = Array.isArray(b.geos) ? b.geos.join(', ') : (b.geos || '');
            } else if (sortConfig.key === 'payout_cad') {
                const aNum = a.payout_cad !== null && a.payout_cad !== undefined ? Number(a.payout_cad) : -Infinity;
                const bNum = b.payout_cad !== null && b.payout_cad !== undefined ? Number(b.payout_cad) : -Infinity;
                return sortConfig.direction === 'asc' ? aNum - bNum : bNum - aNum;
            } else if (sortConfig.key === 'last_reviewed_at') {
                const aTime = a.last_reviewed_at ? new Date(a.last_reviewed_at).getTime() : 0;
                const bTime = b.last_reviewed_at ? new Date(b.last_reviewed_at).getTime() : 0;
                return sortConfig.direction === 'asc' ? aTime - bTime : bTime - aTime;
            } else if (sortConfig.key === 'last_refreshed_at') {
                const aTime = a.last_refreshed_at ? new Date(a.last_refreshed_at).getTime() : 0;
                const bTime = b.last_refreshed_at ? new Date(b.last_refreshed_at).getTime() : 0;
                return sortConfig.direction === 'asc' ? aTime - bTime : bTime - aTime;
            } else if (sortConfig.key === 'status') {
                const priority = { test: 1, watch: 2, rejected: 3 };
                const aPriority = priority[a.status] || 99;
                const bPriority = priority[b.status] || 99;
                if (aPriority !== bPriority) {
                    return sortConfig.direction === 'asc' ? aPriority - bPriority : bPriority - aPriority;
                }
            } else if (sortConfig.key === 'brand_bidding') {
                aVal = a.brand_bidding_label || a.brand_bidding || '';
                bVal = b.brand_bidding_label || b.brand_bidding || '';
            } else if (sortConfig.key === 'true_bof') {
                aVal = a.true_bof_label || a.true_bof || '';
                bVal = b.true_bof_label || b.true_bof || '';
            } else if (sortConfig.key === 'strategy') {
                aVal = a.strategy_label || a.strategy || '';
                bVal = b.strategy_label || b.strategy || '';
            }

            if (typeof aVal === 'string' && typeof bVal === 'string') {
                const cmp = aVal.localeCompare(bVal, undefined, { numeric: true, sensitivity: 'base' });
                return sortConfig.direction === 'asc' ? cmp : -cmp;
            }

            if (aVal < bVal) return sortConfig.direction === 'asc' ? -1 : 1;
            if (aVal > bVal) return sortConfig.direction === 'asc' ? 1 : -1;
            return 0;
        });
    }, [products, sortConfig]);

    const renderSortTh = (label, columnKey, className = '') => {
        const isActive = sortConfig.key === columnKey;
        const isAsc = isActive && sortConfig.direction === 'asc';

        return (
            <th
                scope="col"
                onClick={() => handleSort(columnKey)}
                className={`px-3 py-3 select-none cursor-pointer group transition-colors hover:text-theme-primary ${isActive ? 'text-theme-accent font-bold bg-theme-subtle/50' : ''} ${className}`}
                title={`Sort by ${label} (${isActive && isAsc ? 'Descending' : 'Ascending'})`}
                aria-sort={isActive ? (isAsc ? 'ascending' : 'descending') : 'none'}
            >
                <div className={`flex items-center gap-1.5 ${className.includes('text-right') ? 'justify-end' : ''}`}>
                    <span>{label}</span>
                    <span className="inline-flex shrink-0">
                        {isActive ? (
                            isAsc ? (
                                <svg className="w-3.5 h-3.5 text-theme-accent stroke-[2.5]" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                                </svg>
                            ) : (
                                <svg className="w-3.5 h-3.5 text-theme-accent stroke-[2.5]" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                </svg>
                            )
                        ) : (
                            <svg className="w-3.5 h-3.5 text-theme-muted/40 group-hover:text-theme-muted transition-colors stroke-2" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                            </svg>
                        )}
                    </span>
                </div>
            </th>
        );
    };

    // Form handling for create / edit drawer
    const form = useForm({
        name: '',
        status: 'watch',
        product_kind: 'nutra',
        esteira: false,
        restricted_category: false,
        strategy: 'bof_direct',
        platforms: '',
        payout_amount: '',
        payout_currency: 'USD',
        payout_model: 'cpa_fixed',
        payout_notes: '',
        page_type: 'TSL',
        brand_bidding: 'unknown',
        true_bof: 'ambiguous',
        bof_translation: false,
        bof_vpn_serp: false,
        bof_image_match_pct: 0,
        bof_qualifier: '',
        bof_homonym: false,
        geos: 'US, CA',
        negatives: '',
        approval_required: false,
        demand_notes: '',
        sales_page_url: '',
        affiliate_link: '',
        notes: '',
    });

    const openCreateDrawer = () => {
        setEditingProduct(null);
        form.clearErrors();
        form.setData({
            name: '',
            status: 'watch',
            product_kind: 'nutra',
            esteira: false,
            restricted_category: false,
            strategy: 'bof_direct',
            platforms: '',
            payout_amount: '',
            payout_currency: 'USD',
            payout_model: 'cpa_fixed',
            payout_notes: '',
            page_type: 'TSL',
            brand_bidding: 'unknown',
            true_bof: 'ambiguous',
            bof_translation: false,
            bof_vpn_serp: false,
            bof_image_match_pct: 0,
            bof_qualifier: '',
            bof_homonym: false,
            geos: 'US, CA',
            negatives: '',
            approval_required: false,
            demand_notes: '',
            sales_page_url: '',
            affiliate_link: '',
            notes: '',
        });
        setIsDrawerOpen(true);
    };

    const openEditDrawer = (product) => {
        setEditingProduct(product);
        form.clearErrors();
        form.setData({
            name: product.name || '',
            status: product.status || 'watch',
            product_kind: product.product_kind || 'nutra',
            esteira: Boolean(product.esteira),
            restricted_category: Boolean(product.restricted_category),
            strategy: product.strategy || 'bof_direct',
            platforms: Array.isArray(product.platforms) ? product.platforms.join(', ') : (product.platforms || ''),
            payout_amount: product.payout_amount !== null && product.payout_amount !== undefined ? product.payout_amount : (product.payout_cad || ''),
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
            geos: Array.isArray(product.geos) ? product.geos.join(', ') : (product.geos || ''),
            negatives: product.negatives || '',
            approval_required: Boolean(product.approval_required),
            demand_notes: product.demand_notes || '',
            sales_page_url: product.sales_page_url || '',
            affiliate_link: product.affiliate_link || '',
            notes: product.notes || '',
        });
        setIsDrawerOpen(true);
    };

    const closeDrawer = () => {
        setIsDrawerOpen(false);
        setEditingProduct(null);
        form.reset();
        form.clearErrors();
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if (editingProduct) {
            form.put(`/watching-products/${editingProduct.id}`, {
                onSuccess: () => {
                    closeDrawer();
                    showToast('Product updated successfully!');
                },
            });
        } else {
            form.post('/watching-products', {
                onSuccess: () => {
                    closeDrawer();
                    showToast('Product added to watchlist!');
                },
            });
        }
    };

    const handleQuickStatusChange = (product, newStatus) => {
        router.patch(`/watching-products/${product.id}/status`, {
            status: newStatus,
        }, {
            preserveScroll: true,
        });
    };

    const handleMarkReviewed = (product) => {
        router.post(`/watching-products/${product.id}/review`, {}, {
            preserveScroll: true,
            onSuccess: () => showToast(`Marked ${product.name} as reviewed.`),
        });
    };

    const confirmDelete = (product) => {
        setDeleteConfirmProduct(product);
    };

    const executeDelete = () => {
        if (!deleteConfirmProduct) return;
        router.delete(`/watching-products/${deleteConfirmProduct.id}`, {
            preserveScroll: true,
            onFinish: () => setDeleteConfirmProduct(null),
        });
    };

    // Filter triggers
    const applyFilters = (newFilters) => {
        const query = {
            search: newFilters.search !== undefined ? newFilters.search : search,
            status: newFilters.status !== undefined ? newFilters.status : selectedStatus,
            strategy: newFilters.strategy !== undefined ? newFilters.strategy : selectedStrategy,
            brand_bidding: newFilters.brand_bidding !== undefined ? newFilters.brand_bidding : selectedBrandBidding,
            true_bof: newFilters.true_bof !== undefined ? newFilters.true_bof : selectedTrueBof,
            platform: newFilters.platform !== undefined ? newFilters.platform : selectedPlatform,
        };

        const cleaned = {};
        Object.entries(query).forEach(([k, v]) => {
            if (v && v !== 'all') {
                cleaned[k] = v;
            }
        });

        router.get('/watching-products', cleaned, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const handleResetFilters = () => {
        setSearch('');
        setSelectedStatus('all');
        setSelectedStrategy('all');
        setSelectedBrandBidding('all');
        setSelectedTrueBof('all');
        setSelectedPlatform('all');

        router.get('/watching-products', {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const hasActiveFilters = Boolean(
        search ||
        selectedStatus !== 'all' ||
        selectedStrategy !== 'all' ||
        selectedBrandBidding !== 'all' ||
        selectedTrueBof !== 'all' ||
        selectedPlatform !== 'all'
    );

    const formatCad = (amount) => {
        if (amount === null || amount === undefined || isNaN(amount)) return '—';
        return `CA$${Number(amount).toFixed(2)}`;
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'test':
                return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30';
            case 'watch':
                return 'bg-amber-500/10 text-amber-700 dark:text-amber-400 border-amber-500/30';
            case 'rejected':
                return 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/30';
            default:
                return 'bg-theme-subtle text-theme-secondary border-theme';
        }
    };

    const getBrandBiddingBadge = (bb) => {
        switch (bb) {
            case 'allowed':
                return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
            case 'banned':
                return 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
            case 'confirm_with_am':
                return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
            default:
                return 'bg-theme-subtle text-theme-muted border-theme';
        }
    };

    const getTrueBofBadge = (bof) => {
        switch (bof) {
            case 'true':
                return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
            case 'false':
                return 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
            default:
                return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
        }
    };

    const getTrendBadge = (trend) => {
        switch (trend) {
            case 'rising':
                return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
            case 'falling':
                return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
            case 'dead_post_peak':
                return 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20';
            default:
                return 'bg-theme-subtle text-theme-secondary border-theme';
        }
    };

    return (
        <Layout>
            <Head title="Watching Products – Flow Hub" />

            {/* Page Header */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-theme-primary">
                        Watching Products
                    </h1>
                    <p className="mt-1 text-xs text-theme-secondary">
                        Ranked bottom-of-funnel research catalog with deterministic scoring and automated SerpApi tracking.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={openCreateDrawer}
                        className={button}
                    >
                        + Add product
                    </button>
                </div>
            </div>

            {/* Portfolio Split & SerpApi Header Cards */}
            <div className="mb-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                {/* Nutra / E-com Portfolio Split against 60/40 Target */}
                <div className="rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs">
                    <div className="flex justify-between items-center text-xs font-semibold">
                        <span className="text-theme-secondary">Portfolio Split</span>
                        <span className="text-[10px] text-theme-muted uppercase tracking-wider font-bold">Target 60 / 40</span>
                    </div>
                    <div className="mt-2 flex items-center justify-between text-xs">
                        <span className="font-bold text-emerald-600 dark:text-emerald-400">Nutra: {metrics.nutra_pct}% ({metrics.nutra_count})</span>
                        <span className="font-bold text-sky-600 dark:text-sky-400">E-com: {metrics.ecom_pct}% ({metrics.ecom_count})</span>
                    </div>
                    <div className="mt-2 w-full bg-theme-subtle rounded-full h-2 overflow-hidden flex">
                        <div
                            className="bg-emerald-500 h-2 transition-all"
                            style={{ width: `${metrics.nutra_pct}%` }}
                            title={`Nutra: ${metrics.nutra_pct}%`}
                        />
                        <div
                            className="bg-sky-500 h-2 transition-all"
                            style={{ width: `${metrics.ecom_pct}%` }}
                            title={`E-com: ${metrics.ecom_pct}%`}
                        />
                    </div>
                </div>

                {/* SerpApi Free Tier Usage */}
                <div className="rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs">
                    <div className="flex justify-between items-center text-xs font-semibold">
                        <span className="text-theme-secondary">SerpApi Quota (Month)</span>
                        <span className="rounded bg-theme-subtle px-1.5 py-0.2 text-[10px] font-mono text-theme-muted">
                            Cap: {metrics.serpapi_cap}
                        </span>
                    </div>
                    <div className="mt-1 text-2xl font-bold text-theme-accent">
                        {metrics.serpapi_used} <span className="text-xs font-normal text-theme-muted">/ {metrics.serpapi_limit}</span>
                    </div>
                    <div className="text-[11px] text-theme-muted mt-1">
                        Weekly allowance strictly budgeted. No LLMs in cron.
                    </div>
                </div>

                {/* Status metrics */}
                <div
                    onClick={() => {
                        setSelectedStatus('test');
                        applyFilters({ status: 'test' });
                    }}
                    className={`cursor-pointer rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs transition-all hover:border-theme-hover ${
                        selectedStatus === 'test' ? 'ring-1 ring-emerald-500' : ''
                    }`}
                >
                    <div className="text-xs font-medium text-emerald-600 dark:text-emerald-400">Potential Test</div>
                    <div className="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{metrics.test}</div>
                </div>

                <div
                    onClick={() => {
                        setSelectedStatus('watch');
                        applyFilters({ status: 'watch' });
                    }}
                    className={`cursor-pointer rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs transition-all hover:border-theme-hover ${
                        selectedStatus === 'watch' ? 'ring-1 ring-amber-500' : ''
                    }`}
                >
                    <div className="text-xs font-medium text-amber-600 dark:text-amber-400">Under Watch</div>
                    <div className="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{metrics.watch}</div>
                </div>
            </div>

            {/* Filter and Search Bar */}
            <div className="mb-6 rounded-xl border border-theme bg-theme-surface p-4 shadow-xs">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    {/* Search */}
                    <div className="lg:col-span-2">
                        <form onSubmit={handleSearchSubmit} className="relative">
                            <input
                                type="text"
                                className={`${control} pl-9`}
                                placeholder="Search product name..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <svg
                                className="absolute left-3 top-2.5 h-4 w-4 text-theme-muted"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                aria-hidden="true"
                            >
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                        </form>
                    </div>

                    {/* Status Filter */}
                    <div>
                        <select
                            className={control}
                            value={selectedStatus}
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedStatus(val);
                                applyFilters({ status: val });
                            }}
                            aria-label="Filter by Status"
                        >
                            <option value="all">All Statuses</option>
                            {options.statuses.map((s) => (
                                <option key={s.value} value={s.value}>{s.label}</option>
                            ))}
                        </select>
                    </div>

                    {/* Strategy Filter */}
                    <div>
                        <select
                            className={control}
                            value={selectedStrategy}
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedStrategy(val);
                                applyFilters({ strategy: val });
                            }}
                            aria-label="Filter by Strategy"
                        >
                            <option value="all">All Strategies</option>
                            {options.strategies?.map((st) => (
                                <option key={st.value} value={st.value}>{st.label}</option>
                            ))}
                        </select>
                    </div>

                    {/* Brand Bidding Filter */}
                    <div>
                        <select
                            className={control}
                            value={selectedBrandBidding}
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedBrandBidding(val);
                                applyFilters({ brand_bidding: val });
                            }}
                            aria-label="Filter by Brand Bidding"
                        >
                            <option value="all">All Brand Bidding</option>
                            {options.brand_bidding.map((b) => (
                                <option key={b.value} value={b.value}>{b.label}</option>
                            ))}
                        </select>
                    </div>

                    {/* Platform Filter */}
                    <div>
                        <select
                            className={control}
                            value={selectedPlatform}
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedPlatform(val);
                                applyFilters({ platform: val });
                            }}
                            aria-label="Filter by Platform"
                        >
                            <option value="all">All Platforms</option>
                            {options.platforms.map((p) => (
                                <option key={p} value={p}>{p}</option>
                            ))}
                        </select>
                    </div>
                </div>

                {hasActiveFilters && (
                    <div className="mt-3 flex items-center justify-between border-t border-theme pt-3 text-xs text-theme-muted">
                        <span>Active filters applied</span>
                        <button
                            type="button"
                            onClick={handleResetFilters}
                            className="font-medium text-theme-accent hover:underline cursor-pointer"
                        >
                            Reset filters
                        </button>
                    </div>
                )}
            </div>

            {/* Products Table */}
            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-xs">
                {products.length === 0 ? (
                    <div className="p-12 text-center">
                        <svg className="mx-auto h-10 w-10 text-theme-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <h3 className="mt-3 text-sm font-semibold text-theme-primary">No products found</h3>
                        <p className="mt-1 text-xs text-theme-secondary">
                            {hasActiveFilters
                                ? 'No watched products match the selected filters. Try resetting the filters.'
                                : 'Get started by adding your first researched affiliate product to the watchlist.'}
                        </p>
                        <div className="mt-4">
                            {hasActiveFilters ? (
                                <button type="button" onClick={handleResetFilters} className={quiet}>
                                    Clear filters
                                </button>
                            ) : (
                                <button type="button" onClick={openCreateDrawer} className={button}>
                                    Add product
                                </button>
                            )}
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="hidden md:block overflow-x-auto">
                            <table className="min-w-full divide-y divide-theme text-left text-sm">
                            <thead className="bg-theme-subtle/40 text-xs font-semibold uppercase tracking-wider text-theme-muted">
                                <tr>
                                    {renderSortTh('#', 'rank', 'w-14 text-center')}
                                    {renderSortTh('Score', 'score', 'w-24 text-center')}
                                    {renderSortTh('Product Name', 'name')}
                                    {renderSortTh('Status', 'status')}
                                    {renderSortTh('Strategy', 'strategy')}
                                    {renderSortTh('Kind', 'product_kind')}
                                    {renderSortTh('Payout', 'payout_cad')}
                                    {renderSortTh('Demand', 'trends_trend')}
                                    {renderSortTh('Brand Bidding', 'brand_bidding')}
                                    {renderSortTh('Refreshed', 'last_refreshed_at')}
                                    <th scope="col" className="px-3 py-3 text-right select-none">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme">
                                {sortedProducts.map((product) => {
                                    const scoreHsl = getScoreHsl(product.score);
                                    const flags = product.score_flags || {};

                                    return (
                                        <tr
                                            key={product.id}
                                            className="hover:bg-theme-subtle/30 transition-colors"
                                        >
                                            {/* Rank */}
                                            <td className="px-3 py-3 text-center whitespace-nowrap font-bold text-xs text-theme-secondary">
                                                {product.rank ? `#${product.rank}` : '—'}
                                            </td>

                                            {/* Score with Continuous HSL Gradient & ⓘ Popover Trigger */}
                                            <td className="px-3 py-3 whitespace-nowrap text-center">
                                                <div className="flex items-center justify-center gap-1">
                                                    <span
                                                        style={scoreHsl.style}
                                                        className="inline-block rounded-md border px-2 py-0.5 text-xs font-black min-w-[34px] text-center shadow-2xs"
                                                        title={`Score: ${product.score ?? 'N/A'} (Band: ${scoreHsl.band})`}
                                                    >
                                                        {product.score !== null ? product.score : '—'}
                                                    </span>

                                                    {/* ⓘ Info Button to reveal breakdown */}
                                                    <button
                                                        type="button"
                                                        onClick={() => setScoreModalProduct(product)}
                                                        className="text-theme-muted hover:text-theme-accent p-0.5 rounded cursor-pointer transition-colors"
                                                        title="Click to view score breakdown & flags"
                                                        aria-label="View score breakdown"
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                            <circle cx="12" cy="12" r="10" />
                                                            <line x1="12" y1="16" x2="12" y2="12" />
                                                            <line x1="12" y1="8" x2="12.01" y2="8" />
                                                        </svg>
                                                    </button>
                                                </div>

                                                {/* Mini flag badges below score */}
                                                <div className="flex items-center justify-center gap-1 mt-0.5">
                                                    {flags.false_bof && (
                                                        <span className="text-[10px]" title="False BoF (Score 0)">🚫</span>
                                                    )}
                                                    {flags.payout_below_floor && (
                                                        <span className="text-[10px]" title="Payout below floor ($20)">⚠️</span>
                                                    )}
                                                    {flags.compound_keyword_needed && (
                                                        <span className="text-[10px]" title="Compound keyword needed">🔑</span>
                                                    )}
                                                    {flags.bb_risk && (
                                                        <span className="text-[10px]" title="Brand Bidding Risk">⚡</span>
                                                    )}
                                                    {flags.restricted_category && (
                                                        <span className="text-[10px]" title="Restricted Category (Cap 50)">🔒</span>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Product Name (Clickable to Show page) */}
                                            <td className="px-3 py-3 font-medium text-theme-primary">
                                                <div className="flex flex-col">
                                                    <Link
                                                        href={`/watching-products/${product.id}`}
                                                        className="text-left font-bold text-sm hover:text-theme-accent transition-colors"
                                                    >
                                                        {product.name}
                                                    </Link>
                                                    <div className="mt-0.5 flex items-center gap-1.5 text-xs text-theme-muted">
                                                        <span>{product.page_type || 'General'}</span>
                                                        {product.approval_required && (
                                                            <span className="rounded bg-amber-500/10 px-1 py-0.2 text-[9px] font-semibold text-amber-700 dark:text-amber-400 border border-amber-500/20">
                                                                Approval
                                                            </span>
                                                        )}
                                                        {product.sales_page_url && (
                                                            <a
                                                                href={product.sales_page_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="text-theme-muted hover:text-theme-accent"
                                                                title="Open Sales Page"
                                                            >
                                                                <svg className="w-3 h-3 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                                                    <polyline points="15 3 21 3 21 9" />
                                                                    <line x1="10" y1="14" x2="21" y2="3" />
                                                                </svg>
                                                            </a>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Status */}
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                <select
                                                    value={product.status}
                                                    onChange={(e) => handleQuickStatusChange(product, e.target.value)}
                                                    className={`rounded-md border px-2 py-0.5 text-xs font-semibold cursor-pointer focus:outline-none ${getStatusBadge(product.status)}`}
                                                    aria-label={`Change status for ${product.name}`}
                                                >
                                                    <option value="test">Test</option>
                                                    <option value="watch">Watch</option>
                                                    <option value="rejected">Rejected</option>
                                                </select>
                                            </td>

                                            {/* Strategy */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs">
                                                <span className="rounded-md bg-theme-subtle px-2 py-0.5 font-medium border border-theme text-theme-primary">
                                                    {product.strategy_label || 'BoF Direct'}
                                                </span>
                                            </td>

                                            {/* Category / Kind */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs">
                                                <div className="flex items-center gap-1">
                                                    <span className="rounded bg-theme-subtle px-1.5 py-0.5 text-[10px] uppercase font-bold text-theme-secondary border border-theme">
                                                        {product.product_kind_label || 'Nutra'}
                                                    </span>
                                                    {product.esteira && (
                                                        <span className="rounded bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20 px-1 py-0.2 text-[9px] font-bold">
                                                            E
                                                        </span>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Payout */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs">
                                                <div className="font-bold text-theme-primary">
                                                    {product.payout_formatted}
                                                </div>
                                                <div className="text-[10px] text-theme-muted">
                                                    CA${Number(product.payout_cad).toFixed(2)}
                                                </div>
                                            </td>

                                            {/* Demand Trend */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs">
                                                <span className={`inline-block rounded px-2 py-0.5 text-[11px] font-medium border ${getTrendBadge(product.trends_trend)}`}>
                                                    {product.trends_trend_label || 'Stable'}
                                                </span>
                                            </td>

                                            {/* Brand Bidding */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs">
                                                <span className={`inline-block rounded px-2 py-0.5 text-[11px] font-medium border ${getBrandBiddingBadge(product.brand_bidding)}`}>
                                                    {product.brand_bidding_label}
                                                </span>
                                            </td>

                                            {/* Last Refreshed */}
                                            <td className="px-3 py-3 whitespace-nowrap text-xs text-theme-muted">
                                                <span>{product.last_refreshed_formatted || 'Never'}</span>
                                            </td>

                                            {/* Actions */}
                                            <td className="px-3 py-3 whitespace-nowrap text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={`/watching-products/${product.id}`}
                                                        className="px-2 py-1 rounded text-theme-accent hover:bg-theme-subtle text-xs font-semibold transition-colors"
                                                        title="View Product Details"
                                                    >
                                                        View
                                                    </Link>

                                                    {product.sales_page_url ? (
                                                        <Link
                                                            href={`/pages?tab=cloner&clone_url=${encodeURIComponent(product.sales_page_url)}&affiliate_link=${encodeURIComponent(product.affiliate_link || '')}&product_name=${encodeURIComponent(product.name)}`}
                                                            className="p-1 rounded text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10 transition-colors"
                                                            title="Clone Sales Page"
                                                        >
                                                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                                <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                                <path d="M3 3v5h5" />
                                                                <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                                                                <path d="M16 21h5v-5" />
                                                            </svg>
                                                        </Link>
                                                    ) : null}

                                                    <button
                                                        type="button"
                                                        onClick={() => openEditDrawer(product)}
                                                        className="p-1 rounded text-theme-secondary hover:text-theme-primary transition-colors cursor-pointer"
                                                        title="Edit parameters"
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <path d="M12 20h9" />
                                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                                        </svg>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        onClick={() => confirmDelete(product)}
                                                        className="p-1 rounded text-theme-secondary hover:text-rose-600 transition-colors cursor-pointer"
                                                        title="Delete product"
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <polyline points="3 6 5 6 21 6" />
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile Stacked Responsive Cards (< 768px) */}
                    <div className="md:hidden divide-y divide-theme">
                        {sortedProducts.map((product) => {
                            const scoreHsl = getScoreHsl(product.score);
                            return (
                                <div key={product.id} className="p-4 space-y-3 hover:bg-theme-subtle/20 transition-colors">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-1.5">
                                                {product.rank && (
                                                    <span className="text-xs font-black text-theme-accent">#{product.rank}</span>
                                                )}
                                                <Link
                                                    href={`/watching-products/${product.id}`}
                                                    className="text-left font-bold text-base text-theme-primary hover:text-theme-accent transition-colors truncate block"
                                                >
                                                    {product.name}
                                                </Link>
                                            </div>
                                            <div className="mt-1 flex items-center gap-2 text-xs text-theme-muted">
                                                <span>{product.page_type || 'General'}</span>
                                                <span>•</span>
                                                <span className="font-semibold text-theme-secondary">{product.strategy_label}</span>
                                            </div>
                                        </div>

                                        <span
                                            style={scoreHsl.style}
                                            className="rounded-lg border px-2.5 py-1 text-sm font-black shadow-2xs"
                                        >
                                            {product.score ?? '—'}
                                        </span>
                                    </div>

                                    <div className="grid grid-cols-2 gap-2 text-xs bg-theme-subtle/30 p-2.5 rounded-lg border border-theme">
                                        <div>
                                            <span className="text-[11px] text-theme-muted block">Payout:</span>
                                            <span className="font-bold text-theme-primary">{product.payout_formatted}</span>
                                        </div>
                                        <div>
                                            <span className="text-[11px] text-theme-muted block">CAD Cache:</span>
                                            <span className="font-bold text-emerald-600 dark:text-emerald-400">CA${Number(product.payout_cad).toFixed(2)}</span>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between pt-1">
                                        <select
                                            value={product.status}
                                            onChange={(e) => handleQuickStatusChange(product, e.target.value)}
                                            className={`rounded-md border px-2 py-1 text-xs font-semibold cursor-pointer ${getStatusBadge(product.status)}`}
                                        >
                                            <option value="test">Test</option>
                                            <option value="watch">Watch</option>
                                            <option value="rejected">Rejected</option>
                                        </select>

                                        <div className="flex items-center gap-2">
                                            <Link
                                                href={`/watching-products/${product.id}`}
                                                className="text-xs font-semibold text-theme-accent hover:underline"
                                            >
                                                Details &rarr;
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </>
                )}
            </div>

            {/* Score Breakdown Modal (Click-to-open ⓘ) */}
            {scoreModalProduct && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-fadeIn"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="score-modal-title"
                >
                    <div className="w-full max-w-lg rounded-2xl border border-theme bg-theme-surface p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between border-b border-theme pb-3">
                            <div className="flex items-center gap-2">
                                <span
                                    style={getScoreHsl(scoreModalProduct.score).style}
                                    className="rounded-lg border px-2.5 py-1 text-sm font-black"
                                >
                                    {scoreModalProduct.score ?? '—'}
                                </span>
                                <div>
                                    <h3 id="score-modal-title" className="text-base font-bold text-theme-primary">
                                        {scoreModalProduct.name}
                                    </h3>
                                    <div className="text-[11px] text-theme-muted">Rank #{scoreModalProduct.rank || '—'}</div>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setScoreModalProduct(null)}
                                className="text-theme-muted hover:text-theme-primary cursor-pointer p-1"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Component breakdown */}
                        <div className="space-y-2 text-xs">
                            <div className="font-semibold text-theme-secondary uppercase tracking-wider text-[11px]">
                                Sub-Score Component Breakdown
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex flex-col gap-0.5">
                                    <div className="flex justify-between">
                                        <span>BoF Name Triad:</span>
                                        <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.bof_name ?? 0} / 20</strong>
                                    </div>
                                    <div className="text-[10px] text-theme-muted">
                                        Images: {scoreModalProduct.bof_image_match_pct ?? 0}% • Trans: {scoreModalProduct.bof_translation ? '✓' : '✗'} • VPN: {scoreModalProduct.bof_vpn_serp ? '✓' : '✗'}
                                    </div>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme space-y-0.5">
                                    <div className="flex justify-between">
                                        <span>Demand & Trends:</span>
                                        <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.demand ?? 0} / 20</strong>
                                    </div>
                                    <div className="text-[10px] text-theme-muted">
                                        Trend: {scoreModalProduct.trends_trend_label || scoreModalProduct.trends_trend || 'Unknown'}{scoreModalProduct.flowspy_traffic_trend === 'rising' ? ' • FlowSpy Rising' : ''}
                                    </div>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Payout Model:</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.payout ?? 0} / 15</strong>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Margin Headroom:</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.margin_headroom ?? 0} / 10</strong>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Page Type & Funnel:</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.page_type ?? 0} / 10</strong>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Brand Bidding:</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.brand_bidding ?? 0} / 10</strong>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Geo (CA/US/AU):</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.geo ?? 0} / 5</strong>
                                </div>
                                <div className="p-2 rounded bg-theme-subtle border border-theme flex justify-between">
                                    <span>Competition Density:</span>
                                    <strong className="font-mono text-theme-accent">{scoreModalProduct.score_breakdown?.competition ?? 0} / 5</strong>
                                </div>
                            </div>
                        </div>

                        {/* Active flags */}
                        {scoreModalProduct.score_flags && Object.keys(scoreModalProduct.score_flags).length > 0 && (
                            <div className="pt-2 border-t border-theme text-xs space-y-1">
                                <div className="font-semibold text-theme-secondary uppercase tracking-wider text-[11px]">
                                    Flags & Gate Conditions
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {scoreModalProduct.score_flags.false_bof && (
                                        <span className="rounded bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2 py-0.5 font-bold">
                                            False BoF (Score 0)
                                        </span>
                                    )}
                                    {scoreModalProduct.score_flags.payout_below_floor && (
                                        <span className="rounded bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2 py-0.5">
                                            Payout Below Floor (Cap 20)
                                        </span>
                                    )}
                                    {scoreModalProduct.score_flags.pure_vsl_or_iframe && (
                                        <span className="rounded bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30 px-2 py-0.5">
                                            Pure VSL / iFrame (Cap 20)
                                        </span>
                                    )}
                                    {scoreModalProduct.score_flags.restricted_category && (
                                        <span className="rounded bg-amber-500/20 text-amber-700 dark:text-amber-400 border border-amber-500/30 px-2 py-0.5">
                                            Restricted Category (Cap 50)
                                        </span>
                                    )}
                                    {scoreModalProduct.score_flags.compound_keyword_needed && (
                                        <span className="rounded bg-indigo-500/20 text-indigo-700 dark:text-indigo-400 border border-indigo-500/30 px-2 py-0.5">
                                            Compound Keyword Needed
                                        </span>
                                    )}
                                </div>
                            </div>
                        )}

                        <div className="pt-4 border-t border-theme flex items-center justify-between">
                            <Link
                                href={`/watching-products/${scoreModalProduct.id}`}
                                className="text-xs font-semibold text-theme-accent hover:underline"
                            >
                                View Full Product Page &rarr;
                            </Link>
                            <button
                                type="button"
                                onClick={() => setScoreModalProduct(null)}
                                className={quiet}
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Slide-over Detail / Edit Form Drawer */}
            {isDrawerOpen && (
                <div
                    className="fixed inset-0 z-50 overflow-hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="drawer-title"
                >
                    <div
                        className="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
                        onClick={closeDrawer}
                        aria-hidden="true"
                    />

                    <div className="fixed inset-y-0 right-0 max-w-full flex pl-10">
                        <div className="w-screen max-w-xl border-l border-theme bg-theme-surface shadow-2xl flex flex-col">
                            <div className="flex h-16 shrink-0 items-center justify-between px-6 border-b border-theme bg-theme-surface">
                                <h2 id="drawer-title" className="text-lg font-bold text-theme-primary">
                                    {editingProduct ? 'Edit Watched Product' : 'Add Watched Product'}
                                </h2>
                                <button
                                    type="button"
                                    onClick={closeDrawer}
                                    className="rounded-lg p-2 text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors cursor-pointer"
                                    aria-label="Close form drawer"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={handleFormSubmit} className="flex-1 overflow-y-auto p-6 space-y-5 text-xs">
                                <Field label="Product Name" error={form.errors.name}>
                                    <input
                                        type="text"
                                        required
                                        className={control}
                                        placeholder="e.g. Fimavyn Metabolic Support"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                    />
                                </Field>

                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Status" error={form.errors.status}>
                                        <select
                                            className={control}
                                            value={form.data.status}
                                            onChange={(e) => form.setData('status', e.target.value)}
                                        >
                                            {options.statuses.map((s) => (
                                                <option key={s.value} value={s.value}>{s.label}</option>
                                            ))}
                                        </select>
                                    </Field>

                                    <Field label="Category Kind" error={form.errors.product_kind}>
                                        <select
                                            className={control}
                                            value={form.data.product_kind}
                                            onChange={(e) => form.setData('product_kind', e.target.value)}
                                        >
                                            {options.product_kinds?.map((k) => (
                                                <option key={k.value} value={k.value}>{k.label}</option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <div className="grid grid-cols-3 gap-3">
                                    <Field label="Payout Amount" error={form.errors.payout_amount}>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className={control}
                                            placeholder="120.00"
                                            value={form.data.payout_amount}
                                            onChange={(e) => form.setData('payout_amount', e.target.value)}
                                        />
                                    </Field>

                                    <Field label="Currency" error={form.errors.payout_currency}>
                                        <select
                                            className={control}
                                            value={form.data.payout_currency}
                                            onChange={(e) => form.setData('payout_currency', e.target.value)}
                                        >
                                            {options.currencies?.map((c) => (
                                                <option key={c} value={c}>{c}</option>
                                            ))}
                                        </select>
                                    </Field>

                                    <Field label="Payout Model" error={form.errors.payout_model}>
                                        <select
                                            className={control}
                                            value={form.data.payout_model}
                                            onChange={(e) => form.setData('payout_model', e.target.value)}
                                        >
                                            {options.payout_models?.map((m) => (
                                                <option key={m.value} value={m.value}>{m.label}</option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Page Type" error={form.errors.page_type}>
                                        <input
                                            type="text"
                                            className={control}
                                            placeholder="TSL, DTC, VSL, Quiz..."
                                            value={form.data.page_type}
                                            onChange={(e) => form.setData('page_type', e.target.value)}
                                        />
                                    </Field>

                                    <Field label="Strategy" error={form.errors.strategy}>
                                        <select
                                            className={control}
                                            value={form.data.strategy}
                                            onChange={(e) => form.setData('strategy', e.target.value)}
                                        >
                                            {options.strategies?.map((st) => (
                                                <option key={st.value} value={st.value}>{st.label}</option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Brand Bidding" error={form.errors.brand_bidding}>
                                        <select
                                            className={control}
                                            value={form.data.brand_bidding}
                                            onChange={(e) => form.setData('brand_bidding', e.target.value)}
                                        >
                                            {options.brand_bidding.map((b) => (
                                                <option key={b.value} value={b.value}>{b.label}</option>
                                            ))}
                                        </select>
                                    </Field>

                                    <Field label="True BoF Triad" error={form.errors.true_bof}>
                                        <select
                                            className={control}
                                            value={form.data.true_bof}
                                            onChange={(e) => form.setData('true_bof', e.target.value)}
                                        >
                                            {options.true_bof.map((t) => (
                                                <option key={t.value} value={t.value}>{t.label}</option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>

                                <div className="flex flex-wrap gap-4 pt-2">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.data.esteira}
                                            onChange={(e) => form.setData('esteira', e.target.checked)}
                                        />
                                        <span>Esteira Product</span>
                                    </label>

                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.data.restricted_category}
                                            onChange={(e) => form.setData('restricted_category', e.target.checked)}
                                        />
                                        <span>Restricted Category</span>
                                    </label>

                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.data.approval_required}
                                            onChange={(e) => form.setData('approval_required', e.target.checked)}
                                        />
                                        <span>Approval Required</span>
                                    </label>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <Field label="Sales Page URL" error={form.errors.sales_page_url}>
                                        <input
                                            type="url"
                                            className={control}
                                            value={form.data.sales_page_url}
                                            onChange={(e) => form.setData('sales_page_url', e.target.value)}
                                        />
                                    </Field>

                                    <Field label="Affiliate Link" error={form.errors.affiliate_link}>
                                        <input
                                            type="url"
                                            className={control}
                                            value={form.data.affiliate_link}
                                            onChange={(e) => form.setData('affiliate_link', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                <Field label="Notes" error={form.errors.notes}>
                                    <textarea
                                        rows="2"
                                        className={control}
                                        value={form.data.notes}
                                        onChange={(e) => form.setData('notes', e.target.value)}
                                    />
                                </Field>

                                <div className="sticky bottom-0 -mx-6 -mb-6 border-t border-theme bg-theme-surface p-4 flex items-center justify-end gap-3 shadow-lg">
                                    <button
                                        type="button"
                                        onClick={closeDrawer}
                                        className={quiet}
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className={button}
                                    >
                                        {form.processing ? 'Saving...' : (editingProduct ? 'Update Product' : 'Add to Watchlist')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}

            {/* Delete Confirmation Modal */}
            {deleteConfirmProduct && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="delete-dialog-title"
                >
                    <div className="w-full max-w-md rounded-xl border border-theme bg-theme-surface p-6 shadow-2xl">
                        <h3 id="delete-dialog-title" className="text-base font-bold text-theme-primary">
                            Remove Watched Product
                        </h3>
                        <p className="mt-2 text-sm text-theme-secondary">
                            Are you sure you want to remove <strong>{deleteConfirmProduct.name}</strong> from your watching products catalog? This action cannot be undone.
                        </p>
                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => setDeleteConfirmProduct(null)}
                                className={quiet}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={executeDelete}
                                className="rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700 transition-colors cursor-pointer"
                            >
                                Confirm Delete
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Toast Notification */}
            {toastMessage && (
                <div
                    role="status"
                    className="fixed bottom-5 right-5 z-50 rounded-xl bg-theme-surface border border-emerald-500/40 text-theme-primary px-4 py-3 shadow-2xl flex items-center gap-2 text-xs font-semibold animate-in fade-in slide-in-from-bottom-2 duration-200"
                >
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white text-[11px] font-bold">
                        ✓
                    </span>
                    <span>{toastMessage}</span>
                </div>
            )}
        </Layout>
    );
}
