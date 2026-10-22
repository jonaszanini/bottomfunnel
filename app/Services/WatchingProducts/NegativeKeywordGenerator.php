<?php

namespace App\Services\WatchingProducts;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\Strategy;
use App\Models\NegativeKeywordSeed;
use App\Models\ProductNegativeKeyword;
use App\Models\WatchedProduct;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Support\Facades\Log;

class NegativeKeywordGenerator
{
    /**
     * Generate or re-generate negative keywords for a product.
     * Preserves any existing manual terms and manually overridden toggles.
     *
     * @param  array<string, mixed>  $serpData
     */
    public function generateForProduct(WatchedProduct $product, array $serpData = [], bool $isCreation = false): void
    {
        $currentStrategy = $product->strategy instanceof Strategy
            ? $product->strategy
            : Strategy::tryFrom((string) $product->strategy);

        // 1. Copy enabled base seeds
        $this->syncBaseSeeds($product, $currentStrategy);

        // 2. Add qualifier rule negative if bof_qualifier is set
        $this->syncQualifierRule($product);

        // 3. Enrich from generic meanings dictionary
        $this->syncGenericMeanings($product);

        // 4. Enrich from SERP data if available
        if (! empty($serpData)) {
            $this->syncSerpData($product, $serpData);
        }

        // 5. Optional LLM enrichment on creation only
        if ($isCreation && config('watching.negatives_llm', false)) {
            $this->runLlmEnrichment($product);
        }
    }

    /**
     * Recompute enabled flags for existing keywords when strategy changes.
     * Never overrides manually overridden keywords.
     */
    public function syncStrategyRules(WatchedProduct $product, Strategy $strategy): void
    {
        $keywords = $product->negativeKeywords()->get();

        foreach ($keywords as $kw) {
            // Never touch user's manual override
            if ($kw->manually_overridden || $kw->source === NegativeKeywordSource::Manual) {
                continue;
            }

            $rules = is_array($kw->strategy_rules) ? $kw->strategy_rules : [];
            $disabledStrategies = $rules['disabled_for_strategies'] ?? [];

            $shouldDisable = in_array($strategy->value, $disabledStrategies, true);
            $newEnabled = ! $shouldDisable;

            if ($kw->enabled !== $newEnabled) {
                $kw->update(['enabled' => $newEnabled]);
                Log::info("NegativeKeywordGenerator: Term '{$kw->term}' toggled to ".($newEnabled ? 'enabled' : 'disabled')." for strategy {$strategy->value} on product {$product->id}.");
            }
        }
    }

