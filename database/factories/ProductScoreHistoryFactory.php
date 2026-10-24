<?php

namespace Database\Factories;

use App\Models\ProductScoreHistory;
use App\Models\WatchedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductScoreHistory>
 */
class ProductScoreHistoryFactory extends Factory
{
    protected $model = ProductScoreHistory::class;

    public function definition(): array
    {
        return [
            'product_id' => WatchedProduct::factory(),
            'score' => 75,
            'breakdown' => ['bof_name' => 20, 'demand' => 16],
            'flags' => ['refresh_status' => 'completed'],
            'inputs_snapshot' => ['name' => 'Sample Product'],
            'created_at' => now(),
        ];
    }
}
