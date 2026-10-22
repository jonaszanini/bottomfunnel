<?php

namespace Tests\Feature;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Models\NegativeKeywordSeed;
use App\Models\ProductNegativeKeyword;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use Database\Seeders\NegativeKeywordSeedSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NegativeKeywordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NegativeKeywordSeedSeeder::class);
    }

    public function test_seeds_are_copied_on_product_create(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Alpha Vitality',
            'page_type' => 'TSL',
            'strategy' => Strategy::BofDirect,
            'strategy_source' => StrategySource::Manual,
        ]);

        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'affiliate',
            'source' => NegativeKeywordSource::Base->value,
            'enabled' => 1,
        ]);

        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'review',
            'source' => NegativeKeywordSource::Base->value,
            'enabled' => 1,
        ]);
    }

    public function test_serp_intruder_extraction_works(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Prosta Guard',
            'strategy' => Strategy::BofDirect,
        ]);

        $generator = app(NegativeKeywordGenerator::class);
        $generator->syncSerpData($product, [
            'related_searches' => [
                'prosta guard official website',
                'blue chew scam',
            ],
            'organic_domains' => [
                'competitorbrand.com',
                'google.com',
            ],
        ]);

        // Brand-related search is not an intruder
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'prosta guard official website',
        ]);

        // Unrelated search is an intruder negative
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'blue chew scam',
            'source' => NegativeKeywordSource::SerpIntruder->value,
            'match_type' => NegativeKeywordMatchType::Phrase->value,
        ]);

        // Competitor domain is captured
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'competitorbrand',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);

        // Common domain 'google' is excluded
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'google',
        ]);
    }

    public function test_qualifier_rule_suggests_exact_match_negative(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'All Day Slimming Tea',
            'bof_qualifier' => 'Tea',
            'strategy' => Strategy::BofDirect,
        ]);

        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'all day slimming',
            'match_type' => NegativeKeywordMatchType::Exact->value,
            'source' => NegativeKeywordSource::QualifierRule->value,
        ]);
    }

    public function test_csv_export_format_is_correct(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Test Product',
            'strategy' => Strategy::BofDirect,
        ]);

        // Clear seeds for clean CSV testing
        $product->negativeKeywords()->delete();

        ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'free',
            'match_type' => NegativeKeywordMatchType::Broad,
            'source' => NegativeKeywordSource::Base,
            'enabled' => true,
        ]);

        ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'scam report',
            'match_type' => NegativeKeywordMatchType::Phrase,
            'source' => NegativeKeywordSource::Manual,
            'enabled' => true,
        ]);

        ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'exact match only',
            'match_type' => NegativeKeywordMatchType::Exact,
            'source' => NegativeKeywordSource::Manual,
            'enabled' => true,
        ]);

        $generator = app(NegativeKeywordGenerator::class);
        $csv = $generator->exportCsv($product);

        $this->assertStringContainsString('Keyword,"Match Type",Source,"Formatted Term"', $csv);
        $this->assertStringContainsString('free,Broad,"Base Seed",free', $csv);
        $this->assertStringContainsString('"scam report",Phrase,Manual,"""scam report"""', $csv);
        $this->assertStringContainsString('"exact match only",Exact,Manual,"[exact match only]"', $csv);
    }

    public function test_manual_terms_and_overrides_are_preserved_on_regeneration(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Test Product Preservation',
            'strategy' => Strategy::BofDirect,
        ]);

        // Add a manual term
        $manualKw = ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'custom manual negative',
            'match_type' => NegativeKeywordMatchType::Exact,
            'source' => NegativeKeywordSource::Manual,
            'enabled' => true,
            'manually_overridden' => true,
        ]);

        // Override a base seed to disabled
        $baseSeedKw = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', 'affiliate')
            ->firstOrFail();

        $baseSeedKw->update([
            'enabled' => false,
            'manually_overridden' => true,
        ]);

        // Re-generate
        $generator = app(NegativeKeywordGenerator::class);
        $generator->generateForProduct($product, []);

        // Assert manual term is still there
        $this->assertDatabaseHas('product_negative_keywords', [
            'id' => $manualKw->id,
            'term' => 'custom manual negative',
        ]);

        // Assert overridden toggle is still false
        $baseSeedKw->refresh();
        $this->assertFalse($baseSeedKw->enabled);
        $this->assertTrue($baseSeedKw->manually_overridden);
    }

    public function test_no_llm_call_happens_when_toggle_is_off(): void
    {
        config(['watching.negatives_llm' => false]);
        Http::fake();

        $product = WatchedProduct::factory()->create([
            'name' => 'Alpha Boost',
        ]);

        Http::assertNothingSent();
    }

    public function test_strategy_change_recomputes_enabled_flags_without_overriding_manual_terms(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Alpha Review Strategy Test',
            'page_type' => 'TSL',
            'strategy' => Strategy::BofDirect,
            'strategy_source' => StrategySource::Manual,
        ]);

        // Under bof_direct, 'review' seed is enabled
        $reviewKw = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', 'review')
            ->firstOrFail();
        $this->assertTrue($reviewKw->enabled);

        // User manually disabled 'complaints'
        $complaintsKw = ProductNegativeKeyword::query()
            ->where('product_id', $product->id)
            ->where('term', 'complaints')
            ->firstOrFail();
        $complaintsKw->update([
            'enabled' => false,
            'manually_overridden' => true,
        ]);

        // Switch product strategy to bof_review
        $product->update(['strategy' => Strategy::BofReview]);

        // 'review' is a review-intent term disabled by bof_review strategy rule
        $reviewKw->refresh();
        $this->assertFalse($reviewKw->enabled);

        // 'complaints' remains false (manually overridden)
        $complaintsKw->refresh();
        $this->assertFalse($complaintsKw->enabled);
        $this->assertTrue($complaintsKw->manually_overridden);

        // Switch back to bof_direct
        $product->update(['strategy' => Strategy::BofDirect]);

        // 'review' re-enables
        $reviewKw->refresh();
        $this->assertTrue($reviewKw->enabled);

        // 'complaints' still stays false
        $complaintsKw->refresh();
        $this->assertFalse($complaintsKw->enabled);
    }

    public function test_garbage_competitor_strings_are_filtered_out(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Audifort',
            'strategy' => Strategy::BofDirect,
        ]);

        $generator = app(NegativeKeywordGenerator::class);
        $generator->syncSerpData($product, [
            'organic_domains' => [
                'audifort--audifort.com',
                'en-en--en-audifort.com',
                'audifortaudifort.com',
                'cortexi.com',
                'zeneara-official.org',
                '12345.com',
                'google.com',
                'wikipedia.org',
            ],
            'organic_titles' => [
                'Audifort Review - Scam or Real?',
                'Cortexi vs Audifort Hearing Formula',
            ],
            'related_searches' => [
                'audifort official site',
                'audifort vs quietum plus',
                'hearing support alternatives',
            ],
        ]);

        // Clean competitors are extracted
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'cortexi',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);

        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'zeneara',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);

        // Comparison competitor from search query is extracted
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'quietum plus',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);

        // Intruder search query is captured
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'hearing support alternatives',
            'source' => NegativeKeywordSource::SerpIntruder->value,
        ]);

        // Garbage variants are strictly excluded
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'audifort--audifort',
        ]);
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'en-en--en-audifort',
        ]);
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'audifortaudifort',
        ]);
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => '12345',
        ]);
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'google',
        ]);
        $this->assertDatabaseMissing('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'audifort',
            'source' => NegativeKeywordSource::Competitor->value,
        ]);
    }

    public function test_seeds_are_populated_even_if_seeds_table_was_empty(): void
    {
        // Truncate seed table to simulate fresh / unseeded environment
        NegativeKeywordSeed::query()->truncate();
        $this->assertEquals(0, NegativeKeywordSeed::query()->count());

        $product = WatchedProduct::factory()->create([
            'name' => 'Unseeded Environment Product',
            'page_type' => 'TSL',
            'strategy' => Strategy::BofDirect,
            'strategy_source' => StrategySource::Manual,
        ]);

        // Asserts base seeds are populated via fallback/auto-seed
        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'affiliate',
            'source' => NegativeKeywordSource::Base->value,
            'enabled' => 1,
        ]);

        $this->assertDatabaseHas('product_negative_keywords', [
            'product_id' => $product->id,
            'term' => 'discount',
            'source' => NegativeKeywordSource::Base->value,
            'enabled' => 1,
        ]);
    }

    public function test_pre_existing_garbage_competitor_terms_are_purged_on_sync(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Audifort',
            'strategy' => Strategy::BofDirect,
        ]);

        // Insert legacy garbage competitor keyword
        $garbage = ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'audifort--audifort',
            'match_type' => NegativeKeywordMatchType::Broad,
            'source' => NegativeKeywordSource::Competitor,
            'enabled' => true,
            'manually_overridden' => false,
        ]);

        // Insert manual keyword that user purposefully kept
        $manual = ProductNegativeKeyword::query()->create([
            'product_id' => $product->id,
            'term' => 'audifort--custom-manual',
            'match_type' => NegativeKeywordMatchType::Exact,
            'source' => NegativeKeywordSource::Manual,
            'enabled' => true,
            'manually_overridden' => true,
        ]);

        $generator = app(NegativeKeywordGenerator::class);
        $generator->syncSerpData($product, []);

        // Garbage non-manual term is deleted
        $this->assertDatabaseMissing('product_negative_keywords', [
            'id' => $garbage->id,
        ]);

        // User manual term is preserved
        $this->assertDatabaseHas('product_negative_keywords', [
            'id' => $manual->id,
            'term' => 'audifort--custom-manual',
        ]);
    }
}
