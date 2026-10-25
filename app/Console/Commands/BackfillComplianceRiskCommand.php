<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ComplianceRisk;
use App\Models\WatchedProduct;
use Illuminate\Console\Command;

class BackfillComplianceRiskCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watching:backfill-compliance {--dry-run : Simulate the backfill without modifying database records}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill watched products compliance_risk from low with empty notes to not_assessed (excluding Straivo)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] Simulating compliance risk backfill. No database records will be modified.');
        }

        $products = WatchedProduct::query()
            ->where('compliance_risk', 'low')
            ->where(function ($query) {
                $query->whereNull('compliance_notes')
                    ->orWhere('compliance_notes', '')
                    ->orWhereRaw("TRIM(compliance_notes) = ''");
            })
            ->orderBy('id')
            ->get();

        $rows = [];
        $toUpdate = [];

        foreach ($products as $product) {
            if (str_contains(strtolower($product->name), 'straivo')) {
                continue;
            }

            $oldValue = $product->compliance_risk instanceof ComplianceRisk
                ? $product->compliance_risk->value
                : (string) $product->compliance_risk;

            $newValue = ComplianceRisk::NotAssessed->value;

            $rows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ];

            $toUpdate[] = $product;
        }

        if (empty($rows)) {
            $this->info('No products require compliance risk backfill.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Old Value', 'New Value'], $rows);

        if ($dryRun) {
            $this->info(sprintf('DRY RUN: %d product(s) would be updated to not_assessed.', count($rows)));

            return self::SUCCESS;
        }

        foreach ($toUpdate as $product) {
            $product->compliance_risk = ComplianceRisk::NotAssessed;
            $product->save();
        }

        $this->info(sprintf('Successfully updated %d product(s) to not_assessed.', count($rows)));

        return self::SUCCESS;
    }
}
