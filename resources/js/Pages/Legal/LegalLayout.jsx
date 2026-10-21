import React from 'react';
import { Head, Link } from '@inertiajs/react';
import ThemeToggle from '../../Components/ThemeToggle';
import { FlowHubLogo } from '../../Components/Sidebar';

export default function LegalLayout({ title, activeTab, children }) {
    const scrollToTop = () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <div className="min-h-screen bg-theme-canvas text-theme-primary flex flex-col selection:bg-[var(--accentColor)] selection:text-[var(--accentTextColor)]">
            <Head title={`${title} - Bottom Funnel`} />

            {/* Top Navigation Bar */}
            <header className="sticky top-0 z-30 border-b border-theme bg-theme-surface/85 backdrop-blur-md transition-colors">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3 sm:px-6">
                    {/* Brand / Logo */}
                    <Link href="/" className="flex items-center gap-3 group focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accentFocusRing)] rounded-lg p-1">
                        <FlowHubLogo className="w-8 h-8 transition-transform group-hover:scale-105" />
                        <div>
                            <span className="font-bold tracking-tight text-theme-primary text-base sm:text-lg block leading-none">
                                Bottom Funnel
                            </span>
                            <span className="text-xs text-theme-secondary font-mono block mt-0.5">
                                Flow Hub
                            </span>
                        </div>
                    </Link>

                    {/* Navigation Tabs */}
                    <nav className="flex items-center gap-1 sm:gap-2 rounded-xl border border-theme bg-theme-canvas/70 p-1 text-xs sm:text-sm font-medium">
                        <Link
                            href="/privacy-policy"
                            className={`rounded-lg px-3 py-1.5 transition-colors ${
                                activeTab === 'privacy'
                                    ? 'bg-[var(--accentColor)] text-[var(--accentTextColor)] font-semibold shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-surface'
                            }`}
                        >
                            Privacy Policy
                        </Link>
                        <Link
                            href="/terms-of-service"
                            className={`rounded-lg px-3 py-1.5 transition-colors ${
                                activeTab === 'terms'
                                    ? 'bg-[var(--accentColor)] text-[var(--accentTextColor)] font-semibold shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-surface'
                            }`}
                        >
                            Terms of Service
                        </Link>
                    </nav>

                    {/* Actions: Theme Toggle + Login */}
                    <div className="flex items-center gap-3">
                        <ThemeToggle />
                        <Link
                            href="/login"
                            className="hidden sm:inline-flex items-center justify-center rounded-lg border border-theme bg-theme-surface px-3 py-1.5 text-xs font-semibold text-theme-primary hover:bg-theme-canvas hover:border-theme-secondary transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--accentFocusRing)]"
                        >
                            Log in
                        </Link>
                    </div>
                </div>
            </header>

            {/* Main Content Area */}
            <main className="flex-1 mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 sm:py-12">
                <div className="rounded-2xl border border-theme bg-theme-surface p-6 sm:p-12 shadow-sm transition-colors">
                    {children}
                </div>
            </main>

            {/* Footer */}
            <footer className="mt-auto border-t border-theme bg-theme-surface/50 py-8 text-xs text-theme-secondary transition-colors">
                <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-4 px-4 sm:flex-row sm:px-6">
                    <div className="flex flex-col sm:flex-row items-center gap-2 sm:gap-4 text-center sm:text-left">
                        <p className="font-medium text-theme-primary">
                            © {new Date().getFullYear()} Bottom Funnel. All rights reserved.
                        </p>
                        <span className="hidden sm:inline text-theme-muted">·</span>
                        <a
                            href="https://bottomfunnel.app"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-theme-secondary hover:text-theme-accent transition-colors"
                        >
                            bottomfunnel.app
                        </a>
                    </div>

                    <div className="flex items-center gap-4">
                        <Link href="/privacy-policy" className="hover:text-theme-primary transition-colors">
                            Privacy Policy
                        </Link>
                        <span className="text-theme-muted">·</span>
                        <Link href="/terms-of-service" className="hover:text-theme-primary transition-colors">
                            Terms of Service
                        </Link>
                        <span className="text-theme-muted">·</span>
                        <button
                            type="button"
                            onClick={scrollToTop}
                            className="text-theme-secondary hover:text-theme-primary transition-colors cursor-pointer"
                        >
                            ↑ Back to top
                        </button>
                    </div>
                </div>
            </footer>
        </div>
    );
}
