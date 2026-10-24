<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCopyJob;
use App\Services\Copy\BaseCopyGeneratorService;
use App\Services\Copy\CopyCharacterValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
    public function generateBase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesPageUrl' => ['required', 'string', 'max:2048'],
            'manualContext' => ['nullable', 'string', 'max:30000'],
            'targetKeyword' => ['nullable', 'string', 'max:255'],
        ]);

        $jobId = (string) Str::uuid();

        GenerateCopyJob::dispatch(GenerateCopyJob::TYPE_BASE_COPY, $validated, $jobId);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        if (is_array($cached) && ($cached['status'] ?? null) === 'completed') {
            return response()->json(array_merge([
                'job_id' => $jobId,
                'status' => 'completed',
            ], $cached['result']));
        }

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'pending',
            'message' => 'Base copy generation job queued.',
        ], 202);
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
    public function refineWithScreenshot(Request $request): JsonResponse
    {
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

        $jobId = (string) Str::uuid();

        GenerateCopyJob::dispatch(GenerateCopyJob::TYPE_VISION_REFINE, $validated, $jobId);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        if (is_array($cached) && ($cached['status'] ?? null) === 'completed') {
            return response()->json(array_merge([
                'job_id' => $jobId,
                'status' => 'completed',
            ], $cached['result']));
        }

        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'pending',
            'message' => 'Vision copy refinement job queued.',
        ], 202);
    }

    /**
     * Poll status of an asynchronous copy generation job.
     */
    public function copyStatus(string $jobId): JsonResponse
    {
        $cached = Cache::get("generate_copy_job:{$jobId}");
        if (! $cached) {
            return response()->json([
                'status' => 'pending',
                'job_id' => $jobId,
            ]);
        }

        if ($cached['status'] === 'completed') {
            return response()->json(array_merge([
                'job_id' => $jobId,
                'status' => 'completed',
            ], $cached['result'] ?? []));
        }

        if ($cached['status'] === 'failed') {
            return response()->json([
                'success' => false,
                'job_id' => $jobId,
                'status' => 'failed',
                'message' => $cached['error'] ?? 'Copy generation failed.',
            ], 422);
        }

        return response()->json([
            'status' => $cached['status'] ?? 'processing',
            'job_id' => $jobId,
        ]);
    }
}
