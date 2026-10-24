import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import Layout, { button, control, quiet } from '../../Components/Layout';

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) return meta;
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Index({ initialUrl = '', initialProduct = '', initialLang = 'en', initialKeyword = '' }) {
    // Section A: Offer Input Bar State
    const [salesPageUrl, setSalesPageUrl] = useState(initialUrl);
    const [targetKeyword, setTargetKeyword] = useState(initialKeyword || initialProduct);
    const [manualContext, setManualContext] = useState('');
    const [showFallback, setShowFallback] = useState(false);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    const [scrapeWarning, setScrapeWarning] = useState(null);
    const [detectedLanguage, setDetectedLanguage] = useState(initialLang);
    const [productSummary, setProductSummary] = useState('');

    // Section B: 20x10 Assets State
    const [headlines, setHeadlines] = useState([]);
    const [descriptions, setDescriptions] = useState([]);
    const [selectedHeadlines, setSelectedHeadlines] = useState({});
    const [selectedDescriptions, setSelectedDescriptions] = useState({});
    const [shorteningId, setShorteningId] = useState(null);

    // Section C: Competitor SERP Screenshot State
    const [screenshotBase64, setScreenshotBase64] = useState(null);
    const [screenshotFileName, setScreenshotFileName] = useState('');
    const [isRefining, setIsRefining] = useState(false);
    const [competitorSummary, setCompetitorSummary] = useState(null);
    const [round1Backup, setRound1Backup] = useState(null); // { headlines, descriptions }
    const [activeRoundView, setActiveRoundView] = useState('round2'); // 'round1' | 'round2'

    // Notification toast
    const [toastMessage, setToastMessage] = useState(null);
    const fileInputRef = useRef(null);

    // Show temporary toast
    function showToast(msg) {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 2500);
    }

    // Global Paste Listener for Screenshots (Ctrl+V / Cmd+V)
    useEffect(() => {
        function handlePaste(e) {
            if (!e.clipboardData || !e.clipboardData.items) return;
            const items = e.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        setScreenshotBase64(event.target.result);
                        setScreenshotFileName('Pasted_SERP_Screenshot.png');
                        showToast('Screenshot pasted from clipboard!');
                    };
                    reader.readAsDataURL(blob);
                    break;
                }
            }
        }

        window.addEventListener('paste', handlePaste);
        return () => window.removeEventListener('paste', handlePaste);
    }, []);

    // Handle File Drop or Upload
    function handleFileSelect(file) {
        if (!file || !file.type.startsWith('image/')) {
            alert('Please select an image file (PNG, JPG, WebP).');
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            setScreenshotBase64(e.target.result);
            setScreenshotFileName(file.name);
            showToast('Competitor screenshot loaded!');
        };
        reader.readAsDataURL(file);
    }

    // Section A Action: Generate Base Ads
    async function handleAnalyzeAndGenerate(e) {
        if (e) e.preventDefault();
        if (!salesPageUrl.trim()) {
            alert('Please enter a sales page URL to analyze.');
            return;
        }

        setIsAnalyzing(true);
        setScrapeWarning(null);

        try {
            const res = await fetch('/api/copy/generate-base', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    salesPageUrl: salesPageUrl.trim(),
                    manualContext: manualContext.trim() || undefined,
                    targetKeyword: targetKeyword.trim() || undefined,
                }),
            });

            let data = await res.json();
            if (!res.ok) {
                throw new Error(data.message || 'Failed to analyze sales page.');
            }

            if (data?.job_id && (data?.status === 'pending' || data?.status === 'processing')) {
                const jobId = data.job_id;
                let pollAttempts = 0;
                const maxAttempts = 60;
                while (pollAttempts < maxAttempts) {
                    await new Promise((resolve) => setTimeout(resolve, 1500));
                    pollAttempts++;
                    const statusRes = await fetch(`/api/copy/status/${jobId}`, {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    });
                    const statusJson = await statusRes.json();
                    if (statusJson?.status === 'completed') {
                        data = statusJson;
                        break;
                    }
                    if (statusJson?.status === 'failed') {
                        throw new Error(statusJson.message || 'Failed to analyze sales page.');
                    }
                }
            }

            if (data.headlines && data.descriptions) {
                setHeadlines(data.headlines);
                setDescriptions(data.descriptions);
                setDetectedLanguage(data.detectedLanguage || 'en');
                setProductSummary(data.productSummary || '');
                if (data.scraper?.warning) {
                    setScrapeWarning(data.scraper.warning);
                }
                setRound1Backup(null);
                setCompetitorSummary(null);
                showToast('Generated 20 headlines & 10 descriptions!');
            }
        } catch (err) {
            alert('Error generating base copy: ' + err.message);
        } finally {
            setIsAnalyzing(false);
        }
    }

    // Section B Action: On-Demand Shortener
    async function handleShorten(id, type, currentText) {
        setShorteningId(`${type}-${id}`);
        try {
            const maxChars = type === 'headline' ? 30 : 90;
            const res = await fetch('/api/copy/shorten', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    text: currentText,
                    type: type,
                    maxChars: maxChars,
                    language: detectedLanguage,
                }),
            });

            const data = await res.json();
            if (data.success && data.text) {
                if (type === 'headline') {
                    setHeadlines((prev) =>
                        prev.map((h) => (h.id === id ? { ...h, text: data.text } : h))
                    );
                } else {
                    setDescriptions((prev) =>
                        prev.map((d) => (d.id === id ? { ...d, text: data.text } : d))
                    );
                }
                showToast(`Shortened to ${data.length}/${maxChars} chars!`);
            }
        } catch (err) {
            alert('Shortener error: ' + err.message);
        } finally {
            setShorteningId(null);
        }
    }

    // Toggle Pin/Lock for item
    function toggleLock(id, type) {
        if (type === 'headline') {
            setHeadlines((prev) =>
                prev.map((h) => (h.id === id ? { ...h, isLocked: !h.isLocked } : h))
            );
        } else {
            setDescriptions((prev) =>
                prev.map((d) => (d.id === id ? { ...d, isLocked: !d.isLocked } : d))
            );
        }
    }

    // Update text inline
    function updateText(id, type, newText) {
        if (type === 'headline') {
            setHeadlines((prev) =>
                prev.map((h) => (h.id === id ? { ...h, text: newText } : h))
            );
        } else {
            setDescriptions((prev) =>
                prev.map((d) => (d.id === id ? { ...d, text: newText } : d))
            );
        }
    }

    // Single copy action
    function copySingle(text) {
        navigator.clipboard.writeText(text);
        showToast('Copied to clipboard!');
    }

    // Section C Action: Refine with Screenshot
    async function handleRefineWithScreenshot() {
        if (!screenshotBase64) {
            alert('Please paste or upload a competitor Google Ads SERP screenshot first.');
            return;
        }

        setIsRefining(true);

        try {
            const res = await fetch('/api/copy/refine-with-screenshot', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    screenshotBase64: screenshotBase64,
                    currentHeadlines: headlines,
                    currentDescriptions: descriptions,
                    productSummary: productSummary,
                    detectedLanguage: detectedLanguage,
                }),
            });

            let data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Vision refinement failed.');
            }

            if (data?.job_id && (data?.status === 'pending' || data?.status === 'processing')) {
                const jobId = data.job_id;
                let pollAttempts = 0;
                const maxAttempts = 60;
                while (pollAttempts < maxAttempts) {
                    await new Promise((resolve) => setTimeout(resolve, 1500));
                    pollAttempts++;
                    const statusRes = await fetch(`/api/copy/status/${jobId}`, {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                    });
                    const statusJson = await statusRes.json();
                    if (statusJson?.status === 'completed') {
                        data = statusJson;
                        break;
                    }
                    if (statusJson?.status === 'failed') {
                        throw new Error(statusJson.message || 'Vision refinement failed.');
                    }
                }
            }

            // Save Round 1 backup if first refinement
            if (!round1Backup) {
                setRound1Backup({
                    headlines: structuredClone(headlines),
                    descriptions: structuredClone(descriptions),
                });
            }

            setHeadlines(data.headlines);
            setDescriptions(data.descriptions);
            setCompetitorSummary(data.competitorAnalysisSummary || []);
            setActiveRoundView('round2');
            showToast('Copy refined against competitor screenshot!');
        } catch (err) {
            alert('Refinement error: ' + err.message);
        } finally {
            setIsRefining(false);
        }
    }

    // Switch between Round 1 (Initial Base) and Round 2 (Refined)
    function switchRoundView(round) {
        if (round === 'round1' && round1Backup) {
            setActiveRoundView('round1');
        } else if (round === 'round2') {
            setActiveRoundView('round2');
        }
    }

    // Section D: Bulk Copy Actions
    function copyAllHeadlines() {
        const text = displayedHeadlines.map((h) => h.text).join('\n');
        navigator.clipboard.writeText(text);
        showToast(`Copied all ${displayedHeadlines.length} headlines!`);
    }

    function copyAllDescriptions() {
        const text = displayedDescriptions.map((d) => d.text).join('\n');
        navigator.clipboard.writeText(text);
        showToast(`Copied all ${displayedDescriptions.length} descriptions!`);
    }

    function copySelectedOnly() {
        const selectedH = displayedHeadlines.filter((h) => selectedHeadlines[h.id]).map((h) => h.text);
        const selectedD = displayedDescriptions.filter((d) => selectedDescriptions[d.id]).map((d) => d.text);
        const allSelected = [...selectedH, ...selectedD].join('\n');

        if (!allSelected) {
            alert('No assets selected. Use checkboxes to select specific lines.');
            return;
        }

        navigator.clipboard.writeText(allSelected);
        showToast(`Copied ${selectedH.length + selectedD.length} selected items!`);
    }

    // Toggle selection
    function toggleSelectHeadline(id) {
        setSelectedHeadlines((prev) => ({ ...prev, [id]: !prev[id] }));
    }

    function toggleSelectDescription(id) {
        setSelectedDescriptions((prev) => ({ ...prev, [id]: !prev[id] }));
    }

    function toggleSelectAllHeadlines() {
        const allSelected = displayedHeadlines.every((h) => selectedHeadlines[h.id]);
        const next = {};
        if (!allSelected) {
            displayedHeadlines.forEach((h) => { next[h.id] = true; });
        }
        setSelectedHeadlines(next);
    }

    function toggleSelectAllDescriptions() {
        const allSelected = displayedDescriptions.every((d) => selectedDescriptions[d.id]);
        const next = {};
        if (!allSelected) {
            displayedDescriptions.forEach((d) => { next[d.id] = true; });
        }
        setSelectedDescriptions(next);
    }

    // Active displayed assets depending on round view
    const displayedHeadlines = activeRoundView === 'round1' && round1Backup ? round1Backup.headlines : headlines;
    const displayedDescriptions = activeRoundView === 'round1' && round1Backup ? round1Backup.descriptions : descriptions;

    // Counts
    const validHeadlinesCount = displayedHeadlines.filter((h) => h.text.length <= 30).length;
    const validDescriptionsCount = displayedDescriptions.filter((d) => d.text.length <= 90).length;
    const selectedCount = Object.values(selectedHeadlines).filter(Boolean).length + Object.values(selectedDescriptions).filter(Boolean).length;

    // Category Badge Colors
    const categoryStyles = {
        Authority: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
        Guarantee: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
        Bonus: 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        Benefit: 'bg-teal-50 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300 border-teal-200 dark:border-teal-800',
        Security: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300 border-cyan-200 dark:border-cyan-800',
    };

    return (
        <Layout title="Google Ads Copy Studio">
            <div className="space-y-6">
                {/* Toast Notification */}
                {toastMessage && (
                    <div className="fixed bottom-6 right-6 z-50 rounded-lg border border-teal-500 bg-teal-900/90 text-white px-4 py-2.5 shadow-xl text-xs font-semibold flex items-center gap-2 animate-bounce">
                        <span className="text-emerald-300">&check;</span>
                        <span>{toastMessage}</span>
                    </div>
                )}

                {/* Top Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-theme pb-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-theme-primary sm:text-3xl">
                                Google Ads Copy Studio
                            </h1>
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-[var(--accentSubtleColor)] text-theme-accent border border-theme">
                                BoF Asset Engine
                            </span>
                        </div>
                        <p className="mt-1 text-xs sm:text-sm text-theme-secondary">
                            Scrape sales pages, generate 20 headlines + 10 descriptions, and refine unlocked copy against live competitor SERP screenshots.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href="/pages" className={`${quiet} text-xs py-1.5 px-3 flex items-center gap-1.5`}>
                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <span>Back to Pages</span>
                        </Link>
                    </div>
                </div>

                {/* Section A: Offer Input Bar */}
                <section className="rounded-xl border border-theme bg-theme-surface p-5 shadow-sm space-y-4">
                    <form onSubmit={handleAnalyzeAndGenerate} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-12 items-end">
                            <div className="md:col-span-6">
                                <label className="block text-xs font-semibold text-theme-secondary mb-1">
                                    Sales Page / Merchant Destination URL *
                                </label>
                                <input
                                    type="url"
                                    required
                                    placeholder="https://officialoffer.com/vsl or Hoplink"
                                    className={control}
                                    value={salesPageUrl}
                                    onChange={(e) => setSalesPageUrl(e.target.value)}
                                />
                            </div>

                            <div className="md:col-span-3">
                                <label className="block text-xs font-semibold text-theme-secondary mb-1">
                                    Target Keyword (Optional)
                                </label>
                                <input
                                    type="text"
                                    placeholder="e.g., Fimavyn Official"
                                    className={control}
                                    value={targetKeyword}
                                    onChange={(e) => setTargetKeyword(e.target.value)}
                                />
                            </div>

                            <div className="md:col-span-3">
                                <button
                                    type="submit"
                                    disabled={isAnalyzing || !salesPageUrl.trim()}
                                    className={`${button} w-full py-2.5 flex items-center justify-center gap-2 font-semibold text-xs`}
                                >
                                    {isAnalyzing ? (
                                        <>
                                            <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                            <span>Scraping &amp; Generating...</span>
                                        </>
                                    ) : (
                                        <>
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                <circle cx="11" cy="11" r="8" />
                                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                            </svg>
                                            <span>Analyze Page &amp; Generate Base Ads</span>
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* Expandable Fallback Context */}
                        <div className="pt-2 border-t border-theme/60 flex items-center justify-between text-xs">
                            <button
                                type="button"
                                onClick={() => setShowFallback(!showFallback)}
                                className="text-theme-accent hover:underline flex items-center gap-1 font-medium"
                            >
                                <span>{showFallback ? '▲ Hide Paste Page Text (Fallback)' : '▼ Paste Page Text (Fallback if Page is Blocked)'}</span>
                            </button>
                            {detectedLanguage && (
                                <span className="text-theme-muted">
                                    Detected Language: <strong className="text-theme-primary uppercase">{detectedLanguage}</strong>
                                </span>
                            )}
                        </div>

                        {showFallback && (
                            <div className="space-y-1">
                                <label className="block text-xs font-semibold text-theme-secondary">
                                    Raw Sales Page Text / VSL Transcript
                                </label>
                                <textarea
                                    rows="4"
                                    placeholder="Paste headings, guarantees, ingredients, and offer text here if direct scraper access is blocked by Cloudflare or bot protection..."
                                    className={`${control} font-mono text-xs`}
                                    value={manualContext}
                                    onChange={(e) => setManualContext(e.target.value)}
                                />
                                <span className="text-[11px] text-theme-muted block">
                                    The generator automatically extracts authority claims, 60-day guarantees, bonuses, and refund policies from this text.
                                </span>
                            </div>
                        )}

                        {/* Warning Alert if Scraper fell back */}
                        {scrapeWarning && (
                            <div className="rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/40 p-3 text-xs text-amber-800 dark:text-amber-200 flex items-start gap-2 shadow-2xs">
                                <span className="text-base leading-none">⚠️</span>
                                <div>
                                    <strong className="block font-semibold">Scraper Fallback Notification</strong>
                                    <span>{scrapeWarning}</span>
                                </div>
                            </div>
                        )}
                    </form>
                </section>

                {/* Section C: Competitor SERP Vision Box */}
                <section className="rounded-xl border border-theme bg-theme-surface p-5 shadow-sm space-y-4">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="p-1.5 rounded-md bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                    <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                        <circle cx="9" cy="9" r="2" />
                                        <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                    </svg>
                                </span>
                                <h3 className="text-sm font-bold uppercase tracking-wider text-theme-primary">
                                    Stage 2: Competitor SERP Screenshot Refinement
                                </h3>
                            </div>
                            <p className="text-xs text-theme-secondary mt-1">
                                Press <kbd className="px-1.5 py-0.5 rounded bg-theme-card border border-theme font-mono text-[10px]">Ctrl+V</kbd> / <kbd className="px-1.5 py-0.5 rounded bg-theme-card border border-theme font-mono text-[10px]">Cmd+V</kbd> anywhere or drop a Google Search screenshot to analyze competitor ad claims.
                            </p>
                        </div>

                        {round1Backup && (
                            <div className="flex items-center rounded-lg border border-theme bg-theme-card p-1">
                                <button
                                    type="button"
                                    onClick={() => switchRoundView('round1')}
                                    className={`px-3 py-1 rounded-md text-xs font-medium transition-colors ${
                                        activeRoundView === 'round1'
                                            ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                            : 'text-theme-secondary hover:text-theme-primary'
                                    }`}
                                >
                                    Round 1 (Initial Base)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => switchRoundView('round2')}
                                    className={`px-3 py-1 rounded-md text-xs font-medium transition-colors ${
                                        activeRoundView === 'round2'
                                            ? 'bg-[var(--accentColor)] text-white shadow-xs'
                                            : 'text-theme-secondary hover:text-theme-primary'
                                    }`}
                                >
                                    Round 2 (Refined Against SERP)
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="grid gap-4 md:grid-cols-12 items-center">
                        {/* Drag and drop / Paste Target */}
                        <div
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                                    handleFileSelect(e.dataTransfer.files[0]);
                                }
                            }}
                            onClick={() => fileInputRef.current?.click()}
                            className="md:col-span-7 rounded-xl border-2 border-dashed border-theme hover:border-theme-accent bg-theme-subtle/30 p-4 text-center cursor-pointer transition-colors flex flex-col items-center justify-center min-h-[110px]"
                        >
                            <input
                                type="file"
                                ref={fileInputRef}
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => handleFileSelect(e.target.files?.[0])}
                            />
                            {screenshotBase64 ? (
                                <div className="flex items-center gap-3 w-full">
                                    <img
                                        src={screenshotBase64}
                                        alt="SERP Preview"
                                        className="w-20 h-16 object-cover rounded-md border border-theme shadow-xs"
                                    />
                                    <div className="text-left flex-1 min-w-0">
                                        <span className="text-xs font-semibold text-theme-primary block truncate">
                                            {screenshotFileName || 'SERP Screenshot Loaded'}
                                        </span>
                                        <span className="text-[11px] text-emerald-600 dark:text-emerald-400 block">
                                            ✓ Ready for Multimodal Vision Refinement
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setScreenshotBase64(null);
                                            setScreenshotFileName('');
                                        }}
                                        className="text-xs text-red-600 hover:underline px-2 py-1"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ) : (
                                <div className="space-y-1">
                                    <div className="text-xs font-semibold text-theme-primary">
                                        Click to browse, or paste image with <kbd className="px-1 py-0.5 rounded bg-theme-card border border-theme font-mono text-[10px]">Ctrl+V</kbd>
                                    </div>
                                    <p className="text-[11px] text-theme-muted">
                                        Captures competitor headlines, descriptions, and extensions directly from Google SERP.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Refine Trigger Button */}
                        <div className="md:col-span-5 flex flex-col justify-center">
                            <button
                                type="button"
                                disabled={isRefining || !screenshotBase64 || displayedHeadlines.length === 0}
                                onClick={handleRefineWithScreenshot}
                                className={`${button} py-3 flex items-center justify-center gap-2 font-semibold text-xs bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white shadow-xs`}
                            >
                                {isRefining ? (
                                    <>
                                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                        </svg>
                                        <span>Auditing SERP &amp; Refining Unlocked Copy...</span>
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                        </svg>
                                        <span>Refine Copy Against Competitors &rarr;</span>
                                    </>
                                )}
                            </button>
                            <span className="text-[11px] text-theme-muted text-center mt-1.5">
                                Locked assets (📌) are preserved untouched.
                            </span>
                        </div>
                    </div>

                    {/* Competitor Analysis Summary (3 Bullet Points) */}
                    {competitorSummary && (
                        <div className="rounded-xl border border-indigo-200 dark:border-indigo-900 bg-indigo-50/70 dark:bg-indigo-950/40 p-4 space-y-2 mt-3 shadow-xs">
                            <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-200">
                                <span>🎯 Competitor SERP Weakness Audit</span>
                            </div>
                            <ul className="space-y-1.5 text-xs text-indigo-900 dark:text-indigo-200 list-disc list-inside">
                                {competitorSummary.map((item, idx) => (
                                    <li key={idx} className="leading-relaxed">
                                        <span>{item}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                {/* Section D: Bulk Export Toolbar */}
                <section className="sticky top-16 z-10 rounded-xl border border-theme bg-theme-surface/95 backdrop-blur-md p-3 shadow-md flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3 text-xs">
                        <span className="font-semibold text-theme-primary">
                            Asset Compliance:
                        </span>
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-mono font-medium ${
                            validHeadlinesCount === 20 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-amber-50 text-amber-700'
                        }`}>
                            Headlines: {validHeadlinesCount}/20 (&le;30)
                        </span>
                        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-mono font-medium ${
                            validDescriptionsCount === 10 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-amber-50 text-amber-700'
                        }`}>
                            Descriptions: {validDescriptionsCount}/10 (&le;90)
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={displayedHeadlines.length === 0}
                            onClick={copyAllHeadlines}
                            className={`${quiet} text-xs py-1.5 px-3 flex items-center gap-1 font-medium`}
                            title="Copy all 20 headlines to clipboard (newline separated)"
                        >
                            <span>Copy All 20 Headlines</span>
                        </button>

                        <button
                            type="button"
                            disabled={displayedDescriptions.length === 0}
                            onClick={copyAllDescriptions}
                            className={`${quiet} text-xs py-1.5 px-3 flex items-center gap-1 font-medium`}
                            title="Copy all 10 descriptions to clipboard (newline separated)"
                        >
                            <span>Copy All 10 Descriptions</span>
                        </button>

                        <button
                            type="button"
                            disabled={selectedCount === 0}
                            onClick={copySelectedOnly}
                            className={`${button} text-xs py-1.5 px-3 flex items-center gap-1 font-medium`}
                            title="Copy only checked items"
                        >
                            <span>Copy Selected ({selectedCount})</span>
                        </button>
                    </div>
                </section>

                {/* Section B: Interactive 20x10 Asset Editor */}
                {displayedHeadlines.length > 0 ? (
                    <div className="grid gap-6 lg:grid-cols-12 items-start">
                        {/* 20 Headlines Column (7 cols) */}
                        <div className="lg:col-span-7 rounded-xl border border-theme bg-theme-surface shadow-xs overflow-hidden">
                            <div className="p-3.5 border-b border-theme bg-theme-card/60 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all headlines"
                                        checked={displayedHeadlines.length > 0 && displayedHeadlines.every((h) => selectedHeadlines[h.id])}
                                        onChange={toggleSelectAllHeadlines}
                                        className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                    />
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-theme-primary">
                                        Headlines (20 Assets &bull; Max 30 Chars)
                                    </h3>
                                </div>
                                <span className="text-[11px] text-theme-muted">
                                    Authority, Guarantee, Bonus, Benefit, Security
                                </span>
                            </div>

                            <div className="divide-y divide-theme">
                                {displayedHeadlines.map((h, index) => {
                                    const len = h.text.length;
                                    const isOver = len > 30;
                                    const isAmber = len >= 27 && len <= 30;
                                    const badgeClass = categoryStyles[h.category] || categoryStyles.Authority;
                                    const isShortening = shorteningId === `headline-${h.id}`;

                                    return (
                                        <div
                                            key={h.id}
                                            className={`p-3 flex items-center gap-2.5 transition-colors ${
                                                h.isLocked ? 'bg-amber-50/20 dark:bg-amber-950/10' : 'hover:bg-theme-subtle/30'
                                            }`}
                                        >
                                            {/* Selection Checkbox */}
                                            <input
                                                type="checkbox"
                                                checked={!!selectedHeadlines[h.id]}
                                                onChange={() => toggleSelectHeadline(h.id)}
                                                className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)] shrink-0"
                                            />

                                            {/* Index & Category Badge */}
                                            <span className="text-[11px] font-mono text-theme-muted w-5 shrink-0 text-right">
                                                {index + 1}.
                                            </span>
                                            <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border shrink-0 ${badgeClass}`}>
                                                {h.category}
                                            </span>

                                            {/* Editable Text Input */}
                                            <input
                                                type="text"
                                                value={h.text}
                                                onChange={(e) => updateText(h.id, 'headline', e.target.value)}
                                                className={`flex-1 rounded-md border px-2.5 py-1 text-xs transition-colors focus:outline-none ${
                                                    isOver
                                                        ? 'border-red-500 bg-red-50/50 dark:bg-red-950/20 text-red-900 dark:text-red-200'
                                                        : 'border-theme bg-theme-input text-theme-primary focus:border-[var(--borderFocusColor)]'
                                                }`}
                                            />

                                            {/* Character Counter */}
                                            <span
                                                className={`text-[11px] font-mono shrink-0 w-12 text-right ${
                                                    isOver
                                                        ? 'text-red-600 dark:text-red-400 font-bold'
                                                        : isAmber
                                                        ? 'text-amber-600 dark:text-amber-400 font-semibold'
                                                        : 'text-emerald-600 dark:text-emerald-400'
                                                }`}
                                            >
                                                {len}/30
                                            </span>

                                            {/* Shorten Button if Over */}
                                            {isOver && (
                                                <button
                                                    type="button"
                                                    disabled={isShortening}
                                                    onClick={() => handleShorten(h.id, 'headline', h.text)}
                                                    className="px-2 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-bold shrink-0 animate-pulse"
                                                    title="Auto Shorten to <= 30 characters"
                                                >
                                                    {isShortening ? '...' : 'Shorten'}
                                                </button>
                                            )}

                                            {/* Lock / Pin Toggle */}
                                            <button
                                                type="button"
                                                onClick={() => toggleLock(h.id, 'headline')}
                                                className={`p-1 rounded transition-colors shrink-0 ${
                                                    h.isLocked
                                                        ? 'text-amber-600 bg-amber-100 dark:bg-amber-950 dark:text-amber-300'
                                                        : 'text-theme-muted hover:text-theme-primary'
                                                }`}
                                                title={h.isLocked ? 'Locked (Will not change in SERP refinement)' : 'Click to lock'}
                                            >
                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill={h.isLocked ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2">
                                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                                </svg>
                                            </button>

                                            {/* Single Copy Button */}
                                            <button
                                                type="button"
                                                onClick={() => copySingle(h.text)}
                                                className="p-1 rounded text-theme-muted hover:text-theme-primary transition-colors shrink-0"
                                                title="Copy headline"
                                            >
                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                    <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                    <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                </svg>
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* 10 Descriptions Column (5 cols) */}
                        <div className="lg:col-span-5 rounded-xl border border-theme bg-theme-surface shadow-xs overflow-hidden">
                            <div className="p-3.5 border-b border-theme bg-theme-card/60 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all descriptions"
                                        checked={displayedDescriptions.length > 0 && displayedDescriptions.every((d) => selectedDescriptions[d.id])}
                                        onChange={toggleSelectAllDescriptions}
                                        className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                    />
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-theme-primary">
                                        Descriptions (10 Assets &bull; Max 90)
                                    </h3>
                                </div>
                                <span className="text-[11px] text-theme-muted">
                                    Conversion Angles
                                </span>
                            </div>

                            <div className="divide-y divide-theme">
                                {displayedDescriptions.map((d, index) => {
                                    const len = d.text.length;
                                    const isOver = len > 90;
                                    const isAmber = len >= 85 && len <= 90;
                                    const isShortening = shorteningId === `description-${d.id}`;

                                    return (
                                        <div
                                            key={d.id}
                                            className={`p-3 space-y-2 transition-colors ${
                                                d.isLocked ? 'bg-amber-50/20 dark:bg-amber-950/10' : 'hover:bg-theme-subtle/30'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="flex items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={!!selectedDescriptions[d.id]}
                                                        onChange={() => toggleSelectDescription(d.id)}
                                                        className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)] shrink-0"
                                                    />
                                                    <span className="text-[11px] font-mono text-theme-muted">
                                                        #{index + 1}
                                                    </span>
                                                    <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-theme-card border border-theme text-theme-secondary">
                                                        {d.angle || 'Conversion Angle'}
                                                    </span>
                                                </div>

                                                <div className="flex items-center gap-1.5">
                                                    {/* Character Counter */}
                                                    <span
                                                        className={`text-[11px] font-mono ${
                                                            isOver
                                                                ? 'text-red-600 dark:text-red-400 font-bold'
                                                                : isAmber
                                                                ? 'text-amber-600 dark:text-amber-400 font-semibold'
                                                                : 'text-emerald-600 dark:text-emerald-400'
                                                        }`}
                                                    >
                                                        {len}/90
                                                    </span>

                                                    {/* Shorten Button if Over */}
                                                    {isOver && (
                                                        <button
                                                            type="button"
                                                            disabled={isShortening}
                                                            onClick={() => handleShorten(d.id, 'description', d.text)}
                                                            className="px-2 py-0.5 rounded bg-amber-500 hover:bg-amber-600 text-white text-[10px] font-bold animate-pulse"
                                                            title="Auto Shorten to <= 90 characters"
                                                        >
                                                            {isShortening ? '...' : 'Shorten'}
                                                        </button>
                                                    )}

                                                    {/* Lock / Pin Toggle */}
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleLock(d.id, 'description')}
                                                        className={`p-1 rounded transition-colors ${
                                                            d.isLocked
                                                                ? 'text-amber-600 bg-amber-100 dark:bg-amber-950 dark:text-amber-300'
                                                                : 'text-theme-muted hover:text-theme-primary'
                                                        }`}
                                                        title={d.isLocked ? 'Locked (Will not change in SERP refinement)' : 'Click to lock'}
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill={d.isLocked ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2">
                                                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                                                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                                        </svg>
                                                    </button>

                                                    {/* Single Copy */}
                                                    <button
                                                        type="button"
                                                        onClick={() => copySingle(d.text)}
                                                        className="p-1 rounded text-theme-muted hover:text-theme-primary transition-colors"
                                                        title="Copy description"
                                                    >
                                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            {/* Editable Textarea */}
                                            <textarea
                                                rows="2"
                                                value={d.text}
                                                onChange={(e) => updateText(d.id, 'description', e.target.value)}
                                                className={`w-full rounded-md border px-2.5 py-1.5 text-xs transition-colors focus:outline-none ${
                                                    isOver
                                                        ? 'border-red-500 bg-red-50/50 dark:bg-red-950/20 text-red-900 dark:text-red-200'
                                                        : 'border-theme bg-theme-input text-theme-primary focus:border-[var(--borderFocusColor)]'
                                                }`}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                ) : (
                    /* Empty State */
                    <div className="rounded-xl border border-theme bg-theme-surface p-12 text-center space-y-3 shadow-xs">
                        <div className="w-14 h-14 rounded-full bg-theme-subtle border border-theme flex items-center justify-center mx-auto text-theme-accent">
                            <svg className="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M12 20h9" />
                                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                            </svg>
                        </div>
                        <h3 className="text-base font-semibold text-theme-primary">
                            Enter an Offer URL Above to Generate Ad Copy
                        </h3>
                        <p className="text-xs text-theme-secondary max-w-md mx-auto">
                            The studio scrapes authority signals, guarantees, bonuses, and shipping details to generate 20 character-validated headlines (&le;30) and 10 descriptions (&le;90).
                        </p>
                    </div>
                )}
            </div>
        </Layout>
    );
}
