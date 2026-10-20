import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { button, quiet } from '../../../Components/Layout';

export default function SavedPagesTable({ pages = [], onStartGenerator }) {
    const [previewingPage, setPreviewingPage] = useState(null);
    const [previewHtml, setPreviewHtml] = useState('');
    const [isLoadingPreview, setIsLoadingPreview] = useState(false);

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
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-theme bg-theme-card/60 text-theme-secondary uppercase tracking-wider font-semibold">
                                <tr>
                                    <th className="px-4 py-3">Page Name</th>
                                    <th className="px-4 py-3">Linked Offer / Track</th>
                                    <th className="px-4 py-3">Strategy / Format</th>
                                    <th className="px-4 py-3">Updated</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme">
                                {pages.map((p) => (
                                    <tr key={p.id} className="hover:bg-theme-subtle/40 transition-colors">
                                        <td className="px-4 py-3 font-semibold text-theme-primary">
                                            {p.name}
                                        </td>
                                        <td className="px-4 py-3 text-theme-secondary">
                                            {p.product_name || 'Standalone Offer'}
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
                                                    className="py-1 px-2 text-[11px] text-red-600 hover:text-red-700 dark:text-red-400 hover:underline"
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
