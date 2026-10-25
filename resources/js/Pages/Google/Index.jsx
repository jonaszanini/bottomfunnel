import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet, money } from '../../Components/Layout';
import GoogleAdsAccountSelect from '../../Components/GoogleAdsAccountSelect';
import CampaignCreationModal from '../../Components/CampaignCreationModal';

export default function Index({ accounts = [], campaigns = [], default_account_id, pending = [] }) {
    const [showCampaignModal, setShowCampaignModal] = useState(false);

    const defaultAccId = default_account_id || accounts.find((a) => a.is_default)?.id || (accounts.length > 0 ? accounts[0].id : '');

    const accountForm = useForm({
        customer_id: pending[0] || '',
        login_customer_id: '',
    });

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

            {/* Campaign Creation Modal */}
            <CampaignCreationModal
                isOpen={showCampaignModal}
                onClose={() => setShowCampaignModal(false)}
                accounts={accounts}
                defaultAccountId={defaultAccId}
                onSuccess={() => {
                    router.reload({ only: ['campaigns', 'accounts'] });
                }}
            />

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
                        <div className="p-8 text-center text-sm text-theme-muted space-y-3">
                            <p className="font-medium text-theme-primary">No campaigns tracked yet.</p>
                            <p className="text-xs text-theme-secondary max-w-sm mx-auto">
                                Launch a new Google Ads Search campaign with targeting, ad group, and keywords without touching the terminal.
                            </p>
                            <div className="pt-1">
                                <button
                                    type="button"
                                    onClick={() => setShowCampaignModal(true)}
                                    disabled={accounts.length === 0}
                                    className={`${button} text-xs`}
                                >
                                    + New Campaign
                                </button>
                            </div>
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
