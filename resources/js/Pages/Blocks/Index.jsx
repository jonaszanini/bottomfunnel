import { router } from '@inertiajs/react';
import Layout, { quiet } from '../../Components/Layout';

export default function Index({ blocks }) {
    return (
        <Layout title="IP blocks">
            <h1 className="text-2xl font-semibold text-theme-primary">IP blocks</h1>
            <p className="mt-1 text-sm text-theme-secondary">Unblock removes the Google Ads exclusion. Whitelist stops future blocks for that IP on this Track. Very low time on page is a flag on the dashboard, not an exclusion.</p>
            <div className="mt-4 overflow-x-auto rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary">
                <table className="min-w-full text-left text-sm">
                    <thead className="text-theme-muted border-b border-theme bg-theme-canvas/50">
                        <tr>{['When', 'Track', 'IP', 'Reason', 'Status', 'Campaign', ''].map((heading) => <th key={heading} className="px-3 py-2 font-medium">{heading}</th>)}</tr>
                    </thead>
                    <tbody className="divide-y divide-theme">
                        {blocks.map((block) => (
                            <tr key={block.id} className="hover:bg-theme-subtle/50 transition-colors">
                                <td className="px-3 py-2 text-theme-secondary">{block.created_at}</td>
                                <td className="px-3 py-2 font-medium text-theme-primary">{block.product_name}</td>
                                <td className="px-3 py-2 font-mono text-xs text-theme-primary">{block.ip_address}</td>
                                <td className="px-3 py-2 text-theme-secondary">{block.reason}{block.last_error ? ` ${block.last_error}` : ''}</td>
                                <td className="px-3 py-2 text-theme-secondary">{block.status}</td>
                                <td className="px-3 py-2 text-theme-secondary">{block.campaign_id}</td>
                                <td className="px-3 py-2">
                                    <button className={quiet} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/unblock`)}>Unblock</button>
                                    <button className={`${quiet} ml-2`} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/whitelist`)}>Whitelist</button>
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
