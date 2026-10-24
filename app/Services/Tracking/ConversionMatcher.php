<?php

namespace App\Services\Tracking;

use App\Models\Track;
use App\Models\Visit;
use App\Services\WatchingProducts\Money;

class ConversionMatcher
{
    /**
     * @return array{visit: Visit, source: string}|null
     */
    public function match(Track $track, ParsedPostback $postback): ?array
    {
        if ($postback->gclid) {
            $visit = Visit::query()
                ->where('track_id', $track->id)
                ->where('gclid', $postback->gclid)
                ->orderByDesc('landed_at')
                ->first();
            if ($visit) {
                return ['visit' => $visit, 'source' => 'gclid'];
            }
        }

        foreach ($postback->tokens as $token) {
            $visit = Visit::query()
                ->where('track_id', $track->id)
                ->where('session_id', $token)
                ->first();
            if ($visit) {
                return ['visit' => $visit, 'source' => 'session'];
            }
        }

        return null;
    }

    public function expectedMoney(Track $track): Money
    {
        return Money::make($track->typical_commission_cad, 'CAD');
    }

    public function postbackMoney(ParsedPostback $postback): Money
    {
        return Money::make($postback->amount, $postback->currency);
    }
}
