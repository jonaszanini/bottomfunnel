<?php

namespace App\Console\Commands;

use App\Models\WatchedProduct;
use App\Services\WatchingProducts\PotentialScoreService;
use App\Services\WatchingProducts\RankService;
use Illuminate\Console\Command;

class RescoreWatchingProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watching:rescore
                            {--dry-run : Print old/new scores without writing to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rescore and rerank all watching products from stored inputs (no external API calls)';

    /**
     * Execute the console command.
     */
    public function handle(PotentialScoreService $scoreService, RankService $rankService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $products = WatchedProduct::query()->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->info('No watching products to rescore.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn("[DRY RUN] Would rescore {$products->count()} products. No database writes.");
        } else {
            $this->info("Rescoring {$products->count()} watching products...");
        }

        $rows = [];

        foreach ($products as $product) {
            $oldScore = $product->score;
            $result = $isDryRun
                ? $scoreService->score($product)
                : $scoreService->persistScore($product);

            $rows[] = [
                $product->id,
                $product->name,
                $oldScore ?? 'N/A',
                $result['score'],
                $result['breakdown']['q'] ?? 'N/A',
                $result['breakdown']['v'] ?? 'N/A',
            ];
        }

        $this->table(['ID', 'Product', 'Old Score', 'New Score', 'Q', 'V'], $rows);

        if ($isDryRun) {
            $this->info('Dry run completed. No scores, flags, ranks, or statuses were written.');

            return self::SUCCESS;
        }

        $this->line('Recalculating ranks...');
        $rankService->recalculateRanks();
        $this->info('Rescore and rerank completed. Status and campaign fields were not modified.');

        return self::SUCCESS;
    }
}
