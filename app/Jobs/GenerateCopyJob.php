<?php

namespace App\Jobs;

use App\Models\Page;
use App\Models\Track;
use App\Services\Copy\BaseCopyGeneratorService;
use App\Services\Copy\SalesPageScraperService;
use App\Services\Copy\VisionCopyRefinementService;
use App\Services\Pages\OpenAiPageGenerator;
use App\Services\Pages\VariationGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class GenerateCopyJob implements ShouldQueue
{
    use Queueable;

    public const TYPE_BASE_COPY = 'base_copy';

    public const TYPE_VISION_REFINE = 'vision_refine';

    public const TYPE_OPENAI_PAGE = 'openai_page';

    public const TYPE_VARIATIONS = 'variations';

    public int $tries = 2;

    public int $timeout = 90;

    public array $backoff = [15, 45];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload,
        public ?string $jobId = null,
    ) {
        $this->jobId ??= (string) Str::uuid();
    }

    /**
     * Prevent duplicate concurrent executions for the same target record.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $key = (string) ($this->payload['page_id'] ?? $this->payload['track_id'] ?? $this->payload['salesPageUrl'] ?? $this->payload['productName'] ?? $this->jobId);

        return [
            (new WithoutOverlapping("copy:{$this->type}:{$key}"))
                ->releaseAfter(15)
                ->expireAfter(120),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forBaseCopy(array $payload, ?string $jobId = null): self
    {
        return new self(self::TYPE_BASE_COPY, $payload, $jobId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forVisionRefinement(array $payload, ?string $jobId = null): self
    {
        return new self(self::TYPE_VISION_REFINE, $payload, $jobId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forOpenAiPage(array $payload, ?string $jobId = null): self
    {
        return new self(self::TYPE_OPENAI_PAGE, $payload, $jobId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forVariations(array $payload, ?string $jobId = null): self
    {
        return new self(self::TYPE_VARIATIONS, $payload, $jobId);
    }

    public function handle(
        SalesPageScraperService $scraper,
        BaseCopyGeneratorService $baseCopyGenerator,
        VisionCopyRefinementService $visionRefiner,
        OpenAiPageGenerator $openAiGenerator,
        VariationGeneratorService $variationGenerator
    ): void {
        $cacheKey = "generate_copy_job:{$this->jobId}";

        // Idempotency check: if already completed, do not re-run
        $existing = Cache::get($cacheKey);
        if (is_array($existing) && ($existing['status'] ?? null) === 'completed') {
            Log::info("GenerateCopyJob {$this->type} for {$this->jobId} already completed. Skipping.");

            return;
        }

        Cache::put($cacheKey, [
            'status' => 'processing',
            'type' => $this->type,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        if (! empty($this->payload['page_id'])) {
            Page::query()->where('id', $this->payload['page_id'])->update([
                'status' => 'processing',
                'error_message' => null,
            ]);
        }

        try {
            $result = match ($this->type) {
                self::TYPE_BASE_COPY => $this->handleBaseCopy($baseCopyGenerator),
                self::TYPE_VISION_REFINE => $this->handleVisionRefine($visionRefiner),
                self::TYPE_OPENAI_PAGE => $this->handleOpenAiPage($openAiGenerator),
                self::TYPE_VARIATIONS => $this->handleVariations($variationGenerator),
                default => throw new InvalidArgumentException("Unsupported generation type: {$this->type}"),
            };

            Cache::put($cacheKey, [
                'status' => 'completed',
                'type' => $this->type,
                'result' => $result,
                'completed_at' => now()->toIso8601String(),
            ], now()->addHours(2));

            if (! empty($this->payload['page_id'])) {
                $page = Page::query()->find($this->payload['page_id']);
                if ($page) {
                    $page->update([
                        'status' => 'completed',
                        'error_message' => null,
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error("GenerateCopyJob ({$this->type}) failed for job {$this->jobId}: {$e->getMessage()}", [
                'job_id' => $this->jobId,
                'type' => $this->type,
                'exception' => $e,
            ]);

            $userMessage = Str::limit($e->getMessage(), 250);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'type' => $this->type,
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
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("GenerateCopyJob ({$this->type}) permanently failed for job {$this->jobId}: {$exception->getMessage()}", [
            'job_id' => $this->jobId,
            'type' => $this->type,
            'payload' => $this->payload,
            'exception' => $exception,
        ]);

        $userMessage = Str::limit($exception->getMessage(), 250);
        $cacheKey = "generate_copy_job:{$this->jobId}";

        Cache::put($cacheKey, [
            'status' => 'failed',
            'type' => $this->type,
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

    protected function handleBaseCopy(BaseCopyGeneratorService $generator): array
    {
        $raw = $generator->generateValidatedBaseAdCopy([
            'salesPageUrl' => trim($this->payload['salesPageUrl']),
            'manualContext' => $this->payload['manualContext'] ?? null,
            'targetKeyword' => $this->payload['targetKeyword'] ?? null,
        ]);

        return [
            'success' => true,
            'detectedLanguage' => $raw['adCopy']['detectedLanguage'],
            'productSummary' => $raw['adCopy']['productSummary'],
            'scraper' => [
                'isFallback' => $raw['usedScraperFallback'],
                'warning' => $raw['scraperWarning'],
                'title' => $raw['scraped']['pageTitle'],
                'metaDescription' => $raw['scraped']['metaDescription'],
                'headings' => $raw['scraped']['headings'],
                'bullets' => $raw['scraped']['bulletPoints'],
                'bulletPoints' => $raw['scraped']['bulletPoints'],
                'guaranteeSignals' => $raw['scraped']['guaranteeSignals'],
                'bonusSignals' => $raw['scraped']['bonusAndOfferSignals'],
                'bonusAndOfferSignals' => $raw['scraped']['bonusAndOfferSignals'],
            ],
            'headlines' => $raw['adCopy']['headlines'],
            'descriptions' => $raw['adCopy']['descriptions'],
        ];
    }

    protected function handleVisionRefine(VisionCopyRefinementService $refiner): array
    {
        return $refiner->refine(
            $this->payload['screenshotBase64'],
            $this->payload['currentHeadlines'],
            $this->payload['currentDescriptions'],
            $this->payload['productSummary'] ?? null,
            $this->payload['detectedLanguage'] ?? 'en'
        );
    }

    protected function handleOpenAiPage(OpenAiPageGenerator $generator): array
    {
        $track = Track::query()->findOrFail($this->payload['track_id']);
        $language = $this->payload['language'] ?? $track->language;

        $generated = $generator->generate(
            $this->payload['product_name'],
            $this->payload['page_type'],
            $language,
            $this->payload['instructions'] ?? null,
        );

        $track->update([
            'page_type' => $this->payload['page_type'],
            'language' => $language,
        ]);

        $page = $track->pages()->create([
            'name' => Str::limit($this->payload['product_name'].' '.config('flowhub.page_types.'.$this->payload['page_type']), 160, ''),
            'page_type' => $this->payload['page_type'],
            'status' => 'completed',
            'error_message' => null,
            'language' => $language,
            'structure_json' => $generated['structure'],
            'html' => $generated['html'],
            'injected_script' => '',
        ]);
        $page->update(['injected_script' => $track->scriptTag($page->id)]);

        return [
            'success' => true,
            'page_id' => $page->id,
            'track_id' => $track->id,
            'structure' => $generated['structure'],
            'html' => $generated['html'],
        ];
    }

    protected function handleVariations(VariationGeneratorService $generator): array
    {
        $variations = $generator->generateVariations($this->payload);

        return [
            'success' => true,
            'variations' => $variations,
        ];
    }
}
