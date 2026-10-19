import { router } from '@inertiajs/react';
import Layout, { quiet } from '../../Components/Layout';

export default function Index({ blocks }) {
    return (
        <Layout title="IP blocks">
            <h1 className="text-2xl font-semibold">IP blocks</h1>
            <p className="mt-1 text-sm text-stone-600">Unblock removes the Google Ads exclusion. Whitelist stops future blocks for that IP on this Track. Very low time on page is a flag on the dashboard, not an exclusion.</p>
            <div className="mt-4 overflow-x-auto rounded-xl bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="text-stone-500">
                        <tr>{['When', 'Track', 'IP', 'Reason', 'Status', 'Campaign', ''].map((heading) => <th key={heading} className="px-3 py-2 font-medium">{heading}</th>)}</tr>
                    </thead>
                    <tbody>
                        {blocks.map((block) => (
                            <tr key={block.id} className="border-t border-stone-100">
                                <td className="px-3 py-2">{block.created_at}</td>
                                <td className="px-3 py-2">{block.product_name}</td>
                                <td className="px-3 py-2">{block.ip_address}</td>
                                <td className="px-3 py-2">{block.reason}{block.last_error ? ` ${block.last_error}` : ''}</td>
                                <td className="px-3 py-2">{block.status}</td>
                                <td className="px-3 py-2">{block.campaign_id}</td>
                                <td className="px-3 py-2">
                                    <button className={quiet} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/unblock`)}>Unblock</button>
                                    <button className={`${quiet} ml-2`} type="button" onClick={() => router.post(`/ip-blocks/${block.id}/whitelist`)}>Whitelist</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {blocks.length === 0 && <p className="p-4 text-sm text-stone-500">No IP records yet.</p>}
            </div>
        </Layout>
    );
}
