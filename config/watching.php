
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Watching Products Platforms / Marketplaces
    |--------------------------------------------------------------------------
    |
    | Centralized list of supported platforms/marketplaces where products
    | are sourced. Used across validation, filters, and UI forms.
    |
    */
    'platforms' => [
        'Affplus',
        'Affplus (ClickHunts)',
        'Affplus (WW)',
        'BuyGoods',
        'ClickBank',
        'Digistore24',
        'MaxWeb',
        'SmartAdv',
        'Other',
        'Unknown',
    ],

    /*
    |--------------------------------------------------------------------------
    | SerpApi Configuration & Monthly Free-Tier Quota
    |--------------------------------------------------------------------------
    |
    | SerpApi free tier provides 250 calls/month.
    | We enforce a 230 call monthly cap for automated weekly jobs,
    | leaving 20 calls in reserve for manual "Refresh now" actions.
    |
    */
    'serpapi_key' => env('SERPAPI_KEY'),
    'serpapi_monthly_cap' => (int) env('SERPAPI_MONTHLY_CAP', 230),
    'serpapi_total_limit' => 250,
    'serpapi_reserve' => 20,
    'serpapi_gl' => env('SERPAPI_GL', 'ca'),
    'serpapi_cache_ttl_hours' => (int) env('SERPAPI_CACHE_TTL_HOURS', 12),
    'serpapi_quota_high_threshold' => (float) env('SERPAPI_QUOTA_HIGH_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Negative Keyword LLM Generation
    |--------------------------------------------------------------------------
    |
    | When enabled, triggers a single cheap gpt-4o-mini-class call on product
    | creation only. Never invoked in scheduled jobs. Toggled OFF by default.
    |
    */
    'negatives_llm' => (bool) env('WATCHING_NEGATIVES_LLM', false),

    /*
    |--------------------------------------------------------------------------
    | Potential Score Weights (Sum to 100)
    |--------------------------------------------------------------------------
    */
    'weights' => [
        'bof_name' => 20,
        'demand' => 20,
        'payout' => 15,
        'margin_headroom' => 10,
        'page_type' => 10,
        'brand_bidding' => 10,
        'geo' => 5,
        'competition' => 5,
        'freshness' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Demand Component Scoring Points (Max 20)
    |--------------------------------------------------------------------------
    |
    | Point values awarded for the Demand component based on Google Trends
    | direction (trends_trend), FlowSpy traffic boost, and baseline values
    | for null/unknown trends.
    |
    */
    'demand_points' => [
        'rising' => 20,
        'stable' => 16,
        'falling' => 10,
        'dead_post_peak' => 0,
        'unknown' => 8,
        'flowspy_rising_min' => 18,
        'kp_volume_tiers' => [
            5000 => 18,
            1000 => 14,
            250 => 10,
        ],
        'kp_growth_bonus' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Thresholds & Hard Gates
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'payout_floor_usd' => 20.0,
        'payout_linear_ceiling_usd' => 50.0,
        'payout_decay_threshold_usd' => 200.0,
        'payout_cpa_fixed_bonus' => 2,
        'cancel_rate_max_pct' => 25.0,
        'restricted_category_cap' => 50,
        'hard_gate_cap' => 20,
        'kp_cpc_audit_floor_brl' => 4.0, // R$3-4 equivalent triggers audit_false_bof
        // Dead Post Peak detection: requires genuine historical spike (>= 60) in first half of 90d
        // followed by a sustained collapse where both 7d (<15% of peak) and 30d (<25% of peak) cratered.
        // Products with flat low volume or temporary single-week dips are NOT dead_post_peak.
        'dead_post_peak_min_peak' => (float) env('WATCHING_DEAD_POST_PEAK_MIN_PEAK', 60.0),
        'dead_post_peak_7d_ratio' => (float) env('WATCHING_DEAD_POST_PEAK_7D_RATIO', 0.15),
        'dead_post_peak_30d_ratio' => (float) env('WATCHING_DEAD_POST_PEAK_30D_RATIO', 0.25),
        'fallback_cpc_floor_cad' => (float) env('WATCHING_FALLBACK_CPC_FLOOR_CAD', 1.50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Brand Meanings Dictionary
    |--------------------------------------------------------------------------
    |
    | Words commonly appearing in brand names that have standalone generic
    | or dictionary meanings to be suggested as negatives.
    |
    */
    'generic_meanings' => [
        'steel' => ['steel metal', 'stainless steel', 'sheet metal', 'structural steel'],
        'alpha' => ['alpha male', 'alpha letter', 'alpha greek', 'alpha symbol'],
        'lean' => ['lean management', 'lean meat', 'lean manufacturing', 'lean six sigma'],
        'flow' => ['blood flow', 'cash flow', 'work flow', 'air flow'],
        'peak' => ['mountain peak', 'peak performance', 'peak hours', 'pikes peak'],
        'prime' => ['amazon prime', 'prime number', 'prime minister', 'prime rib'],
        'vital' => ['vital signs', 'vital records', 'vital statistics'],
        'boost' => ['boost mobile', 'boost audio', 'turbo boost'],
        'burn' => ['burn treatment', 'first degree burn', 'wood burn'],
        'pure' => ['pure water', 'pure arithmetic', 'pure math'],
        'force' => ['air force', 'police force', 'gravitational force', 'centrifugal force'],
        'shield' => ['shield armor', 'blue cross blue shield', 'shield volcano'],
        'flex' => ['ford flex', 'flex schedule', 'flex hose'],
        'clarity' => ['diamond clarity', 'clarity act', 'clarity call'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automated Campaign Safety Rules
    |--------------------------------------------------------------------------
    |
    | When auto_pause_enabled is false, safety rules (listing-down and
    | spend-without-conversion bleeders) only raise alerts and will NEVER
    | mutate Google Ads campaign statuses unless explicitly enabled.
    |
    */
    'auto_pause_enabled' => (bool) env('SAFETY_AUTO_PAUSE_ENABLED', false),

    'safety_rules' => [
        'auto_pause_enabled' => (bool) env('SAFETY_AUTO_PAUSE_ENABLED', false),
        'listing_down' => [
            'enabled' => (bool) env('SAFETY_LISTING_DOWN_ENABLED', true),
            'action' => env('SAFETY_LISTING_DOWN_ACTION', 'alert'),
            'consecutive_failures_required' => (int) env('SAFETY_LISTING_CONSECUTIVE_FAILURES', 2),
        ],
        'bleeder' => [
            'enabled' => (bool) env('SAFETY_BLEEDER_ENABLED', true),
            'spend_payout_multiplier' => (float) env('SAFETY_BLEEDER_MULTIPLIER', 1.5),
            'window_days' => (int) env('SAFETY_BLEEDER_WINDOW_DAYS', 7),
            'min_clicks' => (int) env('SAFETY_BLEEDER_MIN_CLICKS', 20),
            'postback_buffer_hours' => (int) env('SAFETY_BLEEDER_POSTBACK_BUFFER_HOURS', 3),
            'action' => env('SAFETY_BLEEDER_ACTION', 'alert'),
        ],
    ],
];
