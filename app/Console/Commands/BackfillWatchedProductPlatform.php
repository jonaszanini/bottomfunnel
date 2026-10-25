<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WatchedProduct;
use Illuminate\Console\Command;

class BackfillWatchedProductPlatform extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watching:backfill-platform {--dry-run : Simulate the backfill without modifying database records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing platform for watched products based on existing platforms list or domain inference';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Running in DRY-RUN mode. No database records will be modified.');
        }

        // Target products where platform is empty or null
        $products = WatchedProduct::query()
            ->where(function ($q) {
                $q->whereNull('platform')->orWhere('platform', '');
            })
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->info('No watched products require platform backfill.');

            return self::SUCCESS;
        }

        $rows = [];
        $count = 0;

        foreach ($products as $product) {
            $oldPlatform = $product->platform ?: '(empty)';
            $platforms = (array) ($product->platforms ?? []);

            $newPlatform = null;
            $ruleApplied = null;

            // Rule A: platform empty and platforms has exactly one item -> use that item
            if (count($platforms) === 1 && ! empty($platforms[0])) {
                $newPlatform = (string) $platforms[0];
                $ruleApplied = 'Rule A (single platforms item)';
            } else {
                // Rule B: otherwise infer from host of sales_page_url or affiliate_link
                $inferred = self::inferFromUrl($product->sales_page_url)
                    ?? self::inferFromUrl($product->affiliate_link);

                if ($inferred !== null) {
                    $newPlatform = $inferred;
                    $ruleApplied = 'Rule B (inferred from domain)';
                } else {
                    // Rule C: if it can't be inferred -> 'Unknown'
                    $newPlatform = 'Unknown';
                    $ruleApplied = 'Rule C (uninferrable fallback)';
                }
            }

            // Sync platforms array
            $syncedPlatforms = $platforms;
            if (empty($syncedPlatforms)) {
                $syncedPlatforms = [$newPlatform];
            } elseif (! in_array($newPlatform, $syncedPlatforms, true)) {
                $syncedPlatforms[] = $newPlatform;
            }

            $rows[] = [
                'ID' => $product->id,
                'Name' => $product->name,
                'Old Value' => $oldPlatform,
                'New Value' => $newPlatform,
                'Rule Applied' => $ruleApplied,
            ];

            if (! $dryRun) {
                $product->platform = $newPlatform;
                $product->platforms = array_values(array_unique($syncedPlatforms));
                $product->save();
            }

            $count++;
        }

        $this->table(
            ['ID', 'Name', 'Old Value', 'New Value', 'Rule Applied'],
            $rows
        );

        if ($dryRun) {
            $this->info("[Dry Run] Evaluated {$count} watched product(s). No changes were written.");
        } else {
            $this->info("Successfully backfilled platform for {$count} watched product(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Infer platform name from a given URL host.
     */
    public static function inferFromUrl(?string $url): ?string
    {
        $host = self::extractHost($url);

        if ($host === null) {
            return null;
        }

        return self::inferFromHost($host);
    }

    /**
     * Extract normalized lowercase host from raw URL string.
     */
    public static function extractHost(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return strtolower(trim((string) $host));
    }

    /**
     * Map host domain to known platform name.
     */
    public static function inferFromHost(string $host): ?string
    {
        // clickbank.net / hop.clickbank.net / *.hop.clickbank.net = ClickBank
        if (
            $host === 'clickbank.net' ||
            str_ends_with($host, '.clickbank.net') ||
            $host === 'clickbank.com' ||
            str_ends_with($host, '.clickbank.com')
        ) {
            return 'ClickBank';
        }

        // digistore24.com = Digistore24
        if ($host === 'digistore24.com' || str_ends_with($host, '.digistore24.com')) {
            return 'Digistore24';
        }

        // buygoods.com = BuyGoods
        if ($host === 'buygoods.com' || str_ends_with($host, '.buygoods.com')) {
            return 'BuyGoods';
        }

        // maxweb.com = MaxWeb
        if ($host === 'maxweb.com' || str_ends_with($host, '.maxweb.com')) {
            return 'MaxWeb';
        }

        // affplus.com = Affplus
        if ($host === 'affplus.com' || str_ends_with($host, '.affplus.com')) {
            return 'Affplus';
        }

        // smartadv = SmartAdv
        if (str_contains($host, 'smartadv')) {
            return 'SmartAdv';
        }

        return null;
    }
}
