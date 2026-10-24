import { Link } from '@inertiajs/react';
import Layout, { button } from '../../Components/Layout';

export default function Index({ tracks }) {
    return (
        <Layout title="Tracks">
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Tracks</h1>
                <Link className={button} href="/tracks/create">New Track</Link>
            </div>
            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-sm">
                {tracks.length === 0 && <p className="p-4 text-sm text-theme-muted">No tracks yet. Create one offer to issue its postback URL and script.</p>}
                {tracks.map((track) => (
                    <Link key={track.id} href={`/tracks/${track.id}`} className="flex items-center justify-between border-b border-theme px-4 py-3 text-sm text-theme-primary hover:bg-theme-subtle/50 transition-colors">
                        <span>
                            <strong className="text-theme-primary">{track.product_name}</strong>
                            {track.watched_product && (
                                <span className="ml-2 inline-flex items-center gap-1 rounded border border-theme bg-theme-subtle px-1.5 py-0.2 text-[11px] text-theme-secondary font-medium">
                                    Watched: {track.watched_product.name}
                                </span>
                            )}
                            <span className="ml-2 text-theme-muted">{track.platform} · {track.page_type} · {track.strategy}</span>
                        </span>
                        <span className="text-right">
                            {track.account ? (
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-theme bg-theme-subtle/70 px-2 py-0.5 text-xs text-theme-secondary">
                                    <span className="font-medium text-theme-primary">{track.account.name}</span>
                                    <span className="font-mono text-theme-muted">({track.account.customer_id})</span>
                                </span>
                            ) : (
                                <span className="text-xs text-theme-muted">{track.account_name || 'No account'}</span>
                            )}
                        </span>
                    </Link>
                ))}
            </div>
        </Layout>
    );
}
