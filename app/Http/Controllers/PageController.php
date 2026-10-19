<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Track;
use App\Services\Pages\HtmlDocument;
use App\Services\Pages\HtmlRenderer;
use App\Services\Pages\OpenAiPageGenerator;
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
    public function index(): Response
    {
        return Inertia::render('Pages/Index', [
            'pages' => Page::query()->with('track:id,product_name')->latest()->get()->map(fn (Page $page): array => [
                'id' => $page->id,
                'name' => $page->name,
                'page_type' => config('flowhub.page_types.'.$page->page_type, $page->page_type),
                'product_name' => $page->track?->product_name,
                'updated_at' => $page->updated_at?->toDateTimeString(),
            ]),
            'tracks' => Track::query()->orderBy('product_name')->get(['id', 'product_name', 'page_type', 'language']),
            ...Options::forForms(),
        ]);
    }

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
        $script = $this->script($page->injected_script, $page);
        $documentHtml = $document->wrap($page->html, $page->name, $script);

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
            throw ValidationException::withMessages([
                'injected_script' => 'This page is not attached to a Track.',
            ]);
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
