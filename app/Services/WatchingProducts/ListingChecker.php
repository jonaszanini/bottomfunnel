<?php

namespace App\Services\WatchingProducts;

use App\Enums\BrandBiddingStatus;
use App\Enums\ListingStatus;
use App\Models\WatchedProduct;
use App\Services\Pages\Cloner\PageCrawlerService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ListingChecker
{
    public function __construct(
        protected PageCrawlerService $crawler = new PageCrawlerService
    ) {}

    /**
     * Check public offer/sales page for active status, platform, and brand bidding rules.
     *
     * @return array{listing_status: ListingStatus, brand_bidding: ?BrandBiddingStatus, detected_platform: ?string}
     */
    public function check(WatchedProduct $product): array
    {
        $targetUrl = $product->listing_url ?: $product->sales_page_url;

        if (empty($targetUrl) || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            return [
                'listing_status' => $product->listing_status ?? ListingStatus::Unknown,
                'brand_bidding' => null,
                'detected_platform' => null,
            ];
        }

        try {
            // Polite pause between requests to external hosts
            usleep(250000); // 250ms

            $crawlResult = $this->crawler->crawl($targetUrl);
            $html = strtolower($crawlResult['html'] ?? '');

            // Check if page indicates expiration, takedown, or broken state
            $isExpired = str_contains($html, 'offer expired')
                || str_contains($html, 'domain is for sale')
                || str_contains($html, 'this page is no longer available')
                || str_contains($html, 'campaign has ended')
                || str_contains($html, 'product is no longer available')
                || str_contains($html, 'offer is no longer available')
                || str_contains($html, 'this listing has ended')
                || str_contains($html, 'domain has expired')
                || str_contains($html, 'page not found')
                || str_contains($html, '404 not found')
                || str_contains($html, '404 error');

            $listingStatus = $isExpired ? ListingStatus::Expired : ListingStatus::Active;

            // Brand bidding detection in affiliate terms text
            $brandBidding = null;
            if (str_contains($html, 'no brand bidding')
                || str_contains($html, 'brand bidding is prohibited')
                || str_contains($html, 'banned: brand bidding')
                || str_contains($html, 'bidding on brand terms is not allowed')) {
                $brandBidding = BrandBiddingStatus::Banned;
            } elseif (str_contains($html, 'brand bidding is allowed')
                || str_contains($html, 'brand bidding permitted')) {
                $brandBidding = BrandBiddingStatus::Allowed;
            }

            $detectedPlatform = $crawlResult['detectedPlatform'] ?? null;

            return [
                'listing_status' => $listingStatus,
                'brand_bidding' => $brandBidding,
                'detected_platform' => $detectedPlatform,
            ];
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            $isBrokenOrUnavailable = str_contains($msg, '404')
                || str_contains($msg, '410')
                || str_contains($msg, '502')
                || str_contains($msg, '503')
                || str_contains($msg, 'could not resolve host')
                || str_contains($msg, 'failed to connect')
                || str_contains($msg, 'connection refused')
                || str_contains($msg, 'name or service not known')
                || str_contains($msg, 'nxdomain');

            $listingStatus = $isBrokenOrUnavailable ? ListingStatus::Expired : ($product->listing_status ?? ListingStatus::Unknown);

            Log::info("ListingChecker: Failed checking URL '{$targetUrl}': {$e->getMessage()}", [
                'is_unavailable' => $isBrokenOrUnavailable,
                'listing_status' => $listingStatus->value,
            ]);

            return [
                'listing_status' => $listingStatus,
                'brand_bidding' => null,
                'detected_platform' => null,
            ];
        }
    }
}
