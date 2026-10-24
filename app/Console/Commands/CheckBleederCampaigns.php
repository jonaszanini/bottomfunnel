<?php

namespace App\Console\Commands;

use App\Services\Google\CampaignSafetyService;
use Illuminate\Console\Command;

class CheckBleederCampaigns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaigns:check-bleeders
                            {--dry-run : Simulate bleeder check without pausing campaigns}
                            {--suggest : Suggest pause in logs instead of mutating campaigns}
                            {--multiplier= : Spend to typical payout multiplier threshold}
                            {--days= : Evaluation window in days}
                            {--track= : Specific Track ID to evaluate}
                            {--min-clicks= : Minimum clicks threshold required before flagging bleeder}
                            {--postback-hours= : Attribution lag buffer window in hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect recent ad spend vs conversion revenue and auto-pause or flag bleeder campaigns with spend > 1.5x payout and 0 conversions';

    /**
     * Execute the console command.
     */
    public function handle(CampaignSafetyService $safetyService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $suggestOnly = (bool) $this->option('suggest');
        $multiplier = $this->option('multiplier') !== null ? (float) $this->option('multiplier') : null;
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $trackId = $this->option('track') !== null ? (int) $this->option('track') : null;
        $minClicks = $this->option('min-clicks') !== null ? (int) $this->option('min-clicks') : null;
        $postbackHours = $this->option('postback-hours') !== null ? (int) $this->option('postback-hours') : null;

        $this->info('=== Campaign Safety: Spend-without-Conversion Bleeder Check ===');
        if ($dryRun) {
            $this->warn('[DRY RUN MODE ENABLED] No Google Ads campaigns will be paused.');
        } elseif ($suggestOnly) {
            $this->warn('[SUGGESTION MODE ENABLED] Bleeders will only be flagged and logged without mutating campaigns.');
        }

        $results = $safetyService->checkBleeders(
            dryRun: $dryRun,
            spendMultiplier: $multiplier,
            windowDays: $days,
            suggestOnly: $suggestOnly,
            trackId: $trackId,
            minClicks: $minClicks,
            postbackBufferHours: $postbackHours
        );

        if (empty($results)) {
            $this->line('No active tracks with linked Google Ads campaigns found for evaluation.');

            return self::SUCCESS;
        }

        $headers = ['Track ID', 'Product Name', 'Spend (CAD)', 'Payout (CAD)', 'Threshold (CAD)', 'Clicks', 'Conversions', 'Bleeder?', 'Action Taken'];
        $rows = [];
        $bleederCount = 0;
        $postbackBufferCount = 0;

        foreach ($results as $eval) {
            if ($eval['is_bleeder']) {
                $bleederCount++;
            }
            if ($eval['action_taken'] === 'postback_buffer_held') {
                $postbackBufferCount++;
            }

            $rows[] = [
                $eval['track_id'],
                $eval['product_name'],
                number_format($eval['spend_cad'], 2),
                number_format($eval['payout_cad'], 2),
                number_format($eval['threshold_cad'], 2),
                $eval['clicks_count'] ?? 0,
                $eval['conversions_count'],
                $eval['is_bleeder'] ? 'YES' : 'No',
                $eval['action_taken'],
            ];
        }

        $this->table($headers, $rows);

        if ($postbackBufferCount > 0) {
            $this->warn("Postback buffer active for {$postbackBufferCount} track(s): recent clicks or pending postbacks occurred within buffer window; auto-pauses deferred.");
        }

        if ($bleederCount > 0) {
            $this->warn("Total bleeder tracks detected: {$bleederCount}");
        } else {
            $this->info('All evaluated campaigns are healthy within spend thresholds.');
        }

        return self::SUCCESS;
    }
}
