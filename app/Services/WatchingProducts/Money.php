<?php

namespace App\Services\WatchingProducts;

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
        $fx ??= app(FrankfurterFx::class);

        return $fx->toCad($this->amount, $this->currency);
    }

    public function toUsd(?FrankfurterFx $fx = null): float
    {
        $fx ??= app(FrankfurterFx::class);

        return $fx->toUsd($this->amount, $this->currency);
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
