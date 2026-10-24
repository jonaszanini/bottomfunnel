import { useForm, router, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

function csrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Builder({ page, currentPage = null, pages = [], jobStatus = null }) {
    const effectivePage = currentPage || page;

    const form = useForm({
        name: effectivePage.name || '',
        structure: effectivePage.structure || effectivePage.structure_json || {},
        injected_script: effectivePage.injected_script || '',
    });
    const [preview, setPreview] = useState('');
    const [isRetrying, setIsRetrying] = useState(false);

    const isProcessing = effectivePage?.status === 'processing' || jobStatus?.status === 'processing';
    const isFailed = effectivePage?.status === 'failed' || jobStatus?.status === 'failed';
    const failureReason = effectivePage?.error || effectivePage?.failure_reason || jobStatus?.error || null;

    // Background job polling with Inertia partial reload
    useEffect(() => {
        if (!isProcessing) return;

        const interval = setInterval(() => {
            router.reload({
                only: ['pages', 'currentPage', 'jobStatus'],
                preserveState: true,
                preserveScroll: true,
            });
        }, 2000);

        return () => clearInterval(interval);
    }, [isProcessing, effectivePage?.id]);

    // Automatically synchronize active page state when cloned or generated page transitions to completed
    useEffect(() => {
        if (effectivePage) {
            form.setData((prev) => ({
                ...prev,
                name: effectivePage.name || prev.name,
                structure: effectivePage.structure || effectivePage.structure_json || prev.structure || {},
                injected_script: effectivePage.injected_script ?? prev.injected_script ?? '',
            }));
        }
    }, [
        effectivePage?.id,
        effectivePage?.status,
        effectivePage?.name,
        JSON.stringify(effectivePage?.structure || effectivePage?.structure_json),
    ]);

    useEffect(() => {
        const timer = setTimeout(() => {
            fetch(`/pages/${effectivePage.id}/preview`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ structure: form.data.structure }),
            }).then(async (response) => {
                if (!response.ok) return;
                const body = await response.json();
                setPreview(body.html || '');
            }).catch(() => {});
        }, 400);
        return () => clearTimeout(timer);
    }, [form.data.structure, effectivePage.id]);

    function update(path, value) {
        const next = structuredClone(form.data.structure || {});
        const keys = path.split('.');
        let cursor = next;
        keys.slice(0, -1).forEach((key) => { cursor = cursor[key]; });
        cursor[keys[keys.length - 1]] = value;
        form.setData('structure', next);
    }

    function add(key, item) {
        const currentStructure = form.data.structure || {};
        update(key, [...(currentStructure[key] || []), item]);
    }

    function handleRetry() {
        setIsRetrying(true);
        router.post(`/pages/${effectivePage.id}/retry`, {}, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setIsRetrying(false),
        });
    }

    const structure = form.data.structure || {};

    return (
        <Layout title={effectivePage.name}>
            {/* Failure Alert Banner with Retry Button */}
            {isFailed && (
                <div className="mb-4 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs sm:text-sm text-rose-700 dark:text-rose-300 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-xs">
                    <div className="flex items-start gap-2.5">
                        <svg className="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <div>
                            <span className="font-bold block">Background Processing Failed:</span>
                            <span className="font-mono text-xs">{failureReason || 'An unexpected error occurred during page processing.'}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={handleRetry}
                        disabled={isRetrying}
                        className="rounded-lg bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 text-xs font-semibold shadow-xs flex items-center justify-center gap-1.5 shrink-0 cursor-pointer disabled:opacity-50"
                    >
                        <svg className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                            <path d="M3 3v5h5" />
                        </svg>
                        <span>{isRetrying ? 'Retrying...' : 'Retry Processing'}</span>
                    </button>
                </div>
            )}

            {/* Processing Banner */}
            {isProcessing && (
                <div className="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs sm:text-sm text-amber-800 dark:text-amber-200 flex items-center justify-between gap-3 shadow-xs animate-pulse">
                    <div className="flex items-center gap-2.5">
                        <svg className="w-5 h-5 shrink-0 text-amber-600 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <circle cx="12" cy="12" r="10" strokeDasharray="32" strokeDashoffset="12" />
                        </svg>
                        <div>
                            <span className="font-bold">Page is currently processing in the background...</span>
                            <p className="text-xs text-amber-700 dark:text-amber-300">
                                Polling queue status automatically. Form and live preview will populate when complete.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <div className="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-semibold text-theme-primary">{effectivePage.product_name || effectivePage.name}</h1>
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold ${
                            effectivePage.status === 'failed'
                                ? 'bg-rose-500/10 text-rose-600 border border-rose-500/20'
                                : effectivePage.status === 'processing'
                                ? 'bg-amber-500/10 text-amber-600 border border-amber-500/20'
                                : 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20'
                        }`}>
                            {effectivePage.status === 'failed' ? 'Failed' : effectivePage.status === 'processing' ? 'Processing...' : 'Completed'}
                        </span>
                    </div>
                    <p className="text-sm text-theme-secondary">{effectivePage.page_type_label || effectivePage.page_type}. Saving writes this page type onto the Track.</p>
                </div>

                <div className="flex items-center gap-2">
                    <Link
                        href={`/pages?tab=studio&page_id=${effectivePage.id}&track_id=${effectivePage.track_id || ''}&product_name=${encodeURIComponent(effectivePage.name || '')}`}
                        className={`${quiet} text-xs flex items-center gap-1.5 font-semibold text-theme-accent`}
                        title="Proceed to Presell Studio multi-step generator"
                    >
                        <span>Presell Studio &rarr;</span>
                    </Link>
                    <a className={quiet} href={`/pages/${effectivePage.id}/export`}>Export index.html</a>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <form
                    className="grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm text-theme-primary"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/pages/${effectivePage.id}`);
                    }}
                >
                    <Field label="Page name" error={form.errors.name}>
                        <input className={control} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                    </Field>
                    <Field label="Headline"><input className={control} value={structure.headline || ''} onChange={(event) => update('headline', event.target.value)} /></Field>
                    <Field label="Subheadline"><input className={control} value={structure.subheadline || ''} onChange={(event) => update('subheadline', event.target.value)} /></Field>

                    <div className="flex flex-wrap gap-2">
                        <button type="button" className={quiet} onClick={() => add('sections', { heading: 'New section', body: '' })}>Add section</button>
                        <button type="button" className={quiet} onClick={() => add('navigation', { label: 'Read the review', target: '#section-0' })}>Navigation Buttons</button>
                        <button type="button" className={quiet} onClick={() => add('shippingBenefits', 'Tracked delivery')}>Shipping Benefits</button>
                        <button type="button" className={quiet} onClick={() => add('socialProof', { label: 'Readers', detail: 'Excellent value' })}>Social Proof</button>
                        <button type="button" className={quiet} onClick={() => add('benefits', 'A clear next step')}>Benefit</button>
                        <button type="button" className={quiet} onClick={() => add('callsToAction', { label: 'Take the next step' })}>Call to action</button>
                    </div>

                    {(structure.sections || []).map((section, index) => (
                        <div key={`section-${index}`} className="grid gap-2 rounded-md border border-stone-200 p-3">
                            <input className={control} value={section.heading} onChange={(event) => update(`sections.${index}.heading`, event.target.value)} />
                            <textarea className={control} rows="3" value={section.body} onChange={(event) => update(`sections.${index}.body`, event.target.value)} />
                        </div>
                    ))}
                    {(structure.benefits || []).map((benefit, index) => (
                        <input key={`benefit-${index}`} className={control} value={benefit} onChange={(event) => update(`benefits.${index}`, event.target.value)} />
                    ))}
                    {(structure.navigation || []).map((item, index) => (
                        <div key={`nav-${index}`} className="grid grid-cols-2 gap-2">
                            <input className={control} value={item.label} onChange={(event) => update(`navigation.${index}.label`, event.target.value)} />
                            <input className={control} value={item.target} onChange={(event) => update(`navigation.${index}.target`, event.target.value)} />
                        </div>
                    ))}
                    {(structure.shippingBenefits || []).map((item, index) => (
                        <input key={`ship-${index}`} className={control} value={item} onChange={(event) => update(`shippingBenefits.${index}`, event.target.value)} />
                    ))}
                    {(structure.socialProof || []).map((item, index) => (
                        <div key={`proof-${index}`} className="grid grid-cols-2 gap-2">
                            <input className={control} value={item.label} onChange={(event) => update(`socialProof.${index}.label`, event.target.value)} />
                            <input className={control} value={item.detail} onChange={(event) => update(`socialProof.${index}.detail`, event.target.value)} />
                        </div>
                    ))}
                    {(structure.testimonials || []).map((item, index) => (
                        <div key={`quote-${index}`} className="grid gap-2">
                            <input className={control} value={item.name} onChange={(event) => update(`testimonials.${index}.name`, event.target.value)} />
                            <textarea className={control} rows="2" value={item.quote} onChange={(event) => update(`testimonials.${index}.quote`, event.target.value)} />
                        </div>
                    ))}
                    {(structure.faq || []).map((item, index) => (
                        <div key={`faq-${index}`} className="grid gap-2">
                            <input className={control} value={item.question} onChange={(event) => update(`faq.${index}.question`, event.target.value)} />
                            <textarea className={control} rows="2" value={item.answer} onChange={(event) => update(`faq.${index}.answer`, event.target.value)} />
                        </div>
                    ))}
                    {(structure.callsToAction || []).map((item, index) => (
                        <input key={`cta-${index}`} className={control} value={item.label} onChange={(event) => update(`callsToAction.${index}.label`, event.target.value)} />
                    ))}

                    <Field label="Track script" error={form.errors.injected_script}>
                        <textarea className={control} rows="3" value={form.data.injected_script} onChange={(event) => form.setData('injected_script', event.target.value)} />
                    </Field>
                    <button className={button} disabled={form.processing}>Save page</button>
                </form>
                <iframe title="Live preview" className="min-h-[720px] w-full rounded-xl border border-theme bg-white" srcDoc={preview} />
            </div>
        </Layout>
    );
}
