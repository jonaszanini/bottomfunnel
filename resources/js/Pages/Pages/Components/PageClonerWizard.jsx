import React, { useState, useEffect, useRef } from 'react';
import { Field, button, control, quiet } from '../../../Components/Layout';
import { api, ApiError } from '../../../utils/apiClient';

export default function PageClonerWizard({
    tracks = [],
    googleAdsAccounts = [],
    prefillData = null,
    onSaveToDb,
    isSaving,
    onCancel,
}) {
    // Current Wizard Step: 1: Quick Entry -> 2: Processing & Diagnostics -> 3: Visual Review & Inspector
    const [step, setStep] = useState(1);

    // Form inputs
    const [form, setForm] = useState({
        salesPageUrl: prefillData?.salesPageUrl || '',
        affiliateLink: prefillData?.affiliateLink || '',
        productName: prefillData?.productName || '',
        trackId: prefillData?.trackId || '',
        forceBrowser: false,
        stripTrackers: true,
        optimizeImages: true,
        enableParamPassThrough: true,
        customHead: '',
        customBody: '',
    });

    const [showAdvanced, setShowAdvanced] = useState(false);

    // Google Ads Tag Injection State
    const [clonerAccountId, setClonerAccountId] = useState(() => {
        const initialTrack = tracks.find((t) => String(t.id) === String(prefillData?.trackId || ''));
        if (initialTrack?.google_ads_account_id) return String(initialTrack.google_ads_account_id);
        const def = googleAdsAccounts.find((a) => a.is_default);
        return def ? String(def.id) : (googleAdsAccounts[0] ? String(googleAdsAccounts[0].id) : '');
    });

    const [clonerActionId, setClonerActionId] = useState(() => {
        const initialTrack = tracks.find((t) => String(t.id) === String(prefillData?.trackId || ''));
        return initialTrack?.conversion_action_id ? String(initialTrack.conversion_action_id) : '';
    });

    const [isFetchingTag, setIsFetchingTag] = useState(false);
    const [tagNotice, setTagNotice] = useState('');

    const clonerActiveAccount = googleAdsAccounts.find((a) => String(a.id) === String(clonerAccountId));
    const clonerAvailableActions = clonerActiveAccount?.conversion_actions || [];

    async function handleInsertGoogleAdsTag() {
        if (!clonerAccountId) {
            alert('Please select a Google Ads account first.');
            return;
        }
        setIsFetchingTag(true);
        setTagNotice('');

        try {
            const actionParam = clonerActionId || 'default';
            const res = await fetch(`/google-ads/accounts/${clonerAccountId}/conversion-actions/${actionParam}/tag`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.message || 'Failed to fetch Google Ads tag');
            }

            const tagCode = data.global_site_tag;
            if (!tagCode) {
                throw new Error('No Google Ads tag could be retrieved for this account.');
            }

            const current = (form.customHead || '').trim();
            if (current.length > 0) {
                if (current.includes(tagCode.trim())) {
                    setTagNotice('Google Ads tag is already present in custom <head>.');
                    return;
                }
                const confirmed = window.confirm(
                    'The Custom <head> Code field is not empty.\n\nClick OK to append the Google Ads tag, or Cancel to leave it unchanged.'
                );
                if (!confirmed) {
                    return;
                }
                setForm((prev) => ({
                    ...prev,
                    customHead: `${current}\n\n${tagCode}`,
                }));
            } else {
                setForm((prev) => ({
                    ...prev,
                    customHead: tagCode,
                }));
            }

            setTagNotice(
                data.has_tag
                    ? 'Google Ads conversion tag inserted!'
                    : 'Google Ads global site tag (gtag.js) inserted.'
            );
        } catch (err) {
            setTagNotice(err.message || 'Error inserting Google Ads tag.');
        } finally {
            setIsFetchingTag(false);
        }
    }

    // Extraction & Crawl states
    const [isExtractingMeta, setIsExtractingMeta] = useState(false);
    const [isCloning, setIsCloning] = useState(false);
    const [currentStageIndex, setCurrentStageIndex] = useState(0);
    const [errorMessage, setErrorMessage] = useState('');
    const abortControllerRef = useRef(null);

    // Cloned Data State
    const [cloneResult, setCloneResult] = useState(null);
    const [editableHtml, setEditableHtml] = useState('');

    // Step 3 Review State
    const [viewport, setViewport] = useState('desktop'); // 'desktop' (1280px) | 'tablet' (768px) | 'mobile' (375px)
    const [highlightLinks, setHighlightLinks] = useState(true);
    const [isContentEditable, setIsContentEditable] = useState(false);
    const [showLinkInspector, setShowLinkInspector] = useState(false);
    const [copiedHtml, setCopiedHtml] = useState(false);
    const [showSaveModal, setShowSaveModal] = useState(false);
    const [saveFormName, setSaveFormName] = useState('');

    const iframeRef = useRef(null);

    const stages = [
        'Connecting to sales page & inspecting headers...',
        'Rendering DOM & executing dynamic lazy-loads...',
        'Rewriting relative URLs, images, and CSS assets...',
        'Deep sanitizing tracking pixels & domain locks...',
        'Mapping affiliate checkout links & parameter engine...',
        'Finalizing clone & preparing visual sandbox...',
    ];

    // If prefill data is passed (e.g. from Watching Products "Clone Page" button), prefill
    useEffect(() => {
        if (prefillData) {
            setForm((prev) => ({
                ...prev,
                salesPageUrl: prefillData.salesPageUrl || prev.salesPageUrl,
                affiliateLink: prefillData.affiliateLink || prev.affiliateLink,
                productName: prefillData.productName || prev.productName,
                trackId: prefillData.trackId || prev.trackId,
            }));
        }
    }, [prefillData]);

    // Handle Quick Meta Extraction on URL blur or manual trigger
    async function handleQuickExtractMeta() {
        if (!form.salesPageUrl || !form.salesPageUrl.startsWith('http')) return;

        setIsExtractingMeta(true);
        setErrorMessage('');

        try {
            const data = await api.post('/pages/extract-metadata', {
                url: form.salesPageUrl,
                force_browser: form.forceBrowser,
            });

            if (data.title && !form.productName) {
                setForm((prev) => ({ ...prev, productName: data.title.split(/[-–|]/)[0].trim() }));
            }
        } catch (err) {
            // Non-blocking meta extraction
        } finally {
            setIsExtractingMeta(false);
        }
    }

    // Start 1-Click Crawl & Clone
    async function handleStartClone() {
        if (!form.salesPageUrl || !form.salesPageUrl.startsWith('http')) {
            setErrorMessage('Please enter a valid Sales Page URL (starting with http:// or https://)');
            return;
        }
        if (!form.affiliateLink || !form.affiliateLink.startsWith('http')) {
            setErrorMessage('Please enter a valid Affiliate Link (starting with http:// or https://)');
            return;
        }

        setStep(2);
        setIsCloning(true);
        setErrorMessage('');
        setCurrentStageIndex(0);

        // Progress stage animation ticker
        const ticker = setInterval(() => {
            setCurrentStageIndex((prev) => {
                if (prev < stages.length - 2) {
                    return prev + 1;
                }
                return prev;
            });
        }, 1800);

        const controller = new AbortController();
        abortControllerRef.current = controller;

        try {
            let data = await api.post('/pages/crawl-and-clone', {
                url: form.salesPageUrl,
                affiliate_link: form.affiliateLink,
                product_name: form.productName,
                force_browser: form.forceBrowser,
                custom_head: form.customHead,
                custom_body: form.customBody,
                strip_trackers: form.stripTrackers,
                optimize_images: form.optimizeImages,
                enable_param_pass_through: form.enableParamPassThrough,
            }, {
                signal: controller.signal,
                timeout: 60000,
            });

            if (data?.job_id && (data?.status === 'pending' || data?.status === 'processing')) {
                const jobId = data.job_id;
                let pollAttempts = 0;
                const maxAttempts = 60;
                while (pollAttempts < maxAttempts) {
                    await new Promise((resolve) => setTimeout(resolve, 1500));
                    if (controller.signal.aborted) {
                        throw new Error('Cloning cancelled.');
                    }
                    pollAttempts++;
                    const statusRes = await api.get(`/pages/crawl-and-clone/status/${jobId}`, {}, {
                        signal: controller.signal,
                    });
                    if (statusRes?.status === 'completed') {
                        data = statusRes;
                        break;
                    }
                    if (statusRes?.status === 'failed') {
                        throw new Error(statusRes.message || 'Cloning process encountered an error.');
                    }
                }
            }

            clearInterval(ticker);
            setCurrentStageIndex(stages.length - 1);

            setCloneResult(data);
            setEditableHtml(data.clonedHtml);
            setSaveFormName(data.productName ? `${data.productName} (Cloned Presell)` : 'Cloned Sales Page');

            setTimeout(() => {
                setIsCloning(false);
                setStep(3); // Advance to Visual Review & Inspector
            }, 600);
        } catch (err) {
            clearInterval(ticker);
            setIsCloning(false);
            setErrorMessage(err.message || 'Cloning process encountered an unexpected error.');
            setStep(1); // Return to entry step with error banner
        }
    }

    // Cancel current in-flight clone
    function handleCancelClone() {
        if (abortControllerRef.current) {
            abortControllerRef.current.abort();
        }
        setIsCloning(false);
        setStep(1);
    }

    // Sync Link Highlighting and ContentEditable into the preview iframe
    useEffect(() => {
        if (step !== 3 || !iframeRef.current) return;

        const iframe = iframeRef.current;
        const handleLoad = () => {
            try {
                const doc = iframe.contentDocument || iframe.contentWindow?.document;
                if (!doc) return;

                // 1. Link Highlighting
                let styleTag = doc.getElementById('flowhub-inspector-styles');
                if (!styleTag) {
                    styleTag = doc.createElement('style');
                    styleTag.id = 'flowhub-inspector-styles';
                    doc.head.appendChild(styleTag);
                }

                if (highlightLinks) {
                    styleTag.textContent = `
                        a[data-flowhub-affiliate="true"], a[href*="${form.affiliateLink}"] {
                            outline: 3px solid #10b981 !important;
                            outline-offset: 2px !important;
                            position: relative !important;
                        }
                        a[data-flowhub-affiliate="true"]::after, a[href*="${form.affiliateLink}"]::after {
                            content: "✓ YOUR AFFILIATE LINK" !important;
                            position: absolute !important;
                            top: -18px !important;
                            left: 0 !important;
                            background: #059669 !important;
                            color: #ffffff !important;
                            font-size: 10px !important;
                            font-weight: 800 !important;
                            padding: 2px 6px !important;
                            border-radius: 4px !important;
                            z-index: 999999 !important;
                            white-space: nowrap !important;
                            pointer-events: none !important;
                            font-family: sans-serif !important;
                            box-shadow: 0 2px 4px rgba(0,0,0,0.2) !important;
                        }
                        a:not([data-flowhub-affiliate="true"]):not([href*="${form.affiliateLink}"]):not([href^="#"]):not([href^="javascript:"]) {
                            outline: 2px dashed #f59e0b !important;
                            outline-offset: 2px !important;
                        }
                    `;
                } else {
                    styleTag.textContent = '';
                }

                // 2. Inline editing
                if (doc.body) {
                    doc.body.contentEditable = isContentEditable ? 'true' : 'false';
                    if (isContentEditable) {
                        doc.body.style.cursor = 'text';
                    } else {
                        doc.body.style.cursor = 'default';
                    }
                }

                // Listen to edits in iframe to keep state in sync
                const updateHtmlOnInput = () => {
                    if (doc.documentElement) {
                        setEditableHtml(doc.documentElement.outerHTML);
                    }
                };

                doc.body?.removeEventListener('input', updateHtmlOnInput);
                doc.body?.addEventListener('input', updateHtmlOnInput);
            } catch (e) {
                // Ignore cross-origin issues if any
            }
        };

        iframe.addEventListener('load', handleLoad);
        handleLoad();

        return () => {
            iframe.removeEventListener('load', handleLoad);
        };
    }, [step, highlightLinks, isContentEditable, editableHtml, form.affiliateLink]);

    // Swap any detected link directly from the Link Inspector drawer
    function handleSwapLink(targetOriginalUrl) {
        if (!iframeRef.current) return;
        try {
            const doc = iframeRef.current.contentDocument || iframeRef.current.contentWindow?.document;
            if (!doc) return;

            let swappedCount = 0;
            doc.querySelectorAll('a').forEach((a) => {
                if (a.href === targetOriginalUrl || a.getAttribute('href') === targetOriginalUrl) {
                    a.setAttribute('href', form.affiliateLink);
                    a.setAttribute('data-flowhub-affiliate', 'true');
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener noreferrer');
                    swappedCount++;
                }
            });

            // Update cloned HTML
            if (doc.documentElement) {
                setEditableHtml(doc.documentElement.outerHTML);
            }

            // Update local links state in cloneResult
            if (cloneResult && cloneResult.links) {
                setCloneResult((prev) => ({
                    ...prev,
                    stats: {
                        ...prev.stats,
                        affiliateLinksReplaced: prev.stats.affiliateLinksReplaced + swappedCount,
                    },
                    links: prev.links.map((l) =>
                        l.originalUrl === targetOriginalUrl
                            ? { ...l, newUrl: form.affiliateLink, isAffiliate: true }
                            : l
                    ),
                }));
            }
        } catch (e) {
            alert('Failed to swap link in DOM: ' + e.message);
        }
    }

    // Copy Cloned HTML to clipboard
    function handleCopyHtml() {
        if (!editableHtml) return;
        navigator.clipboard.writeText(editableHtml).then(() => {
            setCopiedHtml(true);
            setTimeout(() => setCopiedHtml(false), 2500);
        });
    }

    // Download standalone index.html
    function handleDownloadHtml() {
        if (!editableHtml) return;
        const blob = new Blob([editableHtml], { type: 'text/html;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const safeName = (form.productName || 'cloned-page').toLowerCase().replace(/[^a-z0-9]+/g, '-');
        a.href = url;
        a.download = `${safeName}-cloned.html`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Open in separate browser window
    function handleOpenNewWindow() {
        const win = window.open('', '_blank');
        if (win) {
            win.document.open();
            win.document.write(editableHtml);
            win.document.close();
        }
    }

    // Save to FlowHub library
    function handleSaveSubmit(e) {
        e.preventDefault();
        onSaveToDb({
            name: saveFormName || `${form.productName || 'Cloned'} Landing Page`,
            track_id: form.trackId || null,
            page_type: 'cloned',
            language: 'en',
            html: editableHtml,
            variation: {
                strategyType: 'cloned',
                sourceUrl: form.salesPageUrl,
                affiliateUrl: form.affiliateLink,
                productName: form.productName,
            },
        });
        setShowSaveModal(false);
    }

    const byteSize = new Blob([editableHtml]).size;
    const kbSize = (byteSize / 1024).toFixed(1);

    return (
        <div className="space-y-6">
            {/* Step Tracker */}
            <div className="flex items-center justify-between border-b border-theme pb-4">
                <div className="flex items-center gap-3">
                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--accentColor)] text-white text-xs font-bold shadow-xs">
                        {step === 3 ? '✓' : step}
                    </span>
                    <div>
                        <h2 className="text-xl font-bold tracking-tight text-theme-primary sm:text-2xl">
                            {step === 1 && 'Step 1: Offer URLs & Cloner Setup'}
                            {step === 2 && 'Step 2: Crawling, Sanitizing & Rewriting Page...'}
                            {step === 3 && 'Step 3: Visual Link Inspector & Export'}
                        </h2>
                        <p className="text-xs text-theme-secondary mt-0.5">
                            {step === 1 && 'Paste the vendor sales page URL and your affiliate link to automatically clone the page with zero broken assets.'}
                            {step === 2 && 'Executing real-time DOM extraction, asset URL rewriting, and third-party tracker purging.'}
                            {step === 3 && 'Inspect all affiliate links, test mobile responsiveness, and export clean production HTML.'}
                        </p>
                    </div>
                </div>

                {step === 3 && (
                    <button
                        type="button"
                        onClick={() => setStep(1)}
                        className={`${quiet} text-xs`}
                    >
                        &larr; Start New Clone
                    </button>
                )}
            </div>

            {/* Error Banner */}
            {errorMessage && (
                <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs sm:text-sm text-rose-700 dark:text-rose-300 flex items-start gap-3 shadow-2xs">
                    <svg className="w-5 h-5 shrink-0 text-rose-500 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <div className="flex-1">
                        <span className="font-semibold block">Cloning Error:</span>
                        <span>{errorMessage}</span>
                    </div>
                </div>
            )}

            {/* STEP 1: QUICK ENTRY FORM */}
            {step === 1 && (
                <div className="rounded-xl border border-theme bg-theme-surface p-6 shadow-xs space-y-6">
                    <div className="grid gap-5 md:grid-cols-2">
                        {/* Producer Sales Page URL */}
                        <div className="md:col-span-2">
                            <Field
                                label="Producer Official Page URL (To Clone)"
                                error=""
                            >
                                <div className="relative">
                                    <input
                                        type="url"
                                        required
                                        placeholder="https://exampleoffer.com/vsl or https://getproduct.com/special"
                                        className={`${control} pl-9 font-mono text-xs sm:text-sm`}
                                        value={form.salesPageUrl}
                                        onChange={(e) => setForm({ ...form, salesPageUrl: e.target.value })}
                                        onBlur={handleQuickExtractMeta}
                                    />
                                    <svg className="w-4 h-4 text-theme-muted absolute left-3 top-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <line x1="2" y1="12" x2="22" y2="12" />
                                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
                                    </svg>
                                </div>
                                <span className="text-[11px] text-theme-muted mt-1 block">
                                    Full URL of the official VSL, TSL, Advertorial, or DTC landing page you want to clone.
                                </span>
                            </Field>
                        </div>

                        {/* User's Affiliate Link */}
                        <div className="md:col-span-2">
                            <Field label="Your Target Affiliate Link (Destination for all CTAs & Checkout buttons)">
                                <div className="relative">
                                    <input
                                        type="url"
                                        required
                                        placeholder="https://hop.clickbank.net/?affiliate=YOURID&vendor=VENDOR or https://buygoods.com/aff/..."
                                        className={`${control} pl-9 font-mono text-xs sm:text-sm !border-emerald-500/80 dark:!border-emerald-400/80 focus:!border-emerald-500 dark:focus:!border-emerald-400`}
                                        value={form.affiliateLink}
                                        onChange={(e) => setForm({ ...form, affiliateLink: e.target.value })}
                                    />
                                    <svg className="w-4 h-4 text-emerald-500 absolute left-3 top-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                    </svg>
                                </div>
                                <span className="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1 block">
                                    All buy buttons, order links, and CTA forms will be automatically redirected to this URL with full Google Ads parameter forwarding.
                                </span>
                            </Field>
                        </div>

                        {/* Product Name (Optional) */}
                        <div>
                            <Field label="Product / Campaign Name (Optional)">
                                <input
                                    type="text"
                                    placeholder="e.g. ProDentim, Hepagard, LivPure..."
                                    className={control}
                                    value={form.productName}
                                    onChange={(e) => setForm({ ...form, productName: e.target.value })}
                                />
                            </Field>
                        </div>

                        {/* Track Association */}
                        <div>
                            <Field label="Link to Campaign Track (Optional)">
                                <select
                                    className={control}
                                    value={form.trackId}
                                    onChange={(e) => {
                                        const newTrackId = e.target.value;
                                        setForm({ ...form, trackId: newTrackId });
                                        const selectedTrack = tracks.find((t) => String(t.id) === String(newTrackId));
                                        if (selectedTrack?.google_ads_account_id) {
                                            setClonerAccountId(String(selectedTrack.google_ads_account_id));
                                        }
                                        if (selectedTrack?.conversion_action_id) {
                                            setClonerActionId(String(selectedTrack.conversion_action_id));
                                        }
                                    }}
                                >
                                    <option value="">Standalone / Unassigned</option>
                                    {tracks.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.product_name} ({t.language || 'en'})
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    </div>

                    {/* Advanced Settings Accordion */}
                    <div className="border-t border-theme pt-4">
                        <button
                            type="button"
                            onClick={() => setShowAdvanced(!showAdvanced)}
                            className="flex items-center gap-2 text-xs font-semibold text-theme-secondary hover:text-theme-primary cursor-pointer"
                        >
                            <svg
                                className={`w-3.5 h-3.5 transition-transform ${showAdvanced ? 'rotate-90' : ''}`}
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                            >
                                <polyline points="9 18 15 12 9 6" />
                            </svg>
                            <span>Advanced Cloner Options &amp; Pixel Injection</span>
                        </button>

                        {showAdvanced && (
                            <div className="mt-4 space-y-4 rounded-lg border border-theme bg-theme-subtle/30 p-4 text-xs">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.forceBrowser}
                                            onChange={(e) => setForm({ ...form, forceBrowser: e.target.checked })}
                                            className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                        />
                                        <div>
                                            <span className="font-semibold block text-theme-primary">Force Headless Browser Engine (Playwright)</span>
                                            <span className="text-[11px] text-theme-muted">Recommended for React/Vue/Next.js dynamic SPAs and heavily lazy-loaded VSLs.</span>
                                        </div>
                                    </label>

                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.stripTrackers}
                                            onChange={(e) => setForm({ ...form, stripTrackers: e.target.checked })}
                                            className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                        />
                                        <div>
                                            <span className="font-semibold block text-theme-primary">Strip Vendor Analytics &amp; Pixels</span>
                                            <span className="text-[11px] text-theme-muted">Removes Meta Pixel, GTM, GA4, TikTok, Clarity, Hotjar &amp; domain locks.</span>
                                        </div>
                                    </label>

                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.enableParamPassThrough}
                                            onChange={(e) => setForm({ ...form, enableParamPassThrough: e.target.checked })}
                                            className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                        />
                                        <div>
                                            <span className="font-semibold block text-theme-primary">Inject Parameter Pass-Through Engine</span>
                                            <span className="text-[11px] text-theme-muted">Captures gclid, wbraid, gbraid, utm_* and forwards them to checkout.</span>
                                        </div>
                                    </label>

                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={form.optimizeImages}
                                            onChange={(e) => setForm({ ...form, optimizeImages: e.target.checked })}
                                            className="rounded border-theme text-[var(--accentColor)] focus:ring-[var(--accentFocusRing)]"
                                        />
                                        <div>
                                            <span className="font-semibold block text-theme-primary">Core Web Vitals Optimization</span>
                                            <span className="text-[11px] text-theme-muted">Adds async decoding, lazy-loading below fold, and high priority hero image.</span>
                                        </div>
                                    </label>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2 pt-2 border-t border-theme">
                                    <div>
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 mb-1">
                                            <label className="font-semibold block text-theme-primary text-xs sm:text-sm">
                                                Custom &lt;head&gt; Code (Google Ads Tag / GTM)
                                            </label>

                                            {googleAdsAccounts.length > 0 && (
                                                <div className="flex flex-wrap items-center gap-1 text-xs">
                                                    <select
                                                        className="py-0.5 px-1.5 text-[11px] rounded border border-theme bg-theme-surface text-theme-primary max-w-[130px]"
                                                        value={clonerAccountId}
                                                        onChange={(e) => {
                                                            setClonerAccountId(e.target.value);
                                                            setClonerActionId('');
                                                        }}
                                                        title="Google Ads Account"
                                                    >
                                                        <option value="">Select Account...</option>
                                                        {googleAdsAccounts.map((acc) => (
                                                            <option key={acc.id} value={acc.id}>
                                                                {acc.name}
                                                            </option>
                                                        ))}
                                                    </select>

                                                    <select
                                                        className="py-0.5 px-1.5 text-[11px] rounded border border-theme bg-theme-surface text-theme-primary max-w-[130px]"
                                                        value={clonerActionId}
                                                        onChange={(e) => setClonerActionId(e.target.value)}
                                                        disabled={!clonerAccountId || clonerAvailableActions.length === 0}
                                                        title="Conversion Action (optional)"
                                                    >
                                                        <option value="">
                                                            {clonerAvailableActions.length === 0 ? 'Default gtag' : 'Action (opt)...'}
                                                        </option>
                                                        {clonerAvailableActions.map((act) => (
                                                            <option key={act.id} value={act.id}>
                                                                {act.name}
                                                            </option>
                                                        ))}
                                                    </select>

                                                    <button
                                                        type="button"
                                                        onClick={handleInsertGoogleAdsTag}
                                                        disabled={!clonerAccountId || isFetchingTag}
                                                        className="px-2 py-0.5 rounded text-[11px] font-semibold bg-[var(--accentColor)] text-white hover:opacity-90 shadow-2xs transition-all flex items-center gap-1 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                        title="Automatically insert Google Ads global site tag (gtag.js)"
                                                    >
                                                        {isFetchingTag ? (
                                                            <span>Fetching...</span>
                                                        ) : (
                                                            <>
                                                                <svg className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                                                </svg>
                                                                <span>Insert Google Ads tag</span>
                                                            </>
                                                        )}
                                                    </button>
                                                </div>
                                            )}
                                        </div>

                                        {tagNotice && (
                                            <div className="mb-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                {tagNotice}
                                            </div>
                                        )}

                                        <textarea
                                            rows="3"
                                            placeholder="<!-- Global site tag (gtag.js) - Google Ads -->"
                                            className={`${control} font-mono text-xs`}
                                            value={form.customHead}
                                            onChange={(e) => setForm({ ...form, customHead: e.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <label className="font-semibold block text-theme-primary mb-1">Custom &lt;body&gt; Code (Conversion Snippets / Footer)</label>
                                        <textarea
                                            rows="3"
                                            placeholder="<!-- Compliance disclaimer or conversion tracking script -->"
                                            className={`${control} font-mono text-xs`}
                                            value={form.customBody}
                                            onChange={(e) => setForm({ ...form, customBody: e.target.value })}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Action Buttons */}
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-3 border-t border-theme">
                        <button
                            type="button"
                            onClick={onCancel}
                            className={quiet}
                        >
                            Cancel
                        </button>

                        <button
                            type="button"
                            onClick={handleStartClone}
                            disabled={!form.salesPageUrl || !form.affiliateLink}
                            className={`${button} py-2.5 px-6 font-semibold flex items-center justify-center gap-2 text-sm shadow-sm`}
                        >
                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                                <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                <path d="M3 3v5h5" />
                                <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16" />
                                <path d="M16 21h5v-5" />
                            </svg>
                            <span>1-Click Crawl &amp; Clone Page</span>
                        </button>
                    </div>
                </div>
            )}

            {/* STEP 2: PROCESSING & DIAGNOSTICS */}
            {step === 2 && (
                <div className="rounded-xl border border-theme bg-theme-surface p-8 shadow-xs text-center space-y-6 max-w-xl mx-auto">
                    <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-[var(--accentSubtleColor)] text-theme-accent border border-theme animate-pulse">
                        <svg className="w-8 h-8 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                            <line x1="12" y1="2" x2="12" y2="6" />
                            <line x1="12" y1="18" x2="12" y2="22" />
                            <line x1="4.93" y1="4.93" x2="7.76" y2="7.76" />
                            <line x1="16.24" y1="16.24" x2="19.07" y2="19.07" />
                            <line x1="2" y1="12" x2="6" y2="12" />
                            <line x1="18" y1="12" x2="22" y2="12" />
                            <line x1="4.93" y1="19.07" x2="7.76" y2="16.24" />
                            <line x1="16.24" y1="7.76" x2="19.07" y2="4.93" />
                        </svg>
                    </div>

                    <div>
                        <h3 className="text-xl font-bold text-theme-primary">
                            Cloning Sales Page
                        </h3>
                        <p className="text-xs text-theme-secondary mt-1 font-mono break-all max-w-md mx-auto">
                            {form.salesPageUrl}
                        </p>
                    </div>

                    {/* Progress steps checklist */}
                    <div className="space-y-2 text-left max-w-sm mx-auto bg-theme-card p-4 rounded-xl border border-theme shadow-inner">
                        {stages.map((stage, idx) => {
                            const isDone = idx < currentStageIndex;
                            const isCurrent = idx === currentStageIndex;
                            return (
                                <div
                                    key={idx}
                                    className={`flex items-center gap-2.5 text-xs transition-opacity ${
                                        isCurrent ? 'font-bold text-theme-accent opacity-100' : isDone ? 'text-theme-muted opacity-80' : 'text-theme-muted opacity-40'
                                    }`}
                                >
                                    <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px]">
                                        {isDone ? '✓' : isCurrent ? '●' : '○'}
                                    </span>
                                    <span>{stage}</span>
                                </div>
                            );
                        })}
                    </div>

                    <div>
                        <button
                            type="button"
                            onClick={handleCancelClone}
                            className={`${quiet} text-xs text-rose-600 dark:text-rose-400`}
                        >
                            Cancel Request
                        </button>
                    </div>
                </div>
            )}

            {/* STEP 3: VISUAL REVIEW, INSPECTOR & EXPORT */}
            {step === 3 && cloneResult && (
                <div className="space-y-4">
                    {/* Diagnostic Summary Cards Bar */}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div className="rounded-xl border border-theme bg-theme-surface p-3 shadow-2xs">
                            <span className="text-[11px] font-medium text-theme-muted block">Affiliate Links Replaced</span>
                            <span className="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-0.5 block">
                                {cloneResult.stats.affiliateLinksReplaced} / {cloneResult.stats.totalOutboundLinks}
                            </span>
                        </div>

                        <div className="rounded-xl border border-theme bg-theme-surface p-3 shadow-2xs">
                            <span className="text-[11px] font-medium text-theme-muted block">Assets Rewritten (Clean Absolute)</span>
                            <span className="text-xl font-bold text-theme-primary mt-0.5 block">
                                {cloneResult.stats.assetsRewritten}
                            </span>
                        </div>

                        <div className="rounded-xl border border-theme bg-theme-surface p-3 shadow-2xs">
                            <span className="text-[11px] font-medium text-theme-muted block">Trackers &amp; Pixels Stripped</span>
                            <span className="text-xl font-bold text-amber-600 dark:text-amber-400 mt-0.5 block">
                                {cloneResult.stats.trackersStripped}
                            </span>
                        </div>

                        <div className="rounded-xl border border-theme bg-theme-surface p-3 shadow-2xs">
                            <span className="text-[11px] font-medium text-theme-muted block">Lazy Images Promoted</span>
                            <span className="text-xl font-bold text-theme-primary mt-0.5 block">
                                {cloneResult.stats.lazyImagesPromoted}
                            </span>
                        </div>

                        <div className="rounded-xl border border-theme bg-theme-surface p-3 shadow-2xs">
                            <span className="text-[11px] font-medium text-theme-muted block">Rendered Via / Size</span>
                            <span className="text-sm font-semibold text-theme-primary mt-1 block">
                                <span className="uppercase text-[11px] px-1.5 py-0.5 rounded bg-theme-subtle border border-theme">
                                    {cloneResult.renderedVia || 'HTTP'}
                                </span>{' '}
                                &bull; {kbSize} KB
                            </span>
                        </div>
                    </div>

                    {/* Toolbar: Viewport switcher + Inspector Toggles + Actions */}
                    <div className="rounded-xl border border-theme bg-theme-surface p-2.5 shadow-xs flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center flex-wrap gap-2">
                            {/* Viewport switcher */}
                            <div className="flex items-center rounded-lg border border-theme bg-theme-card p-1">
                                <button
                                    type="button"
                                    onClick={() => setViewport('desktop')}
                                    className={`px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                        viewport === 'desktop'
                                            ? 'bg-theme-surface text-theme-primary shadow-xs'
                                            : 'text-theme-muted hover:text-theme-secondary'
                                    }`}
                                    title="Desktop 100% width"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <rect width="20" height="14" x="2" y="3" rx="2" />
                                        <line x1="8" y1="21" x2="16" y2="21" />
                                        <line x1="12" y1="17" x2="12" y2="21" />
                                    </svg>
                                    <span>Desktop (1280px)</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setViewport('tablet')}
                                    className={`px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                        viewport === 'tablet'
                                            ? 'bg-theme-surface text-theme-primary shadow-xs'
                                            : 'text-theme-muted hover:text-theme-secondary'
                                    }`}
                                    title="Tablet 768px viewport"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <rect width="16" height="20" x="4" y="2" rx="2" />
                                        <line x1="12" y1="18" x2="12.01" y2="18" />
                                    </svg>
                                    <span>Tablet (768px)</span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setViewport('mobile')}
                                    className={`px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 transition-colors ${
                                        viewport === 'mobile'
                                            ? 'bg-theme-surface text-theme-primary shadow-xs'
                                            : 'text-theme-muted hover:text-theme-secondary'
                                    }`}
                                    title="Mobile 375px viewport"
                                >
                                    <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <rect width="14" height="20" x="5" y="2" rx="2" />
                                        <line x1="12" y1="18" x2="12.01" y2="18" />
                                    </svg>
                                    <span>Mobile (375px)</span>
                                </button>
                            </div>

                            {/* Highlight Affiliate Links Toggle */}
                            <button
                                type="button"
                                onClick={() => setHighlightLinks(!highlightLinks)}
                                className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 border transition-all ${
                                    highlightLinks
                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/30'
                                        : 'bg-theme-card text-theme-muted border-theme hover:text-theme-secondary'
                                }`}
                                title="Visually outline all replaced affiliate links in green"
                            >
                                <span className={`w-2 h-2 rounded-full ${highlightLinks ? 'bg-emerald-500' : 'bg-stone-400'}`} />
                                <span>Highlight Links</span>
                            </button>

                            {/* Inline Editing Toggle */}
                            <button
                                type="button"
                                onClick={() => setIsContentEditable(!isContentEditable)}
                                className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 border transition-all ${
                                    isContentEditable
                                        ? 'bg-purple-500/15 text-purple-700 dark:text-purple-300 border-purple-500/30'
                                        : 'bg-theme-card text-theme-muted border-theme hover:text-theme-secondary'
                                }`}
                                title="Click on text in the preview to edit live"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                                </svg>
                                <span>{isContentEditable ? 'Editing Enabled' : 'Edit Text'}</span>
                            </button>

                            {/* Link Inventory Inspector Drawer Toggle */}
                            <button
                                type="button"
                                onClick={() => setShowLinkInspector(!showLinkInspector)}
                                className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 border transition-all ${
                                    showLinkInspector
                                        ? 'bg-[var(--accentSubtleColor)] text-theme-accent border-theme'
                                        : 'bg-theme-card text-theme-muted border-theme hover:text-theme-secondary'
                                }`}
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                                </svg>
                                <span>Link Inspector ({cloneResult.links.length})</span>
                            </button>
                        </div>

                        {/* Top Action Buttons */}
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={handleCopyHtml}
                                className={`${button} py-1.5 px-3 text-xs flex items-center gap-1.5 font-semibold shadow-xs`}
                            >
                                {copiedHtml ? (
                                    <>
                                        <span className="text-emerald-200">✓</span>
                                        <span>Copied!</span>
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                        </svg>
                                        <span>Copy HTML</span>
                                    </>
                                )}
                            </button>

                            <button
                                type="button"
                                onClick={handleDownloadHtml}
                                className={`${quiet} py-1.5 px-3 text-xs flex items-center gap-1.5`}
                                title="Download standalone index.html"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>
                                <span>Download</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setShowSaveModal(true)}
                                className={`${quiet} py-1.5 px-3 text-xs flex items-center gap-1.5 text-theme-accent border-theme-accent/40 font-semibold`}
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                                    <polyline points="17 21 17 13 7 13 7 21" />
                                    <polyline points="7 3 7 8 15 8" />
                                </svg>
                                <span>Save Page</span>
                            </button>

                            <button
                                type="button"
                                onClick={handleOpenNewWindow}
                                className={`${quiet} py-1.5 px-2.5 text-xs text-theme-secondary`}
                                title="Open in New Browser Tab"
                            >
                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                    <polyline points="15 3 21 3 21 9" />
                                    <line x1="10" y1="14" x2="21" y2="3" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    {/* Link Inspector Drawer (Collapsible) */}
                    {showLinkInspector && (
                        <div className="rounded-xl border border-theme bg-theme-surface p-4 shadow-sm space-y-3">
                            <div className="flex items-center justify-between border-b border-theme pb-2">
                                <div>
                                    <h4 className="text-sm font-bold text-theme-primary">
                                        Detected Outbound Links ({cloneResult.links.length})
                                    </h4>
                                    <p className="text-xs text-theme-secondary">
                                        Review detected links on the page. All green items point to your affiliate URL. Amber items can be converted with 1 click.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowLinkInspector(false)}
                                    className="text-xs text-theme-muted hover:text-theme-primary cursor-pointer"
                                >
                                    Close ✕
                                </button>
                            </div>

                            <div className="max-h-60 overflow-y-auto space-y-2 divide-y divide-theme">
                                {cloneResult.links.map((link, idx) => (
                                    <div key={idx} className="pt-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`px-1.5 py-0.2 rounded text-[10px] font-bold uppercase ${
                                                        link.isAffiliate
                                                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                                                            : 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30'
                                                    }`}
                                                >
                                                    {link.isAffiliate ? 'Affiliate Link' : 'External Link'}
                                                </span>
                                                <span className="font-semibold text-theme-primary truncate">
                                                    {link.anchorText || 'Button / Link'}
                                                </span>
                                            </div>
                                            <div className="font-mono text-[11px] text-theme-muted truncate mt-0.5">
                                                Target: {link.newUrl}
                                            </div>
                                        </div>

                                        {!link.isAffiliate && (
                                            <button
                                                type="button"
                                                onClick={() => handleSwapLink(link.originalUrl)}
                                                className="shrink-0 rounded bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700 transition-colors shadow-2xs"
                                            >
                                                Swap to Affiliate Link
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Isolated Iframe Stage Frame */}
                    <div className="rounded-xl border border-theme bg-stone-200 dark:bg-stone-900 p-4 min-h-[680px] flex items-center justify-center overflow-auto shadow-inner">
                        {viewport === 'desktop' && (
                            <div className="w-full h-[680px] rounded-lg border border-theme bg-white shadow-md overflow-hidden">
                                <iframe
                                    ref={iframeRef}
                                    title="Desktop Sandboxed Preview"
                                    srcDoc={editableHtml}
                                    sandbox="allow-scripts allow-forms allow-same-origin"
                                    className="w-full h-full border-0"
                                />
                            </div>
                        )}

                        {viewport === 'tablet' && (
                            <div className="w-[768px] h-[680px] rounded-2xl border-8 border-stone-800 bg-white shadow-xl overflow-hidden flex flex-col">
                                <iframe
                                    ref={iframeRef}
                                    title="Tablet Sandboxed Preview"
                                    srcDoc={editableHtml}
                                    sandbox="allow-scripts allow-forms allow-same-origin"
                                    className="w-full h-full border-0"
                                />
                            </div>
                        )}

                        {viewport === 'mobile' && (
                            <div className="w-[375px] h-[720px] rounded-[40px] border-[12px] border-stone-900 bg-white shadow-2xl overflow-hidden flex flex-col relative">
                                {/* Mobile Dynamic Island / Speaker */}
                                <div className="w-28 h-4 bg-stone-900 self-center rounded-b-xl z-20 flex items-center justify-center">
                                    <div className="w-10 h-1 bg-stone-700 rounded-full" />
                                </div>
                                <iframe
                                    ref={iframeRef}
                                    title="Mobile Sandboxed Preview"
                                    srcDoc={editableHtml}
                                    sandbox="allow-scripts allow-forms allow-same-origin"
                                    className="w-full flex-1 border-0"
                                />
                                <div className="w-full h-4 bg-stone-900 flex items-center justify-center">
                                    <div className="w-24 h-1 bg-stone-600 rounded-full" />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Save to FlowHub Library Modal */}
            {showSaveModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md rounded-xl border border-theme bg-theme-surface p-6 shadow-xl space-y-4">
                        <div className="flex items-center justify-between border-b border-theme pb-3">
                            <h3 className="text-lg font-bold text-theme-primary">
                                Save Cloned Page to Library
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowSaveModal(false)}
                                className="text-theme-muted hover:text-theme-primary text-xl font-bold cursor-pointer"
                            >
                                &times;
                            </button>
                        </div>

                        <form onSubmit={handleSaveSubmit} className="space-y-4">
                            <Field label="Page Title in FlowHub">
                                <input
                                    type="text"
                                    required
                                    className={control}
                                    value={saveFormName}
                                    onChange={(e) => setSaveFormName(e.target.value)}
                                />
                            </Field>

                            {tracks.length > 0 && (
                                <Field label="Campaign Track Association">
                                    <select
                                        className={control}
                                        value={form.trackId}
                                        onChange={(e) => setForm({ ...form, trackId: e.target.value })}
                                    >
                                        <option value="">Standalone (No Track)</option>
                                        {tracks.map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.product_name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <div className="flex items-center justify-end gap-2 pt-3 border-t border-theme">
                                <button
                                    type="button"
                                    onClick={() => setShowSaveModal(false)}
                                    className={quiet}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSaving}
                                    className={button}
                                >
                                    {isSaving ? 'Saving...' : 'Confirm & Save Page'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
