import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Sidebar, { FlowHubLogo } from './Sidebar';
import ThemeToggle from './ThemeToggle';

export default function Layout({ title, children }) {
    const { auth, flash } = usePage().props;
    const url = usePage().url;
    const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);

    // Automatically close mobile sidebar on navigation / URL change
    useEffect(() => {
        setIsMobileSidebarOpen(false);
    }, [url]);

    return (
        <div className="min-h-screen bg-theme-canvas text-theme-primary">
            <Head title={title} />

            {/* Sidebar Component (Desktop Fixed + Mobile Off-Canvas Drawer) */}
            <Sidebar
                isOpen={isMobileSidebarOpen}
                onClose={() => setIsMobileSidebarOpen(false)}
                currentUrl={url}
                auth={auth}
            />

            {/* Main Application Wrapper: Offset for desktop sidebar */}
            <div className="lg:pl-64 flex flex-col min-h-screen">
                {/* Mobile Top Header Bar: Sticky on small screens */}
                <header className="lg:hidden sticky top-0 z-20 flex h-16 items-center justify-between border-b border-theme bg-theme-surface px-4 shadow-xs">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setIsMobileSidebarOpen(true)}
                            className="rounded-lg p-2 text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] cursor-pointer"
                            aria-label="Open navigation menu"
                            aria-expanded={isMobileSidebarOpen}
                            aria-controls="mobile-sidebar-drawer"
                        >
                            <svg className="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <line x1="4" y1="6" x2="20" y2="6" />
                                <line x1="4" y1="12" x2="20" y2="12" />
                                <line x1="4" y1="18" x2="20" y2="18" />
                            </svg>
                        </button>
                        <Link href="/" className="flex items-center gap-2 font-semibold text-theme-accent tracking-tight text-lg">
                            <FlowHubLogo className="w-7 h-7 shrink-0" />
                            <span>Flow Hub</span>
                        </Link>
                    </div>

                    <div className="flex items-center gap-2">
                        <ThemeToggle />
                    </div>
                </header>

                {/* Primary Content Area */}
                <main className="flex-1 mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div
                            role="alert"
                            className="mb-4 rounded-lg border border-[var(--successBorderColor)] bg-[var(--successBackgroundColor)] px-4 py-3 text-sm text-[var(--successColor)] shadow-2xs flex items-center gap-2"
                        >
                            <svg className="w-5 h-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            <span>{flash.success}</span>
                        </div>
                    )}
                    {flash?.error && (
                        <div
                            role="alert"
                            className="mb-4 rounded-lg border border-[var(--dangerBorderColor)] bg-[var(--dangerBackgroundColor)] px-4 py-3 text-sm text-[var(--dangerColor)] shadow-2xs flex items-center gap-2"
                        >
                            <svg className="w-5 h-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                            </svg>
                            <span>{flash.error}</span>
                        </div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}

export function money(value) {
    const number = Number(value || 0);
    return `CAD$ ${number.toFixed(2)}`;
}

export function Field({ label, error, children }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block font-medium text-theme-secondary">{label}</span>
            {children}
            {error && <span className="mt-1 block text-sm text-[var(--dangerColor)]">{error}</span>}
        </label>
    );
}

export const control = 'w-full rounded-md border border-theme bg-theme-input px-3 py-2 text-sm text-theme-primary placeholder:text-theme-muted placeholder:opacity-45 dark:placeholder:opacity-30 focus:border-[var(--borderFocusColor)] focus:outline-none transition-colors';
export const button = 'rounded-md bg-[var(--accentColor)] px-3 py-2 text-sm font-medium text-[var(--accentTextColor)] hover:bg-[var(--accentHoverColor)] active:bg-[var(--accentActiveColor)] transition-colors focus:ring-2 focus:ring-[var(--accentFocusRing)] cursor-pointer disabled:opacity-50';
export const quiet = 'rounded-md border border-theme bg-theme-surface px-3 py-2 text-sm text-theme-primary hover:border-theme-hover transition-colors cursor-pointer';
