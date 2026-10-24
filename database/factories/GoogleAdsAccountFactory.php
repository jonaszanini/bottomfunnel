<?php

namespace Database\Factories;

use App\Models\GoogleAdsAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleAdsAccount>
 */
class GoogleAdsAccountFactory extends Factory
{
    protected $model = GoogleAdsAccount::class;

    public function definition(): array
    {
        return [
            'customer_id' => (string) fake()->unique()->numerify('##########'),
            'login_customer_id' => (string) fake()->numerify('##########'),
            'refresh_token' => '1//fake-refresh-token-for-testing',
            'name' => fake()->company().' Ads Account',
            'status' => 'linked',
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
