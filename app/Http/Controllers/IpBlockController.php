<?php

namespace App\Http\Controllers;

use App\Models\IpBlock;
use App\Services\Google\GoogleAdsClient;
use App\Services\Google\GoogleRequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IpBlockController extends Controller
{
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
