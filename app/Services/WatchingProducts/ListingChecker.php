<?php

namespace App\Services\WatchingProducts;

use App\Enums\BrandBiddingStatus;
use App\Enums\ListingStatus;
use App\Models\WatchedProduct;
use App\Services\Pages\Cloner\PageCrawlerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ListingChecker
{
    public const SECONDARY_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    public function __construct(
        protected PageCrawlerService $crawler = new PageCrawlerService
    ) {}

    /**
     * Check public offer/sales page for active status, platform, and brand bidding rules.
     * Prevents false positive campaign pauses by confirming transient errors across multiple runs
     * and performing an immediate retry with a secondary user-agent.
     *
     * @return array{
     *     listing_status: ListingStatus,
     *     brand_bidding: ?BrandBiddingStatus,
     *     detected_platform: ?string,
     *     is_transient?: bool,
     *     consecutive_failures?: int,
     *     required_failures?: int,
     *     confirmed_down?: bool
     * }
     */
    public function check(WatchedProduct $product): array
    {
        $targetUrl = $product->listing_url ?: $product->sales_page_url;

        if (empty($targetUrl) || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            return [
                'listing_status' => $product->listing_status ?? ListingStatus::Unknown,
                'brand_bidding' => null,
                'detected_platform' => null,
                'is_transient' => false,
                'consecutive_failures' => 0,
                'required_failures' => 2,
                'confirmed_down' => false,
            ];
        }

        $cacheKey = "listing_transient_failures_{$product->id}";
        $requiredFailures = (int) config('flowhub.safety_rules.listing_down.consecutive_failures_required', config('watching.safety_rules.listing_down.consecutive_failures_required', 2));

        $html = '';
        $crawlResult = null;
        $isTransient = false;

        try {
            // Polite pause between requests to external hosts
            usleep(250000); // 250ms

            $crawlResult = $this->crawler->crawl($targetUrl);
            $html = strtolower($crawlResult['html'] ?? '');

            // Check if page returned a Cloudflare or bot challenge page
            if ($this->isCloudflareOrChallenge($html)) {
                $isTransient = true;

                // Immediate retry with secondary user agent
                usleep(300000); // 300ms
                try {
                    $retryResult = $this->crawler->crawl($targetUrl, forceBrowser: false, timeoutSeconds: 15, userAgent: self::SECONDARY_USER_AGENT);
                    $retryHtml = strtolower($retryResult['html'] ?? '');

                    if (! $this->isCloudflareOrChallenge($retryHtml) && trim($retryHtml) !== '') {
                        $html = $retryHtml;
                        $crawlResult = $retryResult;
                        $isTransient = false;
                    }
                } catch (Throwable $retryException) {
                    Log::info("ListingChecker: Secondary UA retry after challenge failed for '{$targetUrl}': {$retryException->getMessage()}");
                }
            }
        } catch (Throwable $e) {
            $isTransient = $this->isTransientError($e);

            if ($isTransient) {
                // Immediate retry with secondary user agent after a short delay
                try {
                    usleep(300000); // 300ms
                    $retryResult = $this->crawler->crawl($targetUrl, forceBrowser: false, timeoutSeconds: 15, userAgent: self::SECONDARY_USER_AGENT);
                    $retryHtml = strtolower($retryResult['html'] ?? '');

                    if (! $this->isCloudflareOrChallenge($retryHtml) && trim($retryHtml) !== '') {
                        $html = $retryHtml;
                        $crawlResult = $retryResult;
                        $isTransient = false;
                    }
                } catch (Throwable $retryException) {
                    $isTransient = true;
                    Log::info("ListingChecker: Secondary UA retry after error failed for '{$targetUrl}': {$retryException->getMessage()}");
                }
            } elseif ($this->isDefinitive404or410($e)) {
                Cache::forget($cacheKey);

                Log::info("ListingChecker: Definitive HTTP 404/410 dead link for product {$product->id} ('{$targetUrl}').");

                return [
                    'listing_status' => ListingStatus::Expired,
                    'brand_bidding' => null,
                    'detected_platform' => null,
                    'is_transient' => false,
                    'consecutive_failures' => 0,
                    'required_failures' => $requiredFailures,
                    'confirmed_down' => true,
                ];
            } else {
                // Unrecognized exception: treat conservatively as transient to prevent false positive pauses
                $isTransient = true;
            }
        }

        // Handle persistent transient failures (timeouts, 503s, bot challenges) across runs
        if ($isTransient) {
            $consecutiveFailures = (int) Cache::get($cacheKey, 0) + 1;
            Cache::put($cacheKey, $consecutiveFailures, now()->addHours(24));

            Log::warning("ListingChecker: Transient failure detected for product {$product->id} ('{$product->name}'). Consecutive failures: {$consecutiveFailures}/{$requiredFailures}.", [
                'target_url' => $targetUrl,
                'consecutive_failures' => $consecutiveFailures,
                'required_failures' => $requiredFailures,
            ]);

            if ($consecutiveFailures >= $requiredFailures) {
                return [
                    'listing_status' => ListingStatus::Expired,
                    'brand_bidding' => null,
                    'detected_platform' => null,
                    'is_transient' => true,
                    'consecutive_failures' => $consecutiveFailures,
                    'required_failures' => $requiredFailures,
                    'confirmed_down' => true,
                ];
            }

            // Within confirmation threshold: maintain current listing status without pausing campaign
            return [
                'listing_status' => $product->listing_status ?? ListingStatus::Active,
                'brand_bidding' => null,
                'detected_platform' => null,
                'is_transient' => true,
                'consecutive_failures' => $consecutiveFailures,
                'required_failures' => $requiredFailures,
                'confirmed_down' => false,
            ];
        }

        // Check if page content indicates expiration, takedown, or broken state
        $isExpired = $this->isExpiredHtml($html);

        if ($isExpired) {
            Cache::forget($cacheKey);

            return [
                'listing_status' => ListingStatus::Expired,
                'brand_bidding' => null,
                'detected_platform' => $crawlResult['detectedPlatform'] ?? null,
                'is_transient' => false,
                'consecutive_failures' => 0,
                'required_failures' => $requiredFailures,
                'confirmed_down' => true,
            ];
        }

        // Listing is active and healthy: clear consecutive failures counter
        Cache::forget($cacheKey);

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

        return [
            'listing_status' => ListingStatus::Active,
            'brand_bidding' => $brandBidding,
            'detected_platform' => $crawlResult['detectedPlatform'] ?? null,
            'is_transient' => false,
            'consecutive_failures' => 0,
            'required_failures' => $requiredFailures,
            'confirmed_down' => false,
        ];
    }

    /**
     * Detect Cloudflare, DDoS protection, or bot challenge pages.
     */
    public function isCloudflareOrChallenge(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        return str_contains($html, 'just a moment...')
            || str_contains($html, 'cf-browser-verification')
            || str_contains($html, 'cloudflare ray id')
            || str_contains($html, 'ddos-guard')
            || str_contains($html, 'verify you are human')
            || str_contains($html, 'enable javascript and cookies to continue')
            || str_contains($html, 'checking your browser before accessing')
            || str_contains($html, 'attention required! | cloudflare');
    }

    /**
     * Detect transient network timeouts, 502/503/504 errors, and connection drops.
     */
    public function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, '502')
            || str_contains($msg, '503')
            || str_contains($msg, '504')
            || str_contains($msg, '429')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'connection reset')
            || str_contains($msg, 'failed to connect')
            || str_contains($msg, 'could not resolve host')
            || str_contains($msg, 'name or service not known')
            || str_contains($msg, 'network is unreachable')
            || str_contains($msg, 'cloudflare');
    }

    /**
     * Detect definitive non-transient HTTP 404 or 410 dead link errors.
     */
    public function isDefinitive404or410(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, '404') || str_contains($msg, '410');
    }

    /**
     * Check if HTML body explicitly contains expiration / removal text.
     */
    public function isExpiredHtml(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        return str_contains($html, 'offer expired')
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
    }
}
