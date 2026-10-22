<?php

return [
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
];
