<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GoogleLoginAccountStatus;
use App\Models\GoogleLoginAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleLoginAccount>
 */
class GoogleLoginAccountFactory extends Factory
{
    protected $model = GoogleLoginAccount::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => 'P@ssw0rd'.fake()->numerify('###!'),
            'customer_id' => (string) fake()->unique()->numerify('##########'),
            'login_customer_id' => (string) fake()->numerify('##########'),
            'status' => GoogleLoginAccountStatus::Active,
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'BRL', 'CAD']),
            'notes' => fake()->optional()->sentence(),
            'cancelled_at' => null,
            'linked_google_ads_account_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => GoogleLoginAccountStatus::Active,
            'cancelled_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => GoogleLoginAccountStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => GoogleLoginAccountStatus::Suspended,
            'cancelled_at' => null,
        ]);
    }
}
