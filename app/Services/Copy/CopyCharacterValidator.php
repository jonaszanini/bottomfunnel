<?php

namespace App\Services\Copy;

use Closure;

class CopyCharacterValidator
{
    public const MAX_HEADLINE_CHARS = 30;

    public const MAX_DESCRIPTION_CHARS = 90;

    public const MAX_COMPRESSION_RETRIES = 2;

    /**
     * Get exact character length (Unicode code points count).
     */
    public function getExactCharLength(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Google Ads Policy Sanitizer:
     * - Strips exclamation marks from headlines.
     * - Allows at most one exclamation mark in descriptions.
     * - Normalizes accidental ALL CAPS words (leaving acronyms <= 3 letters).
     * - Collapses multiple spaces.
     */
    public function sanitizeGoogleAdsText(string $rawText, string $type): string
    {
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $rawText));

        if ($type === 'headline') {
            $cleaned = (string) preg_replace('/!+/u', '', $cleaned);
        } else {
            $exclamationSeen = false;
            $cleaned = (string) preg_replace_callback('/!/u', function () use (&$exclamationSeen) {
                if (! $exclamationSeen) {
                    $exclamationSeen = true;

                    return '!';
                }

                return '.';
            }, $cleaned);
        }

        $words = explode(' ', $cleaned);
        $normalizedWords = [];

        foreach ($words as $word) {
            $lettersOnly = (string) preg_replace('/[^a-zA-Z\x{00C0}-\x{00FF}]/u', '', $word);
            if (mb_strlen($lettersOnly, 'UTF-8') > 3 && $lettersOnly === mb_strtoupper($lettersOnly, 'UTF-8')) {
                $normalizedWords[] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                    .mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
            } else {
                $normalizedWords[] = $word;
            }
        }

