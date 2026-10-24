import { Link, router, useForm } from '@inertiajs/react';
import Layout, { button, control, money, quiet } from '../../Components/Layout';

export default function Show({ track, conversions, visits, spend, pages = [], track_campaigns = [] }) {
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
                    <Link className={button} href={`/pages?track_id=${track.id}&name=${encodeURIComponent(track.product_name || '')}&lang=${track.language || 'en'}&aff=${encodeURIComponent(track.affiliate_url || '')}`}>Generate a page</Link>
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

            {/* Generated & Cloned Pages Section */}
            <section className="mt-4 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm text-theme-primary">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-theme pb-3">
                    <div>
                        <h2 className="text-base font-semibold text-theme-primary flex items-center gap-2">
                            <span>Presell Pages &amp; Landers</span>
                            <span className="rounded-full bg-theme-card border border-theme px-2 py-0.5 text-xs text-theme-secondary font-medium">
                                {pages.length}
                            </span>
                        </h2>
                        <p className="text-xs text-theme-secondary mt-0.5">
                            Pages where <code className="font-mono text-theme-accent">track_id = #{track.id}</code>. Assign each page to a Google Ads campaign to route search traffic.
                        </p>
                    </div>

                    <Link
                        className={`${button} text-xs py-1.5 px-3 inline-flex items-center gap-1.5`}
                        href={`/pages?track_id=${track.id}&name=${encodeURIComponent(track.product_name || '')}&lang=${track.language || 'en'}&aff=${encodeURIComponent(track.affiliate_url || '')}`}
                    >
                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        <span>Generate / Clone Page</span>
                    </Link>
                </div>

                {pages.length === 0 ? (
                    <div className="py-8 text-center space-y-2">
                        <p className="text-sm font-medium text-theme-secondary">No pages created for this Track yet.</p>
                        <p className="text-xs text-theme-muted max-w-md mx-auto">
                            Generate unique presells or clone the merchant sales page. All buy buttons will automatically route through this Track.
                        </p>
                        <Link
                            className={`${button} inline-flex items-center gap-1.5 text-xs mt-2 py-1.5 px-3`}
                            href={`/pages?track_id=${track.id}&name=${encodeURIComponent(track.product_name || '')}&lang=${track.language || 'en'}&aff=${encodeURIComponent(track.affiliate_url || '')}`}
                        >
                            Create First Page for this Track
                        </Link>
                    </div>
                ) : (
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-xs divide-y divide-theme">
                            <thead className="bg-theme-canvas/60 text-theme-muted uppercase tracking-wider font-semibold">
                                <tr>
                                    <th className="px-3 py-2">Page Name / Title</th>
                                    <th className="px-3 py-2">Strategy / Format</th>
                                    <th className="px-3 py-2">Created</th>
                                    <th className="px-3 py-2">Assigned Google Ads Campaign</th>
                                    <th className="px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-theme">
                                {pages.map((p) => (
                                    <tr key={p.id} className="hover:bg-theme-subtle/30 transition-colors">
                                        <td className="px-3 py-2.5 font-semibold text-theme-primary">
                                            <div className="flex items-center gap-2">
                                                <span>{p.name}</span>
                                                <span className="font-mono text-[10px] text-theme-muted">#{p.id}</span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5 text-theme-secondary">
                                            <span className="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium bg-theme-card border border-theme">
                                                {p.page_type}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5 text-theme-muted whitespace-nowrap">
                                            {p.created_at ? new Date(p.created_at).toLocaleDateString() : '—'}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <select
                                                    className={`${control} !py-1 !px-2 text-xs max-w-[280px]`}
                                                    value={p.campaign_id || ''}
                                                    onChange={(e) => {
                                                        const newCid = e.target.value;
                                                        router.post(
                                                            `/pages/${p.id}/assign-campaign`,
                                                            { campaign_id: newCid },
                                                            { preserveScroll: true }
                                                        );
                                                    }}
                                                >
                                                    <option value="">Unassigned (No Campaign)</option>
                                                    {track_campaigns.map((camp) => (
                                                        <option key={camp.campaign_id} value={camp.campaign_id}>
                                                            {camp.name} · {camp.account_name || track.account_name || 'Account'} (#{camp.campaign_id})
                                                        </option>
                                                    ))}
                                                </select>

                                                {p.campaign_id && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            router.post(
                                                                `/pages/${p.id}/assign-campaign`,
                                                                { campaign_id: '' },
                                                                { preserveScroll: true }
                                                            );
                                                        }}
                                                        className="text-[11px] text-rose-500 hover:text-rose-600 px-1 hover:underline cursor-pointer"
                                                        title="Unassign from campaign"
                                                    >
                                                        Unassign
                                                    </button>
                                                )}
                                            </div>
                                            {p.campaign_name && (
                                                <div className="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                                    <span>Assigned to: <strong>{p.campaign_name}</strong></span>
                                                    {p.campaign_account_name && (
                                                        <span className="text-theme-muted">({p.campaign_account_name})</span>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right whitespace-nowrap">
                                            <div className="inline-flex items-center gap-2">
                                                <Link
                                                    href={`/pages/${p.id}/edit`}
                                                    className={`${quiet} py-1 px-2 text-xs`}
                                                >
                                                    Edit
                                                </Link>
                                                <a
                                                    href={`/pages/${p.id}/export`}
                                                    className={`${quiet} py-1 px-2 text-xs`}
                                                    download={`${p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.html`}
                                                >
                                                    Export
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
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
