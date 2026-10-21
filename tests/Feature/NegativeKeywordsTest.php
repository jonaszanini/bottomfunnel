<?php

namespace Tests\Feature;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Enums\Strategy;
use App\Enums\StrategySource;
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
}
