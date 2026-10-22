<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\WatchedProductStatus;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\BofChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BofCheckerTest extends TestCase
{
    use RefreshDatabase;

    private BofChecker $bofChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bofChecker = new BofChecker;
    }

    public function test_detect_translation_pass(): void
    {
        // Standard English/Latin names should pass
        $this->assertTrue($this->bofChecker->detectTranslationPass('Dentavim'));
        $this->assertTrue($this->bofChecker->detectTranslationPass('Audifort Drops'));
        $this->assertTrue($this->bofChecker->detectTranslationPass('Nagano Lean Body Tonic'));
        $this->assertTrue($this->bofChecker->detectTranslationPass('Puravive Supplement'));

        // Non-Latin scripts should fail
        $this->assertFalse($this->bofChecker->detectTranslationPass('デンタビム')); // Japanese
        $this->assertFalse($this->bofChecker->detectTranslationPass('Дентавим')); // Cyrillic
        $this->assertFalse($this->bofChecker->detectTranslationPass('齿维他')); // Chinese

        // Explicit foreign language versions should fail
        $this->assertFalse($this->bofChecker->detectTranslationPass('Dentavim [DE]'));
        $this->assertFalse($this->bofChecker->detectTranslationPass('Audifort (Version Française)'));
        $this->assertFalse($this->bofChecker->detectTranslationPass('Puravive en español'));
    }

    public function test_detect_bof_vpn_serp_from_ads_with_commercial_intent(): void
    {
        $serpData = [
            'ads' => [
                [
                    'title' => 'Dentavim Official Store - Order Today With 70% Off',
                    'snippet' => 'Buy Dentavim supplement directly from manufacturer.',
                    'link' => 'https://getdentavim.com',
                ],
            ],
            'organic_results' => [],
        ];

        $this->assertTrue($this->bofChecker->detectBofVpnSerp('Dentavim', $serpData));
    }

    public function test_detect_bof_vpn_serp_from_organic_with_commercial_intent(): void
    {
        $serpData = [
            'ads' => [],
            'organic_results' => [
                [
                    'title' => 'Dentavim Reviews: Buy Official Supplement Online',
                    'snippet' => 'Dentavim official price is $49 per bottle with free shipping.',
                    'link' => 'https://dentavim-official.com',
                ],
                [
                    'title' => 'Dentavim Ingredients and Results',
                    'snippet' => 'Order Dentavim from the official store.',
                    'link' => 'https://reviewsite.com/dentavim',
                ],
            ],
        ];

        $this->assertTrue($this->bofChecker->detectBofVpnSerp('Dentavim', $serpData));
    }

    public function test_detect_bof_vpn_serp_returns_false_when_no_commercial_intent_matches(): void
    {
        $serpData = [
            'ads' => [],
            'organic_results' => [
                [
                    'title' => 'Unrelated Wikipedia Article',
                    'snippet' => 'General information about history and geography.',
                    'link' => 'https://wikipedia.org/wiki/General',
                ],
            ],
        ];

        $this->assertFalse($this->bofChecker->detectBofVpnSerp('Dentavim', $serpData));
    }

    public function test_derive_image_match_pct(): void
    {
        // 9 out of 10 match -> 90% (qualifies for full credit)
        $serpData = [
            'organic_results' => array_merge(
                array_fill(0, 9, [
                    'title' => 'Dentavim Official Site - Purchase Dentavim',
                    'snippet' => 'Dentavim formula details and review',
                ]),
                [
                    [
                        'title' => 'Something Else Completely',
                        'snippet' => 'No mention of brand',
                    ],
                ]
            ),
        ];

        $pct = $this->bofChecker->deriveImageMatchPct('Dentavim', $serpData);
        $this->assertEquals(90, $pct);
        $this->assertGreaterThanOrEqual(90, $pct);

        // Low match (e.g. 2 out of 10 for homonym/generic name)
        $lowMatchSerp = [
            'organic_results' => array_merge(
                array_fill(0, 2, [
                    'title' => 'Steel Supplement',
                    'snippet' => 'A workout powder',
                ]),
                array_fill(0, 8, [
                    'title' => 'Structural Steel Beams and Metal Manufacturing',
                    'snippet' => 'Industrial steel alloys and fabrication',
                ])
            ),
        ];

        $lowPct = $this->bofChecker->deriveImageMatchPct('Steel', $lowMatchSerp);
        $this->assertEquals(20, $lowPct);
        $this->assertLessThan(90, $lowPct);
    }

    public function test_watching_refresh_populates_triad_fields_and_scores_20(): void
    {
        Cache::flush();
        config(['watching.serpapi_key' => 'test-key']);
        config(['watching.serpapi_gl' => 'ca']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'engine=google_trends')) {
                return Http::response([
                    'interest_over_time' => [
                        'timeline_data' => [
                            ['values' => [['extracted_value' => 50]]],
                            ['values' => [['extracted_value' => 60]]],
                            ['values' => [['extracted_value' => 70]]],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), 'engine=google')) {
                return Http::response([
                    'ads' => [
                        [
                            'title' => 'Dentavim Official Store - Buy Online',
                            'snippet' => 'Order Dentavim today with discount.',
                            'displayed_link' => 'https://dentavim.com',
                        ],
                    ],
                    'organic_results' => array_fill(0, 10, [
                        'title' => 'Dentavim Official Supplement Reviews',
                        'snippet' => 'Buy Dentavim online with discount. Natural ingredients.',
                        'link' => 'https://dentavim.com/offer',
                    ]),
                    'related_searches' => [
                        ['query' => 'dentavim reviews'],
                    ],
                ], 200);
            }

            return Http::response('<html><body>Dentavim supplement page</body></html>', 200);
        });

        $product = WatchedProduct::factory()->create([
            'name' => 'Dentavim',
            'status' => WatchedProductStatus::Watch,
            'page_type' => 'TSL',
            'listing_status' => ListingStatus::Active,
            'cancel_rate' => 2.0,
            'bof_translation' => false,
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 0,
            'bof_homonym' => false,
            'payout_amount' => 120.00,
        ]);

        $this->artisan('watching:refresh', ['--product' => $product->id])
            ->assertSuccessful();

        $product->refresh();

        $this->assertTrue((bool) $product->bof_translation);
        $this->assertTrue((bool) $product->bof_vpn_serp);
        $this->assertGreaterThanOrEqual(90, (int) $product->bof_image_match_pct);
        $this->assertEquals(20, $product->score_breakdown['bof_name']);
    }

    public function test_product_update_rescores_when_triad_fields_change(): void
    {
        $user = User::factory()->create();

        $product = WatchedProduct::factory()->create([
            'name' => 'Test Product',
            'status' => WatchedProductStatus::Watch,
            'page_type' => 'TSL',
            'listing_status' => ListingStatus::Active,
            'cancel_rate' => 2.0,
            'bof_translation' => false,
            'bof_vpn_serp' => false,
            'bof_image_match_pct' => 0,
            'payout_amount' => 100.00,
        ]);

        $initialScore = $product->score;

        // Update via controller endpoint
        $response = $this->actingAs($user)->put("/watching-products/{$product->id}", [
            'name' => $product->name,
            'status' => $product->status->value,
            'brand_bidding' => $product->brand_bidding->value,
            'true_bof' => $product->true_bof->value,
            'bof_translation' => true,
            'bof_vpn_serp' => true,
            'bof_image_match_pct' => 95,
            'bof_homonym' => false,
            'payout_amount' => 100.00,
            'payout_currency' => 'USD',
        ]);

        $response->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertTrue((bool) $product->bof_translation);
        $this->assertTrue((bool) $product->bof_vpn_serp);
        $this->assertEquals(95, $product->bof_image_match_pct);
        $this->assertEquals(20, $product->score_breakdown['bof_name']);
        $this->assertGreaterThan($initialScore, $product->score);
    }
}
