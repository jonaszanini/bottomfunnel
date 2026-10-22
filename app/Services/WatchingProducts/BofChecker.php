<?php

namespace App\Services\WatchingProducts;

use App\Models\WatchedProduct;

class BofChecker
{
    /**
     * Commercial intent indicator keywords.
     *
     * @var array<int, string>
     */
    protected array $commercialTerms = [
        'buy',
        'order',
        'shop',
        'store',
        'purchase',
        'get',
        'price',
        'pricing',
        'cost',
        'how much',
        'sale',
        'discount',
        'coupon',
        'promo',
        'deal',
        'save',
        'offer',
        'special offer',
        'official',
        'official site',
        'official website',
        'authorized',
        'review',
        'reviews',
        'scam',
        'legit',
        'does it work',
        'supplement',
        'capsule',
        'capsules',
        'bottle',
        'bottles',
        'formula',
        'drops',
        'pills',
        'ingredients',
        'in stock',
        'free shipping',
        'checkout',
        'cart',
        'guarantee',
        'refund',
    ];

    /**
     * Check product against SERP data (and name heuristics) to evaluate the BoF triad.
     *
     * @param  array<string, mixed>  $serpData
     * @return array{bof_vpn_serp: bool, bof_image_match_pct: int, bof_translation: bool}
     */
    public function check(WatchedProduct $product, array $serpData = []): array
    {
        $vpnSerp = $this->detectBofVpnSerp($product->name, $serpData, (bool) $product->bof_vpn_serp);
        $imagePct = $this->deriveImageMatchPct($product->name, $serpData, (int) ($product->bof_image_match_pct ?? 0));
        $translation = $this->detectTranslationPass($product->name, (bool) $product->bof_translation);

        return [
            'bof_vpn_serp' => $vpnSerp,
            'bof_image_match_pct' => $imagePct,
            'bof_translation' => $translation,
        ];
    }

