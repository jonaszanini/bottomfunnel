<?php

namespace Database\Factories;

use App\Models\Conversion;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversion>
 */
class ConversionFactory extends Factory
{
    protected $model = Conversion::class;

    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'visit_id' => null,
            'order_id' => 'ORD-'.Str::upper(Str::random(10)),
            'gclid' => 'Cj0KCQ_fake_gclid_'.Str::random(16),
            'amount' => 100.00,
            'currency' => 'USD',
            'fx_rate' => 1.38000000,
            'commission_value_cad' => 138.00,
            'status' => 'uploaded',
            'match_source' => 'gclid_exact',
            'upload_attempts' => 1,
            'request_id' => (string) Str::uuid(),
            'last_error' => null,
            'raw_payload' => json_encode(['mock' => true]),
            'uploaded_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'uploaded_at' => null,
            'upload_attempts' => 0,
        ]);
    }
}
