<?php

namespace Database\Factories;

use App\Models\IpBlock;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpBlock>
 */
class IpBlockFactory extends Factory
{
    protected $model = IpBlock::class;

    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'google_ads_account_id' => null,
            'ip_address' => fake()->ipv4(),
            'fraud_score' => 85,
            'is_subnet' => false,
            'reason' => 'Datacenter IP click fraud',
            'campaign_id' => (string) fake()->numerify('###########'),
            'criterion_resource_name' => 'customers/123/campaignCriteria/456',
            'status' => 'applied',
            'last_error' => null,
            'synced_at' => now(),
        ];
    }

    public function subnet(): static
    {
        return $this->state(fn () => [
            'ip_address' => '192.168.1.0/24',
            'is_subnet' => true,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'synced_at' => null,
        ]);
    }
}