    /**
     * Seed Jonas's base list to the product.
     */
    protected function syncBaseSeeds(WatchedProduct $product, ?Strategy $strategy): void
    {
        // 1. Auto-seed table if empty on current environment
        if (NegativeKeywordSeed::query()->count() === 0) {
            try {
                (new NegativeKeywordSeedSeeder)->run();
            } catch (\Throwable $e) {
                Log::warning("NegativeKeywordGenerator: Auto-seeding negative_keyword_seeds failed: {$e->getMessage()}");
            }
        }

        // 2. Fetch enabled seeds from database
        $dbSeeds = NegativeKeywordSeed::query()
            ->where('enabled_by_default', true)
            ->get();

        $seedsData = [];
        if ($dbSeeds->isNotEmpty()) {
            foreach ($dbSeeds as $seed) {
                $rules = is_array($seed->strategy_rules)
                    ? $seed->strategy_rules
                    : (json_decode($seed->strategy_rules ?? '[]', true) ?: []);

                $seedsData[] = [
                    'term' => trim($seed->term),
                    'strategy_rules' => $rules,
                ];
            }
        } else {
            // 3. Resilient fallback to seeder static list if table is empty
            $reviewBuyerTerms = NegativeKeywordSeedSeeder::reviewBuyerTerms();
            foreach (NegativeKeywordSeedSeeder::allSeeds() as $term) {
                $isReview = in_array(strtolower($term), $reviewBuyerTerms, true);
                $seedsData[] = [
                    'term' => trim($term),
                    'strategy_rules' => $isReview ? ['disabled_for_strategies' => ['bof_review', 'robusta']] : [],
                ];
            }
        }

        foreach ($seedsData as $seedItem) {
            $term = $seedItem['term'];
            if ($term === '') {
                continue;
            }

            $rules = $seedItem['strategy_rules'];
            $disabledStrategies = $rules['disabled_for_strategies'] ?? [];
            $enabled = true;

            if ($strategy && in_array($strategy->value, $disabledStrategies, true)) {
                $enabled = false;
            }

            $existing = ProductNegativeKeyword::query()
                ->where('product_id', $product->id)
                ->where('term', $term)
                ->where('match_type', NegativeKeywordMatchType::Broad->value)
                ->first();

            if ($existing) {
                // Never overwrite manually added or overridden terms
                if (! $existing->manually_overridden && $existing->source !== NegativeKeywordSource::Manual) {
                    $existing->update([
                        'strategy_rules' => $rules,
                        'enabled' => $enabled,
                    ]);
                }
            } else {
                ProductNegativeKeyword::query()->create([
                    'product_id' => $product->id,
                    'term' => $term,
                    'match_type' => NegativeKeywordMatchType::Broad,
                    'source' => NegativeKeywordSource::Base,
                    'enabled' => $enabled,
                    'strategy_rules' => $rules,
                    'manually_overridden' => false,
                ]);
            }
        }
    }

    /**
     * If bof_qualifier is set, suggest [simple name] as an exact-match negative.
     */
    protected function syncQualifierRule(WatchedProduct $product): void
    {
        if (empty($product->bof_qualifier)) {
            return;
        }

        // Clean simple name without qualifier
        $simpleName = trim(str_ireplace($product->bof_qualifier, '', $product->name));
        if ($simpleName === '') {
            $simpleName = trim($product->name);
        }

        $existing = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', strtolower($simpleName))
            ->where('match_type', NegativeKeywordMatchType::Exact->value)
            ->first();

        if (! $existing) {
            ProductNegativeKeyword::query()->create([
                'product_id' => $product->id,
                'term' => strtolower($simpleName),
                'match_type' => NegativeKeywordMatchType::Exact,
                'source' => NegativeKeywordSource::QualifierRule,
                'enabled' => true,
                'strategy_rules' => null,
                'manually_overridden' => false,
            ]);
        }
    }

