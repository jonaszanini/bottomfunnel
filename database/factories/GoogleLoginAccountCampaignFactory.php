<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleLoginAccount;
use App\Models\GoogleLoginAccountCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleLoginAccountCampaign>
 */
class GoogleLoginAccountCampaignFactory extends Factory
{
    protected $model = GoogleLoginAccountCampaign::class;

    public function definition(): array
    {
        return [
            'google_login_account_id' => GoogleLoginAccount::factory(),
            'campaign_id' => (string) fake()->unique()->numerify('##########'),
            'campaign_name' => fake()->words(3, true).' Campaign',
            'status' => 'active',
        ];
    }
}
