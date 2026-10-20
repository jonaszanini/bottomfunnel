import { Head, useForm } from '@inertiajs/react';
import ThemeToggle from '../Components/ThemeToggle';

export default function Login() {
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
                    <input
                        type="password"
                        className="mt-1 w-full rounded-md border border-theme bg-theme-input px-3 py-2 text-sm text-theme-primary placeholder:text-theme-muted placeholder:opacity-45 dark:placeholder:opacity-30 focus:border-[var(--borderFocusColor)] focus:outline-none transition-colors"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        autoComplete="current-password"
                        required
                    />
                </label>
                {form.errors.password && <p className="mt-1 text-sm text-[var(--dangerColor)]">{form.errors.password}</p>}

                <button
                    className="mt-6 w-full rounded-md bg-[var(--accentColor)] px-3 py-2.5 text-sm font-medium text-[var(--accentTextColor)] hover:bg-[var(--accentHoverColor)] active:bg-[var(--accentActiveColor)] transition-colors focus:ring-2 focus:ring-[var(--accentFocusRing)] disabled:opacity-50 cursor-pointer"
                    disabled={form.processing}
                >
                    {form.processing ? 'Signing in...' : 'Log in'}
                </button>
            </form>
        </div>
    );
}
