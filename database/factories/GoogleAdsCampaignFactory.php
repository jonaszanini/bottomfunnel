<?php

namespace Database\Factories;

use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleAdsCampaign>
 */
class GoogleAdsCampaignFactory extends Factory
{
    protected $model = GoogleAdsCampaign::class;

    public function definition(): array
    {
        return [
            'google_ads_account_id' => GoogleAdsAccount::factory(),
            'customer_id' => (string) fake()->numerify('##########'),
            'campaign_id' => (string) fake()->unique()->numerify('###########'),
            'name' => fake()->words(3, true).' Campaign',
            'status' => 'PAUSED',
            'budget_amount_micros' => 50000000,
            'bidding_strategy' => 'TARGET_CPA',
            'resource_name' => 'customers/123/campaigns/456',
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn () => [
            'status' => 'ENABLED',
        ]);
    }
}
