<?php

namespace App\Jobs;

use App\Models\Page;
use App\Services\Pages\Cloner\PageClonerService;
use App\Services\Pages\Cloner\PageCrawlerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ClonePageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public array $backoff = [15, 45];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $jobId,
        public array $payload,
    ) {}

    /**
     * Prevent duplicate concurrent executions for the same target page or URL.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $targetKey = (string) ($this->payload['page_id'] ?? $this->payload['url'] ?? $this->jobId);

        return [
            (new WithoutOverlapping($targetKey))
                ->releaseAfter(15)
                ->expireAfter(120),
        ];
    }

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

        if (! empty($this->payload['page_id'])) {
            Page::query()->where('id', $this->payload['page_id'])->update([
                'status' => 'processing',
                'error_message' => null,
            ]);
        }

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

            if (! empty($this->payload['page_id'])) {
                $page = Page::query()->find($this->payload['page_id']);
                if ($page) {
                    $page->update([
                        'status' => 'completed',
                        'error_message' => null,
                        'html' => $cloneResult['clonedHtml'],
                        'structure_json' => array_merge(
                            is_array($page->structure_json) ? $page->structure_json : [],
                            [
                                'status' => 'completed',
                                'renderedVia' => $crawlResult['renderedVia'],
                                'stats' => $cloneResult['stats'],
                                'links' => $cloneResult['links'],
                                'completed_at' => now()->toIso8601String(),
                            ]
                        ),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error("ClonePageJob failed for job {$this->jobId} (URL: {$this->payload['url']}): {$e->getMessage()}", [
                'job_id' => $this->jobId,
                'url' => $this->payload['url'] ?? null,
                'exception' => $e,
            ]);

            $userMessage = Str::limit($e->getMessage(), 250);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $userMessage,
                'failed_at' => now()->toIso8601String(),
            ], now()->addHours(2));

            if (! empty($this->payload['page_id'])) {
                $page = Page::query()->find($this->payload['page_id']);
                if ($page) {
                    $page->update([
                        'status' => 'failed',
                        'error_message' => $userMessage,
                        'structure_json' => array_merge(
                            is_array($page->structure_json) ? $page->structure_json : [],
                            [
                                'status' => 'failed',
                                'error' => $userMessage,
                                'failed_at' => now()->toIso8601String(),
                            ]
                        ),
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Handle a permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ClonePageJob permanently failed for job {$this->jobId} (URL: ".($this->payload['url'] ?? 'none')."): {$exception->getMessage()}", [
            'job_id' => $this->jobId,
            'payload' => $this->payload,
            'exception' => $exception,
        ]);

        $userMessage = Str::limit($exception->getMessage(), 250);
        $cacheKey = "clone_job:{$this->jobId}";

        Cache::put($cacheKey, [
            'status' => 'failed',
            'error' => $userMessage,
            'failed_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        if (! empty($this->payload['page_id'])) {
            $page = Page::query()->find($this->payload['page_id']);
            if ($page) {
                $page->update([
                    'status' => 'failed',
                    'error_message' => $userMessage,
                    'structure_json' => array_merge(
                        is_array($page->structure_json) ? $page->structure_json : [],
                        [
                            'status' => 'failed',
                            'error' => $userMessage,
                            'failed_at' => now()->toIso8601String(),
                        ]
                    ),
                ]);
            }
        }
    }
}
