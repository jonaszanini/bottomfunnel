<?php

namespace Tests\Feature;

use App\Enums\WatchedProductStatus;
use App\Jobs\ClonePageJob;
use App\Jobs\GenerateCopyJob;
use App\Jobs\RefreshSingleWatchedProductJob;
use App\Models\Track;
use App\Models\User;
use App\Models\WatchedProduct;
use App\Services\Copy\BaseCopyGeneratorService;
use App\Services\Copy\SalesPageScraperService;
use App\Services\Copy\VisionCopyRefinementService;
use App\Services\Pages\Cloner\PageClonerService;
use App\Services\Pages\Cloner\PageCrawlerService;
use App\Services\Pages\OpenAiPageGenerator;
use App\Services\Pages\VariationGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueuedHeavyOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
        ]);
    }

    /**
     * Requirement: Controller dispatches ClonePageJob instead of executing cloner/crawler inline.
     */
    public function test_crawl_and_clone_controller_dispatches_clone_page_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/pages/crawl-and-clone', [
            'url' => 'https://example-offer.com/landing',
            'affiliate_link' => 'https://example-offer.com/hop?aff=123',
            'product_name' => 'Test Product',
            'force_browser' => false,
            'strip_trackers' => true,
            'optimize_images' => true,
            'enable_param_pass_through' => true,
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        Queue::assertPushed(ClonePageJob::class, function (ClonePageJob $job) {
            return $job->payload['url'] === 'https://example-offer.com/landing'
                && $job->payload['affiliate_link'] === 'https://example-offer.com/hop?aff=123'
                && ! empty($job->jobId);
        });
    }

    /**
     * Requirement: Controller dispatches GenerateCopyJob for base copy generation.
     */
    public function test_copy_generate_base_controller_dispatches_generate_copy_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/api/copy/generate-base', [
            'salesPageUrl' => 'https://example-product.com',
            'manualContext' => 'Some context',
            'targetKeyword' => 'test keyword',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job) {
            return $job->type === GenerateCopyJob::TYPE_BASE_COPY
                && $job->payload['salesPageUrl'] === 'https://example-product.com';
        });
    }

    /**
     * Requirement: Controller dispatches GenerateCopyJob for vision screenshot refinement.
     */
    public function test_copy_refine_with_screenshot_controller_dispatches_generate_copy_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/api/copy/refine-with-screenshot', [
            'screenshotBase64' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            'currentHeadlines' => [
                ['id' => 'h1', 'text' => 'Official Site - Top Deal', 'category' => 'trust'],
            ],
            'currentDescriptions' => [
                ['id' => 'd1', 'text' => 'Buy directly from the official store with full warranty and fast shipping.', 'angle' => 'offer'],
            ],
            'productSummary' => 'Quality product overview',
            'detectedLanguage' => 'en',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job) {
            return $job->type === GenerateCopyJob::TYPE_VISION_REFINE
                && ! empty($job->payload['currentHeadlines']);
        });
    }

    /**
     * Requirement: Controller dispatches GenerateCopyJob for presell variations.
     */
    public function test_generate_variations_controller_dispatches_generate_copy_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)->postJson('/pages/generate-variations', [
            'strategyType' => 'cookie_popup',
            'productName' => 'Alpha Boost',
            'language' => 'en',
            'affiliateUrl' => 'https://example.com/aff',
            'seoEnabled' => true,
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'success' => true,
            'status' => 'pending',
        ]);

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job) {
            return $job->type === GenerateCopyJob::TYPE_VARIATIONS
                && $job->payload['strategyType'] === 'cookie_popup'
                && $job->payload['productName'] === 'Alpha Boost';
        });
    }

    /**
     * Requirement: Controller dispatches GenerateCopyJob for OpenAI page generation.
     */
    public function test_generate_page_controller_dispatches_generate_copy_job(): void
    {
        Queue::fake();

        $track = Track::query()->create([
            'product_name' => 'Existing Track Offer',
            'platform' => 'clickbank',
            'affiliate_url' => 'https://hop.clickbank.net/?aff=test&vendor=test',
            'page_type' => 'presell',
            'strategy' => 'flash_sale',
            'language' => 'en',
        ]);

        $response = $this->actingAs($this->user)->post('/pages/generate', [
            'track_id' => $track->id,
            'page_type' => 'presell',
            'product_name' => 'Weight Loss Miracle',
            'language' => 'en',
            'instructions' => 'Include testimonials',
        ]);

        $response->assertRedirect(route('pages.index'));
        $response->assertSessionHas('success', 'Page generation job queued.');

        Queue::assertPushed(GenerateCopyJob::class, function (GenerateCopyJob $job) use ($track) {
            return $job->type === GenerateCopyJob::TYPE_OPENAI_PAGE
                && $job->payload['track_id'] === $track->id
                && $job->payload['product_name'] === 'Weight Loss Miracle';
        });
    }

    /**
     * Requirement: Controller dispatches RefreshSingleWatchedProductJob on manual refresh.
     */
    public function test_refresh_now_controller_dispatches_refresh_single_watched_product_job(): void
    {
        Queue::fake();

        $product = WatchedProduct::factory()->create([
            'name' => 'Fimavyn Blood Sugar Formula',
            'status' => WatchedProductStatus::Watch,
            'payout_amount' => 120.00,
        ]);

        $response = $this->actingAs($this->user)->post("/watching-products/{$product->id}/refresh-now");

        $response->assertRedirect();
        Queue::assertPushed(RefreshSingleWatchedProductJob::class, function (RefreshSingleWatchedProductJob $job) use ($product) {
            return $job->productId === $product->id && $job->forceFresh === true;
        });
    }

    /**
     * Requirement: ClonePageJob executes services and caches completed status (idempotent).
     */
    public function test_clone_page_job_execution_and_idempotency(): void
    {
        $mockCrawler = Mockery::mock(PageCrawlerService::class);
        $mockCrawler->shouldReceive('crawl')
            ->once()
            ->andReturn([
                'url' => 'https://offer.com',
                'finalUrl' => 'https://offer.com/final',
                'html' => '<html><body><h1>Buy Now</h1></body></html>',
                'title' => 'Offer Title',
                'description' => 'Offer Description',
                'image' => 'https://offer.com/img.png',
                'priceRange' => '$49',
                'detectedPlatform' => 'ClickBank',
                'renderedVia' => 'http',
            ]);

        $mockCloner = Mockery::mock(PageClonerService::class);
        $mockCloner->shouldReceive('clone')
            ->once()
            ->andReturn([
                'clonedHtml' => '<html><body>Cloned HTML</body></html>',
                'stats' => ['rewrittenLinks' => 2],
                'links' => ['https://offer.com/hop'],
            ]);

        $jobId = 'test-clone-job-123';
        $payload = [
            'url' => 'https://offer.com',
            'affiliate_link' => 'https://hop.clickbank.net',
            'product_name' => 'Offer Title',
        ];

        $job = new ClonePageJob($jobId, $payload);
        $job->handle($mockCrawler, $mockCloner);

        $cached = Cache::get("clone_job:{$jobId}");
        $this->assertNotNull($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertSame('<html><body>Cloned HTML</body></html>', $cached['result']['clonedHtml']);

        // Idempotency: second execution should do nothing and not call crawler/cloner again
        $job->handle($mockCrawler, $mockCloner);
    }

    /**
     * Requirement: ClonePageJob logs failures and updates cache state.
     */
    public function test_clone_page_job_logs_failure(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'ClonePageJob failed for job');
            });

        $mockCrawler = Mockery::mock(PageCrawlerService::class);
        $mockCrawler->shouldReceive('crawl')
            ->once()
            ->andThrow(new RuntimeException('Connection timed out'));

        $mockCloner = Mockery::mock(PageClonerService::class);

        $jobId = 'test-clone-fail-123';
        $payload = ['url' => 'https://failing-offer.com', 'affiliate_link' => 'https://hop.link'];

        $job = new ClonePageJob($jobId, $payload);

        try {
            $job->handle($mockCrawler, $mockCloner);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $cached = Cache::get("clone_job:{$jobId}");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('Connection timed out', $cached['error']);
    }

    /**
     * Requirement: PageCrawlerService enforces hard timeout and cleans up temp directories.
     */
    public function test_page_crawler_service_enforces_hard_timeout_cap_and_cleans_temp_dir(): void
    {
        $crawler = new PageCrawlerService;

        // Verify hard timeout cap constant exists and is within safe range (<= 30s)
        $this->assertTrue(defined(PageCrawlerService::class.'::HARD_TIMEOUT_CAP_SECONDS'));
        $this->assertLessThanOrEqual(30, PageCrawlerService::HARD_TIMEOUT_CAP_SECONDS);

        // Verify cleanupTempDirectories cleans files inside storage_path('app/playwright_temp')
        $tempDir = storage_path('app/playwright_temp/test_run_dir');
        @mkdir($tempDir, 0777, true);
        file_put_contents($tempDir.'/dummy_file.txt', 'test content');

        $this->assertFileExists($tempDir.'/dummy_file.txt');

        $crawler->cleanupTempDirectories(storage_path('app/playwright_temp'));

        $this->assertFileDoesNotExist($tempDir.'/dummy_file.txt');
    }

    /**
     * Requirement: Job status polling endpoints return correct structure.
     */
    public function test_crawl_status_polling_endpoint(): void
    {
        $jobId = 'poll-test-job-456';
        Cache::put("clone_job:{$jobId}", [
            'status' => 'completed',
            'result' => [
                'success' => true,
                'clonedHtml' => '<div>Loaded</div>',
                'productName' => 'Polled Product',
            ],
        ], 300);

        $response = $this->actingAs($this->user)->getJson("/pages/crawl-and-clone/status/{$jobId}");

        $response->assertOk();
        $response->assertJson([
            'job_id' => $jobId,
            'status' => 'completed',
            'clonedHtml' => '<div>Loaded</div>',
        ]);
    }

    /**
     * Requirement: GenerateCopyJob execution for variations and idempotency.
     */
    public function test_generate_copy_job_variations_execution_and_idempotency(): void
    {
        $mockScraper = Mockery::mock(SalesPageScraperService::class);
        $mockBaseGenerator = Mockery::mock(BaseCopyGeneratorService::class);
        $mockVisionRefiner = Mockery::mock(VisionCopyRefinementService::class);
        $mockOpenAi = Mockery::mock(OpenAiPageGenerator::class);
        $mockVariationGenerator = Mockery::mock(VariationGeneratorService::class);

        $mockVariationGenerator->shouldReceive('generateVariations')
            ->once()
            ->andReturn([
                ['id' => 'v1', 'title' => 'Var 1'],
                ['id' => 'v2', 'title' => 'Var 2'],
            ]);

        $jobId = 'test-copy-variations-123';
        $job = GenerateCopyJob::forVariations(['productName' => 'Alpha'], $jobId);

        $job->handle(
            $mockScraper,
            $mockBaseGenerator,
            $mockVisionRefiner,
            $mockOpenAi,
            $mockVariationGenerator
        );

        $cached = Cache::get("generate_copy_job:{$jobId}");
        $this->assertNotNull($cached);
        $this->assertSame('completed', $cached['status']);
        $this->assertCount(2, $cached['result']['variations']);

        // Idempotency: second handle call should not invoke service again
        $job->handle(
            $mockScraper,
            $mockBaseGenerator,
            $mockVisionRefiner,
            $mockOpenAi,
            $mockVariationGenerator
        );
    }

    /**
     * Requirement: GenerateCopyJob logs failure and saves failed status.
     */
    public function test_generate_copy_job_logs_failure(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'GenerateCopyJob');
            });

        $mockScraper = Mockery::mock(SalesPageScraperService::class);
        $mockBaseGenerator = Mockery::mock(BaseCopyGeneratorService::class);
        $mockVisionRefiner = Mockery::mock(VisionCopyRefinementService::class);
        $mockOpenAi = Mockery::mock(OpenAiPageGenerator::class);
        $mockVariationGenerator = Mockery::mock(VariationGeneratorService::class);

        $mockVariationGenerator->shouldReceive('generateVariations')
            ->once()
            ->andThrow(new RuntimeException('API Key missing'));

        $jobId = 'test-copy-fail-456';
        $job = GenerateCopyJob::forVariations(['productName' => 'Fail'], $jobId);

        try {
            $job->handle(
                $mockScraper,
                $mockBaseGenerator,
                $mockVisionRefiner,
                $mockOpenAi,
                $mockVariationGenerator
            );
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('API Key missing', $e->getMessage());
        }

        $cached = Cache::get("generate_copy_job:{$jobId}");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('API Key missing', $cached['error']);
    }

    /**
     * Requirement: Copy status polling endpoint works.
     */
    public function test_copy_status_polling_endpoint(): void
    {
        $jobId = 'poll-copy-job-789';
        Cache::put("generate_copy_job:{$jobId}", [
            'status' => 'completed',
            'result' => [
                'success' => true,
                'headlines' => ['Head 1', 'Head 2'],
                'descriptions' => ['Desc 1'],
            ],
        ], 300);

        $response = $this->actingAs($this->user)->getJson("/api/copy/status/{$jobId}");

        $response->assertOk();
        $response->assertJson([
            'job_id' => $jobId,
            'status' => 'completed',
            'headlines' => ['Head 1', 'Head 2'],
        ]);
    }
}
