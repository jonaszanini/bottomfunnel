import React, { useState, useMemo } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

export default function Index({ products, filters, metrics, options }) {
    const [search, setSearch] = useState(filters.search || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [selectedBrandBidding, setSelectedBrandBidding] = useState(filters.brand_bidding || 'all');
    const [selectedTrueBof, setSelectedTrueBof] = useState(filters.true_bof || 'all');
    const [selectedPlatform, setSelectedPlatform] = useState(filters.platform || 'all');

    // Slide-over drawer / modal state
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editingProduct, setEditingProduct] = useState(null);
    const [deleteConfirmProduct, setDeleteConfirmProduct] = useState(null);

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

    // Table Sorting State (Up-Down / Down-Up)
    const [sortConfig, setSortConfig] = useState({ key: null, direction: 'asc' });

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

            if (sortConfig.key === 'platforms') {
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
                className={`px-4 py-3 select-none cursor-pointer group transition-colors hover:text-theme-primary ${isActive ? 'text-theme-accent font-bold bg-theme-subtle/50' : ''} ${className}`}
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

    // Form handling for create / edit
    const form = useForm({
        name: '',
        status: 'watch',
        platforms: '',
        payout_cad: '',
        payout_notes: '',
        page_type: 'TSL',
        brand_bidding: 'unknown',
        true_bof: 'ambiguous',
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
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            status: 'watch',
            platforms: '',
            payout_cad: '',
            payout_notes: '',
            page_type: 'TSL',
            brand_bidding: 'unknown',
            true_bof: 'ambiguous',
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
            platforms: Array.isArray(product.platforms) ? product.platforms.join(', ') : (product.platforms || ''),
            payout_cad: product.payout_cad !== null && product.payout_cad !== undefined ? product.payout_cad : '',
            payout_notes: product.payout_notes || '',
            page_type: product.page_type || 'TSL',
            brand_bidding: product.brand_bidding || 'unknown',
            true_bof: product.true_bof || 'ambiguous',
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
                },
            });
        } else {
            form.post('/watching-products', {
                onSuccess: () => {
                    closeDrawer();
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
            brand_bidding: newFilters.brand_bidding !== undefined ? newFilters.brand_bidding : selectedBrandBidding,
            true_bof: newFilters.true_bof !== undefined ? newFilters.true_bof : selectedTrueBof,
            platform: newFilters.platform !== undefined ? newFilters.platform : selectedPlatform,
        };

        // Strip default 'all' or empty values for clean URLs
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
        setSelectedBrandBidding('all');
        setSelectedTrueBof('all');
        setSelectedPlatform('all');
        router.get('/watching-products', {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const formatCad = (amount) => {
        if (amount === null || amount === undefined || amount === '') {
            return 'Not set';
        }
        const num = Number(amount);
        return `CAD$ ${num.toFixed(2)}`;
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'test':
                return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
            case 'watch':
                return 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30';
            case 'rejected':
                return 'bg-rose-500/15 text-rose-700 dark:text-rose-300 border-rose-500/30';
            default:
                return 'bg-stone-500/15 text-stone-700 dark:text-stone-300 border-stone-500/30';
        }
    };

    const getBrandBiddingBadge = (bb) => {
        switch (bb) {
            case 'allowed':
                return 'bg-teal-500/15 text-teal-700 dark:text-teal-300 border-teal-500/30';
            case 'banned':
                return 'bg-rose-500/15 text-rose-700 dark:text-rose-300 border-rose-500/30';
            case 'confirm_with_am':
                return 'bg-purple-500/15 text-purple-700 dark:text-purple-300 border-purple-500/30';
            default:
                return 'bg-stone-500/15 text-stone-600 dark:text-stone-400 border-stone-500/20';
        }
    };

    const getTrueBofBadge = (bof) => {
        switch (bof) {
            case 'true':
                return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
            case 'false':
                return 'bg-stone-500/15 text-stone-600 dark:text-stone-400 border-stone-500/20';
            case 'ambiguous':
                return 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/30';
            default:
                return 'bg-stone-500/15 text-stone-600 dark:text-stone-400 border-stone-500/20';
        }
    };

    const hasActiveFilters = Boolean(
        (search && search.trim() !== '') ||
        selectedStatus !== 'all' ||
        selectedBrandBidding !== 'all' ||
        selectedTrueBof !== 'all' ||
        selectedPlatform !== 'all'
    );

    return (
        <Layout title="Watching Products">
            <Head title="Watching Products" />

            {/* Header Section */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-theme-primary">
                        Watching Products
                    </h1>
                    <p className="mt-1 text-sm text-theme-secondary">
                        Catalog of researched affiliate products monitored for bottom of funnel Google Ads campaigns.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={openCreateDrawer}
                        className={button}
                    >
                        <span className="flex items-center gap-2">
                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <line x1="12" y1="5" x2="12" y2="19" />
                                <line x1="5" y1="12" x2="19" y2="12" />
                            </svg>
                            <span>Add Product</span>
                        </span>
                    </button>
                </div>
            </div>

            {/* Metrics Chips Bar */}
            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div
                    onClick={() => {
                        setSelectedStatus('all');
                        applyFilters({ status: 'all' });
                    }}
                    className={`cursor-pointer rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs transition-all hover:border-theme-hover ${
                        selectedStatus === 'all' ? 'ring-1 ring-[var(--accentColor)]' : ''
                    }`}
                >
                    <div className="text-xs font-medium text-theme-muted">Total Monitored</div>
                    <div className="mt-1 text-2xl font-bold text-theme-primary">{metrics.total}</div>
                </div>

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

                <div
                    onClick={() => {
                        setSelectedStatus('rejected');
                        applyFilters({ status: 'rejected' });
                    }}
                    className={`cursor-pointer rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs transition-all hover:border-theme-hover ${
                        selectedStatus === 'rejected' ? 'ring-1 ring-rose-500' : ''
                    }`}
                >
                    <div className="text-xs font-medium text-rose-600 dark:text-rose-400">Archived or Rejected</div>
                    <div className="mt-1 text-2xl font-bold text-rose-600 dark:text-rose-400">{metrics.rejected}</div>
                </div>

                <div className="rounded-xl border border-theme bg-theme-surface p-3.5 shadow-2xs">
                    <div className="text-xs font-medium text-theme-muted">Review Required</div>
                    <div className="mt-1 text-2xl font-bold text-theme-secondary">{metrics.due_review}</div>
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

                    {/* True BoF Filter */}
                    <div>
                        <select
                            className={control}
                            value={selectedTrueBof}
                            onChange={(e) => {
                                const val = e.target.value;
                                setSelectedTrueBof(val);
                                applyFilters({ true_bof: val });
                            }}
                            aria-label="Filter by True BoF"
                        >
                            <option value="all">All BoF States</option>
                            {options.true_bof.map((t) => (
                                <option key={t.value} value={t.value}>{t.label}</option>
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
                                    {renderSortTh('Product Name', 'name')}
                                    {renderSortTh('Status', 'status')}
                                    {renderSortTh('Platforms', 'platforms')}
                                    {renderSortTh('Payout', 'payout_cad')}
                                    {renderSortTh('Brand Bidding', 'brand_bidding')}
                                    {renderSortTh('True BoF', 'true_bof')}
                                    {renderSortTh('Geos', 'geos')}
                                    {renderSortTh('Last Reviewed', 'last_reviewed_at')}
                                    <th scope="col" className="px-4 py-3 text-right select-none">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme">
                                {sortedProducts.map((product) => (
                                    <tr
                                        key={product.id}
                                        className="hover:bg-theme-subtle/30 transition-colors"
                                    >
                                        {/* Product Name */}
                                        <td className="px-4 py-3 font-medium text-theme-primary">
                                            <div className="flex flex-col">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditDrawer(product)}
                                                    className="text-left font-semibold hover:text-theme-accent transition-colors cursor-pointer"
                                                >
                                                    {product.name}
                                                </button>
                                                <div className="mt-0.5 flex items-center gap-2 text-xs text-theme-muted">
                                                    <span>{product.page_type || 'General'}</span>
                                                    {product.approval_required && (
                                                        <span className="rounded bg-amber-500/10 px-1.5 py-0.2 text-[10px] font-medium text-amber-700 dark:text-amber-400 border border-amber-500/20">
                                                            Approval Req
                                                        </span>
                                                    )}
                                                    {product.sales_page_url && (
                                                        <a
                                                            href={product.sales_page_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-theme-muted hover:text-theme-accent"
                                                            title="View Sales Page"
                                                        >
                                                            <svg className="w-3.5 h-3.5 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
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
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <select
                                                value={product.status}
                                                onChange={(e) => handleQuickStatusChange(product, e.target.value)}
                                                className={`rounded-md border px-2 py-1 text-xs font-semibold cursor-pointer focus:outline-none ${getStatusBadge(product.status)}`}
                                                aria-label={`Change status for ${product.name}`}
                                            >
                                                <option value="test">Test</option>
                                                <option value="watch">Watch</option>
                                                <option value="rejected">Rejected</option>
                                            </select>
                                        </td>

                                        {/* Platforms */}
                                        <td className="px-4 py-3 text-xs text-theme-secondary">
                                            <div className="flex flex-wrap gap-1 max-w-[180px]">
                                                {product.platforms && product.platforms.length > 0 ? (
                                                    product.platforms.map((p, idx) => (
                                                        <span
                                                            key={idx}
                                                            className="rounded bg-theme-subtle px-1.5 py-0.5 text-[11px] font-medium text-theme-primary border border-theme"
                                                        >
                                                            {p}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-theme-muted">None</span>
                                                )}
                                            </div>
                                        </td>

                                        {/* Payout CAD */}
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <div className="font-semibold text-theme-primary">
                                                {formatCad(product.payout_cad)}
                                            </div>
                                            {product.payout_notes && (
                                                <div className="text-[11px] text-theme-muted truncate max-w-[140px]" title={product.payout_notes}>
                                                    {product.payout_notes}
                                                </div>
                                            )}
                                        </td>

                                        {/* Brand Bidding */}
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <span className={`inline-block rounded-md border px-2 py-0.5 text-xs font-medium ${getBrandBiddingBadge(product.brand_bidding)}`}>
                                                {product.brand_bidding_label}
                                            </span>
                                        </td>

                                        {/* True BoF */}
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <span className={`inline-block rounded-md border px-2 py-0.5 text-xs font-medium ${getTrueBofBadge(product.true_bof)}`}>
                                                {product.true_bof_label}
                                            </span>
                                        </td>

                                        {/* Geos */}
                                        <td className="px-4 py-3 text-xs text-theme-secondary">
                                            <div className="flex flex-wrap gap-1 max-w-[120px]">
                                                {product.geos && product.geos.length > 0 ? (
                                                    product.geos.map((g, idx) => (
                                                        <span
                                                            key={idx}
                                                            className="rounded bg-stone-500/10 dark:bg-stone-800 px-1.5 py-0.5 text-[11px] font-mono text-theme-secondary border border-theme"
                                                        >
                                                            {g}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="text-theme-muted">Global</span>
                                                )}
                                            </div>
                                        </td>

                                        {/* Last Reviewed */}
                                        <td className="px-4 py-3 whitespace-nowrap text-xs text-theme-muted">
                                            <div className="flex items-center gap-1.5">
                                                <span>{product.last_reviewed_formatted || 'Never'}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => handleMarkReviewed(product)}
                                                    title="Mark reviewed now"
                                                    aria-label={`Mark ${product.name} as reviewed now`}
                                                    className="p-1 rounded text-theme-muted hover:text-theme-accent hover:bg-theme-subtle transition-colors cursor-pointer"
                                                >
                                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                        <polyline points="20 6 9 17 4 12" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>

                                        {/* Actions */}
                                        <td className="px-4 py-3 whitespace-nowrap text-right">
                                            <div className="flex items-center justify-end gap-1.5">
                                                {product.sales_page_url ? (
                                                    <Link
                                                        href={`/pages?tab=cloner&clone_url=${encodeURIComponent(product.sales_page_url)}&affiliate_link=${encodeURIComponent(product.affiliate_link || '')}&product_name=${encodeURIComponent(product.name)}`}
                                                        className="px-2 py-1 rounded-md text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 transition-colors flex items-center gap-1 text-xs font-semibold"
                                                        title="Clone Sales Page with 1 Click"
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                            <path d="M3 3v5h5" />
                                                            <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                                                            <path d="M16 21h5v-5" />
                                                        </svg>
                                                        <span>Clone</span>
                                                    </Link>
                                                ) : null}

                                                {product.sales_page_url && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCopyText(product.sales_page_url, 'Sales Page URL')}
                                                        className="p-1.5 rounded-lg text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors cursor-pointer"
                                                        title="Copy Sales Page URL"
                                                    >
                                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <circle cx="12" cy="12" r="10" />
                                                            <line x1="2" y1="12" x2="22" y2="12" />
                                                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1 4-10z" />
                                                        </svg>
                                                    </button>
                                                )}

                                                {product.affiliate_link && (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleCopyText(product.affiliate_link, 'Affiliate Link')}
                                                        className="p-1.5 rounded-lg text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10 transition-colors cursor-pointer"
                                                        title="Copy Affiliate Link"
                                                    >
                                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                                        </svg>
                                                    </button>
                                                )}

                                                <button
                                                    type="button"
                                                    onClick={() => openEditDrawer(product)}
                                                    className="p-1.5 rounded-lg text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors cursor-pointer"
                                                    title="Edit Product"
                                                    aria-label={`Edit ${product.name}`}
                                                >
                                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                        <path d="M12 20h9" />
                                                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                                    </svg>
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() => confirmDelete(product)}
                                                    className="p-1.5 rounded-lg text-theme-secondary hover:text-rose-600 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                                    title="Delete Product"
                                                    aria-label={`Delete ${product.name}`}
                                                >
                                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile Stacked Responsive Cards (< 768px) */}
                    <div className="md:hidden">
                        {/* Mobile Sort Bar */}
                        <div className="px-4 py-2.5 bg-theme-subtle/30 border-b border-theme flex items-center justify-between text-xs">
                            <span className="text-theme-muted font-medium">
                                {sortedProducts.length} {sortedProducts.length === 1 ? 'product' : 'products'}
                            </span>
                            <div className="flex items-center gap-1.5">
                                <span className="text-theme-muted text-[11px]">Sort:</span>
                                <select
                                    value={sortConfig.key ? `${sortConfig.key}:${sortConfig.direction}` : ''}
                                    onChange={(e) => {
                                        if (!e.target.value) {
                                            setSortConfig({ key: null, direction: 'asc' });
                                        } else {
                                            const [k, d] = e.target.value.split(':');
                                            setSortConfig({ key: k, direction: d });
                                        }
                                    }}
                                    className="rounded border border-theme bg-theme-input px-2 py-1 text-xs text-theme-primary focus:outline-none focus:border-theme-accent cursor-pointer"
                                    aria-label="Sort products on mobile"
                                >
                                    <option value="">Default (Recent)</option>
                                    <option value="name:asc">Name (A &rarr; Z)</option>
                                    <option value="name:desc">Name (Z &rarr; A)</option>
                                    <option value="payout_cad:desc">Payout (High &rarr; Low)</option>
                                    <option value="payout_cad:asc">Payout (Low &rarr; High)</option>
                                    <option value="status:asc">Status (Test &gt; Watch)</option>
                                    <option value="last_reviewed_at:asc">Review (Oldest)</option>
                                    <option value="last_reviewed_at:desc">Review (Newest)</option>
                                </select>
                            </div>
                        </div>

                        <div className="divide-y divide-theme">
                            {sortedProducts.map((product) => (
                            <div key={product.id} className="p-4 space-y-3 hover:bg-theme-subtle/20 transition-colors">
                                {/* Card Header: Product name & Quick Status */}
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex-1 min-w-0">
                                        <button
                                            type="button"
                                            onClick={() => openEditDrawer(product)}
                                            className="text-left font-bold text-base text-theme-primary hover:text-theme-accent transition-colors cursor-pointer truncate block"
                                        >
                                            {product.name}
                                        </button>
                                        <div className="mt-1 flex items-center gap-2 text-xs text-theme-muted">
                                            <span className="font-medium text-theme-secondary">{product.page_type || 'General'}</span>
                                            {product.approval_required && (
                                                <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-400 border border-amber-500/20">
                                                    Approval Req
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Status Dropdown with min 44px touch target */}
                                    <div className="shrink-0 min-h-[44px] flex items-center">
                                        <select
                                            value={product.status}
                                            onChange={(e) => handleQuickStatusChange(product, e.target.value)}
                                            className={`rounded-lg border px-3 py-2 text-xs font-semibold cursor-pointer focus:outline-none min-h-[40px] ${getStatusBadge(product.status)}`}
                                            aria-label={`Change status for ${product.name}`}
                                        >
                                            <option value="test">Test</option>
                                            <option value="watch">Watch</option>
                                            <option value="rejected">Rejected</option>
                                        </select>
                                    </div>
                                </div>

                                {/* Metrics & Badges Row */}
                                <div className="grid grid-cols-2 gap-2 text-xs bg-theme-subtle/30 p-2.5 rounded-lg border border-theme">
                                    <div>
                                        <span className="text-[11px] text-theme-muted block">Payout CAD:</span>
                                        <span className="font-bold text-emerald-600 dark:text-emerald-400">
                                            {formatCad(product.payout_cad)}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-[11px] text-theme-muted block">Brand Bidding:</span>
                                        <span className={`inline-block rounded px-1.5 py-0.2 text-[11px] font-medium border ${getBrandBiddingBadge(product.brand_bidding)}`}>
                                            {product.brand_bidding_label}
                                        </span>
                                    </div>
                                    <div className="col-span-2 flex flex-wrap items-center gap-1.5 pt-1 border-t border-theme/50">
                                        <span className="text-[11px] text-theme-muted">Platforms:</span>
                                        {product.platforms && product.platforms.length > 0 ? (
                                            product.platforms.map((p, idx) => (
                                                <span key={idx} className="rounded bg-theme-subtle px-1.5 py-0.2 text-[10px] font-semibold text-theme-primary border border-theme">
                                                    {p}
                                                </span>
                                            ))
                                        ) : (
                                            <span className="text-[11px] text-theme-muted">None</span>
                                        )}
                                    </div>
                                </div>

                                {/* Quick Actions Bar with >= 44x44px Touch Targets */}
                                <div className="flex items-center gap-2 pt-1 flex-wrap">
                                    {/* 1-Click Clone Page */}
                                    {product.sales_page_url ? (
                                        <Link
                                            href={`/pages?tab=cloner&clone_url=${encodeURIComponent(product.sales_page_url)}&affiliate_link=${encodeURIComponent(product.affiliate_link || '')}&product_name=${encodeURIComponent(product.name)}`}
                                            className="flex-1 min-h-[44px] rounded-lg bg-emerald-600 text-white font-semibold text-xs flex items-center justify-center gap-2 hover:bg-emerald-700 transition-colors shadow-2xs"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                <path d="M3 3v5h5" />
                                                <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                                                <path d="M16 21h5v-5" />
                                            </svg>
                                            <span>Clone Page</span>
                                        </Link>
                                    ) : null}

                                    {/* Copy Sales URL */}
                                    {product.sales_page_url && (
                                        <button
                                            type="button"
                                            onClick={() => handleCopyText(product.sales_page_url, 'Sales Page URL')}
                                            className="min-h-[44px] min-w-[44px] rounded-lg border border-theme bg-theme-surface flex items-center justify-center text-theme-secondary hover:text-theme-primary cursor-pointer"
                                            title="Copy Sales Page URL"
                                            aria-label="Copy Sales Page URL"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <circle cx="12" cy="12" r="10" />
                                                <line x1="2" y1="12" x2="22" y2="12" />
                                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                                            </svg>
                                        </button>
                                    )}

                                    {/* Copy Affiliate Link */}
                                    {product.affiliate_link && (
                                        <button
                                            type="button"
                                            onClick={() => handleCopyText(product.affiliate_link, 'Affiliate Link')}
                                            className="min-h-[44px] min-w-[44px] rounded-lg border border-emerald-500/30 bg-emerald-500/10 flex items-center justify-center text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 cursor-pointer"
                                            title="Copy Affiliate Link"
                                            aria-label="Copy Affiliate Link"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                            </svg>
                                        </button>
                                    )}

                                    {/* Edit Button */}
                                    <button
                                        type="button"
                                        onClick={() => openEditDrawer(product)}
                                        className="min-h-[44px] min-w-[44px] rounded-lg border border-theme bg-theme-surface flex items-center justify-center text-theme-secondary hover:text-theme-primary cursor-pointer"
                                        title="Edit Product"
                                        aria-label={`Edit ${product.name}`}
                                    >
                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <path d="M12 20h9" />
                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                        </svg>
                                    </button>

                                    {/* Delete Button */}
                                    <button
                                        type="button"
                                        onClick={() => confirmDelete(product)}
                                        className="min-h-[44px] min-w-[44px] rounded-lg border border-rose-500/20 bg-rose-500/10 flex items-center justify-center text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 cursor-pointer"
                                        title="Delete Product"
                                        aria-label={`Delete ${product.name}`}
                                    >
                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <polyline points="3 6 5 6 21 6" />
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        ))}
                        </div>
                    </div>
                </>
                )}
            </div>

            {/* Slide-over Detail / Edit Form Drawer */}
            {isDrawerOpen && (
                <div
                    className="fixed inset-0 z-50 overflow-hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="drawer-title"
                >
                    {/* Backdrop */}
                    <div
                        className="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
                        onClick={closeDrawer}
                        aria-hidden="true"
                    />

                    <div className="fixed inset-y-0 right-0 max-w-full flex pl-10">
                        <div className="w-screen max-w-xl border-l border-theme bg-theme-surface shadow-2xl flex flex-col">
                            {/* Drawer Header */}
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
                                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <line x1="18" y1="6" x2="6" y2="18" />
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                    </svg>
                                </button>
                            </div>

                            {/* Drawer Body Form */}
                            <form onSubmit={handleFormSubmit} className="flex-1 overflow-y-auto p-6 space-y-6">
                                {/* Section 1: Core Info */}
                                <div className="space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-theme-accent border-b border-theme pb-1.5">
                                        Core Info
                                    </h3>

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

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Classification Status" error={form.errors.status}>
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

                                        <div className="flex items-center pt-6">
                                            <label className="flex items-center gap-2.5 text-sm font-medium text-theme-primary cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.approval_required}
                                                    onChange={(e) => form.setData('approval_required', e.target.checked)}
                                                    className="w-4 h-4 rounded border-theme text-teal-600 focus:ring-[var(--accentFocusRing)]"
                                                />
                                                <span>Affiliate Manager Approval Required</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                {/* Section 2: Financial */}
                                <div className="space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-theme-accent border-b border-theme pb-1.5">
                                        Financial
                                    </h3>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Payout (CAD$)" error={form.errors.payout_cad}>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                className={control}
                                                placeholder="e.g. 120.00"
                                                value={form.data.payout_cad}
                                                onChange={(e) => form.setData('payout_cad', e.target.value)}
                                            />
                                        </Field>

                                        <Field label="Payout Structure Notes" error={form.errors.payout_notes}>
                                            <input
                                                type="text"
                                                className={control}
                                                placeholder="e.g. Tiered: $40/1, $100/3, $150/6"
                                                value={form.data.payout_notes}
                                                onChange={(e) => form.setData('payout_notes', e.target.value)}
                                            />
                                        </Field>
                                    </div>
                                </div>

                                {/* Section 3: Page and Tracking Fit */}
                                <div className="space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-theme-accent border-b border-theme pb-1.5">
                                        Page and Tracking Fit
                                    </h3>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Page Type" error={form.errors.page_type}>
                                            <select
                                                className={control}
                                                value={form.data.page_type}
                                                onChange={(e) => form.setData('page_type', e.target.value)}
                                            >
                                                {options.page_types.map((pt) => (
                                                    <option key={pt} value={pt}>{pt}</option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field label="Platforms (comma separated)" error={form.errors.platforms}>
                                            <input
                                                type="text"
                                                className={control}
                                                placeholder="SmartAdv, ClickBank, MaxWeb"
                                                value={form.data.platforms}
                                                onChange={(e) => form.setData('platforms', e.target.value)}
                                            />
                                        </Field>
                                    </div>

                                    <Field label="Target Geos (comma separated country codes)" error={form.errors.geos}>
                                        <input
                                            type="text"
                                            className={control}
                                            placeholder="US, CA, UK, AU"
                                            value={form.data.geos}
                                            onChange={(e) => form.setData('geos', e.target.value)}
                                        />
                                    </Field>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Sales Page URL" error={form.errors.sales_page_url}>
                                            <input
                                                type="url"
                                                className={control}
                                                placeholder="https://officialoffer.com"
                                                value={form.data.sales_page_url}
                                                onChange={(e) => form.setData('sales_page_url', e.target.value)}
                                            />
                                        </Field>

                                        <Field label="Affiliate Tracking Link" error={form.errors.affiliate_link}>
                                            <input
                                                type="url"
                                                className={control}
                                                placeholder="https://track.network.com/aff_c?..."
                                                value={form.data.affiliate_link}
                                                onChange={(e) => form.setData('affiliate_link', e.target.value)}
                                            />
                                        </Field>
                                    </div>
                                </div>

                                {/* Section 4: Brand Bidding and BoF Fit */}
                                <div className="space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-theme-accent border-b border-theme pb-1.5">
                                        Brand Bidding and BoF Fit
                                    </h3>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <Field label="Brand Bidding Policy" error={form.errors.brand_bidding}>
                                            <select
                                                className={control}
                                                value={form.data.brand_bidding}
                                                onChange={(e) => form.setData('brand_bidding', e.target.value)}
                                            >
                                                {options.brand_bidding.map((bb) => (
                                                    <option key={bb.value} value={bb.value}>{bb.label}</option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field label="True BoF Intent" error={form.errors.true_bof}>
                                            <select
                                                className={control}
                                                value={form.data.true_bof}
                                                onChange={(e) => form.setData('true_bof', e.target.value)}
                                            >
                                                {options.true_bof.map((tb) => (
                                                    <option key={tb.value} value={tb.value}>{tb.label}</option>
                                                ))}
                                            </select>
                                        </Field>
                                    </div>

                                    <Field label="Suggested Negative Keywords" error={form.errors.negatives}>
                                        <textarea
                                            rows="2"
                                            className={control}
                                            placeholder="login, sign in, refund, scam, amazon, ingredients, coupon code"
                                            value={form.data.negatives}
                                            onChange={(e) => form.setData('negatives', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                {/* Section 5: Notes and Observations */}
                                <div className="space-y-4">
                                    <h3 className="text-xs font-semibold uppercase tracking-wider text-theme-accent border-b border-theme pb-1.5">
                                        Notes and Observations
                                    </h3>

                                    <Field label="Search Demand and Keyword Intent Notes" error={form.errors.demand_notes}>
                                        <textarea
                                            rows="2"
                                            className={control}
                                            placeholder="Observations on Google Trends, brand volume stability, CPC estimates, search partner presence..."
                                            value={form.data.demand_notes}
                                            onChange={(e) => form.setData('demand_notes', e.target.value)}
                                        />
                                    </Field>

                                    <Field label="General Research Observations" error={form.errors.notes}>
                                        <textarea
                                            rows="3"
                                            className={control}
                                            placeholder="Affiliate manager contacts, EPC performance, conversion rate trends, seasonal notes..."
                                            value={form.data.notes}
                                            onChange={(e) => form.setData('notes', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                {/* Drawer Form Footer Buttons */}
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
