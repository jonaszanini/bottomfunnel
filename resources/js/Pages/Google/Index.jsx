import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet, money } from '../../Components/Layout';
import GoogleAdsAccountSelect from '../../Components/GoogleAdsAccountSelect';

export default function Index({ accounts = [], campaigns = [], default_account_id, pending = [] }) {
    const [showCampaignModal, setShowCampaignModal] = useState(false);
    const [submittingCampaign, setSubmittingCampaign] = useState(false);
    const [campaignError, setCampaignError] = useState('');

    const defaultAccId = default_account_id || accounts.find((a) => a.is_default)?.id || (accounts.length > 0 ? accounts[0].id : '');

    const accountForm = useForm({
        customer_id: pending[0] || '',
        login_customer_id: '',
    });

    const [campaignData, setCampaignData] = useState({
        account_id: defaultAccId,
        name: '',
        budget_cad: '10.00',
        bidding_strategy: 'MANUAL_CPC',
    });

    async function handleCreateCampaign(event) {
        event.preventDefault();
        setCampaignError('');
        setSubmittingCampaign(true);

        const budgetMicros = Math.round(Number(campaignData.budget_cad || 10) * 1_000_000);

        try {
            const response = await fetch('/internal/google-ads/campaigns', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    account_id: campaignData.account_id ? Number(campaignData.account_id) : undefined,
                    name: campaignData.name || `Search Campaign ${new Date().toISOString().slice(0, 10)}`,
                    budget_amount_micros: budgetMicros,
                    bidding_strategy: campaignData.bidding_strategy,
                }),
            });

            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || result.message || 'Failed to create campaign.');
            }

            setShowCampaignModal(false);
            setCampaignData({
                account_id: defaultAccId,
                name: '',
                budget_cad: '10.00',
                bidding_strategy: 'MANUAL_CPC',
            });
            router.reload({ only: ['campaigns', 'accounts'] });
        } catch (err) {
            setCampaignError(err.message);
        } finally {
            setSubmittingCampaign(false);
        }
    }

    return (
        <Layout title="Google Ads Accounts & Campaigns">
            {/* Header */}
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-theme-primary">Google Ads Management</h1>
                    <p className="text-sm text-theme-secondary">
                        Manage linked Google Ads accounts, set your default account for new Tracks, and monitor Search campaigns.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        className={quiet}
                        onClick={() => setShowCampaignModal(!showCampaignModal)}
                        disabled={accounts.length === 0}
                    >
                        {showCampaignModal ? 'Close Form' : 'New Campaign'}
                    </button>
                    <button
                        className={button}
                        type="button"
                        onClick={() => { window.location.href = '/google-ads/connect'; }}
                    >
                        Link Google Ads
                    </button>
                </div>
            </div>

            {/* Pending OAuth Confirmation */}
            {pending.length > 0 && (
                <form
                    className="mb-6 grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm md:grid-cols-3 text-theme-primary"
                    onSubmit={(event) => {
                        event.preventDefault();
                        accountForm.post('/google-ads/accounts');
                    }}
                >
                    <Field label="Customer id" error={accountForm.errors.customer_id}>
                        <select
                            className={control}
                            value={accountForm.data.customer_id}
                            onChange={(event) => accountForm.setData('customer_id', event.target.value)}
                        >
                            {pending.map((id) => <option key={id} value={id}>{id}</option>)}
                        </select>
                    </Field>
                    <Field label="MCC login customer id" error={accountForm.errors.login_customer_id}>
                        <input
                            className={control}
                            placeholder="Only if an MCC is how you reach this account"
                            value={accountForm.data.login_customer_id}
                            onChange={(event) => accountForm.setData('login_customer_id', event.target.value)}
                        />
                    </Field>
                    <div className="flex items-end">
                        <button className={button}>Save account</button>
                    </div>
                </form>
            )}

            {/* Campaign Creation Card / Form */}
            {showCampaignModal && (
                <div className="mb-6 rounded-xl border border-[var(--accentColor)]/40 bg-theme-surface p-5 shadow-md">
                    <h2 className="text-lg font-semibold text-theme-primary mb-1">Create Search Campaign (PAUSED)</h2>
                    <p className="text-xs text-theme-secondary mb-4">
                        Explicitly select which Google Ads account will own this campaign. All campaigns are created in PAUSED status for safety.
                    </p>

                    <form onSubmit={handleCreateCampaign} className="space-y-4">
                        <div>
                            <span className="mb-1 block text-sm font-medium text-theme-secondary">Target Google Ads Account *</span>
                            <GoogleAdsAccountSelect
                                accounts={accounts}
                                value={campaignData.account_id}
                                onChange={(val) => setCampaignData({ ...campaignData, account_id: val })}
                                required
                            />
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <Field label="Campaign Name">
                                <input
                                    className={control}
                                    placeholder="e.g. Search Campaign Alpha"
                                    value={campaignData.name}
                                    onChange={(e) => setCampaignData({ ...campaignData, name: e.target.value })}
                                />
                            </Field>

                            <Field label="Daily Budget (CAD$)">
                                <input
                                    className={control}
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    placeholder="10.00"
                                    value={campaignData.budget_cad}
                                    onChange={(e) => setCampaignData({ ...campaignData, budget_cad: e.target.value })}
                                />
                            </Field>

                            <Field label="Bidding Strategy">
                                <select
                                    className={control}
                                    value={campaignData.bidding_strategy}
                                    onChange={(e) => setCampaignData({ ...campaignData, bidding_strategy: e.target.value })}
                                >
                                    <option value="MANUAL_CPC">Manual CPC</option>
                                    <option value="MAXIMIZE_CLICKS">Maximize Clicks</option>
                                    <option value="MAXIMIZE_CONVERSIONS">Maximize Conversions</option>
                                    <option value="TARGET_CPA">Target CPA</option>
                                    <option value="TARGET_ROAS">Target ROAS</option>
                                </select>
                            </Field>
                        </div>

                        {campaignError && (
                            <div className="rounded-lg border border-[var(--dangerColor)]/30 bg-[var(--dangerColor)]/10 p-3 text-sm text-[var(--dangerColor)]">
                                {campaignError}
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-2 pt-2">
                            <button
                                type="button"
                                className={quiet}
                                onClick={() => setShowCampaignModal(false)}
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className={button}
                                disabled={submittingCampaign || !campaignData.account_id}
                            >
                                {submittingCampaign ? 'Creating in Google Ads...' : 'Create Campaign'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Linked Accounts Section */}
            <div className="mb-8">
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-theme-primary">Linked Google Ads Accounts</h2>
                    <span className="text-xs text-theme-secondary">{accounts.length} account{accounts.length === 1 ? '' : 's'} linked</span>
                </div>

                <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary divide-y divide-theme">
                    {accounts.length === 0 && (
                        <p className="p-4 text-sm text-theme-muted">
                            No linked accounts yet. Click "Link Google Ads" above to connect your first account.
                        </p>
                    )}

                    {accounts.map((account) => (
                        <div
                            key={account.id}
                            className={`flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between hover:bg-theme-subtle/40 transition-colors ${
                                account.is_default ? 'bg-theme-subtle/20' : ''
                            }`}
                        >
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong className="text-theme-primary font-semibold text-base">{account.name}</strong>
                                    {account.is_default ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 px-2.5 py-0.5 text-xs font-medium text-emerald-400">
                                            ★ Default Account
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            className="rounded-full border border-theme bg-theme-canvas/70 px-2.5 py-0.5 text-xs text-theme-secondary hover:text-theme-primary hover:border-theme-hover transition-colors cursor-pointer"
                                            onClick={() => router.post(`/google-ads/accounts/${account.id}/default`)}
                                        >
                                            Set as Default
                                        </button>
                                    )}
                                    <span className="rounded-full bg-theme-subtle px-2 py-0.5 text-xs text-theme-secondary capitalize">
                                        {account.status || 'linked'}
                                    </span>
                                </div>

                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-theme-secondary">
                                    <span className="font-mono">Customer ID: {account.customer_id}</span>
                                    {account.login_customer_id && (
                                        <span className="font-mono">MCC ID: {account.login_customer_id}</span>
                                    )}
                                    <span className="inline-flex items-center gap-1 rounded bg-theme-subtle px-2 py-0.5 font-medium text-theme-secondary">
                                        {account.tracks_count ?? 0} Tracks · {account.campaigns_count ?? 0} Campaigns
                                    </span>
                                </div>

                                <div className="text-xs">
                                    {account.email ? (
                                        <span className="text-theme-secondary">
                                            linked as <span className="font-medium text-theme-primary">{account.email}</span>
                                        </span>
                                    ) : (
                                        <span className="text-theme-muted italic">email not captured</span>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center gap-2 self-end sm:self-center">
                                {!account.is_default && (
                                    <button
                                        className={quiet}
                                        type="button"
                                        onClick={() => router.post(`/google-ads/accounts/${account.id}/default`)}
                                    >
                                        Use this account
                                    </button>
                                )}
                                <button
                                    className={`${quiet} text-rose-400 hover:text-rose-300 hover:border-rose-500/30`}
                                    type="button"
                                    onClick={() => {
                                        if (confirm(`Unlink ${account.name} (${account.customer_id})?`)) {
                                            router.delete(`/google-ads/accounts/${account.id}`);
                                        }
                                    }}
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Campaign Ownership & Search Campaigns Section */}
            <div>
                <div className="mb-3 flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-theme-primary">Tracked Search Campaigns</h2>
                        <p className="text-xs text-theme-secondary">Campaigns created or tracked by Flow Hub with explicit account ownership.</p>
                    </div>
                    <span className="text-xs text-theme-secondary">{campaigns.length} campaign{campaigns.length === 1 ? '' : 's'}</span>
                </div>

                <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-sm text-theme-primary">
                    {campaigns.length === 0 ? (
                        <div className="p-6 text-center text-sm text-theme-muted">
                            <p>No campaigns tracked yet.</p>
                            <p className="mt-1 text-xs text-theme-secondary">
                                Create a campaign using the "New Campaign" button above or via Artisan command <code className="font-mono text-theme-accent">php artisan google-ads:campaign create --account=ID</code>.
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm divide-y divide-theme">
                                <thead className="border-b border-theme bg-theme-canvas/60 text-xs font-semibold text-theme-secondary uppercase tracking-wider">
                                    <tr>
                                        <th className="px-4 py-3">Campaign</th>
                                        <th className="px-4 py-3">Account Ownership</th>
                                        <th className="px-4 py-3">Assigned Presell Page</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3">Daily Budget</th>
                                        <th className="px-4 py-3">Bidding Strategy</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-theme">
                                    {campaigns.map((camp) => (
                                        <tr key={camp.id} className="hover:bg-theme-subtle/30 transition-colors">
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-theme-primary">{camp.name}</div>
                                                <div className="font-mono text-xs text-theme-muted">ID: {camp.campaign_id}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {camp.account ? (
                                                    <div className="inline-flex items-center gap-1.5 rounded-md border border-theme bg-theme-subtle/70 px-2.5 py-1 text-xs">
                                                        <span className="font-semibold text-theme-primary">{camp.account.name}</span>
                                                        <span className="font-mono text-theme-muted">({camp.account.customer_id})</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-theme-muted">Account #{camp.account_id}</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {camp.pages && camp.pages.length > 0 ? (
                                                    <div className="flex flex-col gap-1">
                                                        {camp.pages.map((p) => (
                                                            <div key={p.id} className="flex items-center gap-1.5 text-xs">
                                                                <Link
                                                                    href={`/pages/${p.id}/edit`}
                                                                    className="font-medium text-theme-accent hover:underline inline-flex items-center gap-1 truncate max-w-[200px]"
                                                                    title={p.name}
                                                                >
                                                                    <span>📄</span>
                                                                    <span className="truncate">{p.name}</span>
                                                                </Link>
                                                                {p.track_id && (
                                                                    <Link
                                                                        href={`/tracks/${p.track_id}`}
                                                                        className="inline-flex rounded border border-theme bg-theme-subtle/50 px-1.5 py-0.5 text-[10px] text-theme-muted hover:text-theme-primary whitespace-nowrap"
                                                                        title={`Linked Track #${p.track_id}${p.product_name ? ` - ${p.product_name}` : ''}`}
                                                                    >
                                                                        Track #{p.track_id}
                                                                    </Link>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-theme-muted italic">None assigned</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                                                    camp.status === 'ENABLED'
                                                        ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                                                        : camp.status === 'REMOVED'
                                                        ? 'bg-rose-500/15 text-rose-400 border border-rose-500/30'
                                                        : 'bg-amber-500/15 text-amber-400 border border-amber-500/30'
                                                }`}>
                                                    {camp.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-medium text-theme-primary">
                                                {camp.budget_amount_micros ? money(camp.budget_amount_micros / 1_000_000) : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs font-mono text-theme-secondary">
                                                {camp.bidding_strategy || 'MANUAL_CPC'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </Layout>
    );
}
