import React, { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import {
    ResponsiveContainer,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    Tooltip,
    CartesianGrid,
} from 'recharts';

const COUNTRY_FLAGS = {
    US: '🇺🇸',
    CA: '🇨🇦',
    GB: '🇬🇧',
    AU: '🇦🇺',
    NZ: '🇳🇿',
    IE: '🇮🇪',
    DE: '🇩🇪',
    FR: '🇫🇷',
};

const MONTH_NAMES = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
];

export default function SearchVolumeSection({ product, onToast }) {
    if (!product) return null;

    const [isRefreshing, setIsRefreshing] = useState(false);
    const [openPopover, setOpenPopover] = useState(null); // 'volume' | 'competition' | 'bid' | 'trend' | 'keyword' | null

    const togglePopover = (name) => {
        setOpenPopover((prev) => (prev === name ? null : name));
    };

    const handleRefreshVolume = () => {
        if (isRefreshing) return;
        setIsRefreshing(true);
        router.post(
            `/watching-products/${product.id}/refresh-volume`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (onToast) onToast('Search volume refreshed via Google Ads Keyword Planner!');
                },
                onError: (err) => {
                    const msg = err?.error || 'Failed to refresh search volume.';
                    if (onToast) onToast(msg);
                },
                onFinish: () => {
                    setIsRefreshing(false);
                },
            }
        );
    };

    const searchVolumeByGeo = product.search_volume_by_geo && typeof product.search_volume_by_geo === 'object'
        ? product.search_volume_by_geo
        : {};

    const geoEntries = Object.entries(searchVolumeByGeo);

    // Format volume number
    const formatVol = (val, isRange) => {
        if (val === null || val === undefined || isNaN(val)) return '—';
        const num = Number(val);
        const prefix = isRange ? '~' : '';
        if (num >= 1000000) return `${prefix}${(num / 1000000).toFixed(1).replace(/\.0$/, '')}M`;
        if (num >= 1000) return `${prefix}${(num / 1000).toFixed(1).replace(/\.0$/, '')}k`;
        return `${prefix}${num.toLocaleString()}`;
    };

    const formatBid = (micros) => {
        if (micros === null || micros === undefined || isNaN(micros)) return '—';
        const dollars = Number(micros) / 1000000;
        return `$${dollars.toFixed(2)}`;
    };

    const getCompetitionBadge = (comp) => {
        const c = String(comp || 'UNSPECIFIED').toUpperCase();
        if (c === 'LOW') {
            return (
                <span className="inline-flex items-center rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                    Low
                </span>
            );
        }
        if (c === 'MEDIUM') {
            return (
                <span className="inline-flex items-center rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                    Medium
                </span>
            );
        }
        if (c === 'HIGH') {
            return (
                <span className="inline-flex items-center rounded-md border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400">
                    High
                </span>
            );
        }
        return (
            <span className="inline-flex items-center rounded-md border border-theme bg-theme-subtle px-2 py-0.5 text-[11px] font-semibold text-theme-muted">
                Unspecified
            </span>
        );
    };

    // Calculate aggregated 12-month trend across all geos
    const trendData = useMemo(() => {
        const monthMap = new Map();

        geoEntries.forEach(([geo, geoData]) => {
            const monthlyList = Array.isArray(geoData?.monthly) ? geoData.monthly : [];
            monthlyList.forEach((item) => {
                const year = item.year;
                const month = item.month;
                const searches = item.searches !== null && item.searches !== undefined ? Number(item.searches) : 0;
                if (!year || !month) return;

                const key = `${year}-${String(month).padStart(2, '0')}`;
                if (!monthMap.has(key)) {
                    const monthIndex = Number(month) - 1;
                    const label = `${MONTH_NAMES[monthIndex] || month} ${String(year).slice(-2)}`;
                    monthMap.set(key, { key, label, total: 0, byGeo: {} });
                }

                const rec = monthMap.get(key);
                rec.total += searches;
                rec.byGeo[geo] = searches;
            });
        });

        const sorted = Array.from(monthMap.values()).sort((a, b) => a.key.localeCompare(b.key));
        return sorted.slice(-12);
    }, [geoEntries]);

    const targetKeyword = product.search_volume_keyword || product.name;
    const isCustomKeyword = Boolean(product.search_volume_keyword);

    return (
        <div className="rounded-2xl border border-theme bg-theme-surface p-5 sm:p-6 shadow-xs">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-theme pb-4">
                <div className="flex items-center gap-2">
                    <h3 className="font-bold text-base text-theme-primary">
                        Google Search Volume (Keyword Planner)
                    </h3>
                    <div className="relative">
                        <button
                            type="button"
                            onClick={() => togglePopover('volume')}
                            className="p-1 text-theme-muted hover:text-theme-accent cursor-pointer rounded transition-colors"
                            aria-label="Search volume info"
                        >
                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="16" x2="12" y2="12" />
                                <line x1="12" y1="8" x2="12.01" y2="8" />
                            </svg>
                        </button>
                        {openPopover === 'volume' && (
                            <div className="absolute left-0 top-full mt-2 z-30 w-72 rounded-xl border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-xl">
                                <div className="flex justify-between items-center mb-1 font-semibold text-theme-primary">
                                    <span>Keyword Planner Volume</span>
                                    <button
                                        type="button"
                                        onClick={() => setOpenPopover(null)}
                                        className="text-theme-muted hover:text-theme-primary text-xs"
                                    >
                                        ✕
                                    </button>
                                </div>
                                Average monthly search queries generated from official Google Ads Keyword Planner metrics for this product's buyer target countries.
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={handleRefreshVolume}
                        disabled={isRefreshing}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-theme-accent bg-theme-accent/10 px-3 py-1.5 text-xs font-bold text-theme-accent hover:bg-theme-accent/20 transition-colors disabled:opacity-50 cursor-pointer"
                    >
                        <svg
                            className={`w-3.5 h-3.5 ${isRefreshing ? 'animate-spin' : ''}`}
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.5"
                        >
                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                            <path d="M3 3v5h5" />
                            <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                            <path d="M16 21h5v-5" />
                        </svg>
                        <span>{isRefreshing ? 'Refreshing...' : 'Refresh volume now'}</span>
                    </button>
                </div>
            </div>

            {/* Error Banner if any */}
            {product.search_volume_error && (
                <div className="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-300 flex items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <svg className="w-4 h-4 text-rose-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <span>{product.search_volume_error}</span>
                    </div>
                </div>
            )}

            {/* Summary Highlights */}
            <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                {/* Total Monthly Searches */}
                <div className="rounded-xl border border-theme bg-theme-subtle/40 p-3">
                    <div className="text-[10px] uppercase font-bold tracking-wider text-theme-muted mb-0.5">
                        Total Monthly Volume
                    </div>
                    <div className="text-xl font-black text-theme-primary flex items-baseline gap-1">
                        <span>{formatVol(product.search_volume_total, product.search_volume_is_range)}</span>
                        {product.search_volume_is_range && (
                            <span className="text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                                (range)
                            </span>
                        )}
                    </div>
                    <div className="text-[10px] text-theme-muted mt-0.5">
                        Sum across buyer countries
                    </div>
                </div>

                {/* Target Keyword */}
                <div className="rounded-xl border border-theme bg-theme-subtle/40 p-3">
                    <div className="text-[10px] uppercase font-bold tracking-wider text-theme-muted mb-0.5 flex items-center justify-between">
                        <span>Queried Keyword</span>
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => togglePopover('keyword')}
                                className="text-theme-muted hover:text-theme-accent cursor-pointer"
                                aria-label="Keyword override info"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="16" x2="12" y2="12" />
                                    <line x1="12" y1="8" x2="12.01" y2="8" />
                                </svg>
                            </button>
                            {openPopover === 'keyword' && (
                                <div className="absolute right-0 top-full mt-2 z-30 w-64 rounded-xl border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-xl">
                                    <div className="flex justify-between items-center mb-1 font-semibold text-theme-primary">
                                        <span>Target Keyword</span>
                                        <button
                                            type="button"
                                            onClick={() => setOpenPopover(null)}
                                            className="text-theme-muted hover:text-theme-primary text-xs"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    Default is product name. You can override it with a specific brand or high-intent root term in the Edit drawer.
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="font-bold text-xs text-theme-primary truncate" title={targetKeyword}>
                        {targetKeyword}
                    </div>
                    <div className="mt-0.5">
                        {isCustomKeyword ? (
                            <span className="inline-flex items-center rounded bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 border border-indigo-500/30 px-1.5 py-0.2 text-[9px] font-bold">
                                Custom Override
                            </span>
                        ) : (
                            <span className="text-[10px] text-theme-muted">Default (Product Name)</span>
                        )}
                    </div>
                </div>

                {/* Last Checked */}
                <div className="rounded-xl border border-theme bg-theme-subtle/40 p-3">
                    <div className="text-[10px] uppercase font-bold tracking-wider text-theme-muted mb-0.5">
                        Last Refreshed
                    </div>
                    <div className="font-bold text-xs text-theme-primary">
                        {product.search_volume_checked_at
                            ? new Date(product.search_volume_checked_at).toLocaleDateString(undefined, {
                                  year: 'numeric',
                                  month: 'short',
                                  day: 'numeric',
                              })
                            : 'Never'}
                    </div>
                    <div className="text-[10px] text-theme-muted mt-0.5">
                        Refreshed weekly via cron
                    </div>
                </div>

                {/* Tracked Geos */}
                <div className="rounded-xl border border-theme bg-theme-subtle/40 p-3">
                    <div className="text-[10px] uppercase font-bold tracking-wider text-theme-muted mb-0.5">
                        Buyer Geos
                    </div>
                    <div className="font-bold text-xs text-theme-primary flex items-center gap-1 flex-wrap">
                        {geoEntries.length > 0 ? (
                            geoEntries.map(([geo]) => (
                                <span key={geo} className="inline-flex items-center gap-0.5">
                                    <span>{COUNTRY_FLAGS[geo] || '🌐'}</span>
                                    <span>{geo}</span>
                                </span>
                            ))
                        ) : (
                            <span>US, CA</span>
                        )}
                    </div>
                    <div className="text-[10px] text-theme-muted mt-0.5">
                        {geoEntries.length} {geoEntries.length === 1 ? 'country' : 'countries'} analyzed
                    </div>
                </div>
            </div>

            {/* Per-Geo Table */}
            <div className="mt-6">
                <div className="flex items-center justify-between mb-2">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-theme-secondary">
                        Country Breakdown
                    </h4>
                    <div className="flex items-center gap-2">
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => togglePopover('competition')}
                                className="inline-flex items-center gap-1 text-[11px] text-theme-muted hover:text-theme-accent cursor-pointer"
                                aria-label="Competition info"
                            >
                                <span>Competition</span>
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="16" x2="12" y2="12" />
                                    <line x1="12" y1="8" x2="12.01" y2="8" />
                                </svg>
                            </button>
                            {openPopover === 'competition' && (
                                <div className="absolute right-0 top-full mt-2 z-30 w-72 rounded-xl border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-xl">
                                    <div className="flex justify-between items-center mb-1 font-semibold text-theme-primary">
                                        <span>Competition & Index</span>
                                        <button
                                            type="button"
                                            onClick={() => setOpenPopover(null)}
                                            className="text-theme-muted hover:text-theme-primary text-xs"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    Relative advertiser competition level (LOW, MEDIUM, HIGH) and competition index score (0-100) indicating the density of advertisers bidding on this keyword in the country.
                                </div>
                            )}
                        </div>

                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => togglePopover('bid')}
                                className="inline-flex items-center gap-1 text-[11px] text-theme-muted hover:text-theme-accent cursor-pointer"
                                aria-label="Bid range info"
                            >
                                <span>Top-of-Page Bids</span>
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="16" x2="12" y2="12" />
                                    <line x1="12" y1="8" x2="12.01" y2="8" />
                                </svg>
                            </button>
                            {openPopover === 'bid' && (
                                <div className="absolute right-0 top-full mt-2 z-30 w-72 rounded-xl border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-xl">
                                    <div className="flex justify-between items-center mb-1 font-semibold text-theme-primary">
                                        <span>Top of Page Bids (USD)</span>
                                        <button
                                            type="button"
                                            onClick={() => setOpenPopover(null)}
                                            className="text-theme-muted hover:text-theme-primary text-xs"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    Estimated CPC range: Low range (20th percentile) to High range (80th percentile) historically required to appear at the top of the Google Search results page.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-theme">
                    <table className="min-w-full divide-y divide-theme text-left text-xs">
                        <thead className="bg-theme-subtle/50 text-[11px] font-semibold uppercase tracking-wider text-theme-muted">
                            <tr>
                                <th scope="col" className="px-3 py-2.5">Country</th>
                                <th scope="col" className="px-3 py-2.5 text-right">Avg Monthly Searches</th>
                                <th scope="col" className="px-3 py-2.5 text-center">Competition</th>
                                <th scope="col" className="px-3 py-2.5 text-right">Top of Page Bid (Low - High)</th>
                                <th scope="col" className="px-3 py-2.5 text-center">Data Type</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-theme bg-theme-surface">
                            {geoEntries.length === 0 ? (
                                <tr>
                                    <td colSpan="5" className="px-3 py-4 text-center text-theme-muted">
                                        No keyword search volume data available. Click "Refresh volume now" to query the Google Ads Keyword Planner.
                                    </td>
                                </tr>
                            ) : (
                                geoEntries.map(([geo, data]) => {
                                    const avgFormatted = formatVol(data.avg_monthly_searches, data.is_range);
                                    const rangeText = data.min !== undefined && data.max !== undefined && data.min !== null && data.max !== null
                                        ? `${Number(data.min).toLocaleString()} - ${Number(data.max).toLocaleString()}`
                                        : null;

                                    return (
                                        <tr key={geo} className="hover:bg-theme-subtle/30 transition-colors">
                                            <td className="px-3 py-2.5 whitespace-nowrap font-semibold text-theme-primary">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-base">{COUNTRY_FLAGS[geo] || '🌐'}</span>
                                                    <span>{geo}</span>
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-right font-bold text-theme-primary">
                                                <div>{avgFormatted}</div>
                                                {rangeText && (
                                                    <div className="text-[10px] font-normal text-theme-muted">
                                                        Range: {rangeText}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-center">
                                                <div className="flex flex-col items-center gap-0.5">
                                                    {getCompetitionBadge(data.competition)}
                                                    {data.competition_index !== null && data.competition_index !== undefined && (
                                                        <span className="text-[10px] text-theme-muted font-mono">
                                                            Index: {data.competition_index}/100
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-right font-mono text-theme-secondary">
                                                {data.low_bid_micros || data.high_bid_micros ? (
                                                    <span>
                                                        {formatBid(data.low_bid_micros)} – {formatBid(data.high_bid_micros)}
                                                    </span>
                                                ) : (
                                                    <span className="text-theme-muted">—</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 whitespace-nowrap text-center">
                                                {data.is_range ? (
                                                    <span className="inline-flex items-center rounded bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30 px-2 py-0.5 text-[10px] font-semibold">
                                                        Range Bucket
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 px-2 py-0.5 text-[10px] font-semibold">
                                                        Exact
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* 12-Month Trend Chart */}
            {trendData.length > 0 && (
                <div className="mt-6 pt-5 border-t border-theme">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-theme-secondary">
                                12-Month Search Volume Trend
                            </h4>
                            <div className="relative">
                                <button
                                    type="button"
                                    onClick={() => togglePopover('trend')}
                                    className="text-theme-muted hover:text-theme-accent cursor-pointer"
                                    aria-label="12-month trend info"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <line x1="12" y1="16" x2="12" y2="12" />
                                        <line x1="12" y1="8" x2="12.01" y2="8" />
                                    </svg>
                                </button>
                                {openPopover === 'trend' && (
                                    <div className="absolute left-0 top-full mt-2 z-30 w-72 rounded-xl border border-theme bg-theme-surface p-3 text-xs text-theme-secondary shadow-xl">
                                        <div className="flex justify-between items-center mb-1 font-semibold text-theme-primary">
                                            <span>Monthly Trend Breakdown</span>
                                            <button
                                                type="button"
                                                onClick={() => setOpenPopover(null)}
                                                className="text-theme-muted hover:text-theme-primary text-xs"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                        Total monthly searches over the past 12 months returned by Google Keyword Planner, combined across all target buyer countries.
                                    </div>
                                )}
                            </div>
                        </div>
                        <span className="text-[11px] text-theme-muted">
                            Past 12 months • Combined geos
                        </span>
                    </div>

                    <div className="h-44 w-full">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={trendData} margin={{ top: 10, right: 10, left: -15, bottom: 0 }}>
                                <CartesianGrid strokeDasharray="3 3" opacity={0.15} vertical={false} />
                                <XAxis
                                    dataKey="label"
                                    tick={{ fontSize: 10, fill: 'currentColor', opacity: 0.7 }}
                                    tickLine={false}
                                />
                                <YAxis
                                    tick={{ fontSize: 10, fill: 'currentColor', opacity: 0.7 }}
                                    tickLine={false}
                                    tickFormatter={(val) => {
                                        if (val >= 1000000) return `${(val / 1000000).toFixed(1)}M`;
                                        if (val >= 1000) return `${(val / 1000).toFixed(0)}k`;
                                        return val;
                                    }}
                                />
                                <Tooltip
                                    content={({ active, payload, label }) => {
                                        if (!active || !payload || !payload.length) return null;
                                        const item = payload[0].payload;
                                        return (
                                            <div className="rounded-lg border border-theme bg-theme-surface p-2.5 text-xs shadow-lg">
                                                <div className="font-bold text-theme-primary">{label}</div>
                                                <div className="text-theme-accent font-semibold mt-1">
                                                    Total: {Number(item.total).toLocaleString()} searches
                                                </div>
                                                {item.byGeo && Object.keys(item.byGeo).length > 1 && (
                                                    <div className="mt-1 pt-1 border-t border-theme text-[10px] text-theme-muted space-y-0.5">
                                                        {Object.entries(item.byGeo).map(([g, s]) => (
                                                            <div key={g} className="flex justify-between gap-3">
                                                                <span>{COUNTRY_FLAGS[g] || ''} {g}:</span>
                                                                <span className="font-mono">{Number(s).toLocaleString()}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    }}
                                />
                                <Bar
                                    dataKey="total"
                                    fill="var(--color-theme-accent, #3b82f6)"
                                    radius={[4, 4, 0, 0]}
                                />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            )}
        </div>
    );
}
