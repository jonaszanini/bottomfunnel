<?php

namespace App\Services\Copy;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SalesPageScraperService
{
    public const GUARANTEE_KEYWORDS = '/(guarantee|garantia|garantía|garantie|refund|reembolso|rückerstattung|money back|risk free|sem risco|100%|days|dias|días|tage|secure|seguro)/iu';

    public const BONUS_KEYWORDS = '/(bonus|bônus|bono|free|grátis|gratis|kostenlos|gift|presente|regalo|discount|desconto|descuento|rabatt|off|special offer|oferta)/iu';

    /**
     * Scrape affiliate sales page HTML, extract conversion signals, or fallback to manual text.
     *
     * @return array{
     *     url: string,
     *     detectedLanguageHint: string,
     *     pageTitle: string,
     *     metaDescription: string,
     *     headings: array<int, string>,
     *     bulletPoints: array<int, string>,
     *     guaranteeSignals: array<int, string>,
     *     bonusAndOfferSignals: array<int, string>,
     *     bodyExcerpt: string,
     *     usedFallback: bool,
     *     warningMessage: ?string
     * }
     */
    public function scrape(string $salesPageUrl, ?string $manualContext = null, int $timeoutMs = 12000): array
    {
        $fallbackText = $this->normalizeWhitespace($manualContext ?? '');
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

        try {
            if (! filter_var($salesPageUrl, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Invalid URL format');
            }

            $response = Http::timeout($timeoutSeconds)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7,es;q=0.6,de;q=0.5',
                ])
                ->get($salesPageUrl);

            if (! $response->successful()) {
                throw new RuntimeException("HTTP status {$response->status()}");
            }

            $html = $response->body();

            // Remove script, style, noscript, iframe, svg, canvas, nav, header, footer, form
            $cleanedHtml = preg_replace(
                '/<(script|style|noscript|iframe|svg|canvas|nav|header|footer|form)[^>]*>.*?<\/\1>/siu',
                ' ',
                $html
            ) ?? $html;

            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$cleanedHtml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // 1. Language hint
            $htmlLangNode = $xpath->query('//html/@lang');
            $htmlLangAttr = $htmlLangNode && $htmlLangNode->length > 0 ? $this->normalizeWhitespace($htmlLangNode->item(0)->textContent) : '';
            $detectedLanguageHint = $htmlLangAttr !== '' ? $htmlLangAttr : 'auto';

            // 2. Title
            $titleNode = $xpath->query('//title');
            $pageTitle = $titleNode && $titleNode->length > 0 ? $this->normalizeWhitespace($titleNode->item(0)->textContent) : '';

            // 3. Meta description
            $metaDescNode = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content');
            if (! $metaDescNode || $metaDescNode->length === 0) {
                $metaDescNode = $xpath->query('//meta[@property="og:description"]/@content');
            }
            $metaDescription = $metaDescNode && $metaDescNode->length > 0 ? $this->normalizeWhitespace($metaDescNode->item(0)->textContent) : '';

            // 4. Headings (h1, h2, h3)
            $rawHeadings = [];
            $headingNodes = $xpath->query('//h1 | //h2 | //h3');
            if ($headingNodes) {
                foreach ($headingNodes as $el) {
                    $text = $this->normalizeWhitespace($el->textContent);
                    if (mb_strlen($text, 'UTF-8') >= 6 && mb_strlen($text, 'UTF-8') <= 180) {
                        $rawHeadings[] = $text;
                    }
                }
            }

            // 5. Bullet points (li)
            $rawBullets = [];
            $liNodes = $xpath->query('//li');
            if ($liNodes) {
                foreach ($liNodes as $el) {
                    $text = $this->normalizeWhitespace($el->textContent);
                    if (mb_strlen($text, 'UTF-8') >= 15 && mb_strlen($text, 'UTF-8') <= 220) {
                        $rawBullets[] = $text;
                    }
                }
            }

            // 6. Paragraphs and conversion signals
            $rawParagraphs = [];
            $guaranteeMatches = [];
            $bonusMatches = [];

            $contentNodes = $xpath->query('//p | //div | //section | //span');
            if ($contentNodes) {
                foreach ($contentNodes as $el) {
                    if ($el instanceof DOMElement) {
                        // Skip if element has more than 3 direct element children
                        $childElementCount = 0;
                        foreach ($el->childNodes as $child) {
                            if ($child instanceof DOMElement) {
                                $childElementCount++;
                                if ($childElementCount > 3) {
                                    break;
                                }
                            }
                        }
                        if ($childElementCount > 3) {
                            continue;
                        }
                    }

                    $text = $this->normalizeWhitespace($el->textContent);
                    $len = mb_strlen($text, 'UTF-8');
                    if ($len < 25 || $len > 500) {
                        continue;
                    }

                    $rawParagraphs[] = $text;

                    if (preg_match(self::GUARANTEE_KEYWORDS, $text)) {
                        $guaranteeMatches[] = $text;
                    }
                    if (preg_match(self::BONUS_KEYWORDS, $text)) {
                        $bonusMatches[] = $text;
                    }
                }
            }

            $headings = $this->deduplicateStrings($rawHeadings, 20);
            $bulletPoints = $this->deduplicateStrings($rawBullets, 25);
            $guaranteeSignals = $this->deduplicateStrings($guaranteeMatches, 10);
            $bonusAndOfferSignals = $this->deduplicateStrings($bonusMatches, 12);
            $uniqueParagraphs = $this->deduplicateStrings($rawParagraphs, 40);
            $bodyExcerpt = mb_substr(implode("\n", $uniqueParagraphs), 0, 6000, 'UTF-8');

            $totalExtractedLength = mb_strlen($pageTitle, 'UTF-8') +
                mb_strlen(implode('', $headings), 'UTF-8') +
                mb_strlen(implode('', $bulletPoints), 'UTF-8') +
                mb_strlen($bodyExcerpt, 'UTF-8');

            if ($totalExtractedLength < 150) {
                if ($fallbackText !== '') {
                    return $this->buildFallbackPayload(
                        $salesPageUrl,
                        $fallbackText,
                        'Sales page rendered via client side script; using manual context fallback.'
                    );
                }
                throw new RuntimeException('Extracted page content is too short or gated by bot protection.');
            }

            return [
                'url' => $salesPageUrl,
                'detectedLanguageHint' => $detectedLanguageHint,
                'pageTitle' => $pageTitle,
                'metaDescription' => $metaDescription,
                'headings' => $headings,
                'bulletPoints' => $bulletPoints,
                'guaranteeSignals' => $guaranteeSignals,
                'bonusAndOfferSignals' => $bonusAndOfferSignals,
                'bodyExcerpt' => $bodyExcerpt,
                'usedFallback' => false,
                'warningMessage' => null,
            ];
        } catch (Throwable $error) {
            $reason = $error->getMessage();

            if ($fallbackText !== '') {
                return $this->buildFallbackPayload(
                    $salesPageUrl,
                    $fallbackText,
                    "Automated scrape blocked ({$reason}); using manual text fallback."
                );
            }

            throw new RuntimeException(
                "Could not extract content from {$salesPageUrl} ({$reason}). Please paste the sales page text into the fallback box."
            );
        }
    }

    /**
     * Normalize internal whitespace.
     */
    public function normalizeWhitespace(string $input): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $input));
    }

    /**
     * Deduplicate strings keeping items with length >= 10.
     *
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    public function deduplicateStrings(array $items, int $maxItems): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $raw) {
            $cleaned = $this->normalizeWhitespace($raw);
            $key = mb_strtolower($cleaned, 'UTF-8');
            if (mb_strlen($cleaned, 'UTF-8') >= 10 && ! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $cleaned;
                if (count($result) >= $maxItems) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Build fallback payload from manual text.
     *
     * @return array{
     *     url: string,
     *     detectedLanguageHint: string,
     *     pageTitle: string,
     *     metaDescription: string,
     *     headings: array<int, string>,
     *     bulletPoints: array<int, string>,
     *     guaranteeSignals: array<int, string>,
     *     bonusAndOfferSignals: array<int, string>,
     *     bodyExcerpt: string,
     *     usedFallback: bool,
     *     warningMessage: string
     * }
     */
    public function buildFallbackPayload(string $url, string $manualContext, string $warningMessage): array
    {
        $rawLines = preg_split('/\r?\n/u', $manualContext) ?: [];
        $lines = [];
        foreach ($rawLines as $line) {
            $norm = $this->normalizeWhitespace($line);
            if ($norm !== '') {
                $lines[] = $norm;
            }
        }

        $headings = [];
        $bulletPoints = [];
        $guaranteeSignals = [];
        $bonusAndOfferSignals = [];

        foreach ($lines as $line) {
            $len = mb_strlen($line, 'UTF-8');
            if ($len >= 10 && $len <= 100 && count($headings) < 15) {
                $headings[] = $line;
            }
            if ($len > 20 && $len <= 200 && count($bulletPoints) < 20) {
                $bulletPoints[] = $line;
            }
            if (preg_match(self::GUARANTEE_KEYWORDS, $line) && count($guaranteeSignals) < 8) {
                $guaranteeSignals[] = $line;
            }
            if (preg_match(self::BONUS_KEYWORDS, $line) && count($bonusAndOfferSignals) < 8) {
                $bonusAndOfferSignals[] = $line;
            }
        }

        $firstLine = $lines[0] ?? 'Product Offer';

        return [
            'url' => $url,
            'detectedLanguageHint' => 'auto',
            'pageTitle' => mb_substr($firstLine, 0, 120, 'UTF-8'),
            'metaDescription' => '',
            'headings' => $headings,
            'bulletPoints' => $bulletPoints,
            'guaranteeSignals' => $guaranteeSignals,
            'bonusAndOfferSignals' => $bonusAndOfferSignals,
            'bodyExcerpt' => mb_substr($manualContext, 0, 6000, 'UTF-8'),
            'usedFallback' => true,
            'warningMessage' => $warningMessage,
        ];
    }
}
