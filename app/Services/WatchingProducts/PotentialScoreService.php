<?php

namespace App\Services\WatchingProducts;

use App\Enums\BrandBiddingStatus;
use App\Enums\FlowspyTrafficTrend;
use App\Enums\ListingStatus;
use App\Enums\PayoutModel;
use App\Enums\TrendsTrend;
use App\Models\WatchedProduct;
use App\Services\WatchingProducts\Fx\FrankfurterFx;

class PotentialScoreService
{
    public function __construct(
        protected FrankfurterFx $fx = new FrankfurterFx
    ) {}

    /**
     * Compute the deterministic 0-100 Potential Score, component breakdown, and flags.
     *
     * @return array{score: int, breakdown: array<string, int>, flags: array<string, mixed>, raw_score: int}
     */
    public function score(WatchedProduct $product): array
    {
        $breakdown = [];
        $flags = [];

        // 1. BoF Name (max 20)
        $bofResult = $this->calculateBofScore($product);
        $breakdown['bof_name'] = $bofResult['score'];
        if (! empty($bofResult['flags'])) {
            $flags = array_merge($flags, $bofResult['flags']);
        }

        // 2. Demand (max 20)
        $demandResult = $this->calculateDemandScore($product);
        $breakdown['demand'] = $demandResult['score'];
        if (! empty($demandResult['flags'])) {
            $flags = array_merge($flags, $demandResult['flags']);
        }

        // 3. Payout (max 15)
        $payoutResult = $this->calculatePayoutScore($product);
        $breakdown['payout'] = $payoutResult['score'];
        if (! empty($payoutResult['flags'])) {
            $flags = array_merge($flags, $payoutResult['flags']);
        }

        // 4. Margin Headroom (max 10)
        $marginResult = $this->calculateMarginScore($product);
        $breakdown['margin_headroom'] = $marginResult['score'];
        if (! empty($marginResult['flags'])) {
            $flags = array_merge($flags, $marginResult['flags']);
        }

        // 5. Page Type & Funnel (max 10)
        $pageTypeResult = $this->calculatePageTypeScore($product);
        $breakdown['page_type'] = $pageTypeResult['score'];
        if (! empty($pageTypeResult['flags'])) {
            $flags = array_merge($flags, $pageTypeResult['flags']);
        }

        // 6. Brand Bidding (max 10)
        $bbResult = $this->calculateBrandBiddingScore($product);
        $breakdown['brand_bidding'] = $bbResult['score'];
        if (! empty($bbResult['flags'])) {
            $flags = array_merge($flags, $bbResult['flags']);
        }

        // 7. Geo Coverage (max 5)
        $breakdown['geo'] = $this->calculateGeoScore($product);

        // 8. Competition (max 5)
        $compResult = $this->calculateCompetitionScore($product);
        $breakdown['competition'] = $compResult['score'];
        if (! empty($compResult['flags'])) {
            $flags = array_merge($flags, $compResult['flags']);
        }

        // 9. Freshness & Approval (max 5)
        $breakdown['freshness'] = $this->calculateFreshnessScore($product);

        // Additional checks: Cancel rate & Expired listing & Audit False BoF
        if ($product->cancel_rate !== null && (float) $product->cancel_rate >= 25.0) {
            $flags['high_cancel_rate'] = true;
        }

        $listingStatus = $product->listing_status instanceof ListingStatus
            ? $product->listing_status
            : ListingStatus::tryFrom((string) $product->listing_status);

        if ($listingStatus === ListingStatus::Expired) {
            $flags['expired_listing'] = true;
        }

        // KP CPC audit floor: below R$3-4 equivalent triggers audit_false_bof
        if ($product->kp_cpc_amount !== null && (float) $product->kp_cpc_amount > 0) {
            $kpCpcBrl = Money::make((float) $product->kp_cpc_amount, $product->kp_cpc_currency ?? 'USD')->convertTo('BRL', $this->fx);
            if ($kpCpcBrl < 4.0) {
                $flags['audit_false_bof'] = true;
            }
        }

        if ($product->restricted_category) {
            $flags['restricted_category'] = true;
        }

        // Raw sum of components (0 - 100)
        $rawScore = (int) array_sum($breakdown);
        $score = $rawScore;

        // Hard gate evaluations
        $hardGatesHit = [];

        // Homonym is a hard discard (score 0)
        if (! empty($flags['false_bof'])) {
            $hardGatesHit[] = 'false_bof';
            $score = 0;
        } else {
            if (! empty($flags['payout_below_floor'])) {
                $hardGatesHit[] = 'payout_below_floor';
            }
            if (! empty($flags['whatsapp_x1_funnel'])) {
                $hardGatesHit[] = 'whatsapp_x1_funnel';
            }
            if (! empty($flags['high_cancel_rate'])) {
                $hardGatesHit[] = 'high_cancel_rate';
            }
            if (! empty($flags['expired_listing'])) {
                $hardGatesHit[] = 'expired_listing';
            }
            if (! empty($flags['dead_post_peak'])) {
                $hardGatesHit[] = 'dead_post_peak';
            }
            if (! empty($flags['pure_vsl_or_iframe'])) {
                $hardGatesHit[] = 'pure_vsl_or_iframe';
            }

            // Any hard gate caps the score at 20
            if (! empty($hardGatesHit)) {
                $score = min($score, 20);
            }

            // Restricted category caps at 50
            if (! empty($flags['restricted_category'])) {
                $score = min($score, 50);
            }
        }

        if (! empty($hardGatesHit)) {
            $flags['hard_gates_hit'] = $hardGatesHit;
        }

        return [
            'score' => max(0, min(100, $score)),
            'raw_score' => $rawScore,
            'breakdown' => $breakdown,
            'flags' => $flags,
        ];
    }

