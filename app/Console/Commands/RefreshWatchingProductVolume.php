<?php

namespace App\Console\Commands;

use App\Enums\WatchedProductStatus;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\KeywordVolumeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshWatchingProductVolume extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watching:refresh-volume
                            {--id=* : Only refresh these specific product IDs}
                            {--stale-days=7 : Skip products checked within this many days}
                            {--dry-run : Print keywords and geos without making API calls or database writes}
                            {--limit= : Limit the number of products to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Google Keyword Planner monthly search volume for active watching products';

    /**
     * Execute the console command.
     */
    public function handle(KeywordVolumeService $volumeService): int
    {
        $ids = array_values(array_filter((array) $this->option('id')));
        $staleDays = (int) $this->option('stale-days');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $isDryRun = (bool) $this->option('dry-run');

        $query = WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected);

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        if ($staleDays > 0) {
            $query->where(function ($q) use ($staleDays) {
                $q->whereNull('search_volume_checked_at')
                    ->orWhere('search_volume_checked_at', '<=', now()->subDays($staleDays))
                    ->orWhereNotNull('search_volume_error');
            });
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('No watching products require search volume refresh.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[DRY RUN] Would refresh search volume for {$products->count()} products.");

            $headers = ['ID', 'Product Name', 'Target Keyword', 'Geos', 'Last Checked'];
            $rows = $products->map(fn (WatchedProduct $p) => [
                $p->id,
                $p->name,
                $p->effectiveSearchVolumeKeyword(),
                implode(', ', $p->searchVolumeGeos()),
                $p->search_volume_checked_at?->format('Y-m-d H:i') ?? 'Never',
            ])->toArray();

            $this->table($headers, $rows);
            $this->info('Dry run completed. No API calls or database updates were made.');

            return self::SUCCESS;
        }

        $account = $volumeService->resolveAccount();
        if (! $account) {
            $errorMsg = 'No active Google Ads account found with a refresh token. Volume refresh aborted.';
            $this->error($errorMsg);
            Log::error('watching:refresh-volume: '.$errorMsg);

            return self::FAILURE;
        }

        $this->info("Refreshing search volume for {$products->count()} products using account [{$account->customer_id}]...");

        try {
            $result = $volumeService->refreshProducts($products, $account);
        } catch (\Throwable $e) {
            $this->error("Search volume refresh failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $summary = "Volume refresh completed: {$result['updated']} updated, {$result['nulls']} nulls, {$result['ranges']} ranges, {$result['errors']} errors.";
        $this->info($summary);
        Log::info('watching:refresh-volume: '.$summary);

        if ($result['errors'] > 0) {
            $distinct = \App\Models\WatchedProduct::query()
                ->whereNotNull('search_volume_error')
                ->distinct()
                ->limit(5)
                ->pluck('search_volume_error');
            foreach ($distinct as $err) {
                $this->warn('Error: '.$err);
            }
        }

        return self::SUCCESS;
    }
}
