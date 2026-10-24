<?php

namespace App\Jobs;

use App\Models\IpBlock;
use App\Models\Track;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyIpBlockJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(
        public int $trackId,
        public string $ip,
        public string $reason,
    ) {}

    public function handle(GoogleAdsClient $googleAds): void
    {
        $track = Track::query()->with('account')->find($this->trackId);
        if (! $track) {
            return;
        }

        if (IpBlock::query()->where('track_id', $track->id)->where('ip_address', $this->ip)->where('status', 'whitelisted')->exists()) {
            return;
        }

        $account = $track->account;
        $campaigns = array_values(array_filter($track->campaign_ids ?? []));
        if (! $account || $campaigns === []) {
            IpBlock::query()->updateOrCreate(
                ['track_id' => $track->id, 'ip_address' => $this->ip, 'campaign_id' => ''],
                [
                    'google_ads_account_id' => $account?->id,
                    'reason' => $this->reason,
                    'status' => 'failed',
                    'last_error' => 'Select at least one campaign on the Track before IP exclusion can be applied.',
                ],
            );

            return;
        }

        foreach ($campaigns as $campaignId) {
            $campaignIdStr = (string) $campaignId;
            $block = IpBlock::query()->firstOrNew([
                'track_id' => $track->id,
                'ip_address' => $this->ip,
                'campaign_id' => $campaignIdStr,
            ]);
            if (in_array($block->status, ['applied', 'whitelisted'], true) && $block->criterion_resource_name) {
                continue;
            }

            $block->fill([
                'google_ads_account_id' => $account->id,
                'reason' => $this->reason,
                'status' => 'pending',
            ])->save();

            try {
                // Check active IP exclusions and enforce 500 limit (consolidate subnets or rotate oldest)
                $googleAds->enforceIpExclusionLimit($account, $campaignIdStr);

                $resource = $googleAds->blockIp($account, $campaignIdStr, $this->ip);
                $block->fill([
                    'criterion_resource_name' => $resource,
                    'status' => 'applied',
                    'last_error' => null,
                    'synced_at' => now(),
                ])->save();
            } catch (GoogleRequestException $exception) {
                $block->fill([
                    'status' => 'failed',
                    'last_error' => $exception->getMessage(),
                ])->save();
                if ($exception->transient && $this->attempts() < $this->tries) {
                    throw $exception;
                }
            }
        }
    }
}