    /**
     * BoF Name triad test (max 20).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculateBofScore(WatchedProduct $product): array
    {
        $flags = [];

        // Same-niche homonym is a hard discard
        if ((bool) $product->bof_homonym) {
            $flags['false_bof'] = true;

            return ['score' => 0, 'flags' => $flags];
        }

        $translation = (bool) $product->bof_translation;
        $vpnSerp = (bool) $product->bof_vpn_serp;
        $imagePct = (int) ($product->bof_image_match_pct ?? 0);

        // Triad pass: translation + VPN SERP + 90-100% exact-product images
        if ($translation && $vpnSerp && $imagePct >= 90) {
            return ['score' => 20, 'flags' => $flags];
        }

        // Compound qualifier needed gives partial credit of 12 plus flag
        if (! empty($product->bof_qualifier)) {
            $flags['compound_keyword_needed'] = true;

            return ['score' => 12, 'flags' => $flags];
        }

        // Proportional partial credit
        $score = (int) round(($imagePct / 100) * 10);
        if ($translation) {
            $score += 3;
        }
        if ($vpnSerp) {
            $score += 2;
        }

        return ['score' => min(15, $score), 'flags' => $flags];
    }

    /**
     * Demand component (max 20).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculateDemandScore(WatchedProduct $product): array
    {
        $flags = [];

        try {
            $trend = $product->trends_trend instanceof TrendsTrend
                ? $product->trends_trend
                : TrendsTrend::tryFrom((string) $product->trends_trend);
        } catch (\Throwable) {
            $trend = null;
        }

        $demandPoints = config('watching.demand_points', []);

        if ($trend === TrendsTrend::DeadPostPeak) {
            $flags['dead_post_peak'] = true;
            $flags['dead_post_peak_audit'] = [
                'trends_7d' => $product->trends_7d !== null ? (float) $product->trends_7d : 0.0,
                'trends_30d' => $product->trends_30d !== null ? (float) $product->trends_30d : 0.0,
                'trends_90d' => $product->trends_90d !== null ? (float) $product->trends_90d : 0.0,
                'reason' => "Demand collapsed post-peak (7d: {$product->trends_7d}, 30d: {$product->trends_30d}, 90d: {$product->trends_90d}). Hard gate caps score at 20.",
            ];

            return [
                'score' => (int) ($demandPoints['dead_post_peak'] ?? 0),
                'flags' => $flags,
            ];
        }

        $score = match ($trend) {
            TrendsTrend::Rising => (int) ($demandPoints['rising'] ?? 20),
            TrendsTrend::Stable => (int) ($demandPoints['stable'] ?? 16),
            TrendsTrend::Falling => (int) ($demandPoints['falling'] ?? 10),
            default => null,
        };

        // When trend direction is unknown / null, derive from KP volume tiers or use unknown baseline
        if ($score === null) {
            $volume = (int) ($product->kp_volume ?? 0);
            $unknownBase = (int) ($demandPoints['unknown'] ?? 8);

            if ($volume > 0) {
                $score = match (true) {
                    $volume >= 5000 => (int) ($demandPoints['kp_volume_tiers'][5000] ?? 18),
                    $volume >= 1000 => (int) ($demandPoints['kp_volume_tiers'][1000] ?? 14),
                    $volume >= 250 => (int) ($demandPoints['kp_volume_tiers'][250] ?? 10),
                    default => $unknownBase,
                };

                if ((float) $product->kp_yoy > 0 || (float) $product->kp_3m > 0) {
                    $score += (int) ($demandPoints['kp_growth_bonus'] ?? 2);
                }
            } else {
                $score = $unknownBase;
            }
        }

        try {
            $trafficTrend = $product->flowspy_traffic_trend instanceof FlowspyTrafficTrend
                ? $product->flowspy_traffic_trend
                : FlowspyTrafficTrend::tryFrom((string) $product->flowspy_traffic_trend);
        } catch (\Throwable) {
            $trafficTrend = null;
        }

        // FlowSpy traffic rising can boost or override
        if ($trafficTrend === FlowspyTrafficTrend::Rising) {
            $minFlowspyPoints = (int) ($demandPoints['flowspy_rising_min'] ?? 18);
            $score = max($score, $minFlowspyPoints);
        }

        return ['score' => max(0, min(20, $score)), 'flags' => $flags];
    }

    /**
     * Payout component (max 15).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculatePayoutScore(WatchedProduct $product): array
    {
        $flags = [];

        $amount = (float) ($product->payout_amount ?? $product->payout_cad ?? 0.0);
        $currency = $product->payout_currency ?? 'USD';
        $payoutUsd = Money::make($amount, $currency)->toUsd($this->fx);

        // Hard gate floor: US$20 / R$100
        if ($payoutUsd < 20.0) {
            $flags['payout_below_floor'] = true;

            return ['score' => 0, 'flags' => $flags];
        }

        if ($payoutUsd >= 50.0 && $payoutUsd <= 200.0) {
            $score = 15;
        } elseif ($payoutUsd < 50.0) {
            // Scale linearly between $20 and $50
            $score = (int) round((($payoutUsd - 20.0) / 30.0) * 15.0);
        } else {
            // Decay slightly above $200
            $decay = (int) round((($payoutUsd - 200.0) / 100.0) * 2.0);
            $score = max(10, 15 - $decay);
        }

        // +2 for fixed CPA over ClickBank RevShare, capped at 15
        $payoutModel = $product->payout_model instanceof PayoutModel
            ? $product->payout_model
            : PayoutModel::tryFrom((string) $product->payout_model);

        if ($payoutModel === PayoutModel::CpaFixed) {
            $score = min(15, $score + 2);
        }

        return ['score' => max(0, min(15, $score)), 'flags' => $flags];
    }

    /**
     * Margin Headroom component (max 10).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculateMarginScore(WatchedProduct $product): array
    {
        $flags = [];

        $amount = (float) ($product->payout_amount ?? $product->payout_cad ?? 0.0);
        $currency = $product->payout_currency ?? 'USD';
        $payoutUsd = Money::make($amount, $currency)->toUsd($this->fx);

        $cvr = $product->est_cvr !== null && (float) $product->est_cvr > 0
            ? (float) $product->est_cvr
            : 0.02;

        $brandCpcAmount = (float) ($product->brand_cpc_amount ?? $product->kp_cpc_amount ?? 0.0);
        $brandCpcCurrency = $product->brand_cpc_currency ?? $product->kp_cpc_currency ?? 'USD';

        $fallbackCpcCad = (float) config('watching.thresholds.fallback_cpc_floor_cad', 1.50);
        $fallbackCpcUsd = Money::make($fallbackCpcCad, 'CAD')->toUsd($this->fx);

        if ($brandCpcAmount <= 0.0) {
            // Missing or zero auction data: apply conservative CAD fallback floor so ROI scores are not artificially inflated
            $brandCpcUsd = $fallbackCpcUsd;
            $flags['cpc_confidence'] = 'fallback';
            $flags['fallback_cpc_applied'] = true;
            $flags['fallback_cpc_cad'] = $fallbackCpcCad;
        } else {
            $rawCpcUsd = Money::make($brandCpcAmount, $brandCpcCurrency)->toUsd($this->fx);
            // Defensive floor: auction CPC cannot be zero or unrealistically negligible
            $brandCpcUsd = max($rawCpcUsd, $fallbackCpcUsd * 0.25);
            $flags['cpc_confidence'] = 'high';
        }

        // Margin per click: payout × est_cvr − brand_cpc
        $expectedMargin = ($payoutUsd * $cvr) - $brandCpcUsd;

        if ($expectedMargin <= 0.0) {
            return ['score' => 0, 'flags' => $flags];
        }

        // Map $0 to $1.50+ margin to 0-10
        $score = (int) round(($expectedMargin / 1.50) * 10);

        return [
            'score' => max(0, min(10, $score)),
            'flags' => $flags,
        ];
    }

    /**
     * Page type & funnel component (max 10).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculatePageTypeScore(WatchedProduct $product): array
    {
        $flags = [];
        $funnelFlags = is_array($product->funnel_flags) ? $product->funnel_flags : [];

        // WhatsApp / X1 funnel is a hard gate
        if (! empty($funnelFlags['whatsapp_x1'])) {
            $flags['whatsapp_x1_funnel'] = true;
        }

        // Pure VSL or iFrame is a hard gate
        $isPureVsl = (strtoupper(trim((string) $product->page_type)) === 'VSL') || (! empty($funnelFlags['vsl_only']));
        $isIframe = ! empty($funnelFlags['iframe']);

        if ($isPureVsl || $isIframe) {
            $flags['pure_vsl_or_iframe'] = true;

            return ['score' => 0, 'flags' => $flags];
        }

        $type = strtoupper(trim((string) $product->page_type));

        $score = match ($type) {
            'TSL', 'DTC', 'QUIZ' => 10,
            'ADVERTORIAL', 'PRESELL', 'REVIEW' => 7,
            default => 5,
        };

        return ['score' => $score, 'flags' => $flags];
    }

    /**
     * Brand Bidding component (max 10).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculateBrandBiddingScore(WatchedProduct $product): array
    {
        $flags = [];

        $bb = $product->brand_bidding instanceof BrandBiddingStatus
            ? $product->brand_bidding
            : BrandBiddingStatus::tryFrom((string) $product->brand_bidding);

        return match ($bb) {
            BrandBiddingStatus::Allowed => ['score' => 10, 'flags' => $flags],
            BrandBiddingStatus::Unknown, BrandBiddingStatus::ConfirmWithAm => ['score' => 6, 'flags' => $flags],
            BrandBiddingStatus::Banned => [
                'score' => 3,
                'flags' => array_merge($flags, ['bb_risk' => true]),
            ],
            default => ['score' => 6, 'flags' => $flags],
        };
    }

    /**
     * Geo coverage component (max 5).
     */
    protected function calculateGeoScore(WatchedProduct $product): int
    {
        $geos = is_array($product->geos) ? array_map('strtoupper', $product->geos) : [];
        $tierOne = ['CA', 'US', 'AU'];

        $matches = count(array_intersect($tierOne, $geos));

        return match ($matches) {
            3 => 5,
            2 => 3,
            1 => 2,
            default => 0,
        };
    }

    /**
     * Competition component (max 5).
     *
     * @return array{score: int, flags: array<string, mixed>}
     */
    protected function calculateCompetitionScore(WatchedProduct $product): array
    {
        $flags = [];

        if ((bool) $product->vendor_bids_brand) {
            $flags['vendor_bids_own_brand'] = true;
        }

        $advertisers = (int) ($product->advertiser_count ?? 0);

        $score = match (true) {
            $advertisers <= 1 => 5,
            $advertisers <= 3 => 4,
            $advertisers <= 5 => 3,
            $advertisers <= 7 => 2,
            default => 1,
        };

        return ['score' => $score, 'flags' => $flags];
    }

    /**
     * Freshness and approval component (max 5).
     */
    protected function calculateFreshnessScore(WatchedProduct $product): int
    {
        $score = 5;

        if ((bool) $product->approval_required) {
            $score -= 2;
        }

        $date = $product->last_refreshed_at ?? $product->created_at;
        if ($date && $date->lt(now()->subDays(60))) {
            $score -= 1;
        }

        return max(0, min(5, $score));
    }
}
