<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        return [
            'track_id' => Track::factory(),
            'campaign_id' => (string) fake()->numerify('###########'),
            'name' => fake()->words(2, true).' Landing Page',
            'page_type' => 'presell',
            'status' => 'completed',
            'error_message' => null,
            'language' => 'en',
            'structure_json' => [
                'headline' => 'Special Limited Time Offer',
                'sections' => [],
            ],
            'html' => '<!DOCTYPE html><html><body><h1>Landing Page</h1></body></html>',
            'injected_script' => '<script>console.log("track");</script>',
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => 'Crawler connection timed out',
        ]);
    }
}
