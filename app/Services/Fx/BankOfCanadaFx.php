<?php

namespace App\Services\Fx;

use App\Models\FxRate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BankOfCanadaFx
{
    public function toCad(float|string $amount, string $currency): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || strlen($currency) !== 3) {
            throw new RuntimeException('The postback did not include a 3 letter currency code.');
        }

        $rate = $this->cadPerUnit($currency);
        $cad = round((float) $amount * (float) $rate, 2);

        return [
            'currency' => $currency,
            'rate' => $rate,
            'cad' => number_format($cad, 2, '.', ''),
        ];
    }

    public function cadPerUnit(string $currency): string
    {
        $currency = strtoupper($currency);
        if ($currency === 'CAD') {
            return '1.00000000';
        }

        $today = FxRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', today())
            ->first();
        if ($today) {
            return (string) $today->cad_per_unit;
        }

        try {
            $rate = $this->fetchLatest($currency);
            $row = FxRate::query()->updateOrCreate(
                ['rate_date' => today()->toDateString(), 'currency' => $currency],
                ['cad_per_unit' => $rate],
            );

            return (string) $row->cad_per_unit;
        } catch (RuntimeException $exception) {
            $fallback = FxRate::query()->where('currency', $currency)->orderByDesc('rate_date')->first();
            if ($fallback && $fallback->rate_date->greaterThanOrEqualTo(today()->subDays(7))) {
                return (string) $fallback->cad_per_unit;
            }

            throw $exception;
        }
    }

    private function fetchLatest(string $currency): string
    {
        $series = 'FX'.$currency.'CAD';
        $response = Http::timeout(15)
            ->acceptJson()
            ->get('https://www.bankofcanada.ca/valet/observations/'.$series.'/json', [
                'recent' => 1,
            ]);

        if ($response->status() === 404) {
            throw new RuntimeException('Bank of Canada has no CAD rate for '.$currency.'.');
        }
        if ($response->failed()) {
            throw new RuntimeException('Bank of Canada Valet did not return a rate for '.$currency.'.');
        }

        $value = $response->json('observations.0.'.$series.'.v');
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException('Bank of Canada returned an unusable CAD rate for '.$currency.'.');
        }

        return number_format((float) $value, 8, '.', '');
    }
}
