<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AffiliateNetwork;
use App\Enums\AffiliateNetworkAccountStatus;
use App\Models\AffiliateNetworkAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateNetworkAccount>
 */
class AffiliateNetworkAccountFactory extends Factory
{
    protected $model = AffiliateNetworkAccount::class;

    public function definition(): array
    {
        $network = fake()->randomElement(AffiliateNetwork::cases());

        return [
            'network' => $network,
            'network_custom' => $network === AffiliateNetwork::Other ? 'CustomCpaNetwork' : null,
            'nickname' => fake()->company().' Account',
            'email' => fake()->safeEmail(),
            'username' => fake()->userName(),
            'password' => 'VaultSecret#'.fake()->numerify('###!'),
            'account_id' => (string) fake()->numerify('AFF-#####'),
            'status' => AffiliateNetworkAccountStatus::Active,
            'login_url' => 'https://'.fake()->domainName().'/login',
            'notes' => fake()->optional()->sentence(),
            'default_currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'CAD']),
            'payment_method_hint' => 'Direct Deposit / Wire',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => AffiliateNetworkAccountStatus::Active,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => AffiliateNetworkAccountStatus::Suspended,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => AffiliateNetworkAccountStatus::Closed,
        ]);
    }
}
