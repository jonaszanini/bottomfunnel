<?php

namespace App\Services\Google;

use App\Models\Conversion;
use Illuminate\Support\Facades\Http;
use Throwable;

class DataManagerClient
{
    public function __construct(private readonly GoogleAdsClient $googleAds) {}

    /**
     * Pre-validate every record (timestamp, identifiers, action ownership) before sending.
     *
     * @throws GoogleRequestException
     */
    public function validateConversion(Conversion $conversion): void
    {
        $conversion->loadMissing('track.account', 'track.conversionAction', 'visit');
        $track = $conversion->track;
        $account = $track?->account;
        $action = $track?->conversionAction;

        if (! $account || ! $action) {
            throw new GoogleRequestException('This Track has no linked Google Ads account or conversion action.');
        }

        if (empty($action->external_id)) {
            throw new GoogleRequestException('This conversion action has no external ID.');
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

        if (empty($conversion->order_id) || str_starts_with((string) $conversion->order_id, 'missing-')) {
            throw new GoogleRequestException('Conversion order_id is missing or invalid.');
        }

        $timestamp = $conversion->created_at ?? now();
        if ($timestamp->isFuture() && $timestamp->diffInHours(now()) > 24) {
            throw new GoogleRequestException('Conversion timestamp cannot be in the future.');
        }
        if ($timestamp->isPast() && $timestamp->diffInDays(now()) > 90) {
            throw new GoogleRequestException('Conversion timestamp is older than Google Ads 90-day window.');
        }
    }

    /**
     * Ingest a single conversion event into Google Data Manager.
     * Sending one conversion per request prevents fast-fail batch rejection.
     */
    public function ingest(Conversion $conversion): string
    {
        $this->validateConversion($conversion);

        $track = $conversion->track;
        $account = $track->account;
        $action = $track->conversionAction;

        $gclid = $conversion->gclid ?: $conversion->visit?->gclid;
        $gbraid = $conversion->visit?->gbraid;
        $wbraid = $conversion->visit?->wbraid;
        $identifiers = array_filter([
            'gclid' => $gclid ?: null,
            'gbraid' => $gbraid ?: null,
            'wbraid' => $wbraid ?: null,
        ]);

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
        $resolvedRequestId = is_string($requestId) && $requestId !== '' ? $requestId : 'accepted';

        $conversion->update(['request_id' => $resolvedRequestId]);

        return $resolvedRequestId;
    }

    /**
     * Ingest multiple conversions individually to avoid fast-fail batch rejection.
     * A single invalid record does not drop or block the remaining records.
     *
     * @param  iterable<Conversion>  $conversions
     * @return array{success: array<int, string>, failed: array<int, string>}
     */
    public function ingestMany(iterable $conversions): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($conversions as $conversion) {
            try {
                $requestId = $this->ingest($conversion);
                $status = in_array($conversion->status, ['refunded', 'chargeback'], true)
                    ? $conversion->status
                    : 'uploaded';

                $conversion->update([
                    'request_id' => $requestId,
                    'status' => $status,
                    'last_error' => null,
                    'uploaded_at' => now(),
                ]);

                $results['success'][$conversion->id] = $requestId;
            } catch (Throwable $exception) {
                $conversion->update([
                    'status' => in_array($conversion->status, ['refunded', 'chargeback'], true) ? $conversion->status : 'failed',
                    'last_error' => $exception->getMessage(),
                ]);

                $results['failed'][$conversion->id] = $exception->getMessage();
            }
        }

        return $results;
    }
}
