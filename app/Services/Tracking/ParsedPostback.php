<?php

namespace App\Services\Tracking;

class ParsedPostback
{
    /**
     * @param  list<string>  $tokens
     */
    public function __construct(
        public readonly string $orderId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $gclid,
        public readonly ?string $sessionToken,
        public readonly array $tokens,
        public readonly string $eventType,
        public readonly bool $signaturePresent,
        public readonly bool $signatureValid,
    ) {}
}
