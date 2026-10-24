<?php

namespace App\Services\Google;

use App\Enums\ListingStatus;
use App\Enums\WatchedProductStatus;
use App\Models\AdSpend;
use App\Models\CampaignAlert;
use App\Models\Conversion;
use App\Models\GoogleAdsCampaign;
use App\Models\Track;
use App\Models\Visit;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\ListingChecker;
use App\Services\WatchingProducts\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignSafetyService
{
    public function __construct(
        protected GoogleAdsClient $googleAds,
        protected ListingChecker $listingChecker
    ) {}

    /**
     * Check whether automated campaign pausing is explicitly enabled.
     * Defaults to false (alert-only mode).
     */
    public function isAutoPauseEnabled(): bool
    {
        return (bool) config('watching.auto_pause_enabled', config('flowhub.auto_pause_enabled', false));
    }

    /**
     * Record or update an active safety alert, avoiding redundant duplicate rows.
     * Deduplicates unresolved alerts for the same alert_type and campaign_id / track_id.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordAlert(array $data): CampaignAlert
    {
        $type = $data['type'] ?? 'general';
        $campaignId = ! empty($data['campaign_id']) ? (string) $data['campaign_id'] : null;
        $trackId = isset($data['track_id']) ? (int) $data['track_id'] : null;
        $productId = isset($data['product_id']) ? (int) $data['product_id'] : null;

        $existing = CampaignAlert::findOpenAlert($type, $campaignId, $trackId, $productId);

        if ($existing) {
            $existing->update([
                'product_name' => $data['product_name'] ?? $existing->product_name,
                'campaign_id' => $data['campaign_id'] ?? $existing->campaign_id,
                'account_id' => $data['account_id'] ?? $existing->account_id,
                'track_id' => $data['track_id'] ?? $existing->track_id,
                'product_id' => $data['product_id'] ?? $existing->product_id,
                'severity' => $data['severity'] ?? $existing->severity,
                'reason' => $data['reason'] ?? $existing->reason,
                'message' => $data['message'] ?? $existing->message,
                'spend_cad' => $data['spend_cad'] ?? $existing->spend_cad,
                'payout_cad' => $data['payout_cad'] ?? $existing->payout_cad,
                'threshold_cad' => $data['threshold_cad'] ?? $existing->threshold_cad,
                'window_days' => $data['window_days'] ?? $existing->window_days,
                'conversions_count' => $data['conversions_count'] ?? $existing->conversions_count,
                'action_taken' => $data['action_taken'] ?? $existing->action_taken,
                'details' => $data['details'] ?? $existing->details,
                'updated_at' => now(),
            ]);

            return $existing;
        }

        return CampaignAlert::query()->create($data);
    }

    /**
     * Inspect offer pages for WatchedProducts linked to active tracks/campaigns.
     * Quota-safe: only crawls products that have active linked Google Ads campaigns.
     *
     * @return list<array{
     *     product_id: int,
     *     product_name: string,
     *     listing_status: string,
     *     is_transient: bool,
     *     consecutive_failures: int,
     *     required_failures: int,
     *     paused_campaigns: list<array{
     *         track_id: int,
     *         campaign_id: string,
     *         account_id: int,
     *         action: string,
     *         reason: string,
     *         error?: string
     *     }>
     * }>
     */
    public function checkListingDown(bool $dryRun = false, ?int $productId = null): array
    {
        $products = $this->resolveProductsToCheck($productId);
        $results = [];

        foreach ($products as $product) {
            $check = $this->listingChecker->check($product);
            $listingStatus = $check['listing_status'] ?? ListingStatus::Unknown;
            $isTransient = $check['is_transient'] ?? false;
            $consecutive = $check['consecutive_failures'] ?? 0;
            $required = $check['required_failures'] ?? 2;
            $confirmedDown = $check['confirmed_down'] ?? ($listingStatus === ListingStatus::Expired);

            $pausedCampaigns = [];
            if ($listingStatus === ListingStatus::Expired && $confirmedDown) {
                // Update product listing status in database
                $product->listing_status = ListingStatus::Expired;
                $product->saveQuietly();

                $pausedCampaigns = $this->pauseCampaignsForProduct(
                    $product,
                    dryRun: $dryRun,
                    reason: $isTransient
                        ? "Offer page persistent connection failure ({$consecutive}/{$required} consecutive runs failed)."
                        : 'Offer page returned broken status or expired listing content.'
                );
            } elseif ($listingStatus === ListingStatus::Active && $product->listing_status !== ListingStatus::Active) {
                $product->listing_status = ListingStatus::Active;
                $product->saveQuietly();
            }

            $results[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'listing_status' => $listingStatus->value,
                'is_transient' => $isTransient,
                'consecutive_failures' => $consecutive,
                'required_failures' => $required,
                'paused_campaigns' => $pausedCampaigns,
            ];
        }

        return $results;
    }

    /**
     * Pause all Google Ads campaigns explicitly linked to a WatchedProduct via Track.
     *
     * @return list<array{
     *     track_id: int,
     *     campaign_id: string,
     *     account_id: int,
     *     action: string,
     *     reason: string,
     *     error?: string
     * }>
     */
    public function pauseCampaignsForProduct(
        WatchedProduct $product,
        bool $dryRun = false,
        string $reason = 'listing_expired'
    ): array {
        $tracks = Track::query()
            ->with('account')
            ->get()
            ->filter(fn (Track $track): bool => strtolower(trim((string) $track->product_name)) === strtolower(trim($product->name)));

        $actions = [];

        foreach ($tracks as $track) {
            $account = $track->account;
            $campaignIds = array_values(array_filter((array) ($track->campaign_ids ?? [])));

            if (! $account || empty($campaignIds)) {
                continue;
            }

            foreach ($campaignIds as $campaignId) {
                $campaignId = (string) $campaignId;

                // When auto_pause_enabled is false (default), only raise alert!
                if (! $this->isAutoPauseEnabled()) {
                    $alert = $this->recordAlert([
                        'type' => 'listing_down',
                        'product_name' => $product->name,
                        'product_id' => $product->id,
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'severity' => 'danger',
                        'reason' => 'Listing unavailable',
                        'message' => "Listing unavailable for product '{$product->name}' (Campaign ID: {$campaignId}, Track ID: {$track->id}). Offer page returned broken status or expired listing content. Auto-pause is disabled; campaign was not paused.",
                        'action_taken' => 'alert_raised',
                        'details' => [
                            'product_name' => $product->name,
                            'product_id' => $product->id,
                            'track_id' => $track->id,
                            'campaign_id' => $campaignId,
                            'reason' => 'Listing unavailable',
                            'auto_pause_enabled' => false,
                        ],
                    ]);

                    Log::warning("CampaignSafety: [ALERT RAISED] Listing unavailable for product '{$product->name}' (Campaign {$campaignId}, Track {$track->id}). Auto-pause disabled; no campaign was paused.", [
                        'product_id' => $product->id,
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'reason' => 'Listing unavailable',
                        'alert_id' => $alert->id,
                    ]);

                    $actions[] = [
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'action' => 'alert_raised',
                        'reason' => 'Listing unavailable',
                    ];

                    continue;
                }

                if ($dryRun) {
                    Log::info("CampaignSafety: [DRY RUN] Would pause Google Ads campaign {$campaignId} on account {$account->id} for product '{$product->name}'. Reason: {$reason}");

                    $actions[] = [
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'action' => 'dry_run_pause',
                        'reason' => $reason,
                    ];

                    continue;
                }

                try {
                    $this->googleAds->updateCampaignStatus($account, $campaignId, 'PAUSED');

                    GoogleAdsCampaign::query()
                        ->where('campaign_id', $campaignId)
                        ->update(['status' => 'PAUSED']);

                    Log::warning("CampaignSafety: Paused Google Ads campaign {$campaignId} on account {$account->id} for product '{$product->name}'. Reason: {$reason}", [
                        'product_id' => $product->id,
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'customer_id' => $account->customer_id,
                        'reason' => $reason,
                    ]);

                    $this->recordAlert([
                        'type' => 'listing_down',
                        'product_name' => $product->name,
                        'product_id' => $product->id,
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'severity' => 'danger',
                        'reason' => 'Listing unavailable',
                        'message' => "Paused Google Ads campaign {$campaignId} on account {$account->id} for product '{$product->name}'. Reason: {$reason}",
                        'action_taken' => 'paused',
                    ]);

                    $actions[] = [
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'action' => 'paused',
                        'reason' => $reason,
                    ];
                } catch (Throwable $e) {
                    Log::error("CampaignSafety: Failed pausing Google Ads campaign {$campaignId} on account {$account->id}: {$e->getMessage()}", [
                        'product_id' => $product->id,
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'error' => $e->getMessage(),
                    ]);

                    $actions[] = [
                        'track_id' => $track->id,
                        'campaign_id' => $campaignId,
                        'account_id' => $account->id,
                        'action' => 'failed',
                        'reason' => $reason,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $actions;
    }

    /**
     * Check for spend-without-conversion bleeder campaigns and pause or flag them.
     * Rule: Spend > 1.5× typical payout in window (default 7 days), zero conversions,
     * and traffic meets minimum click threshold (e.g. 20+ clicks). Defers auto-pause
     * when traffic or pending postbacks occurred within the postback buffer window.
     *
     * @return list<array{
     *     track_id: int,
     *     product_name: string,
     *     account_id: ?int,
     *     spend_cad: float,
     *     payout_cad: float,
     *     threshold_cad: float,
     *     clicks_count: int,
     *     conversions_count: int,
     *     is_bleeder: bool,
     *     postback_buffer_active: bool,
     *     action_taken: string,
     *     campaigns: list<array{campaign_id: string, action: string, error?: string}>
     * }>
     */
    public function checkBleeders(
        bool $dryRun = false,
        ?float $spendMultiplier = null,
        ?int $windowDays = null,
        bool $suggestOnly = false,
        ?int $trackId = null,
        ?int $minClicks = null,
        ?int $postbackBufferHours = null
    ): array {
        $windowDays ??= (int) config('flowhub.safety_rules.bleeder.window_days', config('watching.safety_rules.bleeder.window_days', 7));
        $spendMultiplier ??= (float) config('flowhub.safety_rules.bleeder.spend_payout_multiplier', config('watching.safety_rules.bleeder.spend_payout_multiplier', 1.5));
        $minClicks ??= (int) config('flowhub.safety_rules.bleeder.min_clicks', config('watching.safety_rules.bleeder.min_clicks', 20));
        $postbackBufferHours ??= (int) config('flowhub.safety_rules.bleeder.postback_buffer_hours', config('watching.safety_rules.bleeder.postback_buffer_hours', 3));
        $configuredAction = config('flowhub.safety_rules.bleeder.action', config('watching.safety_rules.bleeder.action', 'alert'));

        $effectiveAction = ($suggestOnly || $configuredAction === 'suggest') ? 'suggest' : ($configuredAction === 'pause' ? 'pause' : 'alert');

        $from = now()->subDays($windowDays)->startOfDay();
        $to = now()->endOfDay();

        $tracksQuery = Track::query()->with('account');
        if ($trackId !== null) {
            $tracksQuery->where('id', $trackId);
        }
        $tracks = $tracksQuery->get();

        $evaluations = [];

        foreach ($tracks as $track) {
            $account = $track->account;
            $campaignIds = array_values(array_filter((array) ($track->campaign_ids ?? [])));

            // Safety rule: only act on tracks with linked Google Ads accounts and explicitly linked campaigns
            if (! $account || empty($campaignIds)) {
                continue;
            }

            // 1. Calculate AdSpend in window (normalized to CAD via Money.php)
            $spendRows = AdSpend::query()
                ->where('track_id', $track->id)
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->get();

            $totalSpendCad = 0.0;
            foreach ($spendRows as $spendRow) {
                $totalSpendCad += Money::make((float) $spendRow->amount_cad, 'CAD')->toCad();
            }
            $totalSpendCad = round($totalSpendCad, 2);

            // Fallback: if no AdSpend rows recorded, inspect linked WatchedProduct spend_amount
            if ($totalSpendCad <= 0) {
                $linkedProduct = WatchedProduct::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($track->product_name))])
                    ->first();

                if ($linkedProduct && (float) $linkedProduct->spend_amount > 0) {
                    $totalSpendCad = round(
                        Money::make($linkedProduct->spend_amount, $linkedProduct->spend_currency ?: 'CAD')->toCad(),
                        2
                    );
                }
            }

            // 2. Count clicks in window
            $clicksCount = Visit::query()
                ->where('track_id', $track->id)
                ->whereBetween('landed_at', [$from, $to])
                ->count();

            // 3. Count non-refunded conversions in window
            $conversions = Conversion::query()
                ->where('track_id', $track->id)
                ->where('created_at', '>=', $from)
                ->whereNotIn('status', ['unmatched', 'refunded', 'chargeback'])
                ->get();

            $conversionCount = $conversions->count();

            // 4. Resolve typical payout in CAD via Money.php
            $typicalPayoutCad = (float) $track->typical_commission_cad;
            if ($typicalPayoutCad > 0) {
                $typicalPayoutCad = round(Money::make($typicalPayoutCad, 'CAD')->toCad(), 2);
            } else {
                $linkedProduct ??= WatchedProduct::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($track->product_name))])
                    ->first();

                if ($linkedProduct) {
                    if ((float) $linkedProduct->payout_cad > 0) {
                        $typicalPayoutCad = round(Money::make((float) $linkedProduct->payout_cad, 'CAD')->toCad(), 2);
                    } elseif ((float) $linkedProduct->payout_amount > 0) {
                        $typicalPayoutCad = round(Money::make((float) $linkedProduct->payout_amount, $linkedProduct->payout_currency ?: 'USD')->toCad(), 2);
                    }
                }
            }

            // If typical payout is unknown or 0, we cannot reliably compute payout threshold
            if ($typicalPayoutCad <= 0) {
                Log::info("CampaignSafety: Track {$track->id} ('{$track->product_name}') has no typical payout configured; skipping bleeder check.");

                continue;
            }

            $spendThresholdCad = round($typicalPayoutCad * $spendMultiplier, 2);

            // Bleeder evaluation: spend > threshold CAD, 0 conversions, AND clicks >= min_clicks
            $meetsSpend = $totalSpendCad > $spendThresholdCad;
            $hasZeroConversions = $conversionCount === 0;
            $meetsClicks = ($minClicks <= 0) || ($clicksCount >= $minClicks);

            $isBleeder = $meetsSpend && $hasZeroConversions && $meetsClicks;

            // 5. Attribution lag / Postback buffer check
            // Check if there are active clicks or pending/unmatched conversions within postbackBufferHours
            $bufferCutoff = now()->subHours($postbackBufferHours);
            $hasPendingPostbackConversions = Conversion::query()
                ->where('track_id', $track->id)
                ->where('created_at', '>=', $bufferCutoff)
                ->whereIn('status', ['unmatched', 'pending'])
                ->exists();

            $recentClicksCount = Visit::query()
                ->where('track_id', $track->id)
                ->where('landed_at', '>=', $bufferCutoff)
                ->count();

            $isPostbackBufferActive = $hasPendingPostbackConversions || ($recentClicksCount > 0);

            $actionTaken = 'none';
            $campaignResults = [];

            if ($isBleeder) {
                if ($suggestOnly || $effectiveAction === 'suggest') {
                    $actionTaken = 'suggest_pause';

                    Log::warning("CampaignSafety [SUGGESTED PAUSE]: Bleeder detected for track {$track->id} ('{$track->product_name}'). Spend CAD: {$totalSpendCad} exceeds {$spendMultiplier}× payout threshold ({$spendThresholdCad} CAD) with 0 conversions across {$clicksCount} clicks in {$windowDays}d window. Suggested action: PAUSE campaign(s).", [
                        'track_id' => $track->id,
                        'product_name' => $track->product_name,
                        'spend_cad' => $totalSpendCad,
                        'payout_cad' => $typicalPayoutCad,
                        'threshold_cad' => $spendThresholdCad,
                        'clicks' => $clicksCount,
                        'conversions' => 0,
                        'window_days' => $windowDays,
                        'campaign_ids' => $campaignIds,
                    ]);

                    foreach ($campaignIds as $campaignId) {
                        $campaignResults[] = [
                            'campaign_id' => (string) $campaignId,
                            'action' => 'suggest_pause',
                        ];
                    }
                } elseif (! $this->isAutoPauseEnabled()) {
                    $actionTaken = 'alert_raised';

                    foreach ($campaignIds as $campaignId) {
                        $campaignId = (string) $campaignId;
                        $this->recordAlert([
                            'type' => 'bleeder',
                            'product_name' => $track->product_name,
                            'track_id' => $track->id,
                            'campaign_id' => $campaignId,
                            'account_id' => $account->id,
                            'severity' => 'warning',
                            'reason' => 'Spend without conversion',
                            'message' => "Bleeder detected for track {$track->id} ('{$track->product_name}'): Spend CA\${$totalSpendCad} exceeds {$spendMultiplier}× typical payout threshold (CA\${$spendThresholdCad}, payout reference CA\${$typicalPayoutCad}) with 0 conversions across {$clicksCount} clicks in {$windowDays}d window. Auto-pause is disabled; alert raised without mutating campaign status.",
                            'spend_cad' => $totalSpendCad,
                            'payout_cad' => $typicalPayoutCad,
                            'threshold_cad' => $spendThresholdCad,
                            'window_days' => $windowDays,
                            'conversions_count' => 0,
                            'action_taken' => 'alert_raised',
                            'details' => [
                                'spend_cad' => $totalSpendCad,
                                'payout_cad' => $typicalPayoutCad,
                                'threshold_cad' => $spendThresholdCad,
                                'window_days' => $windowDays,
                                'clicks' => $clicksCount,
                                'conversions' => 0,
                                'campaign_ids' => $campaignIds,
                                'auto_pause_enabled' => false,
                                'postback_buffer_active' => $isPostbackBufferActive,
                            ],
                        ]);

                        $campaignResults[] = [
                            'campaign_id' => $campaignId,
                            'action' => 'alert_raised',
                        ];
                    }
                } elseif ($isPostbackBufferActive) {
                    // Auto-pause is ENABLED, but recent traffic or pending conversions fall within the postback buffer window
                    $actionTaken = 'postback_buffer_held';

                    Log::warning("CampaignSafety [POSTBACK BUFFER HELD]: Bleeder threshold met for track {$track->id} ('{$track->product_name}'), but {$recentClicksCount} clicks or pending postbacks occurred in the {$postbackBufferHours}h attribution lag buffer. Auto-pause held pending attribution window completion.", [
                        'track_id' => $track->id,
                        'recent_clicks' => $recentClicksCount,
                        'buffer_hours' => $postbackBufferHours,
                    ]);

                    foreach ($campaignIds as $campaignId) {
                        $campaignId = (string) $campaignId;
                        $this->recordAlert([
                            'type' => 'bleeder',
                            'product_name' => $track->product_name,
                            'track_id' => $track->id,
                            'campaign_id' => $campaignId,
                            'account_id' => $account->id,
                            'severity' => 'warning',
                            'reason' => 'Spend without conversion (postback buffer active)',
                            'message' => "Bleeder detected for track {$track->id} ('{$track->product_name}'): Spend CA\${$totalSpendCad} exceeded threshold CA\${$spendThresholdCad}, but {$recentClicksCount} clicks occurred within the {$postbackBufferHours}h postback delay buffer. Auto-pause held pending postback arrival.",
                            'spend_cad' => $totalSpendCad,
                            'payout_cad' => $typicalPayoutCad,
                            'threshold_cad' => $spendThresholdCad,
                            'window_days' => $windowDays,
                            'conversions_count' => 0,
                            'action_taken' => 'postback_buffer_held',
                            'details' => [
                                'spend_cad' => $totalSpendCad,
                                'payout_cad' => $typicalPayoutCad,
                                'threshold_cad' => $spendThresholdCad,
                                'window_days' => $windowDays,
                                'clicks' => $clicksCount,
                                'recent_clicks' => $recentClicksCount,
                                'postback_buffer_hours' => $postbackBufferHours,
                                'postback_buffer_active' => true,
                            ],
                        ]);

                        $campaignResults[] = [
                            'campaign_id' => $campaignId,
                            'action' => 'postback_buffer_held',
                        ];
                    }
                } elseif ($dryRun) {
                    $actionTaken = 'dry_run_pause';

                    Log::info("CampaignSafety: [DRY RUN] Would pause bleeder campaigns for track {$track->id} ('{$track->product_name}'). Spend CAD: {$totalSpendCad} > Threshold CAD: {$spendThresholdCad} with 0 conversions across {$clicksCount} clicks in {$windowDays}d.", [
                        'track_id' => $track->id,
                        'campaign_ids' => $campaignIds,
                    ]);

                    foreach ($campaignIds as $campaignId) {
                        $campaignResults[] = [
                            'campaign_id' => (string) $campaignId,
                            'action' => 'dry_run_pause',
                        ];
                    }
                } else {
                    $actionTaken = 'paused';

                    foreach ($campaignIds as $campaignId) {
                        $campaignId = (string) $campaignId;

                        try {
                            $this->googleAds->updateCampaignStatus($account, $campaignId, 'PAUSED');

                            GoogleAdsCampaign::query()
                                ->where('campaign_id', $campaignId)
                                ->update(['status' => 'PAUSED']);

                            Log::warning("CampaignSafety: Paused bleeder Google Ads campaign {$campaignId} on account {$account->id} for track {$track->id} ('{$track->product_name}'). Spend CAD: {$totalSpendCad} > Threshold CAD: {$spendThresholdCad} with 0 conversions across {$clicksCount} clicks in {$windowDays}d.", [
                                'track_id' => $track->id,
                                'product_name' => $track->product_name,
                                'campaign_id' => $campaignId,
                                'spend_cad' => $totalSpendCad,
                                'payout_cad' => $typicalPayoutCad,
                                'threshold_cad' => $spendThresholdCad,
                                'conversions' => 0,
                                'clicks' => $clicksCount,
                                'window_days' => $windowDays,
                            ]);

                            $this->recordAlert([
                                'type' => 'bleeder',
                                'product_name' => $track->product_name,
                                'track_id' => $track->id,
                                'campaign_id' => $campaignId,
                                'account_id' => $account->id,
                                'severity' => 'danger',
                                'reason' => 'Spend without conversion',
                                'message' => "Paused bleeder Google Ads campaign {$campaignId} for track {$track->id} ('{$track->product_name}'). Spend CA\${$totalSpendCad} exceeded threshold CA\${$spendThresholdCad} with 0 conversions across {$clicksCount} clicks.",
                                'spend_cad' => $totalSpendCad,
                                'payout_cad' => $typicalPayoutCad,
                                'threshold_cad' => $spendThresholdCad,
                                'window_days' => $windowDays,
                                'conversions_count' => 0,
                                'action_taken' => 'paused',
                            ]);

                            $campaignResults[] = [
                                'campaign_id' => $campaignId,
                                'action' => 'paused',
                            ];
                        } catch (Throwable $e) {
                            Log::error("CampaignSafety: Failed pausing bleeder campaign {$campaignId} on account {$account->id}: {$e->getMessage()}", [
                                'track_id' => $track->id,
                                'campaign_id' => $campaignId,
                                'error' => $e->getMessage(),
                            ]);

                            $campaignResults[] = [
                                'campaign_id' => $campaignId,
                                'action' => 'failed',
                                'error' => $e->getMessage(),
                            ];
                        }
                    }
                }
            }

            $evaluations[] = [
                'track_id' => $track->id,
                'product_name' => $track->product_name,
                'account_id' => $account->id,
                'spend_cad' => $totalSpendCad,
                'payout_cad' => $typicalPayoutCad,
                'threshold_cad' => $spendThresholdCad,
                'clicks_count' => $clicksCount,
                'conversions_count' => $conversionCount,
                'is_bleeder' => $isBleeder,
                'postback_buffer_active' => $isPostbackBufferActive,
                'action_taken' => $actionTaken,
                'campaigns' => $campaignResults,
            ];
        }

        return $evaluations;
    }

    /**
     * Resolve products to inspect, prioritizing products linked to active tracks with campaigns.
     *
     * @return Collection<int, WatchedProduct>
     */
    protected function resolveProductsToCheck(?int $productId = null): Collection
    {
        if ($productId !== null) {
            return WatchedProduct::query()->where('id', $productId)->get();
        }

        // Find product names linked to tracks that have Google Ads accounts & campaign IDs
        $linkedProductNames = Track::query()
            ->whereNotNull('google_ads_account_id')
            ->whereNotNull('campaign_ids')
            ->pluck('product_name')
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter(fn ($name) => $name !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($linkedProductNames)) {
            return collect();
        }

        return WatchedProduct::query()
            ->where('status', '!=', WatchedProductStatus::Rejected)
            ->get()
            ->filter(function (WatchedProduct $product) use ($linkedProductNames): bool {
                return in_array(strtolower(trim($product->name)), $linkedProductNames, true);
            })
            ->values();
    }
}
