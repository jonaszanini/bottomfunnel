import { Head, Link, router, usePage } from '@inertiajs/react';

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
        <div className="min-h-screen">
            <Head title={title} />
            <header className="border-b border-stone-200 bg-white">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    <div className="flex items-center gap-6">
                        <Link href="/" className="text-lg font-semibold text-teal-800">Flow Hub</Link>
                        <nav className="flex flex-wrap gap-3 text-sm">
                            {links.map(([label, href]) => (
                                <Link
                                    key={href}
                                    href={href}
                                    className={url === href || (href !== '/' && url.startsWith(href)) ? 'text-teal-800 font-medium' : 'text-stone-600'}
                                >
                                    {label}
                                </Link>
                            ))}
                        </nav>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-sm text-stone-500">{auth.user?.username}</span>
                        <button className="text-sm text-stone-600" type="button" onClick={() => router.post('/logout')}>Log out</button>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                {flash?.success && <p className="mb-4 rounded-md bg-teal-50 px-3 py-2 text-sm text-teal-900">{flash.success}</p>}
                {flash?.error && <p className="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>}
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
            <span className="mb-1 block font-medium text-stone-700">{label}</span>
            {children}
            {error && <span className="mt-1 block text-red-700">{error}</span>}
        </label>
    );
}

export const control = 'w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm';
export const button = 'rounded-md bg-teal-800 px-3 py-2 text-sm font-medium text-white';
export const quiet = 'rounded-md border border-stone-300 bg-white px-3 py-2 text-sm';
