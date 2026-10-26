<?php

namespace App\Services\Google;

class GoogleAdsQuotaException extends GoogleRequestException
{
    public function __construct(string $message = 'Google Ads API quota exceeded (RESOURCE_EXHAUSTED).', int $retryAfterSeconds = 60)
    {
        parent::__construct($message, transient: true);
    }
}
