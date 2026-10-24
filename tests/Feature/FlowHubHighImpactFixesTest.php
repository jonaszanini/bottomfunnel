<?php

namespace Tests\Feature;

use App\Enums\WatchedProductStatus;
use App\Jobs\UploadConversionJob;
use App\Models\Conversion;
use App\Models\FxRate;
use App\Models\GoogleAdsAccount;
use App\Models\Track;
use App\Models\Visit;
use App\Models\WatchedProduct;
use App\Services\Fx\BankOfCanadaFx;
use App\Services\Google\DataManagerClient;
use App\Services\Reporting\DashboardReport;
use App\Services\Tracking\ParsedPostback;
use App\Services\Tracking\PostbackRecorder;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
use App\Services\WatchingProducts\RankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowHubHighImpactFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement 1:
     * - BankOfCanadaFx is the primary source for CAD pairs.
     * - PotentialScoreService, DashboardReport, ConversionMatcher, and payout display all read through FxRate model / Money value object.
     * - The same USD amount converts to the same CAD value in both scoring and reporting.
     */
    public function test_same_usd_amount_converts_to_same_cad_value_in_both_scoring_and_reporting(): void
    {
        // 1. Establish CAD/USD rate via Bank of Canada (e.g. 1 USD = 1.3650 CAD)
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => '1.36500000',
            'source' => 'bank_of_canada',
        ]);

        $usdAmount = 100.00;

        // 2. Scoring conversion: WatchedProduct payout using Money value object
        $money = Money::make($usdAmount, 'USD');
        $scoringCad = $money->toCad();
        $this->assertEquals(136.50, $scoringCad);

        $product = WatchedProduct::factory()->create([
            'name' => 'FX Test Product',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => $usdAmount,
            'payout_currency' => 'USD',
            'payout_cad' => null, // Observer computes it using Money
        ]);
        $this->assertEquals(136.50, (float) $product->fresh()->payout_cad);

        // 3. Reporting conversion: PostbackRecorder records conversion in CAD using BankOfCanadaFx/Money
        $track = Track::query()->create([
            'product_name' => 'FX Test Product',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'advertorial',
            'strategy' => 'bof',
            'language' => 'en',
            'typical_commission_cad' => 136.50,
            'postback_secret' => 'test-secret-fx',
        ]);

        $postback = new ParsedPostback(
            orderId: 'ORDER-FX-100',
            amount: $usdAmount,
            currency: 'USD',
            gclid: 'gclid-fx-100',
            sessionToken: null,
            tokens: [],
            eventType: 'sale',
            signaturePresent: false,
            signatureValid: false,
        );

        $recorder = app(PostbackRecorder::class);
        $conversion = $recorder->record($track, 'raw-test-payload', $postback);

        $reportingCad = (float) $conversion->commission_value_cad;
        $this->assertEquals(136.50, $reportingCad);

        // 4. DashboardReport aggregates the exact same CAD commission
        $report = app(DashboardReport::class)->build([
            'track_id' => $track->id,
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]);
        $this->assertEquals(136.50, $report['summary']['commission']);

        // 5. Assert scoring CAD and reporting CAD match to the exact cent
        $this->assertSame(
            number_format($scoringCad, 2),
            number_format($reportingCad, 2),
            'Scoring CAD payout and reporting CAD commission must convert to the exact same value.'
        );
    }

    /**
     * Requirement 1 (cont.):
     * BankOfCanadaFx is the primary source for CAD pairs and FrankfurterFx serves as fallback
     * for currencies Bank of Canada does not cover.
     */
    public function test_bank_of_canada_is_primary_and_frankfurter_is_fallback(): void
    {
        Http::fake([
            // Bank of Canada covers USD
            'www.bankofcanada.ca/valet/observations/FXUSDCAD/json*' => Http::response([
                'observations' => [[
                    'FXUSDCAD' => ['v' => '1.34000000'],
                ]],
            ]),
            // Bank of Canada returns 404 for an exotic currency (e.g. PHP)
            'www.bankofcanada.ca/valet/observations/FXPHPCAD/json*' => Http::response([
                'description' => 'Not found',
            ], 404),
            // Frankfurter covers PHP to CAD
            'api.frankfurter.app/latest*from=PHP*to=CAD*' => Http::response([
                'amount' => 1.0,
                'base' => 'PHP',
                'date' => today()->toDateString(),
                'rates' => ['CAD' => 0.02450000],
            ]),
            // Catch-all
            'api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'PHP',
                'date' => today()->toDateString(),
                'rates' => ['CAD' => 0.02450000],
            ]),
        ]);

        $bocFx = app(BankOfCanadaFx::class);

        // USD/CAD should use Bank of Canada primary
        $usdCadRate = $bocFx->cadPerUnit('USD');
        $this->assertEquals('1.34000000', $usdCadRate);

        $cachedUsd = FxRate::query()->where('currency', 'USD')->whereDate('rate_date', today())->first();
        $this->assertNotNull($cachedUsd);
        $this->assertEquals('bank_of_canada', $cachedUsd->source);

        // PHP/CAD is not in Bank of Canada, should fall back to Frankfurter
        $phpCadRate = $bocFx->cadPerUnit('PHP');
        $this->assertEquals('0.02450000', $phpCadRate);

        $cachedPhp = FxRate::query()->where('currency', 'PHP')->whereDate('rate_date', today())->first();
        $this->assertNotNull($cachedPhp);
        $this->assertEquals('frankfurter', $cachedPhp->source);

        // FrankfurterFx also delegates to BoC for CAD
        $frankfurterFx = app(FrankfurterFx::class);
        $rate = $frankfurterFx->getRate('USD', 'CAD');
        $this->assertEquals(1.34, $rate);
    }

    /**
     * Requirement 2:
     * - In DataManagerClient and UploadConversionJob, avoid fast-fail batch rejection.
     * - Persist requestId returned by Data Manager on Conversion model.
     * - A single invalid record does not silently drop others.
     */
    public function test_single_invalid_record_does_not_silently_drop_others_in_batch(): void
    {
        config([
            'flowhub.google_ads.client_id' => 'client',
            'flowhub.google_ads.client_secret' => 'secret',
            'flowhub.google_ads.developer_token' => 'developer',
        ]);

        $account = GoogleAdsAccount::query()->create([
            'customer_id' => '1234567890',
            'refresh_token' => 'refresh-token',
            'name' => 'Test Account',
            'status' => 'linked',
        ]);

        $action = $account->conversionActions()->create([
            'resource_name' => 'customers/1234567890/conversionActions/987654321',
            'external_id' => '987654321',
            'name' => 'Purchase Action',
        ]);

        $track = Track::query()->create([
            'google_ads_account_id' => $account->id,
            'conversion_action_id' => $action->id,
            'product_name' => 'Harden Test Product',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://example.com/hop',
            'page_type' => 'advertorial',
            'strategy' => 'bof',
            'language' => 'en',
            'typical_commission_cad' => 50.00,
            'postback_secret' => 'harden-secret',
        ]);

        $visit = Visit::query()->create([
            'track_id' => $track->id,
            'session_id' => 'session-valid-1',
            'page_type' => 'advertorial',
            'gclid' => 'valid-gclid-12345',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'landed_at' => now(),
        ]);

        // Conversion 1: Valid record
        $validConversion = Conversion::query()->create([
            'track_id' => $track->id,
            'visit_id' => $visit->id,
            'order_id' => 'ORDER-VALID-1',
            'gclid' => 'valid-gclid-12345',
            'amount' => 50.00,
            'currency' => 'CAD',
            'fx_rate' => 1.0,
            'commission_value_cad' => 50.00,
            'status' => 'matched',
            'raw_payload' => 'payload-valid',
        ]);

        // Conversion 2: Invalid record (missing order_id and no gclid/attribution identifiers)
        $invalidConversion = Conversion::query()->create([
            'track_id' => $track->id,
            'visit_id' => null,
            'order_id' => 'missing-order-bad',
            'gclid' => null,
            'amount' => 20.00,
            'currency' => 'CAD',
            'fx_rate' => 1.0,
            'commission_value_cad' => 20.00,
            'status' => 'matched',
            'raw_payload' => 'payload-invalid',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'mock-access-token',
                'expires_in' => 3600,
            ]),
            'datamanager.googleapis.com/v1/events:ingest' => Http::response([
                'requestId' => 'req-data-manager-abc123',
            ], 200),
        ]);

        // Run batch upload job containing both conversions (invalid first, valid second)
        $job = new UploadConversionJob([$invalidConversion->id, $validConversion->id]);
        $job->handle(app(DataManagerClient::class));

        $invalidConversion->refresh();
        $validConversion->refresh();

        // 1. The invalid conversion must be marked failed with an error message
        $this->assertSame('failed', $invalidConversion->status);
        $this->assertNotNull($invalidConversion->last_error);
        $this->assertNull($invalidConversion->request_id);

        // 2. Crucial: The valid conversion must NOT be dropped; it uploaded successfully
        $this->assertSame('uploaded', $validConversion->status);
        $this->assertNull($validConversion->last_error);
        $this->assertNotNull($validConversion->uploaded_at);

        // 3. The requestId returned by Data Manager is persisted on the Conversion model
        $this->assertSame('req-data-manager-abc123', $validConversion->request_id);

        // 4. Verify Google Data Manager API was invoked for the valid conversion
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'events:ingest')
                && str_contains($request->body(), 'valid-gclid-12345');
        });
    }

    /**
     * Requirement 3:
     * - RankService should not recalculate full ranking on every individual WatchedProduct save/update.
     * - In RefreshWatchingProducts, score each product, then call RankService once at the end of the batch.
     * - In the observer, only trigger rescore of the single product; defer ranking.
     * - Ranking is stable and only updated in batch.
     */
    public function test_ranking_is_stable_on_individual_updates_and_only_updated_in_batch(): void
    {
        $rankService = app(RankService::class);

        // 1. Create Product A with score 80
        $productA = WatchedProduct::factory()->create([
            'name' => 'Product Alpha',
            'status' => WatchedProductStatus::Watch,
            'score' => 80,
            'rank' => null,
            'payout_amount' => 50.00,
            'trends_30d' => 50.0,
        ]);

        // 2. Create Product B with score 40
        $productB = WatchedProduct::factory()->create([
            'name' => 'Product Beta',
            'status' => WatchedProductStatus::Watch,
            'score' => 40,
            'rank' => null,
            'payout_amount' => 30.00,
            'trends_30d' => 20.0,
        ]);

        // 3. Perform initial batch ranking
        $rankService->recalculateRanks();

        $productA->refresh();
        $productB->refresh();

        $this->assertSame(1, $productA->rank, 'Product A with score 80 should be rank 1.');
        $this->assertSame(2, $productB->rank, 'Product B with score 40 should be rank 2.');

        // 4. Update Product B individually to a much higher score (e.g. 95)
        // In the observer, ranking is deferred and NOT recalculated across the watchlist.
        $productB->score = 95;
        $productB->save();

        $productA->refresh();
        $productB->refresh();

        // Ranks must remain STABLE because individual update does not trigger full recalculation
        $this->assertSame(1, $productA->rank, 'Product A rank must remain 1 until batch ranking runs.');
        $this->assertSame(2, $productB->rank, 'Product B rank must remain 2 until batch ranking runs.');

        // 5. Trigger batch ranking (as done at the end of RefreshWatchingProducts)
        $rankService->recalculateRanks();

        $productA->refresh();
        $productB->refresh();

        // Now ranks are updated in batch
        $this->assertSame(1, $productB->rank, 'Product B with score 95 is now rank 1 after batch ranking.');
        $this->assertSame(2, $productA->rank, 'Product A with score 80 is now rank 2 after batch ranking.');
    }
}
