<?php

namespace Database\Factories;

use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    protected $model = Track::class;

    public function definition(): array
    {
        return [
            'product_name' => fake()->words(2, true).' Offer',
            'watched_product_id' => null,
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?affiliate=test&vendor=vendor',
            'page_type' => 'presell',
            'strategy' => 'target_cpa',
            'language' => 'en',
            'typical_commission_cad' => 65.00,
            'postback_secret' => Str::random(48),
            'public_script_key' => Str::lower(Str::random(32)),
            'google_ads_account_id' => null,
            'conversion_action_id' => null,
            'campaign_ids' => [(string) fake()->numerify('###########')],
        ];
    }
}
