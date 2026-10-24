import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { button, quiet } from '../../../Components/Layout';

export default function SavedPagesTable({ pages = [], onStartGenerator }) {
    const [previewingPage, setPreviewingPage] = useState(null);
    const [previewHtml, setPreviewHtml] = useState('');
    const [isLoadingPreview, setIsLoadingPreview] = useState(false);
    const [retryingId, setRetryingId] = useState(null);

    // Auto-poll if any page is in processing state
    React.useEffect(() => {
        const hasProcessing = pages.some((p) => p.status === 'processing');
        if (!hasProcessing) return;

        const interval = setInterval(() => {
            router.reload({
                only: ['pages', 'currentPage', 'jobStatus'],
                preserveState: true,
                preserveScroll: true,
            });
        }, 2500);

        return () => clearInterval(interval);
    }, [pages]);

    function handleRetry(p) {
        setRetryingId(p.id);
        router.post(`/pages/${p.id}/retry`, {}, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setRetryingId(null),
        });
    }

    // Table Sorting State
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

    const sortedPages = React.useMemo(() => {
        if (!sortConfig.key) return pages;

        return [...pages].sort((a, b) => {
            let aVal = a[sortConfig.key] || '';
            let bVal = b[sortConfig.key] || '';

            if (sortConfig.key === 'updated_at') {
                const aTime = a.updated_at ? new Date(a.updated_at).getTime() : 0;
                const bTime = b.updated_at ? new Date(b.updated_at).getTime() : 0;
                return sortConfig.direction === 'asc' ? aTime - bTime : bTime - aTime;
            }

            if (typeof aVal === 'string' && typeof bVal === 'string') {
                const cmp = aVal.localeCompare(bVal, undefined, { numeric: true, sensitivity: 'base' });
                return sortConfig.direction === 'asc' ? cmp : -cmp;
            }

            if (aVal < bVal) return sortConfig.direction === 'asc' ? -1 : 1;
            if (aVal > bVal) return sortConfig.direction === 'asc' ? 1 : -1;
            return 0;
        });
    }, [pages, sortConfig]);

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

    function handleOpenPreview(page) {
        setPreviewingPage(page);
        setIsLoadingPreview(true);
        // Fetch preview from export endpoint or load direct
        fetch(`/pages/${page.id}/export`, { credentials: 'same-origin' })
            .then((res) => res.text())
            .then((html) => {
                setPreviewHtml(html);
                setIsLoadingPreview(false);
            })
            .catch(() => {
                setPreviewHtml('<p style="padding:20px;font-family:sans-serif;">Unable to load preview.</p>');
                setIsLoadingPreview(false);
            });
    }

    function handleDelete(id, name) {
        if (window.confirm(`Are you sure you want to remove "${name}"?`)) {
            router.delete(`/pages/${id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h3 className="text-lg font-semibold text-theme-primary">
                        Saved Presell Pages &amp; Landers
                    </h3>
                    <p className="text-xs text-theme-secondary">
                        Campaign pages deployed or linked to active Google Ads bottom of funnel tracks.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={onStartGenerator}
                    className={`${button} flex items-center gap-1.5 text-xs py-2 px-3 font-semibold`}
                >
                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    <span>Generate New Presell Page</span>
                </button>
            </div>

            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-xs">
                {pages.length === 0 ? (
                    <div className="p-8 text-center space-y-3">
                        <div className="w-12 h-12 rounded-full bg-theme-subtle border border-theme flex items-center justify-center mx-auto text-theme-accent">
                            <svg className="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                        </div>
                        <h4 className="text-sm font-semibold text-theme-primary">No saved presell pages yet</h4>
                        <p className="text-xs text-theme-muted max-w-sm mx-auto">
                            Generate auction-unique presell landers with instant mental triggers, Google Ads compliance overlays, and attribution tracking.
                        </p>
                        <button
                            type="button"
                            onClick={onStartGenerator}
                            className={`${button} text-xs py-2 px-4`}
                        >
                            Launch Presell Studio
                        </button>
                    </div>
                ) : (
                    <>
                        {/* Desktop Table View (>= 768px) */}
                        <div className="hidden md:block overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b border-theme bg-theme-card/60 text-theme-secondary uppercase tracking-wider font-semibold">
                                    <tr>
                                        {renderSortTh('Page Name', 'name')}
                                        {renderSortTh('Linked Offer / Track', 'product_name')}
                                        {renderSortTh('Assigned Campaign', 'campaign_name')}
                                        {renderSortTh('Strategy / Format', 'page_type')}
                                        {renderSortTh('Updated', 'updated_at')}
                                        <th className="px-4 py-3 text-right select-none">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-theme">
                                    {sortedPages.map((p) => (
                                        <tr key={p.id} className="hover:bg-theme-subtle/40 transition-colors">
                                            <td className="px-4 py-3 font-semibold text-theme-primary">
                                                <div className="flex items-center gap-2">
                                                    <span>{p.name}</span>
                                                    {p.status === 'processing' && (
                                                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30 animate-pulse">
                                                            <span className="w-1.5 h-1.5 rounded-full bg-amber-500" />
                                                            Processing...
                                                        </span>
                                                    )}
                                                    {p.status === 'failed' && (
                                                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-700 dark:text-rose-300 border border-rose-500/30">
                                                            Failed
                                                        </span>
                                                    )}
                                                </div>
                                                {p.status === 'failed' && (p.error || p.failure_reason) && (
                                                    <div className="text-[11px] text-rose-600 dark:text-rose-400 font-mono mt-1 font-normal max-w-sm truncate" title={p.error || p.failure_reason}>
                                                        Reason: {p.error || p.failure_reason}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-theme-secondary">
                                                {p.track_id ? (
                                                    <Link
                                                        href={`/tracks/${p.track_id}`}
                                                        className="font-medium text-theme-primary hover:text-theme-accent hover:underline inline-flex items-center gap-1"
                                                    >
                                                        <span>{p.product_name || `Track #${p.track_id}`}</span>
                                                        <svg className="w-3 h-3 text-theme-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                                            <polyline points="15 3 21 3 21 9" />
                                                            <line x1="10" y1="14" x2="21" y2="3" />
                                                        </svg>
                                                    </Link>
                                                ) : (
                                                    <span>{p.product_name || 'Standalone Offer'}</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {p.campaign_name ? (
                                                    <div>
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                                            <svg className="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                                                <polyline points="20 6 9 17 4 12" />
                                                            </svg>
                                                            <span>{p.campaign_name}</span>
                                                        </span>
                                                        {p.campaign_account_name && (
                                                            <span className="block text-[10px] text-theme-muted mt-0.5 font-mono">
                                                                {p.campaign_account_name}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-theme-muted text-[11px]">Unassigned</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-theme-card border border-theme text-theme-secondary">
                                                    {p.page_type}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-theme-muted">
                                                {p.updated_at ? new Date(p.updated_at).toLocaleDateString() : 'Recent'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="inline-flex items-center gap-1.5">
                                                    {p.status === 'failed' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRetry(p)}
                                                            disabled={retryingId === p.id}
                                                            className="py-1 px-2.5 rounded bg-rose-600 hover:bg-rose-700 text-white text-[11px] font-semibold flex items-center gap-1 shadow-2xs cursor-pointer disabled:opacity-50"
                                                            title="Retry failed page generation"
                                                        >
                                                            <svg className={`w-3 h-3 ${retryingId === p.id ? 'animate-spin' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                                <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                                <path d="M3 3v5h5" />
                                                            </svg>
                                                            <span>{retryingId === p.id ? 'Retrying...' : 'Retry'}</span>
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => handleOpenPreview(p)}
                                                        className={`${quiet} py-1 px-2.5 text-[11px]`}
                                                    >
                                                        Preview
                                                    </button>
                                                    <Link
                                                        href={`/pages/${p.id}/edit`}
                                                        className={`${quiet} py-1 px-2.5 text-[11px]`}
                                                    >
                                                        Edit
                                                    </Link>
                                                    <a
                                                        href={`/pages/${p.id}/export`}
                                                        className={`${quiet} py-1 px-2.5 text-[11px]`}
                                                        download={`${p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.html`}
                                                    >
                                                        Export
                                                    </a>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDelete(p.id, p.name)}
                                                        className="py-1 px-2 text-[11px] text-red-600 hover:text-red-700 dark:text-red-400 hover:underline cursor-pointer"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile Stacked Responsive Cards (< 768px) */}
                        <div className="md:hidden divide-y divide-theme">
                            {sortedPages.map((p) => (
                                <div key={p.id} className="p-4 space-y-3 hover:bg-theme-subtle/20 transition-colors">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2">
                                                <h4 className="font-bold text-sm text-theme-primary truncate">
                                                    {p.name}
                                                </h4>
                                                {p.status === 'processing' && (
                                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30 shrink-0 animate-pulse">
                                                        Processing...
                                                    </span>
                                                )}
                                                {p.status === 'failed' && (
                                                    <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/15 text-rose-700 dark:text-rose-300 border border-rose-500/30 shrink-0">
                                                        Failed
                                                    </span>
                                                )}
                                            </div>
                                            {p.status === 'failed' && (p.error || p.failure_reason) && (
                                                <div className="text-[11px] text-rose-600 dark:text-rose-400 font-mono mt-1 font-normal break-words">
                                                    Reason: {p.error || p.failure_reason}
                                                </div>
                                            )}
                                            <div className="mt-0.5 text-xs text-theme-muted">
                                                {p.track_id ? (
                                                    <Link
                                                        href={`/tracks/${p.track_id}`}
                                                        className="text-theme-primary hover:text-theme-accent hover:underline font-medium"
                                                    >
                                                        {p.product_name || `Track #${p.track_id}`}
                                                    </Link>
                                                ) : (
                                                    <span>{p.product_name || 'Standalone Offer'}</span>
                                                )}
                                            </div>
                                        </div>
                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-theme-card border border-theme text-theme-secondary shrink-0">
                                            {p.page_type}
                                        </span>
                                    </div>

                                    {p.campaign_name && (
                                        <div className="text-xs">
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                                <span>Campaign: {p.campaign_name}</span>
                                            </span>
                                        </div>
                                    )}

                                    <div className="text-[11px] text-theme-muted">
                                        Updated: {p.updated_at ? new Date(p.updated_at).toLocaleDateString() : 'Recent'}
                                    </div>

                                    {/* Mobile Touch Actions (min 44px height) */}
                                    <div className="grid grid-cols-2 gap-2 pt-1">
                                        {p.status === 'failed' && (
                                            <button
                                                type="button"
                                                onClick={() => handleRetry(p)}
                                                disabled={retryingId === p.id}
                                                className="col-span-2 min-h-[44px] rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer disabled:opacity-50"
                                            >
                                                <svg className={`w-4 h-4 ${retryingId === p.id ? 'animate-spin' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                    <path d="M3 3v5h5" />
                                                </svg>
                                                <span>{retryingId === p.id ? 'Retrying...' : 'Retry Generation'}</span>
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => handleOpenPreview(p)}
                                            className="min-h-[44px] rounded-lg border border-theme bg-theme-surface text-xs font-semibold text-theme-primary flex items-center justify-center gap-1.5 hover:bg-theme-subtle"
                                        >
                                            <svg className="w-4 h-4 text-theme-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                            <span>Live Preview</span>
                                        </button>

                                        <a
                                            href={`/pages/${p.id}/export`}
                                            download={`${p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.html`}
                                            className="min-h-[44px] rounded-lg bg-[var(--accentColor)] text-white text-xs font-semibold flex items-center justify-center gap-1.5 hover:bg-[var(--accentHoverColor)] shadow-2xs"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="7 10 12 15 17 10" />
                                                <line x1="12" y1="15" x2="12" y2="3" />
                                            </svg>
                                            <span>Export HTML</span>
                                        </a>

                                        <Link
                                            href={`/pages/${p.id}/edit`}
                                            className="min-h-[44px] rounded-lg border border-theme bg-theme-surface text-xs font-semibold text-theme-secondary flex items-center justify-center hover:text-theme-primary"
                                        >
                                            Edit
                                        </Link>

                                        <button
                                            type="button"
                                            onClick={() => handleDelete(p.id, p.name)}
                                            className="min-h-[44px] rounded-lg border border-rose-500/20 bg-rose-500/10 text-xs font-semibold text-rose-600 dark:text-rose-400 flex items-center justify-center hover:bg-rose-500/20"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>

            {/* Quick Preview Modal */}
            {previewingPage && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-4xl h-[85vh] rounded-xl border border-theme bg-theme-surface shadow-2xl flex flex-col overflow-hidden">
                        <div className="p-4 border-b border-theme bg-theme-card flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-bold text-theme-primary">{previewingPage.name}</h3>
                                <span className="text-xs text-theme-muted">{previewingPage.product_name} &bull; {previewingPage.page_type}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <a
                                    href={`/pages/${previewingPage.id}/export`}
                                    className={`${button} text-xs py-1.5 px-3`}
                                    download
                                >
                                    Download HTML
                                </a>
                                <button
                                    type="button"
                                    onClick={() => setPreviewingPage(null)}
                                    className="p-1 rounded-md text-theme-muted hover:text-theme-primary text-xl font-bold"
                                >
                                    &times;
                                </button>
                            </div>
                        </div>

                        <div className="flex-1 bg-stone-100 dark:bg-stone-900 relative">
                            {isLoadingPreview ? (
                                <div className="absolute inset-0 flex items-center justify-center text-theme-muted text-xs">
                                    Loading preview...
                                </div>
                            ) : (
                                <iframe
                                    title="Page Preview"
                                    srcDoc={previewHtml}
                                    sandbox="allow-scripts allow-forms"
                                    className="w-full h-full border-0 bg-white"
                                />
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
