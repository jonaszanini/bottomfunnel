import { router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, money } from '../Components/Layout';

export default function Dashboard({ filters, report, trackOptions, pageTypes, strategies, platforms, conversionStatuses }) {
    const spend = useForm({
        track_id: filters.track_id || (trackOptions[0]?.id ?? ''),
        start_date: filters.from,
        end_date: filters.to,
        amount_cad: '',
        note: '',
    });

    function apply(event) {
        event.preventDefault();
        const data = new FormData(event.target);
        router.get('/', Object.fromEntries(data.entries()), { preserveState: true });
    }

    const summary = report.summary;
    const cards = [
        ['Visits', summary.visits],
        ['Unique sessions', summary.sessions],
        ['Average time', `${summary.average_time}s`],
        ['CTA click rate', `${summary.cta_rate}%`],
        ['Sessions under 10s', summary.under_10],
        ['Conversions', summary.conversions],
        ['Commission', money(summary.commission)],
        ['Ad spend', money(summary.spend)],
        ['Profit', money(summary.profit)],
        ['ROI', summary.roi === null ? 'n/a' : `${summary.roi}%`],
    ];

    return (
        <Layout title="Dashboard">
            <form className="mb-4 grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm md:grid-cols-4" onSubmit={apply}>
                <Field label="From"><input className={control} type="date" name="from" defaultValue={filters.from} /></Field>
                <Field label="To"><input className={control} type="date" name="to" defaultValue={filters.to} /></Field>
                <Field label="Track">
                    <select className={control} name="track_id" defaultValue={filters.track_id}>
                        <option value="">All tracks</option>
                        {trackOptions.map((track) => <option key={track.id} value={track.id}>{track.product_name}</option>)}
                    </select>
                </Field>
                <Field label="Strategy">
                    <select className={control} name="strategy" defaultValue={filters.strategy}>
                        <option value="">All strategies</option>
                        {Object.entries(strategies).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <Field label="Page type">
                    <select className={control} name="page_type" defaultValue={filters.page_type}>
                        <option value="">All page types</option>
                        {Object.entries(pageTypes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <Field label="Platform">
                    <select className={control} name="platform" defaultValue={filters.platform}>
                        <option value="">All platforms</option>
                        {Object.entries(platforms).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <Field label="Conversion status">
                    <select className={control} name="conversion_status" defaultValue={filters.conversion_status}>
                        <option value="">All statuses</option>
                        {Object.entries(conversionStatuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </Field>
                <div className="flex items-end"><button className={button}>Apply filters</button></div>
            </form>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {cards.map(([label, value]) => (
                    <div key={label} className="rounded-xl border border-theme bg-theme-surface p-4 shadow-sm">
                        <p className="text-xs uppercase tracking-wide text-theme-muted">{label}</p>
                        <p className="mt-1 text-xl font-semibold text-theme-primary">{value}</p>
                    </div>
                ))}
            </div>

            {report.flags.length > 0 && (
                <section className="mt-4 space-y-2">
                    {report.flags.map((flag, index) => (
                        <p key={`${flag.track_id}-${index}`} className="rounded-md border border-[var(--warningBorderColor)] bg-[var(--warningBackgroundColor)] px-3 py-2 text-sm text-[var(--warningColor)]">
                            <strong>{flag.product_name}.</strong> {flag.message} These flags do not pause anything.
                        </p>
                    ))}
                </section>
            )}

            <section className="mt-6 overflow-x-auto rounded-xl border border-theme bg-theme-surface shadow-sm">
                <table className="min-w-full text-left text-sm">
                    <thead className="border-b border-theme text-theme-muted bg-theme-canvas/50">
                        <tr>
                            {['Track', 'Strategy', 'Page type', 'Sessions', 'Time', 'CTA rate', 'Under 10s', 'Conversions', 'Commission', 'Spend', 'Profit', 'ROI'].map((heading) => (
                                <th key={heading} className="px-3 py-2 font-medium">{heading}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-theme">
                        {report.tracks.map((track) => (
                            <tr key={track.id} className="hover:bg-theme-subtle/50 transition-colors">
                                <td className="px-3 py-2 font-medium text-theme-primary">{track.product_name}</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.strategy}</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.page_type}</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.sessions}</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.average_time}s</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.cta_rate}%</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.under_10}</td>
                                <td className="px-3 py-2 text-theme-secondary">{track.conversions}</td>
                                <td className="px-3 py-2 text-theme-secondary">{money(track.commission)}</td>
                                <td className="px-3 py-2 text-theme-secondary">{money(track.spend)}</td>
                                <td className="px-3 py-2 text-theme-secondary">{money(track.profit)}</td>
                                <td className="px-3 py-2 font-medium text-theme-primary">{track.roi === null ? 'n/a' : `${track.roi}%`}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                <section className="rounded-xl border border-theme bg-theme-surface p-4 shadow-sm">
                    <h2 className="font-semibold text-theme-primary">Time and CTA by page type</h2>
                    <ul className="mt-3 space-y-2 text-sm text-theme-secondary">
                        {report.page_types.map((row) => (
                            <li key={row.page_type} className="flex justify-between gap-3">
                                <span>{row.label}</span>
                                <span className="font-medium text-theme-primary">{row.sessions} sessions, {row.average_time}s, CTA {row.cta_rate}%</span>
                            </li>
                        ))}
                        {report.page_types.length === 0 && <li className="text-theme-muted">No visits in this range.</li>}
                    </ul>
                </section>
                <section className="rounded-xl border border-theme bg-theme-surface p-4 shadow-sm">
                    <h2 className="font-semibold text-theme-primary">Upload status</h2>
                    <ul className="mt-3 space-y-1 text-sm text-theme-secondary">
                        {Object.entries(summary.uploads || {}).map(([status, count]) => (
                            <li key={status} className="flex justify-between"><span>{conversionStatuses[status] || status}</span><span className="font-medium text-theme-primary">{count}</span></li>
                        ))}
                    </ul>
                    <h2 className="mt-4 font-semibold text-theme-primary">Fraud alerts</h2>
                    <ul className="mt-2 space-y-2 text-sm text-theme-secondary">
                        {report.alerts.map((alert, index) => (
                            <li key={index}>{alert.ip_address} at {alert.landed_at}. {alert.reason} {alert.auto_blocked ? '(exclusion queued)' : '(flag only)'}</li>
                        ))}
                        {report.alerts.length === 0 && <li className="text-theme-muted">No fraud alerts in this range.</li>}
                    </ul>
                </section>
            </div>

            <section className="mt-4 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm">
                <h2 className="font-semibold text-theme-primary">Blocked IPs</h2>
                <ul className="mt-2 space-y-1 text-sm text-theme-secondary">
                    {report.blocked.map((block) => (
                        <li key={block.id}>{block.ip_address} on {block.product_name} at {block.created_at}. {block.reason}</li>
                    ))}
                    {report.blocked.length === 0 && <li className="text-theme-muted">No active exclusions in this range.</li>}
                </ul>
            </section>

            <form
                className="mt-4 grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm md:grid-cols-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    spend.post(`/tracks/${spend.data.track_id}/spend`);
                }}
            >
                <Field label="Track for spend" error={spend.errors.track_id}>
                    <select className={control} value={spend.data.track_id} onChange={(event) => spend.setData('track_id', event.target.value)}>
                        {trackOptions.map((track) => <option key={track.id} value={track.id}>{track.product_name}</option>)}
                    </select>
                </Field>
                <Field label="Start" error={spend.errors.start_date}><input className={control} type="date" value={spend.data.start_date} onChange={(event) => spend.setData('start_date', event.target.value)} /></Field>
                <Field label="End" error={spend.errors.end_date}><input className={control} type="date" value={spend.data.end_date} onChange={(event) => spend.setData('end_date', event.target.value)} /></Field>
                <Field label="Amount CAD$" error={spend.errors.amount_cad}><input className={control} value={spend.data.amount_cad} onChange={(event) => spend.setData('amount_cad', event.target.value)} /></Field>
                <div className="flex items-end"><button className={button} disabled={spend.processing || !spend.data.track_id}>Save spend</button></div>
            </form>
        </Layout>
    );
}