    /**
     * Enrich from generic brand meanings dictionary in config.
     */
    protected function syncGenericMeanings(WatchedProduct $product): void
    {
        $dictionary = config('watching.generic_meanings', []);
        $nameWords = preg_split('/[\s\-_]+/', strtolower($product->name)) ?: [];

        foreach ($nameWords as $word) {
            $word = trim($word);
            if (isset($dictionary[$word]) && is_array($dictionary[$word])) {
                foreach ($dictionary[$word] as $phrase) {
                    $phrase = trim(strtolower($phrase));
                    if ($phrase === '') {
                        continue;
                    }

                    $exists = ProductNegativeKeyword::query()
                        ->where('product_id', $product->id)
                        ->where('term', $phrase)
                        ->exists();

                    if (! $exists) {
                        ProductNegativeKeyword::query()->create([
                            'product_id' => $product->id,
                            'term' => $phrase,
                            'match_type' => NegativeKeywordMatchType::Phrase,
                            'source' => NegativeKeywordSource::GenericMeaning,
                            'enabled' => true,
                            'strategy_rules' => null,
                            'manually_overridden' => false,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Enrich from SERP related searches, organic titles, and domains.
     *
     * @param  array<string, mixed>  $serpData
     */
    public function syncSerpData(WatchedProduct $product, array $serpData): void
    {
        // 1. Purge pre-existing garbage competitor terms that are not manually overridden
        $existingCompetitors = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('source', NegativeKeywordSource::Competitor)
            ->where('manually_overridden', false)
            ->get();

        foreach ($existingCompetitors as $compKw) {
            if ($this->isGarbageCompetitorTerm($compKw->term, $product)) {
                $compKw->delete();
            }
        }

        $brandWords = array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($product->name)) ?: [],
            fn ($w) => strlen($w) >= 3
        );

        // 2. Related searches: identify intruder terms and "X vs Y" comparison competitors
        foreach ($serpData['related_searches'] ?? [] as $searchQuery) {
            $queryLower = strtolower(trim((string) $searchQuery));

            // Extract competitor from comparison searches (e.g. "product vs competitor" or "competitor vs product")
            if (preg_match('/\b(?:vs\.?|versus)\b/i', $queryLower)) {
                $compParts = preg_split('/\b(?:vs\.?|versus)\b/i', $queryLower);
                if (is_array($compParts) && count($compParts) === 2) {
                    $sideA = trim($compParts[0]);
                    $sideB = trim($compParts[1]);

                    $aHasBrand = false;
                    foreach ($brandWords as $bw) {
                        if (str_contains($sideA, $bw)) {
                            $aHasBrand = true;
                            break;
                        }
                    }

                    $candidate = $aHasBrand ? $sideB : $sideA;
                    $candidate = trim(preg_replace('/\b(reviews?|drops?|official|scam|price)\b/i', '', $candidate));
                    $cleanComp = $this->normalizeCompetitorCandidate($candidate, $product);
                    if ($cleanComp) {
                        $this->addCompetitorNegative($product, $cleanComp);
                    }
                }
            }

            // Check if search query mentions at least one core brand word
            $matchesBrand = false;
            foreach ($brandWords as $bw) {
                if (str_contains($queryLower, $bw)) {
                    $matchesBrand = true;
                    break;
                }
            }

            // If it doesn't match brand, it is an intruder term
            if (! $matchesBrand && strlen($queryLower) >= 3 && strlen($queryLower) <= 80) {
                if (! str_contains($queryLower, '--') && ! ctype_digit($queryLower)) {
                    $exists = ProductNegativeKeyword::query()
                        ->where('product_id', $product->id)
                        ->where('term', $queryLower)
                        ->exists();

                    if (! $exists) {
                        ProductNegativeKeyword::query()->create([
                            'product_id' => $product->id,
                            'term' => $queryLower,
                            'match_type' => NegativeKeywordMatchType::Phrase,
                            'source' => NegativeKeywordSource::SerpIntruder,
                            'enabled' => true,
                            'strategy_rules' => null,
                            'manually_overridden' => false,
                        ]);
                    }
                }
            }
        }

        // 3. Competitor extraction from organic domains and ad domains
        $candidateDomains = array_merge(
            $serpData['organic_domains'] ?? [],
            $serpData['ad_domains'] ?? []
        );

        foreach ($candidateDomains as $domain) {
            $cleanComp = $this->normalizeCompetitorCandidate((string) $domain, $product);
            if ($cleanComp) {
                $this->addCompetitorNegative($product, $cleanComp);
            }
        }

        // 4. Competitor extraction from organic titles and ad titles
        $candidateTitles = array_merge(
            $serpData['organic_titles'] ?? [],
            $serpData['ad_titles'] ?? []
        );

        foreach ($candidateTitles as $title) {
            if (preg_match('/\b([a-zA-Z0-9\-_]{3,})\s+(?:vs\.?|versus)\s+([a-zA-Z0-9\-_]{3,})\b/i', (string) $title, $matches)) {
                $c1 = $this->normalizeCompetitorCandidate($matches[1], $product);
                if ($c1) {
                    $this->addCompetitorNegative($product, $c1);
                }
                $c2 = $this->normalizeCompetitorCandidate($matches[2], $product);
                if ($c2) {
                    $this->addCompetitorNegative($product, $c2);
                }
            }
        }
    }

    /**
     * Add clean competitor or homonym negative keyword.
     */
    protected function addCompetitorNegative(WatchedProduct $product, string $term): void
    {
        $term = strtolower(trim($term));
        if ($term === '' || $this->isGarbageCompetitorTerm($term, $product)) {
            return;
        }

        $exists = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', $term)
            ->exists();

        if ($exists) {
            return;
        }

        // Determine if homonym or competitor
        $source = NegativeKeywordSource::Competitor;
        if ($product->bof_homonym) {
            $genericMeanings = config('watching.generic_meanings', []);
            foreach ($genericMeanings as $word => $phrases) {
                if ($term === $word || in_array($term, $phrases, true)) {
                    $source = NegativeKeywordSource::Homonym;
                    break;
                }
            }
        }

        ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => $term,
            'match_type' => NegativeKeywordMatchType::Broad,
            'source' => $source,
            'enabled' => true,
            'strategy_rules' => null,
            'manually_overridden' => false,
        ]);
    }

    /**
     * Normalize a raw domain or title candidate into a clean brand-like token.
     */
    public function normalizeCompetitorCandidate(string $raw, WatchedProduct $product): ?string
    {
        $term = strtolower(trim($raw));
        if ($term === '') {
            return null;
        }

        // Strip protocol, paths, queries, ports
        $term = preg_replace('#^https?://#i', '', $term);
        $term = explode('/', $term)[0];
        $term = explode('?', $term)[0];
        $term = explode(':', $term)[0];

        // Strip www
        $term = preg_replace('/^www\d*\./i', '', $term);

        // Extract domain name part before TLD if dots exist
        if (str_contains($term, '.')) {
            $parts = explode('.', $term);
            $commonTlds = [
                'com', 'org', 'net', 'info', 'biz', 'us', 'ca', 'uk', 'co', 'io', 'xyz', 'app', 'online', 'store',
                'site', 'shop', 'pro', 'live', 'club', 'top', 'tech', 'vip', 'link', 'click', 'me', 'tv', 'cc',
                'gov', 'edu', 'mil', 'int', 'br', 'de', 'fr', 'es', 'it', 'nl', 'au', 'in', 'jp', 'ru', 'mx',
            ];
            while (count($parts) > 1 && in_array(end($parts), $commonTlds, true)) {
                array_pop($parts);
            }
            $term = end($parts) ?: '';
        }

        // Strip language/country prefixes like "en-en--", "en-us--", "us-"
        $term = preg_replace('/^(?:[a-z]{2}-[a-z]{2}--?|[a-z]{2}--?)/i', '', $term);
        // Strip common affiliate/marketing prefixes & suffixes
        $term = preg_replace('/^(try|get|buy|order|the|my|official|real)[-_]+/i', '', $term);
        $term = preg_replace('/[-_]+(official|store|shop|online|direct|site|app|review|reviews)$/i', '', $term);

        // Collapse multiple hyphens or underscores
        $term = preg_replace('/[-_]{2,}/', '-', $term);
        $term = trim($term, "-_ \t\n\r\0\x0B");

        // Check for hyphen-separated repeated segments: "audifort-audifort" -> "audifort"
        if (preg_match('/^([a-z0-9]{3,})[-_]\1$/i', $term, $matches)) {
            $term = $matches[1];
        }

        // Check for concatenated repeated segments: "audifortaudifort" -> "audifort"
        $len = strlen($term);
        if ($len >= 6 && $len % 2 === 0) {
            $half = substr($term, 0, (int) ($len / 2));
            if ($half.$half === $term) {
                $term = $half;
            }
        }

        if ($this->isGarbageCompetitorTerm($term, $product)) {
            return null;
        }

        return $term;
    }

    /**
     * Check if a term looks like garbage, domain artifact, or is identical to product name.
     */
    public function isGarbageCompetitorTerm(string $term, WatchedProduct $product): bool
    {
        $termLower = strtolower(trim($term));

        // Length validation: 3 to 30 characters
        if (strlen($termLower) < 3 || strlen($termLower) > 30) {
            return true;
        }

        // Pure numbers
        if (ctype_digit($termLower)) {
            return true;
        }

        // Double dashes, underscores, or dots (domain artifacts)
        if (str_contains($termLower, '--') || str_contains($termLower, '__') || str_contains($termLower, '..')) {
            return true;
        }

        // Lingering language/subdomain prefixes (e.g. "en-en", "us-us")
        if (preg_match('/^(en-[a-z]{2}|us-[a-z]{2}|[a-z]{2}-[a-z]{2})/i', $termLower)) {
            return true;
        }

        // Check against the product itself
        $productClean = strtolower(trim($product->name));
        $brandConsolidated = preg_replace('/[^a-z0-9]/', '', $productClean);
        $termConsolidated = preg_replace('/[^a-z0-9]/', '', $termLower);

        // Identical to product brand
        if ($termConsolidated === $brandConsolidated) {
            return true;
        }

        // Repeated product brand (e.g. audifort-audifort, audifortaudifort)
        if (preg_match('/^([a-z0-9]{3,})[-_]?\1$/i', $termConsolidated, $m)) {
            if ($m[1] === $brandConsolidated || str_contains($m[1], $brandConsolidated)) {
                return true;
            }
        }

        // Check if term matches brand tokens
        $brandTokens = array_filter(
            preg_split('/[^a-z0-9]+/i', $productClean) ?: [],
            fn ($t) => strlen($t) >= 3
        );

        foreach ($brandTokens as $token) {
            if ($termConsolidated === $token) {
                return true;
            }
            if (str_contains($termConsolidated, $token)) {
                $ratio = strlen($token) / max(1, strlen($termConsolidated));
                if ($ratio >= 0.5) {
                    return true;
                }
            }
        }

        // Authority and platform domains to discard
        $authorityDomains = [
            'google', 'youtube', 'wikipedia', 'nih', 'gov', 'healthline', 'webmd', 'mayoclinic',
            'amazon', 'walmart', 'ebay', 'target', 'cvs', 'walgreens', 'facebook', 'instagram',
            'twitter', 'tiktok', 'reddit', 'quora', 'pinterest', 'linkedin', 'medium', 'yahoo',
            'bing', 'duckduckgo', 'apple', 'microsoft', 'wordpress', 'shopify', 'clickbank',
            'buygoods', 'digistore', 'maxweb', 'trustpilot', 'bbb', 'sitejabber', 'scamvoid',
            'blogspot', 'wixsite', 'weebly', 'github', 'cloudflare', 'cpanel', 'hostinger',
            'review', 'reviews', 'official', 'website', 'store', 'shop', 'online', 'order',
            'product', 'products', 'supplement', 'supplements', 'formula', 'health', 'wellness',
        ];

        return in_array($termLower, $authorityDomains, true);
    }

    /**
     * Optional LLM enrichment on product creation only.
     */
    protected function runLlmEnrichment(WatchedProduct $product): void
    {
        // Safe placeholder for gpt-4o-mini enrichment call when configured
        Log::info("NegativeKeywordGenerator: LLM enrichment toggled for product {$product->id}.");
    }

    /**
     * Export negative keywords to Google Ads CSV format.
     */
    public function exportCsv(WatchedProduct $product): string
    {
        $keywords = $product->negativeKeywords()
            ->where('enabled', true)
            ->get();

        $rows = [];
        $rows[] = ['Keyword', 'Match Type', 'Source', 'Formatted Term'];

        foreach ($keywords as $kw) {
            $match = $kw->match_type instanceof NegativeKeywordMatchType
                ? $kw->match_type->label()
                : (string) $kw->match_type;

            $source = $kw->source instanceof NegativeKeywordSource
                ? $kw->source->label()
                : (string) $kw->source;

            $rows[] = [
                $kw->term,
                $match,
                $source,
                $kw->formattedTerm(),
            ];
        }

        $output = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }
}
