import React, { useState, useMemo, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

export default function Index({ accounts = [], filters = {}, networks = [], statuses = [] }) {
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [selectedNetwork, setSelectedNetwork] = useState(filters.network || 'all');
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

    // Clipboard write with fallback for non-HTTPS origins (e.g. local XAMPP over http)
    const textareaCopy = (text) => {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } finally {
            ta.value = '';
            document.body.removeChild(ta);
        }
        if (!ok) throw new Error('Copy failed');
    };

    // Copy text produced by an async promise. Safari requires the clipboard write to start
    // synchronously inside the user gesture, so pass a promise to ClipboardItem when supported.
    const writeClipboardAsync = async (textPromise) => {
        const secure = navigator.clipboard && window.isSecureContext;
        if (secure && typeof window.ClipboardItem !== 'undefined' && navigator.clipboard.write) {
            try {
                const item = new window.ClipboardItem({
                    'text/plain': textPromise.then((t) => new Blob([t], { type: 'text/plain' })),
                });
                await navigator.clipboard.write([item]);
                return;
            } catch (err) {
                // Surface fetch/HTTP errors; otherwise fall through to other strategies.
                if (err && err.fromFetch) throw err;
            }
        }
        const text = await textPromise;
        if (secure) {
            try {
                await navigator.clipboard.writeText(text);
                return;
            } catch {
                // fall back to textarea
            }
        }
        textareaCopy(text);
    };

    const writeClipboard = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } finally {
            ta.value = '';
            document.body.removeChild(ta);
        }
        if (!ok) throw new Error('Copy failed');
    };

    const handleCopy = (text, label) => {
        if (!text) return;
        writeClipboard(text)
            .then(() => showToast(`Copied ${label} to clipboard!`))
            .catch(() => showToast(`Could not copy ${label}.`));
    };

    const [copyingPasswordId, setCopyingPasswordId] = useState(null);
    const handleCopyPassword = async (account) => {
        if (copyingPasswordId) return;
        setCopyingPasswordId(account.id);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const fail = (msg) => {
            const e = new Error(msg);
            e.fromFetch = true;
            return e;
        };
        const passwordPromise = fetch(`/affiliate-network-accounts/${account.id}/reveal-password`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            cache: 'no-store',
        }).then(async (res) => {
            if (!res.ok) {
                if (res.status === 404) throw fail('No password stored for this account.');
                if (res.status === 429) throw fail('Too many requests. Please wait a moment.');
                if (res.status === 500) throw fail('Could not decrypt password.');
                throw fail('Unable to fetch password.');
            }
            const data = await res.json();
            if (!data.ok || typeof data.password !== 'string') throw fail('No password returned.');
            return data.password;
        });
        try {
            await writeClipboardAsync(passwordPromise);
            showToast('Password copied');
        } catch (err) {
            showToast(err?.message || 'Could not copy password.');
        } finally {
            setCopyingPasswordId(null);
        }
    };

    // Filtered accounts list
    const filteredAccounts = useMemo(() => {
        return accounts.filter((acc) => {
            const matchesStatus = selectedStatus === 'all' || acc.status === selectedStatus;
            const matchesNetwork = selectedNetwork === 'all' || acc.network === selectedNetwork;
            const q = searchQuery.toLowerCase().trim();
            if (!q) return matchesStatus && matchesNetwork;

            const matchesSearch =
                (acc.nickname && acc.nickname.toLowerCase().includes(q)) ||
                (acc.email && acc.email.toLowerCase().includes(q)) ||
                (acc.username && acc.username.toLowerCase().includes(q)) ||
                (acc.account_id && acc.account_id.toLowerCase().includes(q)) ||
                (acc.network_custom && acc.network_custom.toLowerCase().includes(q)) ||
                (acc.display_network_name && acc.display_network_name.toLowerCase().includes(q)) ||
                (acc.notes && acc.notes.toLowerCase().includes(q));

            return matchesStatus && matchesNetwork && matchesSearch;
        });
    }, [accounts, selectedStatus, selectedNetwork, searchQuery]);

    // Status counts
    const statusCounts = useMemo(() => {
        const counts = { all: accounts.length, active: 0, pending_approval: 0, suspended: 0, closed: 0 };
        accounts.forEach((a) => {
            if (counts[a.status] !== undefined) {
                counts[a.status]++;
            }
        });
        return counts;
    }, [accounts]);

    // Form setup for Create / Edit
    const form = useForm({
        network: 'clickbank',
        network_custom: '',
        nickname: '',
        email: '',
        username: '',
        password: '',
        account_id: '',
        status: 'active',
        login_url: '',
        notes: '',
        default_currency: 'USD',
        payment_method_hint: '',
    });

    const openCreateModal = () => {
        setEditingAccount(null);
        clearPasswordState();
        form.reset();
        form.setData({
            network: 'clickbank',
            network_custom: '',
            nickname: '',
            email: '',
            username: '',
            password: '',
            account_id: '',
            status: 'active',
            login_url: '',
            notes: '',
            default_currency: 'USD',
            payment_method_hint: '',
        });
        form.clearErrors();
        setIsModalOpen(true);
    };

    const openEditModal = (account) => {
        setEditingAccount(account);
        clearPasswordState();
        form.clearErrors();
        form.setData({
            network: account.network || 'clickbank',
            network_custom: account.network_custom || '',
            nickname: account.nickname || '',
            email: account.email || '',
            username: account.username || '',
            password: '', // Empty unless updated
            account_id: account.account_id || '',
            status: account.status || 'active',
            login_url: account.login_url || '',
            notes: account.notes || '',
            default_currency: account.default_currency || 'USD',
            payment_method_hint: account.payment_method_hint || '',
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
            const res = await fetch(`/affiliate-network-accounts/${editingAccount.id}/reveal-password`, {
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

    const handleFormSubmit = (e) => {
        e.preventDefault();

        if (editingAccount) {
            form.put(`/affiliate-network-accounts/${editingAccount.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    closeModal();
                    showToast('Affiliate account updated successfully.');
                },
            });
        } else {
            form.post('/affiliate-network-accounts', {
                preserveScroll: true,
                onSuccess: () => {
                    closeModal();
                    showToast('Affiliate account created successfully.');
                },
            });
        }
    };

    const handleDeleteAccount = () => {
        if (!deleteModalAccount) return;

        router.delete(`/affiliate-network-accounts/${deleteModalAccount.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteModalAccount(null);
                showToast('Affiliate account deleted successfully.');
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
        <Layout title="Affiliate Accounts - Credentials Vault">
            {/* Header Section */}
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-[var(--accentColor)]">
                        <span>Credentials Vault</span>
                        <span>/</span>
                        <span>Affiliate Accounts</span>
                    </div>
                    <h1 className="mt-1 text-2xl font-bold tracking-tight text-theme-primary">Affiliate Accounts</h1>
                    <p className="mt-1 text-sm text-theme-secondary">
                        Securely store and manage affiliate network credentials (ClickBank, Digistore24, MaxWeb, etc.) with encrypted passwords.
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
                        <span>Add Affiliate Account</span>
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
                        { key: 'pending_approval', label: 'Pending Approval' },
                        { key: 'suspended', label: 'Suspended' },
                        { key: 'closed', label: 'Closed' },
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

                {/* Network Dropdown & Search Input */}
                <div className="flex flex-col sm:flex-row items-center gap-2">
                    <select
                        value={selectedNetwork}
                        onChange={(e) => setSelectedNetwork(e.target.value)}
                        className={`${control} text-xs py-1.5 w-full sm:w-44`}
                        aria-label="Filter by affiliate network"
                    >
                        <option value="all">All Networks</option>
                        {networks.map((net) => (
                            <option key={net.value} value={net.value}>
                                {net.label}
                            </option>
                        ))}
                    </select>

                    <div className="relative w-full sm:w-64">
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
                            placeholder="Search nickname, username, ID..."
                            className={`${control} pl-9 text-xs py-1.5`}
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
            </div>

            {/* Inventory Table */}
            <div className="overflow-hidden rounded-xl border border-theme bg-theme-surface shadow-2xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm text-theme-secondary">
                        <thead className="border-b border-theme bg-theme-subtle text-xs font-semibold uppercase tracking-wider text-theme-muted">
                            <tr>
                                <th scope="col" className="px-5 py-3.5">Nickname</th>
                                <th scope="col" className="px-5 py-3.5">Network</th>
                                <th scope="col" className="px-5 py-3.5">Username / Email</th>
                                <th scope="col" className="px-5 py-3.5">Account ID</th>
                                <th scope="col" className="px-5 py-3.5">Status</th>
                                <th scope="col" className="px-5 py-3.5">Currency</th>
                                <th scope="col" className="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-theme">
                            {filteredAccounts.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="px-5 py-12 text-center text-sm text-theme-muted">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <svg className="w-10 h-10 text-theme-muted/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                <rect width="18" height="18" x="3" y="3" rx="2" />
                                                <circle cx="12" cy="12" r="3" />
                                                <path d="m14 14 3 3" />
                                            </svg>
                                            <p className="font-medium text-theme-primary">No affiliate accounts found</p>
                                            <p className="text-xs text-theme-secondary max-w-sm">
                                                {searchQuery || selectedStatus !== 'all' || selectedNetwork !== 'all'
                                                    ? 'Try adjusting your search query or filters.'
                                                    : 'Add your first affiliate network login to store credentials encrypted at rest.'}
                                            </p>
                                            {!searchQuery && selectedStatus === 'all' && selectedNetwork === 'all' && (
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
                                            {/* Nickname & Login URL link */}
                                            <td className="px-5 py-4">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[var(--accentSubtleColor)] text-[var(--accentColor)]">
                                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                                            <circle cx="9" cy="7" r="4" />
                                                            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                                            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                                        </svg>
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="font-semibold text-theme-primary truncate">
                                                                {account.nickname}
                                                            </span>
                                                            {account.login_url && (
                                                                <a
                                                                    href={account.login_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="text-theme-muted hover:text-[var(--accentColor)] transition-colors p-0.5"
                                                                    title="Open network login page"
                                                                    aria-label={`Open login page for ${account.nickname}`}
                                                                >
                                                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                                                        <polyline points="15 3 21 3 21 9" />
                                                                        <line x1="10" y1="14" x2="21" y2="3" />
                                                                    </svg>
                                                                </a>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2 mt-0.5">
                                                            <span className="inline-flex items-center gap-1 text-[11px] text-theme-muted">
                                                                <svg className="w-3 h-3 text-[var(--successColor)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
                                                                    <polyline points="9 11 12 14 22 4" />
                                                                </svg>
                                                                Encrypted Password
                                                            </span>
                                                            {account.has_password && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleCopyPassword(account)}
                                                                    disabled={copyingPasswordId === account.id}
                                                                    className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5 disabled:opacity-50"
                                                                    title="Copy password"
                                                                    aria-label={`Copy password for ${account.nickname || account.username || 'account'}`}
                                                                >
                                                                    <svg className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                            {account.notes && (
                                                                <span className="truncate max-w-[200px] text-[11px] text-theme-muted" title={account.notes}>
                                                                    • {account.notes}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            {/* Network Badge */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold border ${account.network_badge}`}>
                                                    {account.display_network_name}
                                                </span>
                                            </td>

                                            {/* Username / Email */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                <div className="flex flex-col gap-0.5">
                                                    {account.username && (
                                                        <div className="flex items-center gap-1 text-xs font-mono font-medium text-theme-primary">
                                                            <span>{account.username}</span>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleCopy(account.username, 'Username')}
                                                                className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5"
                                                                title="Copy username"
                                                                aria-label={`Copy username ${account.username}`}
                                                            >
                                                                <svg className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    )}
                                                    {account.email && (
                                                        <div className="flex items-center gap-1 text-[11px] text-theme-secondary">
                                                            <span className="truncate max-w-[180px]">{account.email}</span>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleCopy(account.email, 'Email')}
                                                                className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5"
                                                                title="Copy email"
                                                                aria-label={`Copy email ${account.email}`}
                                                            >
                                                                <svg className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    )}
                                                    {!account.username && !account.email && (
                                                        <span className="text-xs text-theme-muted">—</span>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Account ID */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                {account.account_id ? (
                                                    <div className="flex items-center gap-1.5 font-mono text-xs text-theme-primary font-medium">
                                                        <span>{account.account_id}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopy(account.account_id, 'Account ID')}
                                                            className="text-theme-muted hover:text-theme-primary transition-colors cursor-pointer p-0.5"
                                                            title="Copy Account ID"
                                                            aria-label={`Copy account ID ${account.account_id}`}
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
                                            </td>

                                            {/* Status Badge */}
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium border ${account.status_badge}`}>
                                                    <span className={`h-1.5 w-1.5 rounded-full ${
                                                        account.status === 'active'
                                                            ? 'bg-emerald-500'
                                                            : account.status === 'pending_approval'
                                                            ? 'bg-sky-500'
                                                            : account.status === 'suspended'
                                                            ? 'bg-amber-500'
                                                            : 'bg-rose-500'
                                                    }`} />
                                                    {account.status_label}
                                                </span>
                                            </td>

                                            {/* Currency */}
                                            <td className="px-5 py-4 whitespace-nowrap text-xs font-semibold text-theme-primary">
                                                {account.default_currency ? (
                                                    <span className="rounded-md bg-theme-subtle px-2 py-0.5 text-theme-secondary font-mono">
                                                        {account.default_currency}
                                                    </span>
                                                ) : (
                                                    <span className="text-theme-muted">—</span>
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
                                                        aria-label={`Delete account ${account.nickname}`}
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
                    aria-labelledby="affiliate-modal-title"
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
                                <h2 id="affiliate-modal-title" className="text-lg font-bold text-theme-primary">
                                    {editingAccount ? 'Edit Affiliate Account' : 'Add Affiliate Network Account'}
                                </h2>
                                <p className="text-xs text-theme-secondary mt-0.5">
                                    Passwords are encrypted with AES-256-CBC and only unmasked upon authenticated demand.
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
                            {/* Network & Nickname */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Affiliate Network" error={form.errors.network}>
                                    <select
                                        value={form.data.network}
                                        onChange={(e) => form.setData('network', e.target.value)}
                                        className={control}
                                    >
                                        {networks.map((net) => (
                                            <option key={net.value} value={net.value}>
                                                {net.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Account Nickname / Label" error={form.errors.nickname}>
                                    <input
                                        type="text"
                                        required
                                        value={form.data.nickname}
                                        onChange={(e) => form.setData('nickname', e.target.value)}
                                        placeholder="e.g. ClickBank US Master #1"
                                        className={control}
                                    />
                                </Field>
                            </div>

                            {/* Custom Network input when 'other' is selected */}
                            {form.data.network === 'other' && (
                                <Field label="Custom Network Name" error={form.errors.network_custom}>
                                    <input
                                        type="text"
                                        required
                                        value={form.data.network_custom}
                                        onChange={(e) => form.setData('network_custom', e.target.value)}
                                        placeholder="e.g. CJ Affiliate, ShareASale, Impact..."
                                        className={control}
                                    />
                                </Field>
                            )}

                            {/* Username & Email */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Username / Login Handle" error={form.errors.username}>
                                    <input
                                        type="text"
                                        value={form.data.username}
                                        onChange={(e) => form.setData('username', e.target.value)}
                                        placeholder="e.g. affiliate_top"
                                        className={control}
                                    />
                                </Field>

                                <Field label="Login Email" error={form.errors.email}>
                                    <input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        placeholder="affiliate@company.com"
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
                                                    : 'Enter network account password'
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

                            {/* Account ID, Status, Currency */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <Field label="Account / Vendor ID" error={form.errors.account_id}>
                                    <input
                                        type="text"
                                        value={form.data.account_id}
                                        onChange={(e) => form.setData('account_id', e.target.value)}
                                        placeholder="e.g. TOPAFFIL"
                                        className={control}
                                    />
                                </Field>

                                <Field label="Status" error={form.errors.status}>
                                    <select
                                        value={form.data.status}
                                        onChange={(e) => form.setData('status', e.target.value)}
                                        className={control}
                                    >
                                        <option value="active">Active</option>
                                        <option value="pending_approval">Pending Approval</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="closed">Closed</option>
                                    </select>
                                </Field>

                                <Field label="Default Currency" error={form.errors.default_currency}>
                                    <input
                                        type="text"
                                        maxLength="3"
                                        value={form.data.default_currency}
                                        onChange={(e) => form.setData('default_currency', e.target.value.toUpperCase())}
                                        placeholder="USD"
                                        className={`${control} font-mono uppercase`}
                                    />
                                </Field>
                            </div>

                            {/* Login URL & Payment Hint */}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label="Network Login URL (Optional)" error={form.errors.login_url}>
                                    <input
                                        type="url"
                                        value={form.data.login_url}
                                        onChange={(e) => form.setData('login_url', e.target.value)}
                                        placeholder="https://accounts.clickbank.com/login"
                                        className={control}
                                    />
                                </Field>

                                <Field label="Payment Method Hint (Optional)" error={form.errors.payment_method_hint}>
                                    <input
                                        type="text"
                                        value={form.data.payment_method_hint}
                                        onChange={(e) => form.setData('payment_method_hint', e.target.value)}
                                        placeholder="e.g. Wise USD / Bank ending in 4102"
                                        className={control}
                                    />
                                </Field>
                            </div>

                            {/* Notes */}
                            <Field label="Notes (Optional)" error={form.errors.notes}>
                                <textarea
                                    rows="3"
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Commission tier, affiliate manager Skype, security answers, 2FA backup codes..."
                                    className={control}
                                />
                            </Field>

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
                                    Delete Affiliate Account?
                                </h3>
                                <p className="text-xs text-theme-secondary mt-0.5">
                                    This action will permanently remove credentials for this account.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 rounded-lg bg-theme-subtle p-3 text-xs font-mono text-theme-primary">
                            <div><strong>Nickname:</strong> {deleteModalAccount.nickname}</div>
                            <div><strong>Network:</strong> {deleteModalAccount.display_network_name}</div>
                            {deleteModalAccount.username && <div><strong>Username:</strong> {deleteModalAccount.username}</div>}
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
