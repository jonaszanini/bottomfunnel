<?php

namespace Database\Factories;

use App\Models\AdSpend;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdSpend>
 */
class AdSpendFactory extends Factory
{
    protected $model = AdSpend::class;

    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
            'amount_cad' => 150.00,
            'note' => 'Google Ads automated sync',
        ];
    }
}
