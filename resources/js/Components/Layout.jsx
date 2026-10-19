import { Head, Link, router, usePage } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle';

const links = [
    ['Dashboard', '/'],
    ['Tracks', '/tracks'],
    ['Pages', '/pages'],
    ['Google Ads', '/google-ads'],
    ['IP blocks', '/ip-blocks'],
];

export default function Layout({ title, children }) {
    const { auth, flash } = usePage().props;
    const url = usePage().url;

    return (
        <div className="min-h-screen bg-theme-canvas text-theme-primary">
            <Head title={title} />
            <header className="border-b border-theme bg-theme-surface sticky top-0 z-30 shadow-xs">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    <div className="flex items-center gap-6">
                        <Link href="/" className="text-lg font-semibold text-theme-accent tracking-tight">
                            Flow Hub
                        </Link>
                        <nav className="flex flex-wrap gap-3 text-sm">
                            {links.map(([label, href]) => {
                                const isActive = url === href || (href !== '/' && url.startsWith(href));
                                return (
                                    <Link
                                        key={href}
                                        href={href}
                                        className={isActive
                                            ? 'text-theme-accent font-semibold border-b-2 border-theme-accent pb-0.5'
                                            : 'text-theme-secondary hover:text-theme-primary transition-colors pb-0.5'
                                        }
                                    >
                                        {label}
                                    </Link>
                                );
                            })}
                        </nav>
                    </div>
                    <div className="flex items-center gap-3">
                        {/* Accessible 3-state Theme Toggle Control */}
                        <ThemeToggle />

                        <span className="text-sm text-theme-muted hidden sm:inline-block">
                            {auth.user?.username}
                        </span>
                        <button
                            className="text-sm text-theme-secondary hover:text-theme-primary transition-colors cursor-pointer"
                            type="button"
                            onClick={() => router.post('/logout')}
                        >
                            Log out
                        </button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                {flash?.success && (
                    <div className="mb-4 rounded-md border border-[var(--successBorderColor)] bg-[var(--successBackgroundColor)] px-3 py-2 text-sm text-[var(--successColor)]">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mb-4 rounded-md border border-[var(--dangerBorderColor)] bg-[var(--dangerBackgroundColor)] px-3 py-2 text-sm text-[var(--dangerColor)]">
                        {flash.error}
                    </div>
                )}
                {children}
            </main>
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

export const control = 'w-full rounded-md border border-theme bg-theme-input px-3 py-2 text-sm text-theme-primary placeholder:text-theme-muted focus:border-[var(--borderFocusColor)] focus:outline-none transition-colors';
export const button = 'rounded-md bg-[var(--accentColor)] px-3 py-2 text-sm font-medium text-[var(--accentTextColor)] hover:bg-[var(--accentHoverColor)] active:bg-[var(--accentActiveColor)] transition-colors focus:ring-2 focus:ring-[var(--accentFocusRing)] cursor-pointer disabled:opacity-50';
export const quiet = 'rounded-md border border-theme bg-theme-surface px-3 py-2 text-sm text-theme-primary hover:border-theme-hover transition-colors cursor-pointer';
