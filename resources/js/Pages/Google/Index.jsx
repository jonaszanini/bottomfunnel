import { router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

export default function Index({ accounts, pending }) {
    const form = useForm({
        customer_id: pending[0] || '',
        login_customer_id: '',
    });

    return (
        <Layout title="Google Ads">
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-theme-primary">Google Ads</h1>
                    <p className="text-sm text-theme-secondary">Link the account a Track uses. Flow Hub uploads conversions and manages IP exclusions. It does not create campaigns or change bids.</p>
                </div>
                <button className={button} type="button" onClick={() => router.get('/google-ads/connect')}>Link Google Ads</button>
            </div>

            {pending.length > 0 && (
                <form
                    className="mb-4 grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm md:grid-cols-3 text-theme-primary"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/google-ads/accounts');
                    }}
                >
                    <Field label="Customer id" error={form.errors.customer_id}>
                        <select className={control} value={form.data.customer_id} onChange={(event) => form.setData('customer_id', event.target.value)}>
                            {pending.map((id) => <option key={id} value={id}>{id}</option>)}
                        </select>
                    </Field>
                    <Field label="MCC login customer id" error={form.errors.login_customer_id}>
                        <input className={control} placeholder="Only if an MCC is how you reach this account" value={form.data.login_customer_id} onChange={(event) => form.setData('login_customer_id', event.target.value)} />
                    </Field>
                    <div className="flex items-end"><button className={button}>Save account</button></div>
                </form>
            )}

            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary">
                {accounts.length === 0 && <p className="p-4 text-sm text-theme-muted">No linked accounts.</p>}
                {accounts.map((account) => (
                    <div key={account.id} className="flex items-center justify-between border-b border-theme px-4 py-3 text-sm text-theme-primary hover:bg-theme-subtle/50 transition-colors">
                        <span>{account.name} · {account.customer_id}{account.login_customer_id ? ` · MCC ${account.login_customer_id}` : ''}</span>
                        <button className={quiet} type="button" onClick={() => router.delete(`/google-ads/accounts/${account.id}`)}>Remove</button>
                    </div>
                ))}
            </div>
        </Layout>
    );
}
