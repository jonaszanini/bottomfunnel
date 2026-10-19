<?php

namespace App\Services\Tracking;

use App\Models\Track;
use App\Models\Visit;

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
}
