<?php

namespace App\Console\Commands;

use App\Services\Google\CampaignSafetyService;
use Illuminate\Console\Command;

class CheckListingDownCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaigns:check-listing-down
                            {--dry-run : Simulate check without pausing campaigns}
                            {--product= : Specific WatchedProduct ID to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect offer pages for products linked to active Google Ads campaigns and auto-pause if the listing is broken or expired';

    /**
     * Execute the console command.
     */
    public function handle(CampaignSafetyService $safetyService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $productId = $this->option('product') ? (int) $this->option('product') : null;

        $this->info('=== Campaign Safety: Listing-Down Check ===');
        if ($dryRun) {
            $this->warn('[DRY RUN MODE ENABLED] No Google Ads campaigns will be paused.');
        }

        $results = $safetyService->checkListingDown(dryRun: $dryRun, productId: $productId);

        if (empty($results)) {
            $this->line('No active products with linked Google Ads campaigns found to check.');

            return self::SUCCESS;
        }

        $rows = [];
        $pausedCount = 0;
        $alertCount = 0;
        $transientWarningCount = 0;

        foreach ($results as $item) {
            $actionsSummary = [];
            foreach ($item['paused_campaigns'] as $action) {
                $statusText = $action['action'];
                if ($statusText === 'paused' || $statusText === 'dry_run_pause') {
                    $pausedCount++;
                } elseif ($statusText === 'alert_raised') {
                    $alertCount++;
                }
                $actionsSummary[] = "Campaign {$action['campaign_id']}: {$statusText}";
            }

            if (($item['is_transient'] ?? false) && ($item['listing_status'] !== 'expired')) {
                $transientWarningCount++;
                $actionsSummary[] = "Transient warning ({$item['consecutive_failures']}/{$item['required_failures']} runs - held active)";
            }

            $rows[] = [
                $item['product_id'],
                $item['product_name'],
                $item['listing_status'],
                empty($actionsSummary) ? 'None (Active)' : implode(', ', $actionsSummary),
            ];
        }

        $this->table(['Product ID', 'Product Name', 'Listing Status', 'Campaign Actions'], $rows);

        if ($transientWarningCount > 0) {
            $this->warn("Transient errors detected on {$transientWarningCount} listing(s). Multi-step confirmation held campaign pauses.");
        }

        if ($alertCount > 0) {
            $this->warn("Total safety alerts raised: {$alertCount} (Campaigns NOT paused; auto-pause disabled)");
        }

        if ($pausedCount > 0) {
            $this->warn('Total campaigns '.($dryRun ? 'flagged to pause (dry run)' : 'paused').": {$pausedCount}");
        } elseif ($alertCount === 0 && $transientWarningCount === 0) {
            $this->info('All checked listings are active. No alerts or pauses required.');
        }

        return self::SUCCESS;
    }
}
