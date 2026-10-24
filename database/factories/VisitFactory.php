<?php

namespace Database\Factories;

use App\Models\Track;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'session_id' => (string) Str::uuid(),
            'track_id' => Track::factory(),
            'page_id' => null,
            'page_type' => 'presell',
            'gclid' => 'Cj0KCQ_fake_gclid_'.Str::random(16),
            'gbraid' => null,
            'wbraid' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'referrer' => 'https://www.google.com/',
            'landed_at' => now(),
            'time_on_page_seconds' => fake()->numberBetween(10, 180),
            'engagement' => ['scroll_depth' => 50, 'cta_clicks' => 1],
            'fraud_score' => 0,
            'fraud_reason' => null,
            'flagged' => false,
            'auto_blocked' => false,
            'converted' => false,
            'presell_hop' => false,
            'hopped_at' => null,
            'last_ping_at' => now(),
        ];
    }

    public function converted(): static
    {
        return $this->state(fn () => [
            'converted' => true,
        ]);
    }

    public function hopped(): static
    {
        return $this->state(fn () => [
            'presell_hop' => true,
            'hopped_at' => now(),
        ]);
    }
}
