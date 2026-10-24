<?php

namespace Tests\Feature;

use App\Models\FxRate;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use App\Services\WatchingProducts\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrankfurterFxTest extends TestCase
{
    use RefreshDatabase;

    public function test_faked_frankfurter_call_fetches_and_caches_rate(): void
    {
        Http::fake([
            'api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => today()->toDateString(),
                'rates' => ['CAD' => 1.3850],
            ], 200),
            'https://api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => today()->toDateString(),
                'rates' => ['CAD' => 1.3850],
            ], 200),
            '*api.frankfurter.app/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'USD',
                'date' => today()->toDateString(),
                'rates' => ['CAD' => 1.3850],
            ], 200),
            '*bankofcanada.ca/*' => Http::response('Not found', 404),
            'https://www.bankofcanada.ca/*' => Http::response('Not found', 404),
        ]);

        $fx = app(FrankfurterFx::class);
        $rate = $fx->getRate('USD', 'CAD');

        $this->assertEquals(1.385, $rate);

        // Verify cached in database with source = 'frankfurter'
        $cached = FxRate::query()
            ->where('currency', 'USD')
            ->whereDate('rate_date', today())
            ->first();

        $this->assertNotNull($cached);
        $this->assertEquals('1.38500000', $cached->cad_per_unit);
        $this->assertEquals('frankfurter', $cached->source);
    }

    public function test_cache_hit_does_not_make_http_request(): void
    {
        // Seed database cache
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'EUR',
            'cad_per_unit' => 1.49200000,
            'source' => 'frankfurter',
        ]);

        Http::fake();

        $fx = app(FrankfurterFx::class);
        $rate = $fx->getRate('EUR', 'CAD');

        $this->assertEquals(1.492, $rate);
        Http::assertNothingSent();
    }

    public function test_fallback_on_failure_uses_historical_rate(): void
    {
        // Historical rate 2 days ago
        FxRate::query()->create([
            'rate_date' => today()->subDays(2),
            'currency' => 'GBP',
            'cad_per_unit' => 1.78500000,
            'source' => 'frankfurter',
        ]);

        // Frankfurter fails with 500 error
        Http::fake([
            'https://api.frankfurter.app/latest*' => Http::response('Server Error', 500),
            'https://www.bankofcanada.ca/*' => Http::response('Not found', 404),
        ]);

        $fx = app(FrankfurterFx::class);
        $rate = $fx->getRate('GBP', 'CAD');

        $this->assertEquals(1.785, $rate);
    }

    public function test_money_object_converts_correctly(): void
    {
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.38000000,
            'source' => 'frankfurter',
        ]);

        $money = Money::make(100.00, 'USD');

        $this->assertEquals(100.00, $money->amount);
        $this->assertEquals('USD', $money->currency);
        $this->assertEquals(138.00, $money->toCad());
        $this->assertEquals(100.00, $money->toUsd());
        $this->assertEquals('$100.00 USD', $money->format(true));
    }

    public function test_payout_data_fix_migration_is_reversible(): void
    {
        // Create rate
        FxRate::query()->create([
            'rate_date' => today(),
            'currency' => 'USD',
            'cad_per_unit' => 1.40000000,
            'source' => 'frankfurter',
        ]);

        // Insert legacy product with payout_cad = 100.00 (which was USD)
        $id = DB::table('watched_products')->insertGetId([
            'name' => 'Legacy Payout Product',
            'status' => 'watch',
            'brand_bidding' => 'unknown',
            'true_bof' => 'ambiguous',
            'payout_cad' => 100.00,
            'payout_amount' => null,
            'payout_currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run data fix migration up logic
        $migration = require database_path('migrations/2026_10_21_140300_migrate_payout_cad_usd_data_fix.php');
        $migration->up();

        $product = DB::table('watched_products')->where('id', $id)->first();
        $this->assertEquals(100.00, (float) $product->payout_amount);
        $this->assertEquals('USD', $product->payout_currency);
        $this->assertEquals(140.00, (float) $product->payout_cad);

        // Run data fix migration down logic
        $migration->down();

        $productReverted = DB::table('watched_products')->where('id', $id)->first();
        $this->assertEquals(100.00, (float) $productReverted->payout_cad);
        $this->assertNull($productReverted->payout_amount);
    }
}
