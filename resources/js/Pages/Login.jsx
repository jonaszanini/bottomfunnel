import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ThemeToggle from '../Components/ThemeToggle';

export default function Login() {
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ username: '', password: '' });

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-theme-canvas px-4 py-8 text-theme-primary">
            <Head title="Log in" />

            <div className="mb-4 flex w-full max-w-sm justify-end">
                <ThemeToggle />
            </div>

            <form
                className="w-full max-w-sm rounded-xl border border-theme bg-theme-surface p-6 shadow-md"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/login');
                }}
            >
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-bold tracking-tight text-theme-accent">Flow Hub</h1>
                </div>
                <p className="mt-1 text-sm text-theme-secondary">Sign in to the owner account.</p>

                <label className="mt-5 block text-sm">
                    <span className="font-medium text-theme-secondary">Username</span>
                    <input
                        className="mt-1 w-full rounded-md border border-theme bg-theme-input px-3 py-2 text-sm text-theme-primary placeholder:text-theme-muted placeholder:opacity-45 dark:placeholder:opacity-30 focus:border-[var(--borderFocusColor)] focus:outline-none transition-colors"
                        value={form.data.username}
                        onChange={(event) => form.setData('username', event.target.value)}
                        autoComplete="username"
                        required
                    />
                </label>
                {form.errors.username && <p className="mt-1 text-sm text-[var(--dangerColor)]">{form.errors.username}</p>}

                <label className="mt-4 block text-sm">
                    <span className="font-medium text-theme-secondary">Password</span>
                    <div className="relative mt-1">
                        <input
                            type={showPassword ? 'text' : 'password'}
                            className="w-full rounded-md border border-theme bg-theme-input px-3 py-2 pr-10 text-sm text-theme-primary placeholder:text-theme-muted placeholder:opacity-45 dark:placeholder:opacity-30 focus:border-[var(--borderFocusColor)] focus:outline-none transition-colors"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            autoComplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((prev) => !prev)}
                            className="absolute inset-y-0 right-0 flex items-center pr-3 text-theme-muted hover:text-theme-primary transition-colors focus:outline-none cursor-pointer"
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            title={showPassword ? 'Hide password' : 'Show password'}
                        >
                            {showPassword ? (
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                    <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                    <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                    <line x1="2" y1="2" x2="22" y2="22" />
                                </svg>
                            ) : (
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            )}
                        </button>
                    </div>
                </label>
                {form.errors.password && <p className="mt-1 text-sm text-[var(--dangerColor)]">{form.errors.password}</p>}

                <button
                    className="mt-6 w-full rounded-md bg-[var(--accentColor)] px-3 py-2.5 text-sm font-medium text-[var(--accentTextColor)] hover:bg-[var(--accentHoverColor)] active:bg-[var(--accentActiveColor)] transition-colors focus:ring-2 focus:ring-[var(--accentFocusRing)] disabled:opacity-50 cursor-pointer"
                    disabled={form.processing}
                >
                    {form.processing ? 'Signing in...' : 'Log in'}
                </button>
            </form>

            <div className="mt-6 flex items-center justify-center gap-4 text-xs text-theme-secondary">
                <Link href="/privacy-policy" className="hover:text-theme-primary transition-colors">
                    Privacy Policy
                </Link>
                <span className="text-theme-muted">·</span>
                <Link href="/terms-of-service" className="hover:text-theme-primary transition-colors">
                    Terms of Service
                </Link>
            </div>
        </div>
    );
}
