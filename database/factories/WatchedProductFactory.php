<?php

namespace Database\Factories;

use App\Enums\BrandBiddingStatus;
use App\Enums\ProductKind;
use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Enums\TrueBofStatus;
use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedProduct>
 */
class WatchedProductFactory extends Factory
{
    protected $model = WatchedProduct::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Metabolic Support',
            'status' => fake()->randomElement(WatchedProductStatus::cases()),
            'platforms' => fake()->randomElements(['SmartAdv', 'ClickBank', 'MaxWeb', 'BuyGoods', 'Digistore24'], 2),
            'payout_amount' => 100.00,
            'payout_currency' => 'USD',
            'payout_cad' => fake()->randomFloat(2, 40, 250),
            'payout_notes' => 'Tiered: $40/1, $100/3, $150/6 bottles',
            'page_type' => fake()->randomElement(['TSL', 'DTC', 'Advertorial', 'VSL', 'Quiz']),
            'product_kind' => ProductKind::Nutra,
            'strategy' => Strategy::BofDirect,
            'strategy_source' => StrategySource::Auto,
            'brand_bidding' => fake()->randomElement(BrandBiddingStatus::cases()),
            'true_bof' => fake()->randomElement(TrueBofStatus::cases()),
            'geos' => ['US', 'CA', 'UK'],
            'negatives' => 'login, scam, review, amazon, ingredients, coupon',
            'approval_required' => fake()->boolean(30),
            'demand_notes' => 'Steady search volume on brand keywords with commercial purchase intent.',
            'sales_page_url' => 'https://example.com/sales',
            'affiliate_link' => 'https://smartadv.example.com/aff_c?offer_id=123',
            'notes' => 'High converting offer with solid EPC and low refund rate.',
            'last_reviewed_at' => fake()->optional()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function test(): static
    {
        return $this->state(fn () => [
            'status' => WatchedProductStatus::Test,
        ]);
    }

    public function watch(): static
    {
        return $this->state(fn () => [
            'status' => WatchedProductStatus::Watch,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => WatchedProductStatus::Rejected,
        ]);
    }
}
