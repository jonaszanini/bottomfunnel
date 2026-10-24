import { router } from '@inertiajs/react';
import Layout, { quiet } from '../../Components/Layout';

export default function Index({ blocks }) {
    const statusBadge = (status) => {
        switch (status) {
            case 'applied':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">Active Exclusion</span>;
            case 'pending':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/20">Pending Sync</span>;
            case 'consolidated':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">Consolidated into /24</span>;
            case 'rotated':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-500/15 text-zinc-500 dark:text-zinc-400 border border-zinc-500/20">Rotated (500 Limit)</span>;
            case 'whitelisted':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/20">Whitelisted</span>;
            case 'failed':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/20">Failed</span>;
            case 'removed':
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-zinc-500/10 text-zinc-400 border border-zinc-500/20">Removed</span>;
            default:
                return <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-theme-canvas text-theme-secondary border border-theme">{status}</span>;
        }
    };

    return (
        <Layout title="IP blocks">
            <h1 className="text-2xl font-semibold text-theme-primary">Google Ads IP Exclusions</h1>
            <p className="mt-1 text-sm text-theme-secondary">
                Automated click fraud prevention pipeline with Google Ads campaign exclusion sync. Enforces Google Ads 500-IP campaign limits via /24 CIDR subnet consolidation and automated oldest IP rotation.
            </p>
            <div className="mt-4 overflow-x-auto rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary">
                <table className="min-w-full text-left text-sm">
                    <thead className="text-theme-muted border-b border-theme bg-theme-canvas/50">
                        <tr>{['When', 'Track', 'IP / Subnet Mask', 'Reason', 'Exclusion Status', 'Campaign', 'Actions'].map((heading) => <th key={heading} className="px-3 py-2.5 font-medium">{heading}</th>)}</tr>
                    </thead>
                    <tbody className="divide-y divide-theme">
                        {blocks.map((block) => (
                            <tr key={block.id} className="hover:bg-theme-subtle/50 transition-colors">
                                <td className="px-3 py-2.5 text-xs text-theme-secondary whitespace-nowrap">{block.created_at}</td>
                                <td className="px-3 py-2.5 font-medium text-theme-primary">{block.product_name || '—'}</td>
                                <td className="px-3 py-2.5">
                                    <div className="flex items-center gap-1.5 font-mono text-xs text-theme-primary">
                                        <span>{block.ip_address}</span>
                                        {block.ip_address && block.ip_address.includes('/') && (
                                            <span className="rounded px-1.5 py-0.2 bg-purple-500/10 text-purple-600 dark:text-purple-400 font-sans text-[10px] font-semibold">
                                                /24 CIDR
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="px-3 py-2.5 text-xs text-theme-secondary max-w-xs">
                                    <div>{block.reason}</div>
                                    {block.last_error && <div className="text-[11px] text-rose-500 mt-0.5">{block.last_error}</div>}
                                </td>
                                <td className="px-3 py-2.5 whitespace-nowrap">{statusBadge(block.status)}</td>
                                <td className="px-3 py-2.5 text-xs font-mono text-theme-secondary">{block.campaign_id || '—'}</td>
                                <td className="px-3 py-2.5 whitespace-nowrap">
                                    <div className="inline-flex items-center gap-2">
                                        <button className={quiet} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/unblock`)}>Unblock</button>
                                        <button className={quiet} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/whitelist`)}>Whitelist</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {blocks.length === 0 && <p className="p-4 text-sm text-theme-muted">No IP records yet.</p>}
            </div>
        </Layout>
    );
}
