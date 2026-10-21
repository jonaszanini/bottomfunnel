<?php

namespace App\Services\WatchingProducts\Fx;

use App\Models\FxRate;
use App\Services\Fx\BankOfCanadaFx;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FrankfurterFx
{
    public function __construct(
        protected BankOfCanadaFx $bankOfCanadaFx = new BankOfCanadaFx
    ) {}

    /**
     * Convert an amount between currencies.
     */
    public function convert(float|int|string $amount, string $from, string $to): float
    {
        $amount = (float) $amount;
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to || $amount === 0.0) {
            return round($amount, 2);
        }

        $rate = $this->getRate($from, $to);

        return round($amount * $rate, 2);
    }

    /**
     * Convert an amount to CAD.
     */
    public function toCad(float|int|string $amount, string $from): float
    {
        return $this->convert($amount, $from, 'CAD');
    }

    /**
     * Convert an amount to USD.
     */
    public function toUsd(float|int|string $amount, string $from): float
    {
        return $this->convert($amount, $from, 'USD');
    }

    /**
     * Get the exchange rate from currency X to currency Y.
     */
    public function getRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        // Check if to is CAD and we already have a CAD rate cached in fx_rates
        if ($to === 'CAD') {
            $cachedCad = $this->getCachedCadRate($from);
            if ($cachedCad !== null) {
                return $cachedCad;
            }
        }

        // Check if from is CAD and to is USD (can use inverse of USD->CAD)
        if ($from === 'CAD' && $to === 'USD') {
            $usdCadRate = $this->getRate('USD', 'CAD');
            if ($usdCadRate > 0) {
                return 1.0 / $usdCadRate;
            }
        }

        // Attempt Frankfurter API call
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://api.frankfurter.app/latest', [
                    'amount' => 1,
                    'from' => $from,
                    'to' => $to,
                ]);

            if ($response->successful()) {
                $rates = $response->json('rates');
                if (is_array($rates) && isset($rates[$to]) && is_numeric($rates[$to])) {
                    $rate = (float) $rates[$to];

                    // Cache in fx_rates if converting to CAD
                    if ($to === 'CAD') {
                        FxRate::query()->updateOrCreate(
                            [
                                'rate_date' => today()->toDateString(),
                                'currency' => $from,
                            ],
                            [
                                'cad_per_unit' => number_format($rate, 8, '.', ''),
                                'source' => 'frankfurter',
                            ]
                        );
                    }

                    return $rate;
                }
            }

            Log::warning("FrankfurterFx request failed with status: {$response->status()}");
        } catch (Throwable $e) {
            Log::warning("FrankfurterFx HTTP error: {$e->getMessage()}");
        }

        // Secondary fallback to Bank of Canada if converting to CAD
        if ($to === 'CAD') {
            try {
                $bocCad = $this->bankOfCanadaFx->cadPerUnit($from);
                if (is_numeric($bocCad) && (float) $bocCad > 0) {
                    $rate = (float) $bocCad;
                    FxRate::query()->updateOrCreate(
                        [
                            'rate_date' => today()->toDateString(),
                            'currency' => $from,
                        ],
                        [
                            'cad_per_unit' => number_format($rate, 8, '.', ''),
                            'source' => 'bank_of_canada',
                        ]
                    );

                    return $rate;
                }
            } catch (Throwable $e) {
                Log::warning("BankOfCanadaFx secondary fallback failed: {$e->getMessage()}");
            }
        }

        // Fallback to latest known rate in fx_rates
        $historical = FxRate::query()
            ->where('currency', $from)
            ->orderByDesc('rate_date')
            ->first();

        if ($historical && (float) $historical->cad_per_unit > 0) {
            $cadPerFrom = (float) $historical->cad_per_unit;
            if ($to === 'CAD') {
                return $cadPerFrom;
            }
            if ($to === 'USD') {
                $usdHistorical = FxRate::query()->where('currency', 'USD')->orderByDesc('rate_date')->first();
                $cadPerUsd = $usdHistorical ? (float) $usdHistorical->cad_per_unit : 1.38;

                return $cadPerFrom / $cadPerUsd;
            }
        }

        // Hardcoded safety net rates if database is empty and network is unavailable
        return match ("{$from}_{$to}") {
            'USD_CAD' => 1.38,
            'CAD_USD' => 0.7246,
            'EUR_USD' => 1.08,
            'EUR_CAD' => 1.49,
            'GBP_USD' => 1.30,
            'GBP_CAD' => 1.79,
            'BRL_USD' => 0.175,
            'BRL_CAD' => 0.24,
            'USD_BRL' => 5.70,
            default => 1.0,
        };
    }

    /**
     * Get today's cached rate for converting from $currency to CAD.
     */
    protected function getCachedCadRate(string $currency): ?float
    {
        $today = FxRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', today())
            ->first();

        if ($today && (float) $today->cad_per_unit > 0) {
            return (float) $today->cad_per_unit;
        }

        return null;
    }
}
