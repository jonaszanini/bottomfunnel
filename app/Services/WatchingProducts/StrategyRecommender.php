<?php

namespace App\Services\WatchingProducts;

use App\Enums\Strategy;
use App\Enums\StrategySource;
use App\Models\WatchedProduct;

class StrategyRecommender
{
    /**
     * Determine recommended strategy and reason for a product.
     *
     * @return array{strategy: Strategy, reason: string}
     */
    public function recommend(WatchedProduct $product): array
    {
        // 1. Respect manual override
        if ($product->strategy_source === StrategySource::Manual && $product->strategy instanceof Strategy) {
            return [
                'strategy' => $product->strategy,
                'reason' => 'Manually set by user override.',
            ];
        }

        $pageType = strtoupper(trim((string) $product->page_type));
        $funnelFlags = is_array($product->funnel_flags) ? $product->funnel_flags : [];
        $textCorpus = strtolower($product->name.' '.($product->notes ?? '').' '.($product->demand_notes ?? ''));

        // 2. Launch Event / Summit / Workshop
        if ($pageType === 'LAUNCH' || str_contains($textCorpus, 'summit') || str_contains($textCorpus, 'workshop') || str_contains($textCorpus, 'fixed date')) {
            return [
                'strategy' => Strategy::LaunchEvent,
                'reason' => 'Free summit/workshop or fixed date event suited for launch event traffic.',
            ];
        }

        // 3. Quiz funnel
        if ($pageType === 'QUIZ') {
            return [
                'strategy' => Strategy::Quiz,
                'reason' => 'Quiz page type converts highest using interactive quiz pre-sell flow.',
            ];
        }

        // 4. Pure VSL with no TSL/DTC alt
        $isPureVsl = ($pageType === 'VSL') || (! empty($funnelFlags['vsl_only']));
        if ($isPureVsl) {
            return [
                'strategy' => Strategy::Robusta,
                'reason' => 'Pure VSL with no text sales letter requires an advertorial pre-sell bridge (Robusta).',
            ];
        }

        // 5. Compound qualifier needed
        if (! empty($product->bof_qualifier)) {
            return [
                'strategy' => Strategy::BofDirect,
                'reason' => "Compound keyword needed ({$product->bof_qualifier}); direct BoF on qualified phrase.",
            ];
        }

        // 6. Review page type
        if ($pageType === 'REVIEW' || $pageType === 'ADVERTORIAL') {
            return [
                'strategy' => Strategy::BofReview,
                'reason' => 'Review or advertorial page structure ideal for review-intent buyer capture.',
            ];
        }

        // 7. TSL/DTC standard BoF
        return [
            'strategy' => Strategy::BofDirect,
            'reason' => 'TSL/DTC with high purchase intent suited for direct bottom-of-funnel targeting.',
        ];
    }
}
