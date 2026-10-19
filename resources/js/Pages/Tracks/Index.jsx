import { Link } from '@inertiajs/react';
import Layout, { button } from '../../Components/Layout';

export default function Index({ tracks }) {
    return (
        <Layout title="Tracks">
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Tracks</h1>
                <Link className={button} href="/tracks/create">New Track</Link>
            </div>
            <div className="overflow-hidden rounded-xl bg-white">
                {tracks.length === 0 && <p className="p-4 text-sm text-stone-500">No tracks yet. Create one offer to issue its postback URL and script.</p>}
                {tracks.map((track) => (
                    <Link key={track.id} href={`/tracks/${track.id}`} className="flex items-center justify-between border-b border-stone-100 px-4 py-3 text-sm">
                        <span>
                            <strong>{track.product_name}</strong>
                            <span className="ml-2 text-stone-500">{track.platform} · {track.page_type} · {track.strategy}</span>
                        </span>
                        <span className="text-stone-500">{track.account || 'No account'}</span>
                    </Link>
                ))}
            </div>
        </Layout>
    );
}