        $cleaned = implode(' ', $normalizedWords);

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
    }

    /**
     * Deterministic smart trim at last word boundary, removing trailing connectors and punctuation.
     */
    public function deterministicSmartTrim(string $text, int $maxLength): string
    {
        $sanitized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($this->getExactCharLength($sanitized) <= $maxLength) {
            return $sanitized;
        }

        $sliced = mb_substr($sanitized, 0, $maxLength, 'UTF-8');
        $lastSpaceIndex = mb_strrpos($sliced, ' ', 0, 'UTF-8');

        $candidate = ($lastSpaceIndex !== false && $lastSpaceIndex >= 12)
            ? mb_substr($sliced, 0, $lastSpaceIndex, 'UTF-8')
            : $sliced;

        // Remove trailing punctuation
        $candidate = trim((string) preg_replace('/[,:;.\/+&|]+$/u', '', $candidate));

        // Remove trailing connector words in English, Portuguese, Spanish, German
        $trailingConnectorPattern = '/\s+(and|or|with|for|the|to|in|of|e|ou|com|para|de|do|da|em|y|con|por|el|la|und|mit|für|von)$/iu';
        $candidate = trim((string) preg_replace($trailingConnectorPattern, '', $candidate));

        return $candidate;
    }

    /**
     * Three-stage enforcement pipeline matching adCopyValidator.ts:
     * 1. Google Ads policy sanitizer.
     * 2. Targeted LLM compression loop (up to MAX_COMPRESSION_RETRIES).
     * 3. Deterministic word boundary trimmer.
     *
     * @param  array{
     *     detectedLanguage: string,
     *     productSummary: string,
     *     headlines: array<int, array{id: string|int, text: string, category: string, isLocked?: bool}>,
     *     descriptions: array<int, array{id: string|int, text: string, angle: string, isLocked?: bool}>,
     *     competitorAnalysisSummary?: array<int, string>
     * }  $batch
     * @param  (Closure(array{detectedLanguage: string, productSummary: string, itemsToCompress: array<int, array<string, mixed>>}): array<int, array{id: string|int, text: string}>)|null  $compressViaLlm
     * @return array{
     *     detectedLanguage: string,
     *     productSummary: string,
     *     headlines: array<int, array{id: string|int, text: string, category: string, isLocked: bool, charCount: int, isValid: bool}>,
     *     descriptions: array<int, array{id: string|int, text: string, angle: string, isLocked: bool, charCount: int, isValid: bool}>,
     *     competitorAnalysisSummary?: array<int, string>
     * }
     */
    public function enforceCharacterLimitsWithRetry(array $batch, ?Closure $compressViaLlm = null): array
    {
        $headlines = [];
        foreach ($batch['headlines'] as $h) {
            $isLocked = ! empty($h['isLocked']);
            $headlines[] = [
                'id' => (string) ($h['id'] ?? uniqid('h_')),
                'text' => $isLocked ? (string) ($h['text'] ?? '') : $this->sanitizeGoogleAdsText((string) ($h['text'] ?? ''), 'headline'),
                'category' => (string) ($h['category'] ?? 'Authority'),
                'isLocked' => $isLocked,
            ];
        }

        $descriptions = [];
        foreach ($batch['descriptions'] as $d) {
            $isLocked = ! empty($d['isLocked']);
            $descriptions[] = [
                'id' => (string) ($d['id'] ?? uniqid('d_')),
                'text' => $isLocked ? (string) ($d['text'] ?? '') : $this->sanitizeGoogleAdsText((string) ($d['text'] ?? ''), 'description'),
                'angle' => (string) ($d['angle'] ?? 'Authority'),
                'isLocked' => $isLocked,
            ];
        }

        // Targeted LLM compression loop
        for ($attempt = 1; $attempt <= self::MAX_COMPRESSION_RETRIES; $attempt++) {
            $offendingItems = [];

            foreach ($headlines as $h) {
                $len = $this->getExactCharLength($h['text']);
                if (! $h['isLocked'] && $len > self::MAX_HEADLINE_CHARS) {
                    $offendingItems[] = [
                        'id' => $h['id'],
                        'type' => 'headline',
                        'currentText' => $h['text'],
                        'currentLength' => $len,
                        'maxLength' => self::MAX_HEADLINE_CHARS,
                        'contextLabel' => $h['category'],
                    ];
                }
            }

            foreach ($descriptions as $d) {
                $len = $this->getExactCharLength($d['text']);
                if (! $d['isLocked'] && $len > self::MAX_DESCRIPTION_CHARS) {
                    $offendingItems[] = [
                        'id' => $d['id'],
                        'type' => 'description',
                        'currentText' => $d['text'],
                        'currentLength' => $len,
                        'maxLength' => self::MAX_DESCRIPTION_CHARS,
                        'contextLabel' => $d['angle'],
                    ];
                }
            }

            if (empty($offendingItems) || $compressViaLlm === null) {
                break;
            }

            try {
                $compressedResults = $compressViaLlm([
                    'detectedLanguage' => (string) ($batch['detectedLanguage'] ?? 'en'),
                    'productSummary' => (string) ($batch['productSummary'] ?? ''),
                    'itemsToCompress' => $offendingItems,
                ]);

                $rewriteMap = [];
                foreach ($compressedResults as $item) {
                    if (! empty($item['id']) && isset($item['text'])) {
                        $rewriteMap[(string) $item['id']] = (string) $item['text'];
                    }
                }

                foreach ($headlines as &$h) {
                    if (! $h['isLocked'] && isset($rewriteMap[$h['id']])) {
                        $h['text'] = $this->sanitizeGoogleAdsText($rewriteMap[$h['id']], 'headline');
                    }
                }
                unset($h);

                foreach ($descriptions as &$d) {
                    if (! $d['isLocked'] && isset($rewriteMap[$d['id']])) {
                        $d['text'] = $this->sanitizeGoogleAdsText($rewriteMap[$d['id']], 'description');
                    }
                }
                unset($d);
            } catch (\Throwable) {
                break;
            }
        }

        // Final deterministic smart trim pass
        $finalHeadlines = [];
        foreach ($headlines as $h) {
            $finalText = $h['isLocked']
                ? $h['text']
                : $this->deterministicSmartTrim($h['text'], self::MAX_HEADLINE_CHARS);
            $charCount = $this->getExactCharLength($finalText);

            $finalHeadlines[] = [
                ...$h,
                'text' => $finalText,
                'charCount' => $charCount,
                'isValid' => $charCount <= self::MAX_HEADLINE_CHARS,
            ];
        }

        $finalDescriptions = [];
        foreach ($descriptions as $d) {
            $finalText = $d['isLocked']
                ? $d['text']
                : $this->deterministicSmartTrim($d['text'], self::MAX_DESCRIPTION_CHARS);
            $charCount = $this->getExactCharLength($finalText);

            $finalDescriptions[] = [
                ...$d,
                'text' => $finalText,
                'charCount' => $charCount,
                'isValid' => $charCount <= self::MAX_DESCRIPTION_CHARS,
            ];
        }

        return [
            'detectedLanguage' => (string) ($batch['detectedLanguage'] ?? 'en'),
            'productSummary' => (string) ($batch['productSummary'] ?? ''),
            'headlines' => $finalHeadlines,
            'descriptions' => $finalDescriptions,
            'competitorAnalysisSummary' => $batch['competitorAnalysisSummary'] ?? null,
        ];
    }
}
