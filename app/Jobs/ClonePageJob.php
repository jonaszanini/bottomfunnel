<?php

namespace App\Jobs;

use App\Services\Pages\Cloner\PageClonerService;
use App\Services\Pages\Cloner\PageCrawlerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClonePageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $jobId,
        public array $payload,
    ) {}

    public function handle(PageCrawlerService $crawler, PageClonerService $cloner): void
    {
        $cacheKey = "clone_job:{$this->jobId}";

        // Idempotency check: if already completed, do not re-run
        $existing = Cache::get($cacheKey);
        if (is_array($existing) && ($existing['status'] ?? null) === 'completed') {
            Log::info("ClonePageJob for {$this->jobId} already completed. Skipping.");

            return;
        }

        Cache::put($cacheKey, [
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        try {
            $forceBrowser = (bool) ($this->payload['force_browser'] ?? false);
            $url = $this->payload['url'];
            $crawlResult = $crawler->crawl($url, $forceBrowser, 25);

            $cloneResult = $cloner->clone([
                'rawHtml' => $crawlResult['html'],
                'sourceUrl' => $crawlResult['finalUrl'],
                'affiliateLink' => $this->payload['affiliate_link'],
                'customHeadScripts' => $this->payload['custom_head'] ?? null,
                'customBodyScripts' => $this->payload['custom_body'] ?? null,
                'stripTrackers' => $this->payload['strip_trackers'] ?? true,
                'optimizeImages' => $this->payload['optimize_images'] ?? true,
                'enableParamPassThrough' => $this->payload['enable_param_pass_through'] ?? true,
            ]);

            $result = [
                'success' => true,
                'url' => $url,
                'finalUrl' => $crawlResult['finalUrl'],
                'productName' => $this->payload['product_name'] ?: ($crawlResult['title'] ?: 'Cloned Offer'),
                'metadata' => [
                    'title' => $crawlResult['title'],
                    'description' => $crawlResult['description'],
                    'image' => $crawlResult['image'],
                    'priceRange' => $crawlResult['priceRange'],
                    'detectedPlatform' => $crawlResult['detectedPlatform'],
                ],
                'renderedVia' => $crawlResult['renderedVia'],
                'clonedHtml' => $cloneResult['clonedHtml'],
                'stats' => $cloneResult['stats'],
                'links' => $cloneResult['links'],
            ];

            Cache::put($cacheKey, [
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now()->toIso8601String(),
            ], now()->addHours(2));
        } catch (Throwable $e) {
            Log::error("ClonePageJob failed for job {$this->jobId} (URL: {$this->payload['url']}): {$e->getMessage()}", [
                'job_id' => $this->jobId,
                'url' => $this->payload['url'] ?? null,
                'exception' => $e,
            ]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ], now()->addHours(2));

            throw $e;
        }
    }
}
