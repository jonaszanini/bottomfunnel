<?php

namespace Tests\Feature;

use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\StrategyRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyRecommenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommends_robusta_for_pure_vsl(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'page_type' => 'VSL',
            'funnel_flags' => ['vsl_only' => true],
            'strategy_source' => StrategySource::Auto,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::Robusta, $result['strategy']);
        $this->assertStringContainsString('Pure VSL', $result['reason']);
    }

    public function test_recommends_quiz_for_quiz_funnel(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'page_type' => 'QUIZ',
            'strategy_source' => StrategySource::Auto,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::Quiz, $result['strategy']);
        $this->assertStringContainsString('Quiz', $result['reason']);
    }

    public function test_recommends_launch_event_for_summit_or_workshop(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'page_type' => 'LAUNCH',
            'strategy_source' => StrategySource::Auto,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::LaunchEvent, $result['strategy']);
        $this->assertStringContainsString('summit/workshop', $result['reason']);
    }

    public function test_recommends_bof_direct_with_compound_qualifier(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'name' => 'All Day Slimming Tea',
            'page_type' => 'TSL',
            'bof_qualifier' => 'Tea',
            'strategy_source' => StrategySource::Auto,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::BofDirect, $result['strategy']);
        $this->assertStringContainsString('Compound keyword needed', $result['reason']);
    }

    public function test_recommends_bof_direct_for_standard_tsl_dtc(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'page_type' => 'TSL',
            'strategy_source' => StrategySource::Auto,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::BofDirect, $result['strategy']);
        $this->assertStringContainsString('TSL/DTC', $result['reason']);
    }

    public function test_respects_manual_override(): void
    {
        $recommender = app(StrategyRecommender::class);

        $product = WatchedProduct::factory()->create([
            'page_type' => 'VSL',
            'strategy' => Strategy::PresellPopup,
            'strategy_source' => StrategySource::Manual,
        ]);

        $result = $recommender->recommend($product);

        $this->assertEquals(Strategy::PresellPopup, $result['strategy']);
        $this->assertStringContainsString('Manually set', $result['reason']);
    }
}
