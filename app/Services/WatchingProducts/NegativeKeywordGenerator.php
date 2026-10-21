<?php

namespace App\Services\WatchingProducts;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\Strategy;
use App\Models\NegativeKeywordSeed;
use App\Models\ProductNegativeKeyword;
use App\Models\WatchedProduct;
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
        $seeds = NegativeKeywordSeed::query()
            ->where('enabled_by_default', true)
            ->get();

        foreach ($seeds as $seed) {
            $term = trim($seed->term);
            if ($term === '') {
                continue;
            }

            $rules = is_array($seed->strategy_rules) ? $seed->strategy_rules : [];
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
                if (! $existing->manually_overridden) {
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
        $brandWords = array_filter(preg_split('/[\s\-_]+/', strtolower($product->name)) ?: [], fn ($w) => strlen($w) > 2);

        // Related searches: look for intruder terms
        foreach ($serpData['related_searches'] ?? [] as $searchQuery) {
            $queryLower = strtolower(trim((string) $searchQuery));

            // Check if search query mentions at least one core brand word
            $matchesBrand = false;
            foreach ($brandWords as $bw) {
                if (str_contains($queryLower, $bw)) {
                    $matchesBrand = true;
                    break;
                }
            }

            // If it doesn't match brand, it is an intruder term
            if (! $matchesBrand && strlen($queryLower) >= 3) {
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

        // Competitor domains
        foreach ($serpData['organic_domains'] ?? [] as $domain) {
            $domain = strtolower(trim((string) $domain));
            $baseDomain = explode('.', $domain)[0] ?? '';

            if (strlen($baseDomain) >= 3 && ! in_array($baseDomain, ['google', 'youtube', 'wikipedia', 'nih', 'gov', 'healthline'], true)) {
                $exists = ProductNegativeKeyword::query()
                    ->where('product_id', $product->id)
                    ->where('term', $baseDomain)
                    ->exists();

                if (! $exists) {
                    ProductNegativeKeyword::query()->create([
                        'product_id' => $product->id,
                        'term' => $baseDomain,
                        'match_type' => NegativeKeywordMatchType::Broad,
                        'source' => NegativeKeywordSource::Competitor,
                        'enabled' => true,
                        'strategy_rules' => null,
                        'manually_overridden' => false,
                    ]);
                }
            }
        }
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
