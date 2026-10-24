import React from 'react';
import { Link } from '@inertiajs/react';
import { control } from './Layout';

export default function GoogleAdsAccountSelect({
    accounts = [],
    value = '',
    onChange,
    error,
    placeholder = 'Choose a Google Ads account...',
    disabled = false,
    required = false,
    className = '',
    showDetails = true,
}) {
    const selectedId = value ? String(value) : '';
    const selectedAccount = accounts.find((a) => String(a.id) === selectedId);

    function handleChange(event) {
        const val = event.target.value;
        const matched = accounts.find((a) => String(a.id) === String(val)) || null;
        if (onChange) {
            onChange(val ? Number(val) : '', matched);
        }
    }

    if (!accounts || accounts.length === 0) {
        return (
            <div className={`rounded-lg border border-[var(--warningBorderColor)] bg-[var(--warningBackgroundColor)] p-3 text-sm text-[var(--warningColor)] ${className}`}>
                <div className="flex items-center justify-between">
                    <span>No Google Ads account linked yet.</span>
                    <Link href="/google-ads" className="font-medium underline hover:text-[var(--accentColor)]">
                        Link Account
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className={`space-y-2 ${className}`}>
            <select
                className={`${control} cursor-pointer`}
                value={selectedId}
                onChange={handleChange}
                disabled={disabled}
                required={required}
            >
                <option value="">{placeholder}</option>
                {accounts.map((account) => (
                    <option key={account.id} value={account.id}>
                        {account.name} ({account.customer_id})
                        {account.is_default ? ' ★ Default' : ''}
                    </option>
                ))}
            </select>

            {showDetails && selectedAccount && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-theme bg-theme-subtle/50 px-3 py-2 text-xs text-theme-secondary">
                    <span className="font-semibold text-theme-primary">{selectedAccount.name}</span>
                    <span className="font-mono text-theme-muted">ID: {selectedAccount.customer_id}</span>
                    {selectedAccount.login_customer_id && (
                        <span className="font-mono text-theme-muted">MCC: {selectedAccount.login_customer_id}</span>
                    )}
                    {selectedAccount.is_default && (
                        <span className="rounded-full bg-emerald-500/15 border border-emerald-500/30 px-2 py-0.5 text-[11px] font-medium text-emerald-400">
                            ★ Default Account
                        </span>
                    )}
                    <span className="rounded-full bg-theme-surface border border-theme px-2 py-0.5 text-[11px] capitalize text-theme-secondary">
                        {selectedAccount.status || 'linked'}
                    </span>
                </div>
            )}

            {error && <span className="block text-sm text-[var(--dangerColor)]">{error}</span>}
        </div>
    );
}
