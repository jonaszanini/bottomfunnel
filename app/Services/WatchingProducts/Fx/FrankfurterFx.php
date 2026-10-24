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

        // CAD pairs: Bank of Canada is the primary source of truth
        if ($to === 'CAD') {
            return $this->getCadRate($from);
        }

        if ($from === 'CAD') {
            $cadPerTo = $this->getCadRate($to);
            if ($cadPerTo > 0) {
                return 1.0 / $cadPerTo;
            }
        }

        // When converting between two non-CAD currencies (e.g. BRL to USD or USD to BRL):
        // Try cross-rate via CAD from Bank of Canada first
        try {
            $cadFrom = $this->getCadRate($from);
            $cadTo = $this->getCadRate($to);
            if ($cadFrom > 0 && $cadTo > 0) {
                return $cadFrom / $cadTo;
            }
        } catch (Throwable) {
            // Proceed to direct Frankfurter fetch
        }

        // Frankfurter API fallback for non-CAD pairs
        $direct = $this->fetchDirect($from, $to);
        if ($direct !== null && $direct > 0) {
            return $direct;
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
     * Get CAD rate per unit of $currency using BankOfCanadaFx as primary source.
     */
    protected function getCadRate(string $currency): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'CAD') {
            return 1.0;
        }

        // 1. Check if rate is cached for today
        $today = FxRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', today())
            ->first();

        if ($today && (float) $today->cad_per_unit > 0) {
            return (float) $today->cad_per_unit;
        }

        // 2. Primary: BankOfCanadaFx
        try {
            $bocCad = $this->bankOfCanadaFx->cadPerUnit($currency);
            if (is_numeric($bocCad) && (float) $bocCad > 0) {
                return (float) $bocCad;
            }
        } catch (Throwable $e) {
            Log::info("BankOfCanadaFx failed for {$currency}, attempting Frankfurter fallback: {$e->getMessage()}");
        }

        // 3. Fallback: Frankfurter
        $frankfurterRate = $this->fetchDirect($currency, 'CAD');
        if ($frankfurterRate !== null && $frankfurterRate > 0) {
            FxRate::query()->updateOrCreate(
                [
                    'rate_date' => today()->toDateString(),
                    'currency' => $currency,
                ],
                [
                    'cad_per_unit' => number_format($frankfurterRate, 8, '.', ''),
                    'source' => 'frankfurter',
                ]
            );

            return $frankfurterRate;
        }

        // 4. Fallback to latest known rate in fx_rates
        $historical = FxRate::query()
            ->where('currency', $currency)
            ->orderByDesc('rate_date')
            ->first();

        if ($historical && (float) $historical->cad_per_unit > 0) {
            return (float) $historical->cad_per_unit;
        }

        return match ($currency) {
            'USD' => 1.38,
            'EUR' => 1.49,
            'GBP' => 1.79,
            'BRL' => 0.24,
            default => 1.0,
        };
    }

    /**
     * Direct call to Frankfurter API.
     */
    public function fetchDirect(string $from, string $to): ?float
    {
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

        return null;
    }
}
