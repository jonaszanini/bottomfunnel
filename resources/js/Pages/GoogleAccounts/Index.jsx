import React, { useState, useMemo, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

export default function Index({ accounts = [], filters = {}, googleAdsAccounts = [], statuses = [] }) {
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [searchQuery, setSearchQuery] = useState(filters.search || '');

    // Modal state for Create / Edit
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingAccount, setEditingAccount] = useState(null);
    const [deleteModalAccount, setDeleteModalAccount] = useState(null);

    // Password reveal state (strictly cleared when modal closes)
    const [revealedPassword, setRevealedPassword] = useState('');
    const [isPasswordRevealed, setIsPasswordRevealed] = useState(false);
    const [isRevealingPassword, setIsRevealingPassword] = useState(false);
    const [revealError, setRevealError] = useState('');

    // Toast notification state
    const [toastMessage, setToastMessage] = useState('');
    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(''), 2500);
    };

    const handleCopy = (text, label) => {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            showToast(`Copied ${label} to clipboard!`);
        });
    };

    // Filtered accounts list
    const filteredAccounts = useMemo(() => {
        return accounts.filter((acc) => {
            const matchesStatus = selectedStatus === 'all' || acc.status === selectedStatus;
            const q = searchQuery.toLowerCase().trim();
            if (!q) return matchesStatus;

            const digits = q.replace(/\D/g, '');
            const matchesSearch =
                acc.email.toLowerCase().includes(q) ||
                (acc.notes && acc.notes.toLowerCase().includes(q)) ||
                (digits && ((acc.customer_id && acc.customer_id.includes(digits)) || (acc.login_customer_id && acc.login_customer_id.includes(digits)))) ||
                (acc.formatted_customer_id && acc.formatted_customer_id.toLowerCase().includes(q));

            return matchesStatus && matchesSearch;
        });
    }, [accounts, selectedStatus, searchQuery]);

    // Status counts
    const statusCounts = useMemo(() => {
        const counts = { all: accounts.length, active: 0, suspended: 0, cancelled: 0 };
        accounts.forEach((a) => {
            if (counts[a.status] !== undefined) {
                counts[a.status]++;
            }
        });
        return counts;
    }, [accounts]);

    // Form setup for Create / Edit
    const form = useForm({
        email: '',
        password: '',
        customer_id: '',
        login_customer_id: '',
        status: 'active',
        currency: 'USD',
        notes: '',
        linked_google_ads_account_id: '',
        campaigns: [],
    });

    const openCreateModal = () => {
        setEditingAccount(null);
        clearPasswordState();
        form.reset();
        form.setData({
            email: '',
            password: '',
            customer_id: '',
            login_customer_id: '',
            status: 'active',
            currency: 'USD',
            notes: '',
            linked_google_ads_account_id: '',
            campaigns: [],
        });
        form.clearErrors();
        setIsModalOpen(true);
    };

    const openEditModal = (account) => {
        setEditingAccount(account);
        clearPasswordState();
        form.clearErrors();
        form.setData({
            email: account.email || '',
            password: '', // Empty unless updated
            customer_id: account.customer_id || '',
            login_customer_id: account.login_customer_id || '',
            status: account.status || 'active',
            currency: account.currency || 'USD',
            notes: account.notes || '',
            linked_google_ads_account_id: account.linked_google_ads_account_id || '',
            campaigns: (account.campaigns || []).map((c) => ({
                id: c.id,
                campaign_id: c.campaign_id,
                campaign_name: c.campaign_name || '',
                status: c.status || 'active',
            })),
        });
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingAccount(null);
        clearPasswordState();
        form.reset();
        form.clearErrors();
    };

    // Critical security: Zero decrypted passwords in React state on close
    const clearPasswordState = () => {
        setRevealedPassword('');
        setIsPasswordRevealed(false);
        setIsRevealingPassword(false);
        setRevealError('');
    };

    // Toggle reveal password for editing account
    const handleToggleReveal = async () => {
        if (!editingAccount) {
            // In create mode: simply toggle visibility of typed password
            setIsPasswordRevealed((prev) => !prev);
            return;
        }

        if (isPasswordRevealed) {
            // Mask password again
            setIsPasswordRevealed(false);
            return;
        }

        // If we already fetched the decrypted password, just reveal
        if (revealedPassword) {
            setIsPasswordRevealed(true);
            return;
        }

        // Fetch decrypted password from protected endpoint
        setIsRevealingPassword(true);
        setRevealError('');

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch(`/google-login-accounts/${editingAccount.id}/reveal-password`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            if (!res.ok) {
                if (res.status === 429) {
                    throw new Error('Too many requests. Please wait a moment before revealing again.');
                }
                throw new Error('Unable to decrypt password.');
            }

            const data = await res.json();
            if (data.ok && typeof data.password === 'string') {
                setRevealedPassword(data.password);
                setIsPasswordRevealed(true);
            } else {
                throw new Error('No password returned.');
            }
        } catch (err) {
            setRevealError(err.message || 'Error revealing password.');
        } finally {
            setIsRevealingPassword(false);
        }
    };

    // Campaign list row handlers
    const addCampaignRow = () => {
        form.setData('campaigns', [
            ...form.data.campaigns,
            { campaign_id: '', campaign_name: '', status: 'active' },
        ]);
    };

    const updateCampaignRow = (index, field, value) => {
        const next = [...form.data.campaigns];
        next[index] = { ...next[index], [field]: value };
        form.setData('campaigns', next);
    };

    const removeCampaignRow = (index) => {
        const next = form.data.campaigns.filter((_, i) => i !== index);
        form.setData('campaigns', next);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();

        if (editingAccount) {
            form.put(`/google-login-accounts/${editingAccount.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    closeModal();
                    showToast('Account updated successfully.');
                },
                onError: (errors) => {
                    const first = Object.values(errors || {})[0];
                    showToast(first ? `Save failed: ${first}` : 'Save failed. Please check the form and try again.');
                },
            });
        } else {
            form.post('/google-login-accounts', {
                preserveScroll: true,
                onSuccess: () => {
                    closeModal();
                    showToast('Account created successfully.');
                },
                onError: (errors) => {
                    const first = Object.values(errors || {})[0];
                    showToast(first ? `Save failed: ${first}` : 'Save failed. Please check the form and try again.');
                },
            });
        }
    };

    const handleDeleteAccount = () => {
        if (!deleteModalAccount) return;

        router.delete(`/google-login-accounts/${deleteModalAccount.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteModalAccount(null);
                showToast('Account deleted successfully.');
            },
        });
    };

    // Keyboard support: Close modal on Escape
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.key === 'Escape') {
                if (isModalOpen) closeModal();
                if (deleteModalAccount) setDeleteModalAccount(null);
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isModalOpen, deleteModalAccount]);

    return (
        <Layout title="Google Accounts Inventory">
            {/* Header Section */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-theme-primary">Google Accounts</h1>
                    <p className="mt-1 text-sm text-theme-secondary">
                        Manually managed Google Ads login credentials with encrypted password storage and linked campaigns.
                    </p>
                </div>
                <div>
                    <button
                        type="button"
                        onClick={openCreateModal}
                        className={`${button} flex items-center gap-2 shadow-xs`}
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        <span>Add Account</span>
                    </button>
                </div>
            </div>

            {/* Filter & Search Bar */}
            <div className="mb-6 flex flex-col gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-2xs md:flex-row md:items-center md:justify-between">
                {/* Status Tabs */}
                <div className="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Account status filters">
                    {[
                        { key: 'all', label: 'All Accounts' },
                        { key: 'active', label: 'Active' },
                        { key: 'suspended', label: 'Suspended' },
                        { key: 'cancelled', label: 'Cancelled' },
                    ].map((tab) => {
                        const isSelected = selectedStatus === tab.key;
                        const count = statusCounts[tab.key] ?? 0;
                        return (
                            <button
                                key={tab.key}
                                type="button"
                                role="tab"
                                aria-selected={isSelected}
                                onClick={() => setSelectedStatus(tab.key)}
                                className={`flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer ${
                                    isSelected
                                        ? 'bg-[var(--accentSubtleColor)] text-[var(--accentColor)] font-semibold shadow-2xs'
                                        : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle'
                                }`}
                            >
                                <span>{tab.label}</span>
                                <span className={`rounded-full px-1.5 py-0.2 text-[10px] ${isSelected ? 'bg-[var(--accentColor)] text-white' : 'bg-theme-subtle text-theme-muted'}`}>
                                    {count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* Search Input */}
                <div className="relative min-w-[260px] md:w-72">
                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-theme-muted">
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search email, CID, notes..."
                        className={`${control} pl-9 text-xs`}
                    />
                    {searchQuery && (
                        <button
                            type="button"
                            onClick={() => setSearchQuery('')}
                            className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-theme-muted hover:text-theme-primary cursor-pointer"
                            aria-label="Clear search"
                        >
                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    )}
                </div>
            </div>

            {/* Inventory Table */}
            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-2xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-theme-secondary">
                        <thead className="border-b border-theme bg-theme-subtle text-xs font-semibold uppercase tracking-wider text-theme-muted">
                            <tr>
                                <th scope="col" className="px-5 py-3.5">Email & Credentials</th>
                                <th scope="col" className="px-5 py-3.5">Customer ID</th>
                                <th scope="col" className="px-5 py-3.5">Status</th>
                                <th scope="col" className="px-5 py-3.5">Currency</th>
                                <th scope="col" className="px-5 py-3.5">Linked Campaigns</th>
                                <th scope="col" className="px-5 py-3.5">OAuth API Account</th>
                                <th scope="col" className="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-theme">
                            {filteredAccounts.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="px-5 py-12 text-center text-sm text-theme-muted">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <svg className="w-10 h-10 text-theme-muted/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                            </svg>
                                            <p className="font-medium text-theme-primary">No Google login accounts found</p>
                                            <p className="text-xs text-theme-secondary max-w-sm">
                                                {searchQuery || selectedStatus !== 'all'
                                                    ? 'Try adjusting your search query or status filter.'
                                                    : 'Add your first manually managed Google Ads login credentials to store them securely.'}
                                            </p>
                                            {!searchQuery && selectedStatus === 'all' && (
                                                <button
                                                    type="button"
                                                    onClick={openCreateModal}
                                                    className={`${button} mt-2 text-xs`}
                                                >
                                                    Add First Account
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredAccounts.map((account) => {
                                    return (
                                        <tr key={account.id} className="hover:bg-theme-subtle/50 transition-colors">
                                            {/* Email & Credentials */}
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--accentSubtleColor)] text-[var(--accentColor)]">
                                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                                        </svg>
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="font-semibold text-theme-primary truncate">
                                                                {account.email}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleCopy(account.email, 'Email')}
                                                                className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5"
                                                                title="Copy email"
                                                                aria-label={`Copy email ${account.email}`}
                                                            >
                                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                        <div className="flex items-center gap-2 mt-0.5">
                                                            <span className="inline-flex items-center gap-1 text-[11px] text-theme-muted">
                                                                <svg className="w-3 h-3 text-[var(--successColor)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                                                                    <polyline points="9 11 12 14 22 4" />
                                                                </svg>
                                                                Encrypted Password
                                                            </span>
                                                            {account.notes && (
                                                                <span className="truncate max-w-[200px] text-[11px] text-theme-muted" title={account.notes}>
                                                                    • {account.notes}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Customer ID */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                {account.customer_id ? (
                                                    <div className="flex items-center gap-1.5 font-mono text-xs text-theme-primary font-medium">
                                                        <span>{account.formatted_customer_id || account.customer_id}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopy(account.customer_id, 'Customer ID')}
                                                            className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5"
                                                            title="Copy Customer ID"
                                                            aria-label={`Copy customer ID ${account.customer_id}`}
                                                        >
                                                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-theme-muted">—</span>
                                                )}
                                                {account.login_customer_id && (
                                                    <div className="text-[11px] text-theme-muted mt-0.5 font-mono">
                                                        MCC: {account.login_customer_id}
                                                    </div>
                                                )}
                                            </td>

                                            {/* Status Badge */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium border ${account.status_badge}`}>
                                                    <span className={`h-1.5 w-1.5 rounded-full ${
                                                        account.status === 'active'
                                                            ? 'bg-emerald-500'
                                                            : account.status === 'suspended'
                                                            ? 'bg-amber-500'
                                                            : 'bg-rose-500'
                                                    }`} />
                                                    {account.status_label}
                                                </span>
                                            </td>

                                            {/* Currency */}
                                            <td className="px-5 py-4 whitespace-nowrap text-xs font-semibold text-theme-primary">
                                                {account.currency ? (
                                                    <span className="rounded-md bg-theme-subtle px-2 py-0.5 text-theme-secondary font-mono">
                                                        {account.currency}
                                                    </span>
                                                ) : (
                                                    <span className="text-theme-muted">—</span>
                                                )}
                                            </td>

                                            {/* Campaigns Chips / Count */}
                                            <td className="px-5 py-4">
                                                {account.campaigns_count > 0 ? (
                                                    <div className="flex flex-col gap-1">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <span className="inline-flex items-center rounded-md bg-[var(--accentSubtleColor)] px-2 py-0.5 text-xs font-semibold text-[var(--accentColor)]">
                                                                {account.campaigns_count} {account.campaigns_count === 1 ? 'campaign' : 'campaigns'}
                                                            </span>
                                                        </div>
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            {account.campaigns.slice(0, 2).map((camp) => (
                                                                <span
                                                                    key={camp.id || camp.campaign_id}
                                                                    className="inline-flex items-center gap-1 rounded bg-theme-subtle px-1.5 py-0.5 text-[11px] text-theme-secondary font-mono"
                                                                    title={camp.campaign_name || camp.campaign_id}
                                                                >
                                                                    <span>{camp.campaign_id}</span>
                                                                    {camp.campaign_name && (
                                                                        <span className="text-theme-muted font-sans font-normal truncate max-w-[80px]">
                                                                            ({camp.campaign_name})
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            ))}
                                                            {account.campaigns.length > 2 && (
                                                                <span className="text-[11px] text-theme-muted">
                                                                    +{account.campaigns.length - 2} more
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-theme-muted">0 linked</span>
                                                )}
                                            </td>

                                            {/* Linked OAuth Account */}
                                            <td className="px-5 py-4 whitespace-nowrap text-xs">
                                                {account.linked_google_ads_account ? (
                                                    <div className="flex items-center gap-1 text-theme-primary font-medium">
                                                        <svg className="w-3.5 h-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                                        </svg>
                                                        <span>{account.linked_google_ads_account.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-theme-muted">Not connected</span>
                                                )}
                                            </td>

                                            {/* Actions */}
                                            <td className="px-5 py-4 text-right whitespace-nowrap">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditModal(account)}
                                                        className={`${quiet} py-1 px-2.5 text-xs flex items-center gap-1`}
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                            <path d="M12 20h9" />
                                                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                                        </svg>
                                                        <span>Edit</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleteModalAccount(account)}
                                                        className="rounded-md border border-theme p-1 text-theme-muted hover:text-rose-500 hover:border-rose-500/30 hover:bg-rose-500/10 transition-colors cursor-pointer"
                                                        title="Delete account"
                                                        aria-label={`Delete account ${account.email}`}
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                            <polyline points="3 6 5 6 21 6" />
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Create / Edit Modal Dialog */}
            {isModalOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 overflow-y-auto"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="account-modal-title"
                >
                    {/* Backdrop */}
                    <div
                        className="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
                        onClick={closeModal}
                    />

                    {/* Modal Content */}
                    <div className="relative w-full max-w-2xl rounded-2xl border border-theme bg-theme-surface p-6 shadow-xl z-10 max-h-[90vh] flex flex-col my-auto">
                        {/* Header */}
                        <div className="flex items-center justify-between pb-4 border-b border-theme shrink-0">
                            <div>
                                <h2 id="account-modal-title" className="text-lg font-bold text-theme-primary">
                                    {editingAccount ? 'Edit Google Account' : 'Add Google Login Account'}
                                </h2>
                                <p className="text-xs text-theme-secondary mt-0.5">
                                    Passwords are encrypted at rest with AES-256-CBC and only revealed on explicit request.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={closeModal}
                                className="rounded-lg p-1.5 text-theme-muted hover:text-theme-primary hover:bg-theme-subtle transition-colors cursor-pointer"
                                aria-label="Close modal"
                            >
                                <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <line x1="18" y1="6" x2="6" y2="18" />
                                    <line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                            </button>
                        </div>

                        {/* Form */}
                        <form onSubmit={handleFormSubmit} className="flex-1 overflow-y-auto py-4 space-y-4 pr-1">
                            {/* Email & Customer ID */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Google Login Email" error={form.errors.email}>
                                    <input
                                        type="email"
                                        required
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        placeholder="ads.login@company.com"
                                        className={control}
                                    />
                                </Field>

                                <Field label="Customer ID (Google Ads ID - Optional)" error={form.errors.customer_id}>
                                    <input
                                        type="text"
                                        value={form.data.customer_id}
                                        onChange={(e) => form.setData('customer_id', e.target.value)}
                                        placeholder="123-456-7890 or 1234567890 (optional)"
                                        className={control}
                                    />
                                </Field>
                            </div>

                            {/* Password Field with Masking and Reveal Button */}
                            <div>
                                <Field
                                    label={
                                        editingAccount
                                            ? 'Password (Leave blank to keep existing encrypted password)'
                                            : 'Password (Encrypted at rest)'
                                    }
                                    error={form.errors.password}
                                >
                                    <div className="relative">
                                        <input
                                            type={isPasswordRevealed ? 'text' : 'password'}
                                            required={!editingAccount}
                                            value={
                                                editingAccount && !form.data.password && isPasswordRevealed && revealedPassword
                                                    ? revealedPassword
                                                    : form.data.password
                                            }
                                            onChange={(e) => {
                                                form.setData('password', e.target.value);
                                                if (editingAccount) {
                                                    setRevealedPassword(e.target.value);
                                                }
                                            }}
                                            placeholder={
                                                editingAccount
                                                    ? (isPasswordRevealed && revealedPassword ? revealedPassword : '••••••••••••••••')
                                                    : 'Enter account password'
                                            }
                                            className={`${control} pr-20`}
                                        />
                                        <div className="absolute inset-y-0 right-0 flex items-center pr-1.5 gap-1">
                                            {/* Copy button if revealed */}
                                            {isPasswordRevealed && (revealedPassword || form.data.password) && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleCopy(form.data.password || revealedPassword, 'Password')}
                                                    className="p-1 text-theme-muted hover:text-theme-primary transition-colors cursor-pointer"
                                                    title="Copy decrypted password"
                                                    aria-label="Copy password"
                                                >
                                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                    </svg>
                                                </button>
                                            )}
                                            {/* Eye Toggle Reveal Button */}
                                            <button
                                                type="button"
                                                onClick={handleToggleReveal}
                                                disabled={isRevealingPassword}
                                                className="rounded p-1 text-theme-muted hover:text-theme-primary transition-colors cursor-pointer disabled:opacity-50"
                                                title={isPasswordRevealed ? 'Mask password' : 'Reveal password'}
                                                aria-label={isPasswordRevealed ? 'Mask password' : 'Reveal password'}
                                            >
                                                {isRevealingPassword ? (
                                                    <svg className="w-4 h-4 animate-spin text-[var(--accentColor)]" viewBox="0 0 24 24" fill="none">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                    </svg>
                                                ) : isPasswordRevealed ? (
                                                    <svg className="w-4 h-4 text-[var(--accentColor)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                                        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                                        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                                        <line x1="2" y1="2" x2="22" y2="22" />
                                                    </svg>
                                                ) : (
                                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg>
                                                )}
                                            </button>
                                        </div>
                                    </div>
                                    {revealError && (
                                        <p className="mt-1 text-xs text-rose-500 font-medium">{revealError}</p>
                                    )}
                                </Field>
                            </div>

                            {/* Status, Currency, Login Customer ID */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <Field label="Account Status" error={form.errors.status}>
                                    <select
                                        value={form.data.status}
                                        onChange={(e) => form.setData('status', e.target.value)}
                                        className={control}
                                    >
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </Field>

                                <Field label="Currency" error={form.errors.currency}>
                                    <input
                                        type="text"
                                        maxLength="3"
                                        value={form.data.currency}
                                        onChange={(e) => form.setData('currency', e.target.value.toUpperCase())}
                                        placeholder="USD"
                                        className={`${control} font-mono uppercase`}
                                    />
                                </Field>

                                <Field label="Login CID (MCC - Optional)" error={form.errors.login_customer_id}>
                                    <input
                                        type="text"
                                        value={form.data.login_customer_id}
                                        onChange={(e) => form.setData('login_customer_id', e.target.value)}
                                        placeholder="Manager ID"
                                        className={control}
                                    />
                                </Field>
                            </div>

                            {/* Link to OAuth Google Ads Account */}
                            <Field label="Link to Connected OAuth Account (Optional)" error={form.errors.linked_google_ads_account_id}>
                                <select
                                    value={form.data.linked_google_ads_account_id || ''}
                                    onChange={(e) => form.setData('linked_google_ads_account_id', e.target.value ? Number(e.target.value) : '')}
                                    className={control}
                                >
                                    <option value="">— None (Manual Only) —</option>
                                    {googleAdsAccounts.map((acc) => (
                                        <option key={acc.id} value={acc.id}>
                                            {acc.name} ({acc.customer_id})
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {/* Notes */}
                            <Field label="Notes (Optional)" error={form.errors.notes}>
                                <textarea
                                    rows="2"
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Billing details, proxy IP, recovery email, card info..."
                                    className={control}
                                />
                            </Field>

                            {/* Linked Campaigns Section */}
                            <div className="rounded-xl border border-theme bg-theme-subtle/40 p-4 space-y-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h3 className="text-xs font-bold uppercase tracking-wider text-theme-primary">
                                            Linked Campaigns ({form.data.campaigns.length})
                                        </h3>
                                        <p className="text-[11px] text-theme-muted mt-0.5">
                                            Associate Google Ads search or display campaign IDs with this login.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={addCampaignRow}
                                        className={`${quiet} py-1 px-2.5 text-xs flex items-center gap-1 font-medium`}
                                    >
                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                            <line x1="12" y1="5" x2="12" y2="19" />
                                            <line x1="5" y1="12" x2="19" y2="12" />
                                        </svg>
                                        <span>Add Campaign</span>
                                    </button>
                                </div>

                                {form.data.campaigns.length === 0 ? (
                                    <p className="text-xs text-theme-muted italic py-1">
                                        No campaigns attached yet. Click "Add Campaign" to link campaign IDs.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {form.data.campaigns.map((camp, idx) => (
                                            <div key={idx} className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    required
                                                    value={camp.campaign_id}
                                                    onChange={(e) => updateCampaignRow(idx, 'campaign_id', e.target.value)}
                                                    placeholder="Campaign ID (e.g. 21087453123)"
                                                    className={`${control} font-mono text-xs flex-1`}
                                                />
                                                <input
                                                    type="text"
                                                    value={camp.campaign_name}
                                                    onChange={(e) => updateCampaignRow(idx, 'campaign_name', e.target.value)}
                                                    placeholder="Campaign Name (optional)"
                                                    className={`${control} text-xs flex-1`}
                                                />
                                                <select
                                                    value={camp.status || 'active'}
                                                    onChange={(e) => updateCampaignRow(idx, 'status', e.target.value)}
                                                    className={`${control} text-xs w-24`}
                                                >
                                                    <option value="active">Active</option>
                                                    <option value="paused">Paused</option>
                                                    <option value="removed">Removed</option>
                                                </select>
                                                <button
                                                    type="button"
                                                    onClick={() => removeCampaignRow(idx)}
                                                    className="p-1.5 text-theme-muted hover:text-rose-500 transition-colors cursor-pointer"
                                                    title="Remove campaign"
                                                    aria-label="Remove campaign"
                                                >
                                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                        <line x1="18" y1="6" x2="6" y2="18" />
                                                        <line x1="6" y1="6" x2="18" y2="18" />
                                                    </svg>
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* Footer Buttons */}
                            <div className="pt-4 border-t border-theme flex items-center justify-end gap-2 shrink-0">
                                <button
                                    type="button"
                                    onClick={closeModal}
                                    className={quiet}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className={`${button} min-w-[120px] flex items-center justify-center gap-2`}
                                >
                                    {form.processing ? (
                                        <>
                                            <svg className="w-4 h-4 animate-spin text-white" viewBox="0 0 24 24" fill="none">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                            <span>Saving...</span>
                                        </>
                                    ) : (
                                        <span>{editingAccount ? 'Update Account' : 'Save Account'}</span>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Delete Confirmation Modal */}
            {deleteModalAccount && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="delete-dialog-title"
                >
                    <div
                        className="fixed inset-0 bg-black/60 backdrop-blur-xs"
                        onClick={() => setDeleteModalAccount(null)}
                    />
                    <div className="relative w-full max-w-md rounded-2xl border border-theme bg-theme-surface p-6 shadow-xl z-10">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-500/10 text-rose-500">
                                <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <polyline points="3 6 5 6 21 6" />
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                </svg>
                            </div>
                            <div>
                                <h3 id="delete-dialog-title" className="text-base font-bold text-theme-primary">
                                    Delete Google Account?
                                </h3>
                                <p className="text-xs text-theme-secondary mt-0.5">
                                    This action will remove credentials and linked campaign associations.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 rounded-lg bg-theme-subtle p-3 text-xs font-mono text-theme-primary">
                            <div><strong>Email:</strong> {deleteModalAccount.email}</div>
                            <div><strong>Customer ID:</strong> {deleteModalAccount.formatted_customer_id || deleteModalAccount.customer_id || '—'}</div>
                        </div>

                        <div className="mt-6 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setDeleteModalAccount(null)}
                                className={quiet}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleDeleteAccount}
                                className="rounded-md bg-rose-600 px-3 py-2 text-sm font-medium text-white hover:bg-rose-700 transition-colors cursor-pointer"
                            >
                                Delete Account
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Quick Toast Notification */}
            {toastMessage && (
                <div
                    role="status"
                    className="fixed bottom-5 right-5 z-50 flex items-center gap-2 rounded-lg bg-zinc-900 border border-zinc-800 px-4 py-2.5 text-xs font-medium text-zinc-100 shadow-xl dark:bg-zinc-100 dark:border-zinc-200 dark:text-zinc-900"
                >
                    <svg className="w-4 h-4 text-emerald-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                    </svg>
                    <span>{toastMessage}</span>
                </div>
            )}
        </Layout>
    );
}
