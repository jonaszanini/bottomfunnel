<?php

namespace App\Services\Pages;

use App\Support\CopyGuard;

class HtmlRenderer
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $structure
     */
    public function render(array $structure): string
    {
        $structure = CopyGuard::cleanStructure($this->normalize($structure));
        $html = $this->markup($structure);

        return $this->sanitizer->sanitize($html);
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    public function normalize(array $structure): array
    {
        return [
            'headline' => $this->text($structure['headline'] ?? ''),
            'subheadline' => $this->text($structure['subheadline'] ?? ''),
            'sections' => $this->pairs($structure['sections'] ?? [], 'heading', 'body'),
            'benefits' => $this->strings($structure['benefits'] ?? []),
            'testimonials' => $this->pairs($structure['testimonials'] ?? [], 'name', 'quote'),
            'faq' => $this->pairs($structure['faq'] ?? [], 'question', 'answer'),
            'callsToAction' => $this->listOf($structure['callsToAction'] ?? [], 'label'),
            'ageGate' => [
                'enabled' => (bool) ($structure['ageGate']['enabled'] ?? false),
                'prompt' => $this->text($structure['ageGate']['prompt'] ?? ''),
                'confirmLabel' => $this->text($structure['ageGate']['confirmLabel'] ?? ''),
                'minimumAge' => max(0, (int) ($structure['ageGate']['minimumAge'] ?? 18)),
            ],
            'popup' => [
                'enabled' => (bool) ($structure['popup']['enabled'] ?? false),
                'headline' => $this->text($structure['popup']['headline'] ?? ''),
                'body' => $this->text($structure['popup']['body'] ?? ''),
                'confirmLabel' => $this->text($structure['popup']['confirmLabel'] ?? ''),
            ],
            'navigation' => $this->pairs($structure['navigation'] ?? [], 'label', 'target'),
            'shippingBenefits' => $this->strings($structure['shippingBenefits'] ?? []),
            'socialProof' => $this->pairs($structure['socialProof'] ?? [], 'label', 'detail'),
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function markup(array $structure): string
    {
        $parts = [];

        if ($structure['ageGate']['enabled']) {
            $prompt = $structure['ageGate']['prompt'] !== ''
                ? $structure['ageGate']['prompt']
                : 'Confirm you are '.$structure['ageGate']['minimumAge'].' or older to continue.';
            $label = $structure['ageGate']['confirmLabel'] !== ''
                ? $structure['ageGate']['confirmLabel']
                : 'I will verify and return';
            $parts[] = '<div class="gate" data-age-gate="1"><div class="gate-card"><p>'.e($prompt).'</p><button type="button" data-age-confirm="1">'.e($label).'</button></div></div>';
        }

        if ($structure['popup']['enabled']) {
            $headline = $structure['popup']['headline'] !== '' ? $structure['popup']['headline'] : 'Before you continue';
            $body = $structure['popup']['body'] !== '' ? $structure['popup']['body'] : 'Cookies keep this visit matched to the right offer.';
            $label = $structure['popup']['confirmLabel'] !== '' ? $structure['popup']['confirmLabel'] : 'Take the next step';
            $parts[] = '<div class="popup" data-popup="1"><div class="popup-card"><h2>'.e($headline).'</h2><p>'.e($body).'</p><button type="button" data-outbound="1">'.e($label).'</button></div></div>';
        }

        $parts[] = '<header class="hero"><h1>'.e($structure['headline']).'</h1>';
        if ($structure['subheadline'] !== '') {
            $parts[] = '<p class="sub">'.e($structure['subheadline']).'</p>';
        }
        $parts[] = '</header>';

        if ($structure['navigation'] !== []) {
            $links = '';
            foreach ($structure['navigation'] as $index => $item) {
                $target = preg_match('/^#[A-Za-z0-9_-]+$/', (string) $item['target']) ? $item['target'] : '#section-'.$index;
                $links .= '<a href="'.e($target).'">'.e($item['label']).'</a>';
            }
            $parts[] = '<nav class="nav-buttons">'.$links.'</nav>';
        }

        if ($structure['socialProof'] !== []) {
            $items = '';
            foreach ($structure['socialProof'] as $item) {
                $items .= '<div><strong>'.e($item['detail']).'</strong><span>'.e($item['label']).'</span></div>';
            }
            $parts[] = '<section class="proof">'.$items.'</section>';
        }

        foreach ($structure['sections'] as $index => $section) {
            $parts[] = '<section class="block" id="section-'.$index.'"><h2>'.e($section['heading']).'</h2><p>'.e($section['body']).'</p></section>';
        }

        if ($structure['benefits'] !== []) {
            $items = '';
            foreach ($structure['benefits'] as $benefit) {
                $items .= '<li>'.e($benefit).'</li>';
            }
            $parts[] = '<section class="block"><h2>What stands out</h2><ul class="benefits">'.$items.'</ul></section>';
        }

        if ($structure['shippingBenefits'] !== []) {
            $items = '';
            foreach ($structure['shippingBenefits'] as $benefit) {
                $items .= '<li>'.e($benefit).'</li>';
            }
            $parts[] = '<section class="block"><h2>Shipping benefits</h2><ul class="benefits">'.$items.'</ul></section>';
        }

        foreach ($structure['testimonials'] as $item) {
            $parts[] = '<blockquote><p>'.e($item['quote']).'</p><footer>'.e($item['name']).'</footer></blockquote>';
        }

        if ($structure['faq'] !== []) {
            $items = '';
            foreach ($structure['faq'] as $item) {
                $items .= '<details><summary>'.e($item['question']).'</summary><p>'.e($item['answer']).'</p></details>';
            }
            $parts[] = '<section class="block" id="questions"><h2>Questions</h2>'.$items.'</section>';
        }

        $actions = $structure['callsToAction'] !== []
            ? $structure['callsToAction']
            : [['label' => 'Take the next step']];
        $buttons = '';
        foreach ($actions as $action) {
            $label = $action['label'] !== '' ? $action['label'] : 'Take the next step';
            $buttons .= '<button type="button" data-outbound="1">'.e($label).'</button>';
        }
        $parts[] = '<div class="actions">'.$buttons.'</div>';

        return implode('', $parts);
    }

    private function text(mixed $value): string
    {
        return trim(strip_tags((string) $value));
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $clean = [];
        foreach ($values as $value) {
            $text = $this->text($value);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean;
    }

    /**
     * @return list<array<string, string>>
     */
    private function pairs(mixed $values, string $left, string $right): array
    {
        if (! is_array($values)) {
            return [];
        }
        $clean = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            $clean[] = [
                $left => $this->text($value[$left] ?? ''),
                $right => $this->text($value[$right] ?? ''),
            ];
        }

        return $clean;
    }

    /**
     * @return list<array{label: string}>
     */
    private function listOf(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            return [];
        }
        $clean = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $clean[] = ['label' => $this->text($value)];

                continue;
            }
            if (is_array($value)) {
                $clean[] = ['label' => $this->text($value[$key] ?? '')];
            }
        }

        return $clean;
    }
}
