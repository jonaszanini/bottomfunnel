import { Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

export default function Index({ pages, tracks, pageTypes, languages }) {
    const form = useForm({
        track_id: tracks[0]?.id || '',
        page_type: tracks[0]?.page_type || 'review_robust',
        product_name: tracks[0]?.product_name || '',
        language: tracks[0]?.language || '',
        instructions: '',
    });

    function chooseTrack(id) {
        const track = tracks.find((item) => String(item.id) === String(id));
        form.setData({
            ...form.data,
            track_id: id,
            page_type: track?.page_type || form.data.page_type,
            product_name: track?.product_name || '',
            language: track?.language || '',
        });
    }

    return (
        <Layout title="Pages">
            <h1 className="text-2xl font-semibold">Pages</h1>
            <form
                className="mt-4 grid gap-3 rounded-xl bg-white p-4 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/pages/generate');
                }}
            >
                <Field label="Track" error={form.errors.track_id}>
                    <select className={control} value={form.data.track_id} onChange={(event) => chooseTrack(event.target.value)}>
                        <option value="">Choose a Track</option>
                        {tracks.map((track) => <option key={track.id} value={track.id}>{track.product_name}</option>)}
                    </select>
                </Field>
                <Field label="Page type" error={form.errors.page_type}>
                    <select className={control} value={form.data.page_type} onChange={(event) => form.setData('page_type', event.target.value)}>
                        {Object.entries(pageTypes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <Field label="Product name" error={form.errors.product_name}>
                    <input className={control} value={form.data.product_name} onChange={(event) => form.setData('product_name', event.target.value)} />
                </Field>
                <Field label="Language" error={form.errors.language}>
                    <select className={control} value={form.data.language} onChange={(event) => form.setData('language', event.target.value)}>
                        <option value="">Track default</option>
                        {Object.entries(languages).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <Field label="Extra instructions" error={form.errors.instructions}>
                    <textarea className={control} rows="4" value={form.data.instructions} onChange={(event) => form.setData('instructions', event.target.value)} />
                </Field>
                <div className="flex items-end">
                    <button className={button} disabled={form.processing || tracks.length === 0}>Generate page</button>
                </div>
            </form>

            <div className="mt-4 overflow-hidden rounded-xl bg-white">
                {pages.length === 0 && <p className="p-4 text-sm text-stone-500">No pages yet.</p>}
                {pages.map((page) => (
                    <div key={page.id} className="flex items-center justify-between border-b border-stone-100 px-4 py-3 text-sm">
                        <div>
                            <strong>{page.name}</strong>
                            <span className="ml-2 text-stone-500">{page.product_name} · {page.page_type}</span>
                        </div>
                        <div className="flex gap-2">
                            <Link className={quiet} href={`/pages/${page.id}/edit`}>Edit</Link>
                            <a className={quiet} href={`/pages/${page.id}/export`}>Export index.html</a>
                            <button className={quiet} type="button" onClick={() => router.delete(`/pages/${page.id}`)}>Remove</button>
                        </div>
                    </div>
                ))}
            </div>
        </Layout>
    );
}
