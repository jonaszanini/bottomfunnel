import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Layout, { Field, button, control, quiet } from '../../Components/Layout';

function csrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Builder({ page }) {
    const form = useForm({
        name: page.name,
        structure: page.structure,
        injected_script: page.injected_script,
    });
    const [preview, setPreview] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => {
            fetch(`/pages/${page.id}/preview`, {
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
    }, [form.data.structure, page.id]);

    function update(path, value) {
        const next = structuredClone(form.data.structure);
        const keys = path.split('.');
        let cursor = next;
        keys.slice(0, -1).forEach((key) => { cursor = cursor[key]; });
        cursor[keys[keys.length - 1]] = value;
        form.setData('structure', next);
    }

    function add(key, item) {
        update(key, [...(form.data.structure[key] || []), item]);
    }

    const structure = form.data.structure;

    return (
        <Layout title={page.name}>
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold text-theme-primary">{page.product_name}</h1>
                    <p className="text-sm text-theme-secondary">{page.page_type_label}. Saving writes this page type onto the Track.</p>
                </div>
                <a className={quiet} href={`/pages/${page.id}/export`}>Export index.html</a>
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <form
                    className="grid gap-3 rounded-xl border border-theme bg-theme-surface p-4 shadow-sm text-theme-primary"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/pages/${page.id}`);
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
