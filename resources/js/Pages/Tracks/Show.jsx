import { Link, router, useForm } from '@inertiajs/react';
import Layout, { button, control, money, quiet } from '../../Components/Layout';

export default function Show({ track, conversions, visits, spend }) {
    const spendForm = useForm({
        start_date: '',
        end_date: '',
        amount_cad: '',
        note: '',
    });

    return (
        <Layout title={track.product_name}>
            <div className="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold text-theme-primary">{track.product_name}</h1>
                    <p className="text-sm text-theme-secondary">{track.platform_label} · {track.page_type} · {track.strategy}</p>
                </div>
                <div className="flex gap-2 items-center">
                    <Link className={quiet} href={`/copy?url=${encodeURIComponent(track.affiliate_url || '')}&product=${encodeURIComponent(track.product_name || '')}&lang=${encodeURIComponent(track.language || 'en')}`}>Generate Ad Copy for this Offer</Link>
                    <Link className={quiet} href={`/tracks/${track.id}/edit`}>Edit</Link>
                    <Link className={button} href="/pages">Generate a page</Link>
                </div>
            </div>

            <section className="grid gap-4 rounded-xl border border-theme bg-theme-surface p-4 text-sm shadow-sm md:grid-cols-2 text-theme-primary">
                <div>
                    <h2 className="font-semibold text-theme-primary">Postback URL</h2>
                    <p className="mt-1 break-all font-mono text-xs text-theme-accent">{track.postback_url}</p>
                    <p className="mt-2 text-theme-secondary">Give this URL to {track.platform_label}. The click field is <strong>{track.click_param}</strong>, and Flow Hub fills it with the session id. gclid stays on the session.</p>
                </div>
                <div>
                    <h2 className="font-semibold text-theme-primary">Script</h2>
                    <pre className="mt-1 overflow-x-auto whitespace-pre-wrap rounded-md border border-theme bg-theme-canvas p-2 font-mono text-xs text-theme-primary">{track.script}</pre>
                    <p className="mt-2 text-theme-secondary">Export index.html from the page builder. The script tag is already in that file and loads on its own. Host the file, then use that URL as the Google Ads destination. You do not paste the script by hand unless you are checking it.</p>
                </div>
                <p>
                    Account:{' '}
                    {track.account ? (
                        <span className="font-medium text-theme-primary">
                            {track.account.name}{' '}
                            <span className="font-mono text-xs text-theme-secondary">({track.account.customer_id})</span>
                        </span>
                    ) : (
                        <span className="text-theme-secondary">{track.account_name || 'Not linked'}</span>
                    )}
                </p>
                <p>Conversion action: <span className="text-theme-secondary">{track.conversion_action || 'Not set'}</span></p>
                <p>Typical commission: <span className="text-theme-secondary">{track.typical_commission_cad ? money(track.typical_commission_cad) : 'Not set'}</span></p>
                <p>Offer URL: <span className="break-all text-theme-secondary">{track.affiliate_url}</span></p>
            </section>

            <section className="mt-4 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm text-theme-primary">
                <h2 className="font-semibold text-theme-primary">Ad spend, CAD$</h2>
                <form
                    className="mt-3 grid gap-3 md:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        spendForm.post(`/tracks/${track.id}/spend`);
                    }}
                >
                    <input className={control} type="date" value={spendForm.data.start_date} onChange={(event) => spendForm.setData('start_date', event.target.value)} />
                    <input className={control} type="date" value={spendForm.data.end_date} onChange={(event) => spendForm.setData('end_date', event.target.value)} />
                    <input className={control} placeholder="Amount" value={spendForm.data.amount_cad} onChange={(event) => spendForm.setData('amount_cad', event.target.value)} />
                    <input className={control} placeholder="Note" value={spendForm.data.note} onChange={(event) => spendForm.setData('note', event.target.value)} />
                    <button className={button}>Save spend</button>
                </form>
                <ul className="mt-3 space-y-1 text-sm text-theme-secondary">
                    {spend.map((row) => <li key={row.id}>{row.start_date} to {row.end_date}: {money(row.amount_cad)} {row.note}</li>)}
                </ul>
            </section>

            <section className="mt-4 overflow-x-auto rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary">
                <h2 className="px-4 pt-4 font-semibold text-theme-primary">Conversions</h2>
                <table className="mt-2 min-w-full text-left text-sm">
                    <thead className="text-theme-muted border-b border-theme bg-theme-canvas/50">
                        <tr>
                            {['Order', 'Status', 'Match', 'CAD$', 'Error', 'Session'].map((heading) => <th key={heading} className="px-3 py-2 font-medium">{heading}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-theme">
                        {conversions.map((conversion) => (
                            <tr key={conversion.id} className="align-top hover:bg-theme-subtle/50 transition-colors">
                                <td className="px-3 py-2">
                                    <div className="font-medium text-theme-primary">{conversion.order_id}</div>
                                    <pre className="mt-1 max-w-xs overflow-auto whitespace-pre-wrap text-xs text-theme-muted">{conversion.raw_payload}</pre>
                                </td>
                                <td className="px-3 py-2 text-theme-secondary">{conversion.status}</td>
                                <td className="px-3 py-2 text-theme-secondary">{conversion.match_source || 'none'}</td>
                                <td className="px-3 py-2 font-medium text-theme-primary">{money(conversion.commission_value_cad)}</td>
                                <td className="px-3 py-2 text-sm text-[var(--dangerColor)]">{conversion.last_error}</td>
                                <td className="px-3 py-2">
                                    {conversion.status === 'unmatched' && (
                                        <select
                                            className={control}
                                            defaultValue=""
                                            onChange={(event) => {
                                                if (!event.target.value) return;
                                                router.post(`/conversions/${conversion.id}/match`, { visit_id: event.target.value });
                                            }}
                                        >
                                            <option value="">Attach a session</option>
                                            {visits.map((visit) => <option key={visit.id} value={visit.id}>{visit.session_id}</option>)}
                                        </select>
                                    )}
                                    <button className={`${quiet} mt-2`} type="button" onClick={() => router.post(`/conversions/${conversion.id}/retry`)}>Retry upload</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </Layout>
    );
}
