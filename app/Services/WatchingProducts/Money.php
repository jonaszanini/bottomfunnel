<?php

namespace App\Services\WatchingProducts;

use App\Models\FxRate;
use App\Services\WatchingProducts\Fx\FrankfurterFx;
use JsonSerializable;

class Money implements JsonSerializable
{
    public function __construct(
        public float $amount,
        public string $currency = 'USD'
    ) {
        $this->currency = strtoupper(trim($currency ?: 'USD'));
    }

    public static function make(float|int|string|null $amount, ?string $currency = 'USD'): self
    {
        return new self((float) ($amount ?? 0.0), $currency ?? 'USD');
    }

    public function toCad(?FrankfurterFx $fx = null): float
    {
        if ($this->currency === 'CAD') {
            return round($this->amount, 2);
        }

        // Fast path: if today's FxRate exists for this currency
        $today = FxRate::query()
            ->where('currency', $this->currency)
            ->whereDate('rate_date', today())
            ->first();

        if ($today && (float) $today->cad_per_unit > 0) {
            return round($this->amount * (float) $today->cad_per_unit, 2);
        }

        $latest = FxRate::query()
            ->where('currency', $this->currency)
            ->orderByDesc('rate_date')
            ->first();

        if ($latest && (float) $latest->cad_per_unit > 0) {
            return round($this->amount * (float) $latest->cad_per_unit, 2);
        }

        if ($this->currency === 'USD') {
            return round($this->amount * 1.38, 2);
        }

        $fx ??= app(FrankfurterFx::class);

        return $fx->toCad($this->amount, $this->currency);
    }

    public function toUsd(?FrankfurterFx $fx = null): float
    {
        if ($this->currency === 'USD') {
            return round($this->amount, 2);
        }

        if ($this->currency === 'CAD') {
            $today = FxRate::query()
                ->where('currency', 'USD')
                ->whereDate('rate_date', today())
                ->first();

            if ($today && (float) $today->cad_per_unit > 0) {
                return round($this->amount / (float) $today->cad_per_unit, 2);
            }

            $latest = FxRate::query()
                ->where('currency', 'USD')
                ->orderByDesc('rate_date')
                ->first();

            if ($latest && (float) $latest->cad_per_unit > 0) {
                return round($this->amount / (float) $latest->cad_per_unit, 2);
            }

            return round($this->amount / 1.38, 2);
        }

        $fx ??= app(FrankfurterFx::class);

        return $fx->toUsd($this->amount, $this->currency);
    }

    public function convertTo(string $targetCurrency, ?FrankfurterFx $fx = null): float
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        if ($this->currency === $targetCurrency) {
            return round($this->amount, 2);
        }

        $fx ??= app(FrankfurterFx::class);

        return $fx->convert($this->amount, $this->currency, $targetCurrency);
    }

    public function toFxRate(): ?FxRate
    {
        return FxRate::query()
            ->where('currency', $this->currency)
            ->orderByDesc('rate_date')
            ->first();
    }

    public function format(bool $withCode = true): string
    {
        $symbol = match ($this->currency) {
            'USD' => '$',
            'CAD' => 'CA$',
            'EUR' => '€',
            'GBP' => '£',
            'BRL' => 'R$',
            default => $this->currency.' ',
        };

        $formatted = $symbol.number_format($this->amount, 2);

        return $withCode ? "{$formatted} {$this->currency}" : $formatted;
    }

    /**
     * Array structure for Inertia/React serialization.
     *
     * @return array{amount: float, currency: string, cad: float, usd: float, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => round($this->amount, 2),
            'currency' => $this->currency,
            'cad' => $this->toCad(),
            'usd' => $this->toUsd(),
            'formatted' => $this->format(true),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
