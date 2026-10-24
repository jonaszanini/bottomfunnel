<?php

namespace Database\Factories;

use App\Models\CampaignAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignAlert>
 */
class CampaignAlertFactory extends Factory
{
    protected $model = CampaignAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'listing_down',
            'product_name' => fake()->words(2, true),
            'severity' => 'warning',
            'reason' => 'Listing unavailable',
            'message' => 'Listing unavailable for product.',
            'action_taken' => 'alert_raised',
            'spend_cad' => null,
            'payout_cad' => null,
            'threshold_cad' => null,
            'window_days' => null,
            'conversions_count' => 0,
            'details' => null,
            'dismissed_at' => null,
        ];
    }

    public function bleeder(): static
    {
        return $this->state(fn () => [
            'type' => 'bleeder',
            'severity' => 'danger',
            'reason' => 'Spend exceeded payout threshold with 0 conversions',
            'message' => 'Bleeder campaign detected.',
            'spend_cad' => 150.00,
            'payout_cad' => 60.00,
            'threshold_cad' => 90.00,
            'window_days' => 7,
            'conversions_count' => 0,
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'dismissed_at' => now(),
        ]);
    }
}