    /**
     * Detect if product name (or close variant) appears in organic or ads results with commercial intent.
     *
     * @param  array<string, mixed>  $serpData
     */
    public function detectBofVpnSerp(string $productName, array $serpData, bool $currentValue = false): bool
    {
        if (empty($serpData)) {
            return $currentValue;
        }

        $variants = $this->getBrandVariants($productName);
        if (empty($variants)) {
            return $currentValue;
        }

        // 1. Check Ads (paid ads for product/brand inherently demonstrate commercial intent)
        $ads = $serpData['ads'] ?? [];
        foreach ($ads as $ad) {
            $title = strtolower((string) ($ad['title'] ?? ''));
            $snippet = strtolower((string) ($ad['snippet'] ?? ''));
            $link = strtolower((string) ($ad['link'] ?? ''));

            foreach ($variants as $variant) {
                if (str_contains($title, $variant) || str_contains($snippet, $variant) || str_contains($link, $variant)) {
                    return true;
                }
            }
        }

        // Also check raw ad_titles if present
        foreach ($serpData['ad_titles'] ?? [] as $adTitle) {
            $lowerTitle = strtolower((string) $adTitle);
            foreach ($variants as $variant) {
                if (str_contains($lowerTitle, $variant)) {
                    return true;
                }
            }
        }

        // 2. Check Shopping results if present
        $shopping = $serpData['shopping_results'] ?? [];
        foreach ($shopping as $item) {
            $title = strtolower((string) ($item['title'] ?? ''));
            foreach ($variants as $variant) {
                if (str_contains($title, $variant)) {
                    return true;
                }
            }
        }

        // 3. Check Organic results for brand variant AND commercial intent keywords or price
        $organic = $serpData['organic_results'] ?? [];
        foreach ($organic as $res) {
            $title = strtolower((string) ($res['title'] ?? ''));
            $snippet = strtolower((string) ($res['snippet'] ?? ''));

            $matchesBrand = false;
            foreach ($variants as $variant) {
                if (str_contains($title, $variant) || str_contains($snippet, $variant)) {
                    $matchesBrand = true;
                    break;
                }
            }

            if ($matchesBrand) {
                // Check for commercial purchase intent keywords
                foreach ($this->commercialTerms as $term) {
                    if (str_contains($title, $term) || str_contains($snippet, $term)) {
                        return true;
                    }
                }

                // Check for price mention (e.g. $49, $69, $39.99)
                if (preg_match('/\$\s*\d+/', $title) || preg_match('/\$\s*\d+/', $snippet)) {
                    return true;
                }
            }
        }

        // If organic_titles was provided without organic_results structure
        if (empty($organic) && ! empty($serpData['organic_titles'])) {
            foreach ($serpData['organic_titles'] as $orgTitle) {
                $lowerTitle = strtolower((string) $orgTitle);
                $matchesBrand = false;
                foreach ($variants as $variant) {
                    if (str_contains($lowerTitle, $variant)) {
                        $matchesBrand = true;
                        break;
                    }
                }

                if ($matchesBrand) {
                    foreach ($this->commercialTerms as $term) {
                        if (str_contains($lowerTitle, $term)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Estimate the % of results that show the exact product. Minimum for full credit is 90.
     *
     * @param  array<string, mixed>  $serpData
     */
    public function deriveImageMatchPct(string $productName, array $serpData, int $currentPct = 0): int
    {
        $organic = $serpData['organic_results'] ?? [];
        if (empty($organic) && ! empty($serpData['organic_titles'])) {
            $organic = array_map(fn ($t) => ['title' => $t, 'snippet' => ''], $serpData['organic_titles']);
        }

        if (empty($organic)) {
            return $currentPct;
        }

        $variants = $this->getBrandVariants($productName);
        if (empty($variants)) {
            return $currentPct;
        }

        // Evaluate top 10 organic results
        $topResults = array_slice($organic, 0, 10);
        $total = count($topResults);
        if ($total === 0) {
            return $currentPct;
        }

        $genericMeanings = array_keys(config('watching.generic_meanings', [
            'steel', 'alpha', 'lean', 'flow', 'peak', 'prime', 'vital', 'boost', 'burn', 'pure', 'force', 'shield', 'flex', 'clarity',
        ]));
        $cleanName = strtolower(trim($productName));
        $isGenericBrand = in_array($cleanName, $genericMeanings, true);

        $matches = 0;
        foreach ($topResults as $res) {
            $title = strtolower((string) ($res['title'] ?? ''));
            $snippet = strtolower((string) ($res['snippet'] ?? ''));

            $brandMatched = false;
            foreach ($variants as $variant) {
                if (str_contains($title, $variant) || str_contains($snippet, $variant)) {
                    $brandMatched = true;
                    break;
                }
            }

            if ($brandMatched) {
                // If it's a generic word homonym, require commercial/product term to count as exact product
                if ($isGenericBrand) {
                    $hasCommercialProductTerm = false;
                    foreach ($this->commercialTerms as $term) {
                        if (str_contains($title, $term) || str_contains($snippet, $term)) {
                            $hasCommercialProductTerm = true;
                            break;
                        }
                    }
                    if ($hasCommercialProductTerm) {
                        $matches++;
                    }
                } else {
                    $matches++;
                }
            }
        }

        $organicMatchPct = ($matches / $total) * 100;

        // If inline images exist, factor them in
        $inlineImages = $serpData['inline_images'] ?? [];
        if (! empty($inlineImages)) {
            $topImages = array_slice($inlineImages, 0, 10);
            $totalImages = count($topImages);
            $imgMatches = 0;

            foreach ($topImages as $img) {
                $title = strtolower((string) ($img['title'] ?? ''));
                $source = strtolower((string) ($img['source'] ?? ''));

                foreach ($variants as $variant) {
                    if (str_contains($title, $variant) || str_contains($source, $variant)) {
                        $imgMatches++;
                        break;
                    }
                }
            }

            if ($totalImages > 0) {
                $imageMatchPct = ($imgMatches / $totalImages) * 100;
                $finalPct = (int) round(($organicMatchPct * 0.7) + ($imageMatchPct * 0.3));

                return min(100, max(0, $finalPct));
            }
        }

        return min(100, max(0, (int) round($organicMatchPct)));
    }

    /**
     * Determine if product name is in target language with no translation issues detected.
     */
    public function detectTranslationPass(string $productName, bool $currentValue = false): bool
    {
        if ($currentValue) {
            return true;
        }

        $name = trim($productName);
        if ($name === '') {
            return false;
        }

        // Check for non-Latin scripts (Cyrillic, Han/Chinese, Japanese, Arabic, Hebrew, Thai, etc.)
        if (preg_match('/[\p{Cyrillic}\p{Han}\p{Hiragana}\p{Katakana}\p{Arabic}\p{Hebrew}\p{Thai}\p{Devanagari}]/u', $name)) {
            return false;
        }

        // Check for explicit translation tags or foreign edition patterns
        if (preg_match('/\b(traduction|versi[oó]n|auf\s+deutsch|en\s+espa[nñ]ol|em\s+portugu[eê]s|edition|fran[cç]ais[e]?|deutsch|italiano)\b/iu', $name)) {
            return false;
        }

        // Country/language code in brackets like [DE], [FR], (ES)
        if (preg_match('/(?:\[|\()(de|fr|es|it|ru|jp|cn)(?:\]|\))/i', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Extract product brand variants (e.g. "Dentavim", "Nagano Lean Body", "Audifort").
     *
     * @return array<int, string>
     */
    public function getBrandVariants(string $productName): array
    {
        $raw = strtolower(trim($productName));
        if ($raw === '') {
            return [];
        }

        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $raw);
        $clean = preg_replace('/\s+/', ' ', trim((string) $clean));

        $variants = [$clean];

        // Split camelCase/PascalCase if present (e.g. ProDentim -> pro dentim)
        $splitCamel = strtolower(preg_replace('/(?<!^)(?=[A-Z])/', ' ', $productName));
        $splitCamel = preg_replace('/\s+/', ' ', trim((string) $splitCamel));
        if ($splitCamel !== $clean && strlen($splitCamel) >= 3) {
            $variants[] = $splitCamel;
        }

        // Strip common supplement form/category descriptors to get core brand stem
        $formWords = [
            'drops', 'supplement', 'supplements', 'capsules', 'formula',
            'pills', 'tea', 'tonic', 'serum', 'cream', 'oil', 'powder',
            'system', 'cleanse', 'diet', 'weight loss', '24',
        ];

        $tokens = explode(' ', $clean);
        $filteredTokens = array_filter($tokens, fn ($t) => ! in_array($t, $formWords, true));
        if (! empty($filteredTokens)) {
            $stem = implode(' ', $filteredTokens);
            if ($stem !== $clean && strlen($stem) >= 3) {
                $variants[] = $stem;
            }
            $firstWord = reset($filteredTokens);
            if (strlen($firstWord) >= 3) {
                $variants[] = $firstWord;
            }
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => strlen($v) >= 3)));
    }
}
