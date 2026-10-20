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
        'version' => env('GOOGLE_ADS_API_VERSION', 'v22'),
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
];
