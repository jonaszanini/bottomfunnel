import React, { useState } from 'react';
import { Field, button, control, quiet } from '../../../Components/Layout';

export default function QuickParamEditor({
    variation,
    variations,
    onSwitchVariation,
    onUpdateParam,
    onSaveAndCompile,
    isCompiling,
    onBack,
}) {
    const [previewKey, setPreviewKey] = useState(0);

    const params = variation.editableParams || {};

    const behaviorOptions = [
        { id: 'special_discount', label: 'Special Discount Page', desc: 'Direct countdown banner with promotional reservation tags' },
        { id: 'exit_intent', label: 'Exit Intent Popup', desc: 'Triggers discount modal when mouse breaks top border or back intent is detected' },
        { id: 'cookie_wall', label: 'Cookie Verification Overlay', desc: 'Overlays compliance consent gateway before unlocking official store link' },
        { id: 'country_gate', label: 'Country Selection Gate', desc: 'Requires clicking destination country flag for warehouse check' },
    ];

    function handleParamChange(field, value) {
        onUpdateParam(variation.id, field, value);
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme mb-1">
                        Step 4 of 5 &bull; Quick Parameter &amp; Mental Trigger Editor
                    </span>
                    <h2 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                        Fine-Tune Mental Triggers &amp; Parameters
                    </h2>
                    <p className="text-sm text-theme-secondary mt-1">
                        Adjust copy angles, discount percentages, and countdown scarcity in real time without writing any HTML/CSS.
                    </p>
                </div>

                {/* Variation Switcher Pills */}
                <div className="flex items-center gap-1.5 p-1 rounded-lg border border-theme bg-theme-surface">
                    {variations.map((v, i) => (
                        <button
                            key={v.id}
                            type="button"
                            onClick={() => onSwitchVariation(v.id)}
                            className={`px-3 py-1.5 rounded-md text-xs font-medium transition-colors ${
                                variation.id === v.id
                                    ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary hover:bg-theme-subtle'
                            }`}
                        >
                            Version {i + 1} ({v.id.toUpperCase()})
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-12">
                {/* Left Side: Live Preview Panel */}
                <div className="lg:col-span-7 flex flex-col rounded-xl border border-theme bg-theme-surface shadow-sm overflow-hidden min-h-[580px]">
                    <div className="p-3 border-b border-theme bg-theme-card/60 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
                            <span className="text-xs font-semibold text-theme-primary">
                                Live Variation Canvas ({variation.id.toUpperCase()})
                            </span>
                        </div>
                        <div className="flex items-center gap-2 text-xs text-theme-muted">
                            <span>Hash: <code className="text-theme-accent">{variation.compiledHtml.match(/fp_[a-z0-9]+_/)?.[0] || 'fp_unique_'}</code></span>
                        </div>
                    </div>

                    <div className="flex-1 w-full bg-stone-100 dark:bg-stone-900 relative">
                        <iframe
                            key={`${variation.id}-${previewKey}`}
                            title="Interactive Editor Preview"
                            srcDoc={variation.compiledHtml}
                            sandbox="allow-scripts"
                            className="w-full h-full min-h-[520px] border-0 bg-white"
                        />
                    </div>
                </div>

                {/* Right Side: Quick Parameter Form */}
                <div className="lg:col-span-5 rounded-xl border border-theme bg-theme-surface p-5 shadow-sm space-y-5 flex flex-col justify-between">
                    <div className="space-y-4">
                        <div className="border-b border-theme pb-3">
                            <h3 className="text-sm font-bold uppercase tracking-wider text-theme-primary">
                                Behavioral Trigger Settings
                            </h3>
                            <span className="text-xs text-theme-muted">
                                Controls modal display and user engagement interaction.
                            </span>
                        </div>

                        <Field label="Trigger Behavior &amp; Overlay Type">
                            <select
                                className={control}
                                value={params.behaviorType || 'special_discount'}
                                onChange={(e) => handleParamChange('behaviorType', e.target.value)}
                            >
                                {behaviorOptions.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Main Headline &amp; Hook">
                            <input
                                type="text"
                                className={control}
                                value={params.headline || ''}
                                onChange={(e) => handleParamChange('headline', e.target.value)}
                                placeholder="Enter strong headline hook..."
                            />
                        </Field>

                        <Field label="Subheadline &amp; Advisory Notice">
                            <textarea
                                rows="2"
                                className={control}
                                value={params.subheadline || ''}
                                onChange={(e) => handleParamChange('subheadline', e.target.value)}
                                placeholder="Regional warehouse stocks confirmed..."
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Discount Percentage">
                                <input
                                    type="text"
                                    className={control}
                                    value={params.discountPercentage || '50% OFF'}
                                    onChange={(e) => handleParamChange('discountPercentage', e.target.value)}
                                    placeholder="50% OFF"
                                />
                                <span className="text-[11px] text-theme-muted mt-0.5 block">
                                    Ensures price congruence with producer.
                                </span>
                            </Field>

                            <Field label="Scarcity Timer (Minutes)">
                                <input
                                    type="number"
                                    min="1"
                                    max="120"
                                    className={control}
                                    value={params.timerMinutes || 11}
                                    onChange={(e) => handleParamChange('timerMinutes', Math.max(1, parseInt(e.target.value, 10) || 1))}
                                />
                                <span className="text-[11px] text-theme-muted mt-0.5 block">
                                    Duration of countdown in MM:SS.
                                </span>
                            </Field>
                        </div>

                        <Field label="Primary Call To Action (CTA Button)">
                            <input
                                type="text"
                                className={control}
                                value={params.ctaText || 'Claim Your Special Allocation Now'}
                                onChange={(e) => handleParamChange('ctaText', e.target.value)}
                                placeholder="Claim Your Special Allocation Now"
                            />
                        </Field>
                    </div>

                    {/* Bottom Save & Recompile Action */}
                    <div className="pt-4 border-t border-theme space-y-3">
                        <button
                            type="button"
                            onClick={onSaveAndCompile}
                            disabled={isCompiling}
                            className={`${button} w-full py-2.5 flex items-center justify-center gap-2 font-semibold text-sm`}
                        >
                            {isCompiling ? (
                                <>
                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                    </svg>
                                    <span>Compiling Pure HTML...</span>
                                </>
                            ) : (
                                <>
                                    <span>Apply Changes &amp; Compile Final HTML &rarr;</span>
                                </>
                            )}
                        </button>

                        <div className="flex justify-between items-center text-xs">
                            <button
                                type="button"
                                onClick={onBack}
                                className={quiet}
                            >
                                &larr; Back to Variations
                            </button>
                            <span className="text-theme-muted">Deterministic instant compilation</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
