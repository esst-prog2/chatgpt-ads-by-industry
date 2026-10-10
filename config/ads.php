<?php

return [
    'base_url' => env('ADS_API_BASE_URL', 'https://api.ads.openai.com/v1'),

    // Each entry is one real ChatGPT Ads account: a fixed local id (used in the
    // account-to-industry mapping and never shown to the user), its own API key
    // (each Ads API key is scoped to a single account), and the label shown on
    // the dashboard instead of the account's real name. A slot with no key
    // configured is skipped entirely (see RealAdsSource::fromConfig()) - the
    // dashboard runs fine with however many of these are currently set.
    // These two are both Education & Careers accounts (see account_industry.csv),
    // labelled as that segment's partners rather than with the generic
    // "Real Account #N" RealAdsSource::fromConfig() falls back to otherwise.
    'accounts' => [
        ['id' => 'real-account-1', 'api_key' => env('ADS_API_KEY'), 'label' => 'Education Partner A'],
        ['id' => 'real-account-2', 'api_key' => env('ADS_API_KEY_2'), 'label' => 'Education Partner B'],
    ],
];
