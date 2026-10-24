<?php

namespace Database\Factories;

use App\Enums\NegativeKeywordMatchType;
use App\Enums\NegativeKeywordSource;
use App\Models\ProductNegativeKeyword;
use App\Models\WatchedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductNegativeKeyword>
 */
class ProductNegativeKeywordFactory extends Factory
{
    protected $model = ProductNegativeKeyword::class;

    public function definition(): array
    {
        return [
            'product_id' => WatchedProduct::factory(),
            'term' => fake()->word(),
            'match_type' => NegativeKeywordMatchType::Broad,
            'source' => NegativeKeywordSource::Base,
            'enabled' => true,
            'strategy_rules' => null,
            'manually_overridden' => false,
        ];
    }
}
