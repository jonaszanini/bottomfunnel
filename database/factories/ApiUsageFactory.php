<?php

namespace Database\Factories;

use App\Models\ApiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiUsage>
 */
class ApiUsageFactory extends Factory
{
    protected $model = ApiUsage::class;

    public function definition(): array
    {
        return [
            'provider' => 'serpapi',
            'period' => now()->format('Y-m'),
            'calls' => fake()->numberBetween(1, 50),
        ];
    }
}
