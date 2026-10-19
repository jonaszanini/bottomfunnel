import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout, { Field, button, control } from '../../Components/Layout';

export default function Form({ track, accounts, pageTypes, strategies, platforms, languages }) {
    const form = useForm({
        product_name: track?.product_name || '',
        platform: track?.platform || 'clickbank',
        affiliate_url: track?.affiliate_url || '',
        page_type: track?.page_type || 'review_robust',
        strategy: track?.strategy || 'target_cpa',
        language: track?.language || '',
        typical_commission_cad: track?.typical_commission_cad || '',
        google_ads_account_id: track?.google_ads_account_id || '',
        conversion_mode: 'existing',
        conversion_action_id: track?.conversion_action_id || '',
        conversion_action_name: '',
        campaign_ids: track?.campaign_ids || [],
    });
    const [actions, setActions] = useState([]);
    const [campaigns, setCampaigns] = useState([]);
    const [loadError, setLoadError] = useState('');

    useEffect(() => {
        if (!form.data.google_ads_account_id) {
            setActions([]);
            setCampaigns([]);
            return;
        }
        let ignore = false;
        setLoadError('');
        fetch(`/google-ads/accounts/${form.data.google_ads_account_id}/options`, {
            headers: { Accept: 'application/json' },
        }).then(async (response) => {
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'Google Ads did not return account options.');
            return body;
        }).then((body) => {
            if (ignore) return;
            setActions(body.conversion_actions || []);
            setCampaigns(body.campaigns || []);
        }).catch((error) => {
            if (!ignore) setLoadError(error.message);
        });
        return () => { ignore = true; };
    }, [form.data.google_ads_account_id]);

    function toggleCampaign(id) {
        const current = form.data.campaign_ids.map(String);
        form.setData('campaign_ids', current.includes(String(id))
            ? current.filter((item) => item !== String(id))
            : [...current, String(id)]);
    }

    return (
        <Layout title={track ? 'Edit Track' : 'New Track'}>
            <form
                className="grid max-w-3xl gap-4 rounded-xl bg-white p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    track ? form.put(`/tracks/${track.id}`) : form.post('/tracks');
                }}
            >
                <h1 className="text-2xl font-semibold">{track ? 'Edit Track' : 'New Track'}</h1>
                <p className="text-sm text-stone-600">Strategy is a label for filtering. Flow Hub does not change bids. Every amount is CAD$.</p>
                {accounts.length === 0 && (
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950">
                        Link a Google Ads account before creating a Track. <Link className="underline" href="/google-ads">Open Google Ads</Link>
                    </p>
                )}
                <Field label="Product name" error={form.errors.product_name}>
                    <input className={control} value={form.data.product_name} onChange={(event) => form.setData('product_name', event.target.value)} />
                </Field>
                <div className="grid gap-4 md:grid-cols-2">
                    <Field label="Affiliate platform" error={form.errors.platform}>
                        <select className={control} value={form.data.platform} onChange={(event) => form.setData('platform', event.target.value)}>
                            {Object.entries(platforms).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </Field>
                    <Field label="Page type" error={form.errors.page_type}>
                        <select className={control} value={form.data.page_type} onChange={(event) => form.setData('page_type', event.target.value)}>
                            {Object.entries(pageTypes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </Field>
                    <Field label="Strategy" error={form.errors.strategy}>
                        <select className={control} value={form.data.strategy} onChange={(event) => form.setData('strategy', event.target.value)}>
                            {Object.entries(strategies).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </Field>
                    <Field label="Language" error={form.errors.language}>
                        <select className={control} value={form.data.language} onChange={(event) => form.setData('language', event.target.value)}>
                            <option value="">Optional</option>
                            {Object.entries(languages).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                    </Field>
                </div>
                <Field label="Affiliate offer URL" error={form.errors.affiliate_url}>
                    <input className={control} value={form.data.affiliate_url} onChange={(event) => form.setData('affiliate_url', event.target.value)} />
                </Field>
                <Field label="Typical commission for one sale, CAD$" error={form.errors.typical_commission_cad}>
                    <input className={control} value={form.data.typical_commission_cad} onChange={(event) => form.setData('typical_commission_cad', event.target.value)} />
                </Field>
                <Field label="Google Ads account" error={form.errors.google_ads_account_id}>
                    <select className={control} value={form.data.google_ads_account_id} onChange={(event) => form.setData('google_ads_account_id', event.target.value)}>
                        <option value="">Choose an account</option>
                        {accounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.customer_id})</option>)}
                    </select>
                </Field>
                {loadError && <p className="text-sm text-red-700">{loadError}</p>}
                <fieldset className="grid gap-3">
                    <legend className="text-sm font-medium text-stone-700">Offline conversion action</legend>
                    <label className="text-sm"><input type="radio" checked={form.data.conversion_mode === 'existing'} onChange={() => form.setData('conversion_mode', 'existing')} /> Select an existing action</label>
                    <select className={control} value={form.data.conversion_action_id} onChange={(event) => form.setData('conversion_action_id', event.target.value)}>
                        <option value="">Choose an action</option>
                        {actions.map((action) => <option key={action.id} value={action.id}>{action.name}</option>)}
                    </select>
                    {form.errors.conversion_action_id && <p className="text-sm text-red-700">{form.errors.conversion_action_id}</p>}
                    <label className="text-sm"><input type="radio" checked={form.data.conversion_mode === 'new'} onChange={() => form.setData('conversion_mode', 'new')} /> Create a new action</label>
                    <input className={control} placeholder="Action name" value={form.data.conversion_action_name} onChange={(event) => form.setData('conversion_action_name', event.target.value)} />
                    {form.errors.conversion_action_name && <p className="text-sm text-red-700">{form.errors.conversion_action_name}</p>}
                </fieldset>
                <fieldset>
                    <legend className="mb-2 text-sm font-medium text-stone-700">Campaigns for IP exclusion</legend>
                    <div className="grid gap-2">
                        {campaigns.map((campaign) => (
                            <label key={campaign.id} className="text-sm">
                                <input type="checkbox" checked={form.data.campaign_ids.map(String).includes(String(campaign.id))} onChange={() => toggleCampaign(campaign.id)} /> {campaign.name}
                            </label>
                        ))}
                        {campaigns.length === 0 && <p className="text-sm text-stone-500">Campaigns appear after the account loads. IP exclusion needs at least one.</p>}
                    </div>
                </fieldset>
                <button className={button} disabled={form.processing || accounts.length === 0}>Save Track</button>
            </form>
        </Layout>
    );
}
