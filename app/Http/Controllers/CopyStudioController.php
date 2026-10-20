<?php

namespace App\Http\Controllers;

use App\Services\Copy\BaseCopyGeneratorService;
use App\Services\Copy\CopyCharacterValidator;
use App\Services\Copy\SalesPageScraperService;
use App\Services\Copy\VisionCopyRefinementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CopyStudioController extends Controller
{
    /**
     * Display the Google Ads Copy Studio workspace.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Copy/Index', [
            'initialUrl' => (string) $request->query('url', ''),
            'initialProduct' => (string) $request->query('product', ''),
            'initialLang' => (string) $request->query('lang', 'en'),
            'initialKeyword' => (string) $request->query('targetKeyword', ''),
        ]);
    }

    /**
     * Stage 1: Scrape sales page and generate 20 headlines + 10 descriptions.
     */
    public function generateBase(
        Request $request,
        SalesPageScraperService $scraper,
        BaseCopyGeneratorService $generator
    ): JsonResponse {
        $validated = $request->validate([
            'salesPageUrl' => ['required', 'string', 'max:2048'],
            'manualContext' => ['nullable', 'string', 'max:30000'],
            'targetKeyword' => ['nullable', 'string', 'max:255'],
        ]);

        $url = trim($validated['salesPageUrl']);
        $manualContext = $validated['manualContext'] ?? null;
        $targetKeyword = $validated['targetKeyword'] ?? null;

        // 1. Scrape and generate validated base copy matching copyGeneratorService.ts
        $result = $generator->generateValidatedBaseAdCopy([
            'salesPageUrl' => $url,
            'manualContext' => $manualContext,
            'targetKeyword' => $targetKeyword,
        ]);

        return response()->json([
            'success' => true,
            'detectedLanguage' => $result['adCopy']['detectedLanguage'],
            'productSummary' => $result['adCopy']['productSummary'],
            'scraper' => [
                'isFallback' => $result['usedScraperFallback'],
                'warning' => $result['scraperWarning'],
                'title' => $result['scraped']['pageTitle'],
                'metaDescription' => $result['scraped']['metaDescription'],
                'headings' => $result['scraped']['headings'],
                'bullets' => $result['scraped']['bulletPoints'],
                'bulletPoints' => $result['scraped']['bulletPoints'],
                'guaranteeSignals' => $result['scraped']['guaranteeSignals'],
                'bonusSignals' => $result['scraped']['bonusAndOfferSignals'],
                'bonusAndOfferSignals' => $result['scraped']['bonusAndOfferSignals'],
            ],
            'headlines' => $result['adCopy']['headlines'],
            'descriptions' => $result['adCopy']['descriptions'],
        ]);
    }

    /**
     * On-demand shortener endpoint for UI character compression.
     */
    public function shorten(Request $request, BaseCopyGeneratorService $generator): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable'],
            'text' => ['required', 'string'],
            'type' => ['required', 'in:headline,description'],
            'maxChars' => ['nullable', 'integer', 'min:5', 'max:120'],
            'language' => ['nullable', 'string', 'max:10'],
            'productSummary' => ['nullable', 'string', 'max:5000'],
        ]);

        $shortened = $generator->shortenSingleAssetOnDemand([
            'id' => $validated['id'] ?? uniqid('shorten_'),
            'type' => $validated['type'],
            'currentText' => $validated['text'],
            'detectedLanguage' => $validated['language'] ?? 'en',
            'productSummary' => $validated['productSummary'] ?? '',
        ]);

        $maxChars = $validated['maxChars'] ?? ($validated['type'] === 'headline' ? CopyCharacterValidator::MAX_HEADLINE_CHARS : CopyCharacterValidator::MAX_DESCRIPTION_CHARS);

        return response()->json([
            'success' => true,
            'text' => $shortened['text'],
            'length' => $shortened['charCount'],
            'maxChars' => $maxChars,
        ]);
    }

    /**
     * Stage 2: Competitor SERP screenshot vision refinement.
     */
    public function refineWithScreenshot(
        Request $request,
        VisionCopyRefinementService $refiner
    ): JsonResponse {
        $validated = $request->validate([
            'screenshotBase64' => ['required', 'string'],
            'currentHeadlines' => ['required', 'array', 'min:1'],
            'currentHeadlines.*.id' => ['required'],
            'currentHeadlines.*.text' => ['required', 'string'],
            'currentHeadlines.*.category' => ['nullable', 'string'],
            'currentHeadlines.*.isLocked' => ['nullable', 'boolean'],
            'currentDescriptions' => ['required', 'array', 'min:1'],
            'currentDescriptions.*.id' => ['required'],
            'currentDescriptions.*.text' => ['required', 'string'],
            'currentDescriptions.*.angle' => ['nullable', 'string'],
            'currentDescriptions.*.isLocked' => ['nullable', 'boolean'],
            'productSummary' => ['nullable', 'string', 'max:5000'],
            'detectedLanguage' => ['nullable', 'string', 'max:10'],
        ]);

        $result = $refiner->refine(
            $validated['screenshotBase64'],
            $validated['currentHeadlines'],
            $validated['currentDescriptions'],
            $validated['productSummary'] ?? null,
            $validated['detectedLanguage'] ?? 'en'
        );

        return response()->json($result);
    }
}
