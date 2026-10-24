<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyIpBlockJob;
use App\Models\IpBlock;
use App\Models\Track;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IpBlockController extends Controller
{
    public function block(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
            'ip_address' => ['required', 'string', 'max:45'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $track = Track::query()->findOrFail($validated['track_id']);
        $ip = trim($validated['ip_address']);
        $reason = $validated['reason'] ?? 'Manual one-click block from Track inspection';

        $isWhitelisted = IpBlock::query()
            ->where('track_id', $track->id)
            ->where('ip_address', $ip)
            ->where('status', 'whitelisted')
            ->exists();

        if ($isWhitelisted) {
            return back()->with('error', "IP {$ip} is whitelisted on this Track.");
        }

        $block = IpBlock::query()->firstOrCreate(
            [
                'track_id' => $track->id,
                'ip_address' => $ip,
                'campaign_id' => (string) ($track->campaign_ids[0] ?? ''),
            ],
            [
                'google_ads_account_id' => $track->google_ads_account_id,
                'reason' => $reason,
                'status' => 'pending',
            ],
        );

        if (! in_array($block->status, ['applied', 'whitelisted'], true)) {
            $block->update([
                'reason' => $reason,
                'status' => 'pending',
            ]);
        }

        ApplyIpBlockJob::dispatch($track->id, $ip, $reason);

        return back()->with('success', "IP {$ip} queued for Google Ads exclusion.");
    }

    public function index(): Response
    {
        return Inertia::render('Blocks/Index', [
            'blocks' => IpBlock::query()->with('track:id,product_name')->latest()->limit(200)->get()->map(fn (IpBlock $block): array => [
                'id' => $block->id,
                'ip_address' => $block->ip_address,
                'reason' => $block->reason,
                'status' => $block->status,
                'campaign_id' => $block->campaign_id,
                'last_error' => $block->last_error,
                'product_name' => $block->track?->product_name,
                'created_at' => $block->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function unblock(IpBlock $ipBlock, GoogleAdsClient $googleAds): RedirectResponse
    {
        return $this->lift($ipBlock, $googleAds, 'removed');
    }

    public function whitelist(IpBlock $ipBlock, GoogleAdsClient $googleAds): RedirectResponse
    {
        return $this->lift($ipBlock, $googleAds, 'whitelisted');
    }

    private function lift(IpBlock $ipBlock, GoogleAdsClient $googleAds, string $status): RedirectResponse
    {
        $ipBlock->load('account', 'track');
        if ($ipBlock->criterion_resource_name && $ipBlock->account) {
            try {
                $googleAds->removeCriterion($ipBlock->account, $ipBlock->criterion_resource_name);
            } catch (GoogleRequestException $exception) {
                $ipBlock->update(['last_error' => $exception->getMessage()]);

                return back()->with('error', $exception->getMessage());
            }
        }

        IpBlock::query()
            ->where('track_id', $ipBlock->track_id)
            ->where('ip_address', $ipBlock->ip_address)
            ->update([
                'status' => $status,
                'last_error' => null,
            ]);

        $message = $status === 'whitelisted'
            ? 'IP whitelisted for this Track. Future blocks will skip it.'
            : 'IP exclusion removed.';

        return back()->with('success', $message);
    }
}
