<?php

namespace Database\Factories;

use App\Models\CampaignAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignAlert>
 */
class CampaignAlertFactory extends Factory
{
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
        ];
    }
}
