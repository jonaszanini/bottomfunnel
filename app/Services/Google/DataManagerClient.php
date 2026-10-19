<?php

namespace App\Services\Google;

use App\Models\Conversion;
use Illuminate\Support\Facades\Http;

class DataManagerClient
{
    public function __construct(private readonly GoogleAdsClient $googleAds) {}

    public function ingest(Conversion $conversion): string
    {
        $conversion->loadMissing('track.account', 'track.conversionAction', 'visit');
        $track = $conversion->track;
        $account = $track?->account;
        $action = $track?->conversionAction;
        if (! $account || ! $action) {
            throw new GoogleRequestException('This Track has no linked Google Ads account or conversion action.');
        }

        $gclid = $conversion->gclid ?: $conversion->visit?->gclid;
        $gbraid = $conversion->visit?->gbraid;
        $wbraid = $conversion->visit?->wbraid;
        $identifiers = array_filter([
            'gclid' => $gclid ?: null,
            'gbraid' => $gbraid ?: null,
            'wbraid' => $wbraid ?: null,
        ]);
        if ($identifiers === []) {
            throw new GoogleRequestException('This conversion has no gclid, gbraid, or wbraid, so Google cannot attribute it.');
        }

        $destination = [
            'operatingAccount' => [
                'accountType' => 'GOOGLE_ADS',
                'accountId' => $this->googleAds->digits($account->customer_id),
            ],
            'productDestinationId' => (string) $action->external_id,
        ];
        $login = $this->googleAds->digits((string) $account->login_customer_id);
        if ($login !== '' && $login !== $destination['operatingAccount']['accountId']) {
            $destination['loginAccount'] = [
                'accountType' => 'GOOGLE_ADS',
                'accountId' => $login,
            ];
        }

        $event = [
            'adIdentifiers' => $identifiers,
            'transactionId' => $conversion->order_id,
            'eventTimestamp' => ($conversion->created_at ?? now())->utc()->toIso8601String(),
            'eventSource' => 'WEB',
            'currency' => 'CAD',
            'conversionValue' => (float) $conversion->commission_value_cad,
        ];

        $token = $this->googleAds->accessToken($account);
        $response = Http::withToken($token)
            ->timeout(25)
            ->acceptJson()
            ->post('https://datamanager.googleapis.com/v1/events:ingest', [
                'destinations' => [$destination],
                'events' => [$event],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: 'Data Manager ingest failed.';
            $transient = in_array($response->status(), [429, 500, 502, 503, 504], true);
            throw new GoogleRequestException('Data Manager: '.$message, $transient);
        }

        $requestId = $response->json('requestId');

        return is_string($requestId) && $requestId !== '' ? $requestId : 'accepted';
    }
}
