import { Head, useForm } from '@inertiajs/react';

export default function Login() {
    const form = useForm({ username: '', password: '' });

    return (
        <div className="flex min-h-screen items-center justify-center px-4">
            <Head title="Log in" />
            <form
                className="w-full max-w-sm rounded-xl bg-white p-6 shadow-sm"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/login');
                }}
            >
                <h1 className="text-xl font-semibold text-teal-900">Flow Hub</h1>
                <p className="mt-1 text-sm text-stone-600">Sign in to the owner account.</p>
                <label className="mt-4 block text-sm">
                    Username
                    <input className="mt-1 w-full rounded-md border border-stone-300 px-3 py-2" value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} />
                </label>
                {form.errors.username && <p className="mt-1 text-sm text-red-700">{form.errors.username}</p>}
                <label className="mt-3 block text-sm">
                    Password
                    <input type="password" className="mt-1 w-full rounded-md border border-stone-300 px-3 py-2" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                </label>
                <button className="mt-4 w-full rounded-md bg-teal-800 px-3 py-2 text-sm font-medium text-white" disabled={form.processing}>
                    Log in
                </button>
            </form>
        </div>
    );
}
