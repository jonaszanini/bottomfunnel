import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Layout, { button, quiet } from '../../Components/Layout';
import StrategySelector from './Components/StrategySelector';
import OfferConfigForm from './Components/OfferConfigForm';
import VariationsMatrix from './Components/VariationsMatrix';
import QuickParamEditor from './Components/QuickParamEditor';
import FinalExporter from './Components/FinalExporter';
import SavedPagesTable from './Components/SavedPagesTable';

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) return meta;
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Index({ pages = [], tracks = [], strategies = [], pageTypes = {}, languages = {} }) {
    // Top Tab Switcher: 'studio' | 'saved'
    const [activeTab, setActiveTab] = useState('studio');

    // Multi-Step Funnel: 1: Strategy -> 2: Config -> 3: Variations -> 4: QuickEdit -> 5: Export
    const [currentStep, setCurrentStep] = useState(1);

    // Step 1 State: Selected Strategy
    const [selectedStrategy, setSelectedStrategy] = useState(strategies[0]?.id || 'flash_sale');

    // Step 2 State: Offer & Tracking Configuration
    const [config, setConfig] = useState({
        trackId: tracks[0]?.id || '',
        productName: tracks[0]?.product_name || '',
        language: tracks[0]?.language || 'en',
        affiliateUrl: tracks[0]?.affiliate_url || '',
        trackingCode: '',
        seoEnabled: true,
    });

    // Step 3 & 4 State: Generated Variations
    const [variations, setVariations] = useState([]);
    const [selectedVariationId, setSelectedVariationId] = useState('v1');
    const [isGenerating, setIsGenerating] = useState(false);
    const [isCompiling, setIsCompiling] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [generateError, setGenerateError] = useState('');

    // Handle Config Updates
    function handleConfigChange(key, value) {
        setConfig((prev) => ({ ...prev, [key]: value }));
    }

    // Step 2 Action: Generate 3 Variations via API (Stage A)
    async function handleGenerateVariations() {
        setIsGenerating(true);
        setGenerateError('');

        try {
            const response = await fetch('/pages/generate-variations', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    strategyType: selectedStrategy,
                    productName: config.productName,
                    language: config.language,
                    affiliateUrl: config.affiliateUrl,
                    trackingCode: config.trackingCode,
                    seoEnabled: config.seoEnabled,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to generate variations.');
            }

            if (data.variations && data.variations.length > 0) {
                setVariations(data.variations);
                setSelectedVariationId(data.variations[0].id);
                setCurrentStep(3); // Advance to Variations Matrix
            } else {
                throw new Error('No variations were returned by the generator engine.');
            }
        } catch (err) {
            setGenerateError(err.message || 'An error occurred while compiling variations.');
        } finally {
            setIsGenerating(false);
        }
    }

    // Step 4 Helper: Update specific editable parameter in state
    function handleUpdateParam(varId, field, value) {
        setVariations((prev) =>
            prev.map((v) => {
                if (v.id === varId) {
                    return {
                        ...v,
                        editableParams: {
                            ...v.editableParams,
                            [field]: value,
                        },
                    };
                }
                return v;
            })
        );
    }

    // Step 4 Action: Instant Deterministic HTML Recompile (Stage B)
    async function handleSaveAndCompile() {
        const activeVar = variations.find((v) => v.id === selectedVariationId) || variations[0];
        if (!activeVar) return;

        setIsCompiling(true);

        try {
            const response = await fetch('/pages/compile', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    variation: activeVar,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Failed to recompile HTML.');
            }

            // Update compiled HTML in state
            setVariations((prev) =>
                prev.map((v) => (v.id === activeVar.id ? { ...v, compiledHtml: data.compiledHtml } : v))
            );

            // Advance to Step 5
            setCurrentStep(5);
        } catch (err) {
            alert('Recompilation error: ' + (err.message || 'Unknown error'));
        } finally {
            setIsCompiling(false);
        }
    }

    // Step 5 Action: Save Page to Flow Hub Database
    function handleSaveToDb(pageData) {
        setIsSaving(true);
        router.post('/pages', pageData, {
            preserveScroll: true,
            onSuccess: () => {
                setIsSaving(false);
                setActiveTab('saved');
            },
            onError: () => {
                setIsSaving(false);
            },
        });
    }

    // Current active variation object
    const activeVariation = variations.find((v) => v.id === selectedVariationId) || variations[0] || null;

    const stepLabels = [
        { num: 1, label: 'Strategy' },
        { num: 2, label: 'Configuration' },
        { num: 3, label: '3x Variations' },
        { num: 4, label: 'Quick Editor' },
        { num: 5, label: 'HTML Export' },
    ];

    return (
        <Layout title="Presell Studio - Pages">
            <div className="space-y-6">
                {/* Top Section: Header & Tab Navigation */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-theme pb-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                                Presell Studio &amp; Cloner
                            </h1>
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme">
                                Google Ads Ready
                            </span>
                        </div>
                        <p className="mt-1 text-xs sm:text-sm text-theme-secondary">
                            Compile auction-unique, high quality score landing pages with anti-footprint HTML and clean attribution scripts.
                        </p>
                    </div>

                    {/* View Switcher: Presell Studio vs Saved Pages */}
                    <div className="flex items-center rounded-lg border border-theme bg-theme-surface p-1 shadow-2xs">
                        <button
                            type="button"
                            onClick={() => setActiveTab('studio')}
                            className={`px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                activeTab === 'studio'
                                    ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary'
                            }`}
                        >
                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z" />
                            </svg>
                            <span>Studio Generator</span>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveTab('saved')}
                            className={`px-3 py-1.5 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                activeTab === 'saved'
                                    ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                    : 'text-theme-secondary hover:text-theme-primary'
                            }`}
                        >
                            <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <span>Saved Pages ({pages.length})</span>
                        </button>
                    </div>
                </div>

                {activeTab === 'studio' ? (
                    <div className="space-y-6">
                        {/* Step Progress Tracker */}
                        <div className="hidden sm:block overflow-x-auto pb-2">
                            <nav aria-label="Workflow progress" className="flex items-center justify-between min-w-[620px] px-2">
                                {stepLabels.map((s, index) => {
                                    const isCurrent = currentStep === s.num;
                                    const isPassed = currentStep > s.num;
                                    const canNavigate = isPassed || (s.num <= 3 && variations.length > 0);

                                    return (
                                        <React.Fragment key={s.num}>
                                            <button
                                                type="button"
                                                disabled={!canNavigate}
                                                onClick={() => canNavigate && setCurrentStep(s.num)}
                                                className={`flex items-center gap-2 group transition-colors text-left ${
                                                    canNavigate ? 'cursor-pointer' : 'cursor-default opacity-60'
                                                }`}
                                            >
                                                <div
                                                    className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all ${
                                                        isCurrent
                                                            ? 'bg-[var(--accentColor)] text-white ring-4 ring-[var(--accentFocusRing)]'
                                                            : isPassed
                                                            ? 'bg-teal-600 text-white'
                                                            : 'bg-theme-card border border-theme text-theme-muted'
                                                    }`}
                                                >
                                                    {isPassed ? '✓' : s.num}
                                                </div>
                                                <div className="text-xs">
                                                    <span className="block font-semibold text-theme-primary">
                                                        {s.label}
                                                    </span>
                                                    <span className="text-[11px] text-theme-muted">
                                                        {isCurrent ? 'Active Step' : isPassed ? 'Completed' : 'Upcoming'}
                                                    </span>
                                                </div>
                                            </button>

                                            {index < stepLabels.length - 1 && (
                                                <div className="flex-1 h-0.5 mx-3 bg-theme border-t border-theme" />
                                            )}
                                        </React.Fragment>
                                    );
                                })}
                            </nav>
                        </div>

                        {/* Funnel Step Views */}
                        {currentStep === 1 && (
                            <StrategySelector
                                strategies={strategies}
                                selectedStrategy={selectedStrategy}
                                onSelectStrategy={(stratId) => setSelectedStrategy(stratId)}
                                onNext={() => setCurrentStep(2)}
                            />
                        )}

                        {currentStep === 2 && (
                            <OfferConfigForm
                                tracks={tracks}
                                config={config}
                                onChangeConfig={handleConfigChange}
                                onBack={() => setCurrentStep(1)}
                                onGenerate={handleGenerateVariations}
                                isGenerating={isGenerating}
                                error={generateError}
                            />
                        )}

                        {currentStep === 3 && (
                            <VariationsMatrix
                                variations={variations}
                                selectedVariationId={selectedVariationId}
                                onSelectVariation={(id) => setSelectedVariationId(id)}
                                onQuickEdit={(id) => {
                                    setSelectedVariationId(id);
                                    setCurrentStep(4);
                                }}
                                onDirectExport={(id) => {
                                    setSelectedVariationId(id);
                                    setCurrentStep(5);
                                }}
                                onBack={() => setCurrentStep(2)}
                            />
                        )}

                        {currentStep === 4 && activeVariation && (
                            <QuickParamEditor
                                variation={activeVariation}
                                variations={variations}
                                onSwitchVariation={(id) => setSelectedVariationId(id)}
                                onUpdateParam={handleUpdateParam}
                                onSaveAndCompile={handleSaveAndCompile}
                                isCompiling={isCompiling}
                                onBack={() => setCurrentStep(3)}
                            />
                        )}

                        {currentStep === 5 && activeVariation && (
                            <FinalExporter
                                variation={activeVariation}
                                productName={config.productName}
                                strategyType={selectedStrategy}
                                language={config.language}
                                tracks={tracks}
                                selectedTrackId={config.trackId}
                                onBackToEditor={() => setCurrentStep(4)}
                                onStartNew={() => {
                                    setCurrentStep(1);
                                    setVariations([]);
                                }}
                                onSaveToDb={handleSaveToDb}
                                isSaving={isSaving}
                            />
                        )}
                    </div>
                ) : (
                    /* Saved Pages Library Tab */
                    <SavedPagesTable
                        pages={pages}
                        onStartGenerator={() => {
                            setActiveTab('studio');
                            setCurrentStep(1);
                        }}
                    />
                )}
            </div>
        </Layout>
    );
}
