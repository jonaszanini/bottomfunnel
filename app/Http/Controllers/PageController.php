<?php

namespace App\Http\Controllers;

use App\Jobs\ClonePageJob;
use App\Jobs\GenerateCopyJob;
use App\Models\GoogleAdsAccount;
use App\Models\Page;
use App\Models\Track;
use App\Services\Pages\Cloner\PageCrawlerService;
use App\Services\Pages\Compiler\PresellCompilerEngine;
use App\Services\Pages\HtmlDocument;
use App\Services\Pages\HtmlRenderer;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PageController extends Controller
{
    /**
     * Display the unified Presell Page Generator dashboard.
     */
    public function index(): Response
    {
        $strategies = [
            [
                'id' => 'cookie_popup',
                'name' => 'Custom Popup Pages',
                'badge' => 'High Intent',
                'subtitle' => 'Cookie Consent & Immediate Discount',
                'description' => 'Lightweight landing pages with an interactive overlay simulating compliance consent or a VIP discount pass before routing to the offer.',
                'features' => ['GDPR / Privacy look', 'High compliance pass-rate', 'Instant coupon trigger'],
            ],
            [
                'id' => 'country_select',
                'name' => 'Country Selection Presell',
                'badge' => 'Global Geo Fit',
                'subtitle' => 'Interactive Delivery & Stock Check',
                'description' => 'Interactive flag cards prompting users to select their destination country to confirm regional warehouse stock and unlock exclusive pricing.',
                'features' => ['Interactive SVG flags', 'Inventory verification state', 'Tier 1 regional routing'],
            ],
            [
                'id' => 'flash_sale',
                'name' => 'Benefits & Flash Sale',
                'badge' => 'Top Converting',
                'subtitle' => 'Scarcity Timer & Exit-Intent Alert',
                'description' => 'Dynamic bottom-of-funnel presell highlighting core product pillars, a live scarcity countdown banner, and an exit-intent discount popup.',
                'features' => ['Live countdown timer', '3-Pillar benefit stack', 'Exit-intent discount modal'],
            ],
            [
                'id' => 'ai_express',
                'name' => 'AI Express Generator',
                'badge' => 'Deep Editorial',
                'subtitle' => 'Consumer Review & Analysis',
                'description' => 'Comprehensive editorial product review and quality analysis pages engineered for top and middle of funnel search traffic.',
                'features' => ['Objective review tone', 'Executive summary box', 'Sticky high-contrast CTA'],
            ],
        ];

        return Inertia::render('Pages/Index', [
            'pages' => Page::query()
                ->with([
                    'track:id,product_name',
                    'campaign:id,campaign_id,name,status,google_ads_account_id',
                    'campaign.account:id,name,customer_id',
                ])
                ->latest()
                ->get()
                ->map(fn (Page $page): array => [
                    'id' => $page->id,
                    'name' => $page->name,
                    'page_type' => config('flowhub.page_types.'.$page->page_type, $page->page_type),
                    'product_name' => $page->track?->product_name ?? 'Standalone Offer',
                    'track_product_name' => $page->track?->product_name ?? 'Standalone Offer',
                    'track_id' => $page->track_id,
                    'campaign_id' => $page->campaign_id,
                    'campaign_name' => $page->campaign?->name,
                    'campaign_account_name' => $page->campaign?->account?->name,
                    'updated_at' => $page->updated_at?->toDateTimeString(),
                    'created_at' => $page->created_at?->toDateTimeString(),
                ]),
            'tracks' => Track::query()
                ->with([
                    'account:id,name,customer_id',
                    'conversionAction:id,name,external_id,resource_name',
                ])
                ->orderBy('product_name')
                ->get(['id', 'product_name', 'page_type', 'language', 'affiliate_url', 'google_ads_account_id', 'conversion_action_id']),
            'google_ads_accounts' => GoogleAdsAccount::query()
                ->with(['conversionActions:id,google_ads_account_id,name,external_id,resource_name'])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'customer_id', 'is_default']),
            ...Options::forForms(),
            'strategies' => $strategies,
        ]);
    }

    /**
     * Stage A: Generate 3 distinct structural and visual variations for the chosen strategy.
     */
    public function generateVariations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strategyType' => ['required', Rule::in(['cookie_popup', 'country_select', 'flash_sale', 'ai_express'])],
            'productName' => ['required', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:10'],
            'affiliateUrl' => ['required', 'url', 'max:2048'],
            'trackingCode' => ['nullable', 'string', 'max:10000'],
            'seoEnabled' => ['boolean'],
        ]);

        $jobId = (string) Str::uuid();

        GenerateCopyJob::dispatch(GenerateCopyJob::TYPE_VARIATIONS, $data, $jobId);

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
            'message' => 'Variations generation job queued.',
        ], 202);
    }

    /**
     * Poll status of an asynchronous variation generation job.
     */
    public function variationsStatus(string $jobId): JsonResponse
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
                'message' => $cached['error'] ?? 'Variations generation failed.',
            ], 422);
        }

        return response()->json([
            'status' => $cached['status'] ?? 'processing',
            'job_id' => $jobId,
        ]);
    }

    /**
     * Stage B: Deterministic instant HTML recompilation without calling LLM.
     */
    public function compile(Request $request, PresellCompilerEngine $compiler): JsonResponse
    {
        $data = $request->validate([
            'variation' => ['required', 'array'],
        ]);

        $html = $compiler->compile($data['variation']);

        return response()->json([
            'success' => true,
            'compiledHtml' => $html,
        ]);
    }

    /**
     * Crawl an external sales page and clone it with asset rewriting and affiliate link replacement.
     */
    public function crawlAndClone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'affiliate_link' => ['required', 'url', 'max:2048'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'force_browser' => ['boolean'],
            'custom_head' => ['nullable', 'string', 'max:15000'],
            'custom_body' => ['nullable', 'string', 'max:15000'],
            'strip_trackers' => ['boolean'],
            'optimize_images' => ['boolean'],
            'enable_param_pass_through' => ['boolean'],
        ]);

        $jobId = (string) Str::uuid();

        ClonePageJob::dispatch($jobId, $data);

        // If running in sync mode, check if the job already stored completed result
        $cached = Cache::get("clone_job:{$jobId}");
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
            'message' => 'Page cloning job queued successfully.',
        ], 202);
    }

    /**
     * Poll status of an asynchronous crawl and clone job.
     */
    public function crawlStatus(string $jobId): JsonResponse
    {
        $cached = Cache::get("clone_job:{$jobId}");
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
                'message' => $cached['error'] ?? 'Page cloning failed.',
            ], 422);
        }

        return response()->json([
            'status' => $cached['status'] ?? 'processing',
            'job_id' => $jobId,
        ]);
    }

    /**
     * Rapid extraction of product metadata and CTA links before cloning.
     */
    public function extractMetadata(Request $request, PageCrawlerService $crawler): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'force_browser' => ['boolean'],
        ]);

        try {
            $result = $crawler->crawl($data['url'], (bool) ($data['force_browser'] ?? false), 15);

            return response()->json([
                'success' => true,
                'url' => $data['url'],
                'finalUrl' => $result['finalUrl'],
                'title' => $result['title'],
                'description' => $result['description'],
                'image' => $result['image'],
                'priceRange' => $result['priceRange'],
                'detectedPlatform' => $result['detectedPlatform'],
                'ctaLinks' => $result['ctaLinks'],
                'renderedVia' => $result['renderedVia'],
                'stats' => $result['stats'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Save the compiled presell page to the database.
     */
    public function store(Request $request, PresellCompilerEngine $compiler): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'track_id' => ['nullable', 'integer', 'exists:tracks,id'],
            'campaign_id' => ['nullable', 'string', 'max:64'],
            'page_type' => ['nullable', 'string', 'max:64'],
            'language' => ['nullable', 'string', 'max:10'],
            'variation' => ['nullable', 'array'],
            'html' => ['nullable', 'string'],
        ]);

        // Resolve or associate Track
        $trackId = $data['track_id'] ?? null;
        if (! $trackId) {
            $firstTrack = Track::query()->first();
            if (! $firstTrack) {
                $firstTrack = Track::query()->create([
                    'product_name' => $data['name'],
                    'platform' => 'other',
                    'affiliate_url' => $data['variation']['affiliateUrl'] ?? 'https://example.com',
                    'page_type' => $data['page_type'] ?? 'presell',
                    'strategy' => 'manual_cpc',
                    'language' => $data['language'] ?? 'en',
                ]);
            }
            $trackId = $firstTrack->id;
        }

        $html = $data['html'] ?? '';
        if ($html === '' && ! empty($data['variation'])) {
            $html = $compiler->compile($data['variation']);
        }

        $page = Page::query()->create([
            'track_id' => $trackId,
            'campaign_id' => ! empty($data['campaign_id']) ? $data['campaign_id'] : null,
            'name' => $data['name'],
            'page_type' => $data['page_type'] ?? ($data['variation']['strategyType'] ?? 'presell'),
            'language' => $data['language'] ?? ($data['variation']['language'] ?? 'en'),
            'structure_json' => $data['variation'] ?? [],
            'html' => $html,
            'injected_script' => '',
        ]);

        return redirect()->route('pages.index')->with('success', "Presell page '{$page->name}' saved successfully.");
    }

    /**
     * Assign or unassign a page to a Google Ads campaign.
     */
    public function assignCampaign(Request $request, Page $page): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['nullable', 'string', 'max:64'],
        ]);

        $campaignId = ! empty($data['campaign_id']) ? trim($data['campaign_id']) : null;

        $page->update([
            'campaign_id' => $campaignId,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'success' => true,
                'page_id' => $page->id,
                'campaign_id' => $page->campaign_id,
                'message' => $campaignId ? 'Page assigned to campaign.' : 'Page unassigned from campaign.',
            ]);
        }

        return back()->with('success', $campaignId ? 'Page assigned to campaign.' : 'Page unassigned from campaign.');
    }

    /**
     * Direct download of compiled standalone index.html without saving.
     */
    public function exportDirect(Request $request, PresellCompilerEngine $compiler): HttpResponse
    {
        $html = $request->input('html');
        if (empty($html) && $request->has('variation')) {
            $html = $compiler->compile($request->input('variation'));
        }

        if (empty($html)) {
            $html = '<!DOCTYPE html><html><body>Error generating page</body></html>';
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="index.html"',
        ]);
    }

    /**
     * Legacy generate endpoint preserved for track integrations.
     */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
            'page_type' => ['required', Rule::in(array_keys(config('flowhub.page_types')))],
            'product_name' => ['required', 'string', 'max:160'],
            'language' => ['nullable', Rule::in(array_keys(config('flowhub.languages')))],
            'instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $jobId = (string) Str::uuid();

        GenerateCopyJob::dispatch(GenerateCopyJob::TYPE_OPENAI_PAGE, $data, $jobId);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        if (is_array($cached) && ($cached['status'] ?? null) === 'completed' && ! empty($cached['result']['page_id'])) {
            $page = Page::query()->find($cached['result']['page_id']);
            if ($page) {
                return redirect()->route('pages.edit', $page)->with('success', 'Page generated. Review it, then export index.html.');
            }
        }

        return redirect()->route('pages.index')->with('success', 'Page generation job queued.');
    }

    public function edit(Page $page): Response
    {
        $page->load('track:id,product_name,public_script_key,page_type');

        return Inertia::render('Pages/Builder', [
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'page_type' => $page->page_type,
                'page_type_label' => config('flowhub.page_types.'.$page->page_type, $page->page_type),
                'language' => $page->language,
                'structure' => $page->structure_json,
                'injected_script' => $page->injected_script,
                'product_name' => $page->track?->product_name,
            ],
        ]);
    }

    public function update(Request $request, Page $page, HtmlRenderer $renderer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'structure' => ['required', 'array'],
            'injected_script' => ['nullable', 'string', 'max:1000'],
        ]);
        $page->load('track');
        $script = $this->script($data['injected_script'] ?? '', $page);
        $structure = $renderer->normalize($data['structure']);
        $page->update([
            'name' => $data['name'],
            'structure_json' => $structure,
            'html' => $renderer->render($structure),
            'injected_script' => $script,
        ]);
        $page->track?->update(['page_type' => $page->page_type]);

        return back()->with('success', 'Page saved.');
    }

    public function preview(Request $request, Page $page, HtmlRenderer $renderer, HtmlDocument $document): JsonResponse
    {
        $data = $request->validate([
            'structure' => ['required', 'array'],
        ]);
        $html = $renderer->render($renderer->normalize($data['structure']));

        return response()->json([
            'html' => $document->wrap($html, $page->name),
        ]);
    }

    public function export(Page $page, HtmlDocument $document): HttpResponse
    {
        $page->load('track');
        $script = $page->injected_script;
        if ($page->track) {
            $script = $this->script($page->injected_script, $page);
        }

        // If the page was created via the standalone compiler, its html is already a full document
        if (str_starts_with(trim($page->html), '<!DOCTYPE html')) {
            $documentHtml = $page->html;
        } else {
            $documentHtml = $document->wrap($page->html, $page->name, $script);
        }

        return response($documentHtml, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="index.html"',
        ]);
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect()->route('pages.index')->with('success', 'Page removed.');
    }

    private function script(string $script, Page $page): string
    {
        $track = $page->track;
        if (! $track) {
            return '';
        }
        $script = trim($script);
        if ($script === '') {
            return $track->scriptTag($page->id);
        }
        $pattern = '/^<script async src="(https?:\/\/[^"]+\/t\.js)" data-track="([A-Za-z0-9]+)" data-page="(\d*)"><\/script>$/';
        if (! preg_match($pattern, $script, $matches) || ! hash_equals($track->public_script_key, $matches[2])) {
            throw ValidationException::withMessages([
                'injected_script' => 'The script field must be this Track script tag.',
            ]);
        }
        if ($matches[3] !== '' && $matches[3] !== (string) $page->id) {
            throw ValidationException::withMessages([
                'injected_script' => 'The script field must point at this page.',
            ]);
        }

        return $script;
    }
}
