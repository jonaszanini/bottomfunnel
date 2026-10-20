<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Track;
use App\Services\Pages\Compiler\PresellCompilerEngine;
use App\Services\Pages\HtmlDocument;
use App\Services\Pages\HtmlRenderer;
use App\Services\Pages\OpenAiPageGenerator;
use App\Services\Pages\VariationGeneratorService;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
            'pages' => Page::query()->with('track:id,product_name')->latest()->get()->map(fn (Page $page): array => [
                'id' => $page->id,
                'name' => $page->name,
                'page_type' => config('flowhub.page_types.'.$page->page_type, $page->page_type),
                'product_name' => $page->track?->product_name ?? 'Standalone Offer',
                'updated_at' => $page->updated_at?->toDateTimeString(),
            ]),
            'tracks' => Track::query()->orderBy('product_name')->get(['id', 'product_name', 'page_type', 'language', 'affiliate_url']),
            ...Options::forForms(),
            'strategies' => $strategies,
        ]);
    }

    /**
     * Stage A: Generate 3 distinct structural and visual variations for the chosen strategy.
     */
    public function generateVariations(Request $request, VariationGeneratorService $generator): JsonResponse
    {
        $data = $request->validate([
            'strategyType' => ['required', Rule::in(['cookie_popup', 'country_select', 'flash_sale', 'ai_express'])],
            'productName' => ['required', 'string', 'max:255'],
            'language' => ['required', 'string', 'max:10'],
            'affiliateUrl' => ['required', 'url', 'max:2048'],
            'trackingCode' => ['nullable', 'string', 'max:10000'],
            'seoEnabled' => ['boolean'],
        ]);

        $variations = $generator->generateVariations($data);

        return response()->json([
            'success' => true,
            'variations' => $variations,
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
     * Save the compiled presell page to the database.
     */
    public function store(Request $request, PresellCompilerEngine $compiler): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'track_id' => ['nullable', 'integer', 'exists:tracks,id'],
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
    public function generate(Request $request, OpenAiPageGenerator $generator): RedirectResponse
    {
        $data = $request->validate([
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
            'page_type' => ['required', Rule::in(array_keys(config('flowhub.page_types')))],
            'product_name' => ['required', 'string', 'max:160'],
            'language' => ['nullable', Rule::in(array_keys(config('flowhub.languages')))],
            'instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $track = Track::query()->findOrFail($data['track_id']);
        try {
            $generated = $generator->generate(
                $data['product_name'],
                $data['page_type'],
                $data['language'] ?? $track->language,
                $data['instructions'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        $track->update([
            'page_type' => $data['page_type'],
            'language' => $data['language'] ?? $track->language,
        ]);

        $page = $track->pages()->create([
            'name' => Str::limit($data['product_name'].' '.config('flowhub.page_types.'.$data['page_type']), 160, ''),
            'page_type' => $data['page_type'],
            'language' => $data['language'] ?? $track->language,
            'structure_json' => $generated['structure'],
            'html' => $generated['html'],
            'injected_script' => '',
        ]);
        $page->update(['injected_script' => $track->scriptTag($page->id)]);

        return redirect()->route('pages.edit', $page)->with('success', 'Page generated. Review it, then export index.html.');
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
