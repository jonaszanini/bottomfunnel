<?php

return [
    'username' => env('FLOW_HUB_USERNAME', 'owner'),
    'password' => env('FLOW_HUB_PASSWORD', 'ChangeMe123!'),

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'redirect' => env('GOOGLE_ADS_REDIRECT_URI', env('APP_URL').'/google-ads/callback'),
        'version' => env('GOOGLE_ADS_API_VERSION', 'v26'),
    ],

    'keyword_volume' => [
        'account_id' => env('KEYWORD_VOLUME_ACCOUNT_ID'),
        'geos' => [
            'US' => '2840',
            'CA' => '2124',
            'GB' => '2826',
            'AU' => '2036',
        ],
        'default_geos' => ['US', 'CA'],
        'language' => 'languageConstants/1000',
    ],

    'page_types' => [
        'popup_cookie' => 'Popup/Cookie',
        'review_robust' => 'Review Robust',
        'age_gate' => 'Age Gate',
        'presell' => 'Presell',
        'review' => 'Review',
        'robust' => 'Robust',
        'captcha_cookies' => 'Captcha/Cookies',
        'custom' => 'Custom combination',
    ],

    'strategies' => [
        'target_cpa' => 'Target CPA',
        'maximize_clicks' => 'Maximize Clicks',
        'manual_cpc' => 'Manual CPC Sniper',
        'review' => 'Review',
    ],

    'platforms' => [
        'clickbank' => 'ClickBank',
        'digistore24' => 'Digistore24',
        'buygoods' => 'BuyGoods',
        'maxweb' => 'MaxWeb',
        'other' => 'Other',
    ],

    'click_params' => [
        'clickbank' => 'tid',
        'digistore24' => 'sid',
        'buygoods' => 'subid',
        'maxweb' => 'subid1',
        'other' => 'tid',
    ],

    'languages' => [
        'en' => 'English',
        'fr' => 'French',
        'es' => 'Spanish',
        'de' => 'German',
        'pt' => 'Portuguese',
    ],

    /*
    |--------------------------------------------------------------------------
    | Automated Campaign Safety Rules
    |--------------------------------------------------------------------------
    |
    | When auto_pause_enabled is false, safety rules only raise alerts.
    |
    */
    'auto_pause_enabled' => (bool) env('SAFETY_AUTO_PAUSE_ENABLED', false),

    'safety_rules' => [
        'auto_pause_enabled' => (bool) env('SAFETY_AUTO_PAUSE_ENABLED', false),
        'listing_down' => [
            'enabled' => (bool) env('SAFETY_LISTING_DOWN_ENABLED', true),
            'action' => env('SAFETY_LISTING_DOWN_ACTION', 'alert'), // 'alert' or 'pause'
            'consecutive_failures_required' => (int) env('SAFETY_LISTING_CONSECUTIVE_FAILURES', 2),
        ],
        'bleeder' => [
            'enabled' => (bool) env('SAFETY_BLEEDER_ENABLED', true),
            'spend_payout_multiplier' => (float) env('SAFETY_BLEEDER_MULTIPLIER', 1.5),
            'window_days' => (int) env('SAFETY_BLEEDER_WINDOW_DAYS', 7),
            'min_clicks' => (int) env('SAFETY_BLEEDER_MIN_CLICKS', 20),
            'postback_buffer_hours' => (int) env('SAFETY_BLEEDER_POSTBACK_BUFFER_HOURS', 3),
            'action' => env('SAFETY_BLEEDER_ACTION', 'alert'), // 'alert' or 'pause'
        ],
    ],

    'cron_token' => env('CRON_TOKEN', 'hgytrsd543T4564FGHf7tgfs635GGf'),
    'cron_url' => env('CRON_URL', 'https://bottomfunnel.app/cron/run?token='.env('CRON_TOKEN', 'hgytrsd543T4564FGHf7tgfs635GGf')),
    'serpapi_cache_ttl_hours' => (int) env('SERPAPI_CACHE_TTL_HOURS', 12),
];
