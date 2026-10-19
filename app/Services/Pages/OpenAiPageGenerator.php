<?php

namespace App\Services\Pages;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiPageGenerator
{
    public function __construct(private readonly HtmlRenderer $renderer) {}

    /**
     * @return array{structure: array<string, mixed>, html: string}
     */
    public function generate(string $productName, string $pageType, ?string $language, ?string $instructions): array
    {
        $key = (string) config('flowhub.openai.key');
        if ($key === '') {
            throw new RuntimeException('OpenAI is not configured. Set OPENAI_API_KEY in the environment.');
        }

        $typeLabel = config('flowhub.page_types.'.$pageType, $pageType);
        $languageName = config('flowhub.languages.'.$language, $language ?: 'English');

        $response = Http::withToken($key)
            ->timeout(60)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('flowhub.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.4,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'landing_page',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($productName, $typeLabel, (string) $languageName, (string) $instructions)],
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: 'OpenAI returned an error.';
            throw new RuntimeException('OpenAI request failed: '.$message);
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI did not return page JSON.');
        }

        $decoded = $this->applyType($decoded, $pageType);
        $structure = $this->renderer->normalize($decoded);

        return [
            'structure' => $structure,
            'html' => $this->renderer->render($structure),
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private function applyType(array $structure, string $pageType): array
    {
        if ($pageType === 'age_gate') {
            $structure['ageGate']['enabled'] = true;
        }
        if (in_array($pageType, ['popup_cookie', 'captcha_cookies'], true)) {
            $structure['popup']['enabled'] = true;
        }

        return $structure;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You write landing page copy as JSON only. Never return HTML, markdown, or a code fence.
Tone is professional and calm.
When money comes up, use Invest, Value, Excellent value, and Special condition.
Do not name a cost, a reduction, or a hard sell.
Use "Take the next step" for the main action.
Use "I will verify and return" when a reader confirms something.
Use commas or periods. Do not use an em dash or an en dash.
Do not invent a checkout, a cart, or a guarantee you were not given.
The script that tracks the visit is added later. Leave calls to action as labels only.
PROMPT;
    }

    private function userPrompt(string $product, string $type, string $language, string $instructions): string
    {
        $extra = trim($instructions) === '' ? 'None.' : trim($instructions);

        return <<<PROMPT
Product: {$product}
Page type: {$type}
Language: {$language}
Extra instructions: {$extra}

Page type guidance:
Popup/Cookie is a short direct page with the popup enabled.
Review Robust is a longer review with several sections, testimonials, and questions.
Age Gate asks the reader to confirm their age before the rest of the page.
Presell tells the story before the next step.
Review is a shorter review.
Robust is a long educational page.
Captcha/Cookies includes a confirmation panel about cookies.
Custom combination follows the extra instructions and may mix those patterns.

Write the page in {$language}.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $string = ['type' => 'string'];
        $object = function (array $properties, array $required): array {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $properties,
                'required' => $required,
            ];
        };

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'headline', 'subheadline', 'sections', 'benefits', 'testimonials', 'faq',
                'callsToAction', 'ageGate', 'popup', 'navigation', 'shippingBenefits', 'socialProof',
            ],
            'properties' => [
                'headline' => $string,
                'subheadline' => $string,
                'sections' => [
                    'type' => 'array',
                    'items' => $object(['heading' => $string, 'body' => $string], ['heading', 'body']),
                ],
                'benefits' => ['type' => 'array', 'items' => $string],
                'testimonials' => [
                    'type' => 'array',
                    'items' => $object(['name' => $string, 'quote' => $string], ['name', 'quote']),
                ],
                'faq' => [
                    'type' => 'array',
                    'items' => $object(['question' => $string, 'answer' => $string], ['question', 'answer']),
                ],
                'callsToAction' => [
                    'type' => 'array',
                    'items' => $object(['label' => $string], ['label']),
                ],
                'ageGate' => $object([
                    'enabled' => ['type' => 'boolean'],
                    'prompt' => $string,
                    'confirmLabel' => $string,
                    'minimumAge' => ['type' => 'integer'],
                ], ['enabled', 'prompt', 'confirmLabel', 'minimumAge']),
                'popup' => $object([
                    'enabled' => ['type' => 'boolean'],
                    'headline' => $string,
                    'body' => $string,
                    'confirmLabel' => $string,
                ], ['enabled', 'headline', 'body', 'confirmLabel']),
                'navigation' => [
                    'type' => 'array',
                    'items' => $object(['label' => $string, 'target' => $string], ['label', 'target']),
                ],
                'shippingBenefits' => ['type' => 'array', 'items' => $string],
                'socialProof' => [
                    'type' => 'array',
                    'items' => $object(['label' => $string, 'detail' => $string], ['label', 'detail']),
                ],
            ],
        ];
    }
}
