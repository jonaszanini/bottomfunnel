<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ListingStatus;
use App\Enums\TrendsTrend;
use App\Jobs\ClonePageJob;
use App\Jobs\GenerateCopyJob;
use App\Jobs\RefreshSingleWatchedProductJob;
use App\Models\Page;
use App\Models\ProductScoreHistory;
use App\Models\Track;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\Copy\BaseCopyGeneratorService;
use App\Services\Copy\CopyCharacterValidator;
use App\Services\Copy\SalesPageScraperService;
use App\Services\Copy\VisionCopyRefinementService;
use App\Services\Pages\Cloner\PageClonerService;
use App\Services\Pages\Cloner\PageCrawlerService;
use App\Services\Pages\OpenAiPageGenerator;
use App\Services\Pages\VariationGeneratorService;
use App\Services\WatchingProducts\BofChecker;
use App\Services\WatchingProducts\ListingChecker;
use App\Services\WatchingProducts\NegativeKeywordGenerator;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\QuotaBudget;
use App\Services\WatchingProducts\RankService;
use App\Services\WatchingProducts\SerpApiClient;
use App\Support\CopyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackgroundJobsResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Track $track;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'job_tester',
        ]);

        $this->track = Track::query()->create([
            'product_name' => 'Keto Extreme Burn',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=tester&vendor=ketoext',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);
    }

    private function createPage(array $attributes = []): Page
    {
        return Page::query()->create(array_merge([
            'track_id' => $this->track->id,
            'name' => 'Keto Landing Page',
            'language' => 'en',
            'page_type' => 'cloned',
            'status' => 'completed',
            'error_message' => null,
            'structure_json' => [
                'cloned_url' => 'https://safe-target-offer.com/landing',
                'affiliate_link' => 'https://hop.clickbank.net/?aff=tester&vendor=ketoext',
            ],
            'html' => '<html><body>Initial Content</body></html>',
            'injected_script' => '<script>console.log("track");</script>',
        ], $attributes));
    }

    // =========================================================================
    // Scenario 1: Controller Dispatching and Queue Pushing
    // =========================================================================

    public function test_page_controller_crawl_and_clone_dispatches_clone_page_job(): void
    {
        Queue::fake();

        $page = $this->createPage([
            'status' => 'failed',
            'error_message' => 'Previous network drop',
        ]);

        $response = $this->actingAs($this->user)->postJson('/pages/crawl-and-clone', [
            'url' => 'https://safe-target-offer.com/sales',
            'affiliate_link' => 'https://hop.clickbank.net/?aff=tester&vendor=ketoext',
            'product_name' => 'Keto Extreme Burn',
            'page_id' => $page->id,
            'force_browser' => false,
            'strip_trackers' => true,
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        $page->refresh();
        $this->assertSame('processing', $page->status);
        $this->assertNull($page->error_message);

        Queue::assertPushed(ClonePageJob::class, function (ClonePageJob $job) use ($page): bool {
            return ($job->payload['page_id'] ?? null) === $page->id
                && ($job->payload['url'] ?? null) === 'https://safe-target-offer.com/sales'
                && ($job->payload['affiliate_link'] ?? null) === 'https://hop.clickbank.net/?aff=tester&vendor=ketoext';
        });
    }

    public function test_page_controller_retry_dispatches_clone_page_job_for_cloned_pages(): void
    {
        Queue::fake();

        $page = $this->createPage([
            'page_type' => 'cloned',
            'status' => 'failed',
            'error_message' => 'Timeout reached during crawl',
            'structure_json' => [
                'sourceUrl' => 'https://upstream-retry-target.com/offer',
                'affiliateUrl' => 'https://hop.clickbank.net/?aff=retry&vendor=ketoext',
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson("/pages/{$page->id}/retry");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'status' => 'processing',
            'page_id' => $page->id,
        ]);

        $page->refresh();
        $this->assertSame('processing', $page->status);
        $this->assertNull($page->error_message);

        Queue::assertPushed(ClonePageJob::class, function (ClonePageJob $job) use ($page): bool {
            return ($job->payload['page_id'] ?? null) === $page->id
                && ($job->payload['url'] ?? null) === 'https://upstream-retry-target.com/offer';
        });
    }

    public function test_copy_studio_controller_generate_base_dispatches_generate_copy_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/api/copy/generate-base', [
            'salesPageUrl' => 'https://safe-target-offer.com/product-sales',
            'manualContext' => 'High conversion keto dietary supplement for weight management.',
            'targetKeyword' => 'keto weight loss pills',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
            'message' => 'Base copy generation job queued.',
        ]);

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job): bool {
            return $job->type === GenerateCopyJob::TYPE_BASE_COPY
                && ($job->payload['salesPageUrl'] ?? null) === 'https://safe-target-offer.com/product-sales'
                && ($job->payload['targetKeyword'] ?? null) === 'keto weight loss pills';
        });
    }

    public function test_copy_studio_controller_refine_with_screenshot_dispatches_vision_refine_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/api/copy/refine-with-screenshot', [
            'screenshotBase64' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'currentHeadlines' => [
                ['id' => 'h_1', 'text' => 'Burn Fat Faster With Keto', 'category' => 'Benefit', 'isLocked' => false],
            ],
            'currentDescriptions' => [
                ['id' => 'd_1', 'text' => 'Support your healthy ketogenic diet routine today.', 'angle' => 'Benefit', 'isLocked' => false],
            ],
            'productSummary' => 'Keto dietary supplement',
            'detectedLanguage' => 'en',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job): bool {
            return $job->type === GenerateCopyJob::TYPE_VISION_REFINE
                && isset($job->payload['screenshotBase64'])
                && count($job->payload['currentHeadlines']) === 1;
        });
    }

    public function test_watched_product_controller_refresh_now_dispatches_refresh_single_product_job(): void
    {
        Queue::fake();

        $product = WatchedProduct::factory()->create([
            'name' => 'Metabolic Formula 360',
            'payout_amount' => 125.00,
        ]);

        $response = $this->actingAs($this->user)
            ->from("/watching-products/{$product->id}")
            ->post("/watching-products/{$product->id}/refresh-now");

        $response->assertRedirect("/watching-products/{$product->id}");
        $response->assertSessionHas('success');

        Queue::assertPushed(RefreshSingleWatchedProductJob::class, function (RefreshSingleWatchedProductJob $job) use ($product): bool {
            return $job->productId === $product->id && $job->forceFresh === true;
        });
    }

    // =========================================================================
    // Scenario 2: Job Execution and Service Mocking
    // =========================================================================

    public function test_clone_page_job_execution_transitions_page_to_completed_and_stores_output(): void
    {
        $page = $this->createPage([
            'status' => 'processing',
            'html' => '<html>Initial</html>',
        ]);

        $jobId = 'clone-exec-test-'.uniqid();
        $targetUrl = 'https://upstream-store.test/keto-deal';
        $affiliateUrl = 'https://hop.clickbank.net/?aff=mock&vendor=ketoext';

        $crawlerMock = Mockery::mock(PageCrawlerService::class);
        $crawlerMock->shouldReceive('crawl')
            ->once()
            ->with($targetUrl, false, 25)
            ->andReturn([
                'html' => '<!DOCTYPE html><html><head><title>Upstream Keto</title></head><body><h1>Upstream Keto Offer</h1></body></html>',
                'finalUrl' => 'https://upstream-store.test/keto-deal/final',
                'title' => 'Upstream Keto Offer',
                'description' => 'Fast acting keto formula',
                'image' => 'https://upstream-store.test/bottle.jpg',
                'priceRange' => '$49 - $199',
                'detectedPlatform' => 'clickbank',
                'renderedVia' => 'http',
            ]);

        $clonedHtml = '<!DOCTYPE html><html><head><title>Cloned Keto</title></head><body><h1>Cloned Keto</h1><a href="'.$affiliateUrl.'">Get Yours</a></body></html>';

        $clonerMock = Mockery::mock(PageClonerService::class);
        $clonerMock->shouldReceive('clone')
            ->once()
            ->with(Mockery::on(function (array $args) use ($affiliateUrl): bool {
                return $args['affiliateLink'] === $affiliateUrl
                    && $args['stripTrackers'] === true
                    && $args['optimizeImages'] === true;
            }))
            ->andReturn([
                'clonedHtml' => $clonedHtml,
                'stats' => [
                    'assetsRewritten' => 5,
                    'affiliateLinksReplaced' => 2,
                    'trackersStripped' => 3,
                ],
                'links' => [
                    ['originalUrl' => 'https://upstream-store.test/checkout', 'newUrl' => $affiliateUrl],
                ],
            ]);

        $job = new ClonePageJob($jobId, [
            'page_id' => $page->id,
            'url' => $targetUrl,
            'affiliate_link' => $affiliateUrl,
            'product_name' => 'Upstream Keto Offer',
            'strip_trackers' => true,
            'optimize_images' => true,
            'enable_param_pass_through' => true,
        ]);

        $job->handle($crawlerMock, $clonerMock);

        $page->refresh();
        $this->assertSame('completed', $page->status);
        $this->assertNull($page->error_message);
        $this->assertSame($clonedHtml, $page->html);
        $this->assertTrue($page->isCompleted());
        $this->assertFalse($page->isProcessing());
        $this->assertSame('completed', $page->structure_json['status'] ?? null);
        $this->assertSame('http', $page->structure_json['renderedVia'] ?? null);

        $cached = Cache::get("clone_job:{$jobId}");
        $this->assertIsArray($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertSame($clonedHtml, $cached['result']['clonedHtml']);
    }

    public function test_generate_copy_job_base_copy_validates_character_limits_and_cleans_copy(): void
    {
        $jobId = 'copy-base-test-'.uniqid();

        $baseCopyGeneratorMock = Mockery::mock(BaseCopyGeneratorService::class);
        $baseCopyGeneratorMock->shouldReceive('generateValidatedBaseAdCopy')
            ->once()
            ->andReturn([
                'adCopy' => [
                    'detectedLanguage' => 'en',
                    'productSummary' => 'Premium Keto supplement designed for metabolic support.',
                    'headlines' => [
                        [
                            'id' => 'h_1',
                            'text' => 'Official Keto Support',
                            'category' => 'Brand',
                            'isLocked' => false,
                            'charCount' => 21,
                            'isValid' => true,
                        ],
                        [
                            'id' => 'h_2',
                            'text' => 'Burn Fat Naturally & Safely',
                            'category' => 'Benefit',
                            'isLocked' => false,
                            'charCount' => 28,
                            'isValid' => true,
                        ],
                    ],
                    'descriptions' => [
                        [
                            'id' => 'd_1',
                            'text' => 'Unlock clean energy and support your daily fitness routine with our verified formula.',
                            'angle' => 'Benefit',
                            'isLocked' => false,
                            'charCount' => 86,
                            'isValid' => true,
                        ],
                    ],
                ],
                'usedScraperFallback' => false,
                'scraperWarning' => null,
                'scraped' => [
                    'pageTitle' => 'Keto Official Store',
                    'metaDescription' => 'Get the real deal.',
                    'headings' => ['Welcome'],
                    'bulletPoints' => ['100% natural'],
                    'guaranteeSignals' => ['60-day money back guarantee'],
                    'bonusAndOfferSignals' => ['Free shipping on 3 bottles'],
                ],
            ]);

        $job = GenerateCopyJob::forBaseCopy([
            'salesPageUrl' => 'https://safe-target-offer.com/product',
            'manualContext' => 'Keto supplement context',
            'targetKeyword' => 'keto diet',
        ], $jobId);

        $scraperMock = Mockery::mock(SalesPageScraperService::class);
        $visionMock = Mockery::mock(VisionCopyRefinementService::class);
        $openAiMock = Mockery::mock(OpenAiPageGenerator::class);
        $variationMock = Mockery::mock(VariationGeneratorService::class);

        $job->handle($scraperMock, $baseCopyGeneratorMock, $visionMock, $openAiMock, $variationMock);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        $this->assertIsArray($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertCount(2, $cached['result']['headlines']);
        $this->assertCount(1, $cached['result']['descriptions']);

        // Verify character constraints via CopyCharacterValidator rules
        $validator = new CopyCharacterValidator;
        foreach ($cached['result']['headlines'] as $h) {
            $this->assertLessThanOrEqual(
                CopyCharacterValidator::MAX_HEADLINE_CHARS,
                $validator->getExactCharLength($h['text'])
            );
        }
        foreach ($cached['result']['descriptions'] as $d) {
            $this->assertLessThanOrEqual(
                CopyCharacterValidator::MAX_DESCRIPTION_CHARS,
                $validator->getExactCharLength($d['text'])
            );
        }

        // Verify CopyGuard cleans forbidden terms like "buy now" into compliant wording
        $uncleanText = 'Special deal — buy now for huge discounts and cheaper prices.';
        $cleanText = CopyGuard::clean($uncleanText);
        $this->assertStringNotContainsString('buy now', strtolower($cleanText));
        $this->assertStringNotContainsString('discounts', strtolower($cleanText));
        $this->assertStringNotContainsString('—', $cleanText);
        $this->assertStringContainsString('take the next step', $cleanText);
        $this->assertStringContainsString('special conditions', $cleanText);
    }

    public function test_generate_copy_job_openai_page_persists_page_and_sets_status_completed(): void
    {
        $jobId = 'copy-openai-test-'.uniqid();

        $openAiGeneratorMock = Mockery::mock(OpenAiPageGenerator::class);
        $openAiGeneratorMock->shouldReceive('generate')
            ->once()
            ->with('Keto Fuel', 'presell', 'en', 'Highlight natural ingredients')
            ->andReturn([
                'structure' => [
                    'headline' => 'Keto Fuel Special Offer',
                    'subheadline' => 'Supercharge your ketosis journey',
                    'cta_text' => 'Claim Offer Now',
                ],
                'html' => '<!DOCTYPE html><html><body><h1>Keto Fuel Special Offer</h1></body></html>',
            ]);

        $job = GenerateCopyJob::forOpenAiPage([
            'track_id' => $this->track->id,
            'product_name' => 'Keto Fuel',
            'page_type' => 'presell',
            'language' => 'en',
            'instructions' => 'Highlight natural ingredients',
        ], $jobId);

        $scraperMock = Mockery::mock(SalesPageScraperService::class);
        $baseCopyGeneratorMock = Mockery::mock(BaseCopyGeneratorService::class);
        $visionMock = Mockery::mock(VisionCopyRefinementService::class);
        $variationMock = Mockery::mock(VariationGeneratorService::class);

        $job->handle($scraperMock, $baseCopyGeneratorMock, $visionMock, $openAiGeneratorMock, $variationMock);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        $this->assertIsArray($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertNotNull($cached['result']['page_id']);

        $createdPage = Page::query()->findOrFail($cached['result']['page_id']);
        $this->assertSame('completed', $createdPage->status);
        $this->assertNull($createdPage->error_message);
        $this->assertStringContainsString('Keto Fuel Special Offer', $createdPage->html);
        $this->assertSame($this->track->id, $createdPage->track_id);
    }

    public function test_refresh_single_watched_product_job_updates_score_and_records_history(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Apex Brain Nootropic',
            'score' => 42,
            'advertiser_count' => 1,
            'score_flags' => ['initial' => true],
        ]);

        $serpClientMock = Mockery::mock(SerpApiClient::class);
        $serpClientMock->shouldReceive('fetchProductData')
            ->once()
            ->with(Mockery::on(fn (WatchedProduct $p): bool => $p->id === $product->id), true, true)
            ->andReturn([
                'success' => true,
                'trends' => [
                    'trends_7d' => 88,
                    'trends_30d' => 82,
                    'trends_90d' => 79,
                    'trends_trend' => TrendsTrend::Rising,
                ],
                'serp' => [
                    'advertiser_count' => 6,
                ],
                'bof' => [
                    'bof_vpn_serp' => true,
                    'bof_image_match_pct' => 95,
                    'bof_translation' => false,
                ],
            ]);

        $bofCheckerMock = Mockery::mock(BofChecker::class);
        $bofCheckerMock->shouldReceive('detectTranslationPass')->byDefault()->andReturn(false);

        $listingCheckerMock = Mockery::mock(ListingChecker::class);
        $listingCheckerMock->shouldReceive('checkStatus')->byDefault()->andReturn(ListingStatus::Active);
        $this->app->instance(ListingChecker::class, $listingCheckerMock);

        $scoreServiceMock = Mockery::mock(PotentialScoreService::class);
        $scoreServiceMock->shouldReceive('score')
            ->once()
            ->with(Mockery::on(fn (WatchedProduct $p): bool => $p->id === $product->id))
            ->andReturn([
                'score' => 91,
                'breakdown' => [
                    'bof_name' => 20,
                    'demand' => 19,
                    'payout' => 15,
                    'margin_headroom' => 10,
                    'page_type' => 10,
                    'competition' => 9,
                    'stability' => 8,
                ],
                'flags' => [
                    'high_potential' => true,
                    'rising_trends' => true,
                ],
                'raw_score' => 91,
            ]);

        $negativeGenerator = $this->app->make(NegativeKeywordGenerator::class);
        $rankService = $this->app->make(RankService::class);
        $quotaBudget = $this->app->make(QuotaBudget::class);

        $job = new RefreshSingleWatchedProductJob($product->id, forceFresh: true);
        $job->handle(
            $serpClientMock,
            $bofCheckerMock,
            $negativeGenerator,
            $scoreServiceMock,
            $rankService,
            $quotaBudget
        );

        $product->refresh();
        $this->assertSame(91, $product->score);
        $this->assertSame(6, $product->advertiser_count);
        $this->assertEquals(88, $product->trends_7d);
        $this->assertSame(TrendsTrend::Rising, $product->trends_trend);
        $this->assertSame('completed', $product->score_flags['refresh_status'] ?? null);
        $this->assertNull($product->score_flags['refresh_error'] ?? null);
        $this->assertFalse($product->isRefreshProcessing());
        $this->assertFalse($product->isRefreshFailed());

        // Assert ProductScoreHistory record created
        $historyRecord = ProductScoreHistory::query()->where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($historyRecord);
        $this->assertSame(91, $historyRecord->score);
        $this->assertSame(20, $historyRecord->breakdown['bof_name'] ?? null);
        $this->assertSame('completed', $historyRecord->flags['refresh_status'] ?? null);
    }

    // =========================================================================
    // Scenario 3: Overlapping Protection and Failure Handling
    // =========================================================================

    public function test_jobs_configure_without_overlapping_middleware_keyed_by_identifier(): void
    {
        // 1. ClonePageJob: keyed by page_id or url
        $cloneJob = new ClonePageJob('clone-job-key-test', [
            'page_id' => 77,
            'url' => 'https://target.com/page',
        ]);
        $cloneMiddleware = $cloneJob->middleware();
        $this->assertNotEmpty($cloneMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $cloneMiddleware[0]);
        $this->assertSame('77', $cloneMiddleware[0]->key);

        // 2. GenerateCopyJob: keyed by type and target identifier
        $copyJob = new GenerateCopyJob(GenerateCopyJob::TYPE_BASE_COPY, [
            'salesPageUrl' => 'https://target.com/sales',
        ], 'copy-job-key-test');
        $copyMiddleware = $copyJob->middleware();
        $this->assertNotEmpty($copyMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $copyMiddleware[0]);
        $this->assertStringContainsString('https://target.com/sales', $copyMiddleware[0]->key);

        // 3. RefreshSingleWatchedProductJob: keyed by productId
        $refreshJob = new RefreshSingleWatchedProductJob(159);
        $refreshMiddleware = $refreshJob->middleware();
        $this->assertNotEmpty($refreshMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $refreshMiddleware[0]);
        $this->assertSame('159', $refreshMiddleware[0]->key);
    }

    public function test_clone_page_job_failed_method_recovers_page_from_processing_state(): void
    {
        $page = $this->createPage([
            'status' => 'processing',
            'error_message' => null,
        ]);

        $jobId = 'clone-failed-test-'.uniqid();
        $job = new ClonePageJob($jobId, [
            'page_id' => $page->id,
            'url' => 'https://upstream-timeout.test/product',
        ]);

        $timeoutException = new RuntimeException('Simulated timeout connecting to origin');
        $job->failed($timeoutException);

        $page->refresh();
        $this->assertSame('failed', $page->status);
        $this->assertTrue($page->isFailed());
        $this->assertFalse($page->isProcessing());
        $this->assertStringContainsString('Simulated timeout connecting to origin', (string) $page->error_message);
        $this->assertSame('Simulated timeout connecting to origin', $page->failureReason());

        $cached = Cache::get("clone_job:{$jobId}");
        $this->assertIsArray($cached);
        $this->assertSame('failed', $cached['status']);
        $this->assertStringContainsString('Simulated timeout connecting to origin', $cached['error']);
    }

    public function test_generate_copy_job_failed_method_recovers_page_from_processing_state(): void
    {
        $page = $this->createPage([
            'status' => 'processing',
            'error_message' => null,
        ]);

        $jobId = 'copy-failed-test-'.uniqid();
        $job = new GenerateCopyJob(
            GenerateCopyJob::TYPE_VARIATIONS,
            [
                'page_id' => $page->id,
                'productName' => 'Zenith Cleanse',
            ],
            $jobId
        );

        $timeoutException = new RuntimeException('Simulated timeout from OpenAI provider');
        $job->failed($timeoutException);

        $page->refresh();
        $this->assertSame('failed', $page->status);
        $this->assertTrue($page->isFailed());
        $this->assertFalse($page->isProcessing());
        $this->assertStringContainsString('Simulated timeout from OpenAI provider', (string) $page->error_message);

        $cached = Cache::get("generate_copy_job:{$jobId}");
        $this->assertIsArray($cached);
        $this->assertSame('failed', $cached['status']);
        $this->assertStringContainsString('Simulated timeout from OpenAI provider', $cached['error']);
    }

    public function test_refresh_single_watched_product_job_failed_method_recovers_product_from_processing_state(): void
    {
        $product = WatchedProduct::factory()->create([
            'name' => 'Apex Sleep Formula',
            'score_flags' => [
                'refresh_status' => 'processing',
                'refresh_started_at' => now()->toIso8601String(),
            ],
        ]);

        $job = new RefreshSingleWatchedProductJob($product->id);
        $timeoutException = new RuntimeException('Simulated timeout from SerpApi provider');
        $job->failed($timeoutException);

        $product->refresh();
        $this->assertSame('failed', $product->score_flags['refresh_status'] ?? null);
        $this->assertTrue($product->isRefreshFailed());
        $this->assertFalse($product->isRefreshProcessing());
        $this->assertStringContainsString('Simulated timeout from SerpApi provider', (string) $product->refreshError());
    }
}
