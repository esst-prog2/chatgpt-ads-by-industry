<?php

// Account #1 keeps its original env var name (ADS_API_KEY, no "_1" suffix) for
// backward compatibility with the existing .env; accounts #2 and up follow the
// numbered convention already in use (ADS_API_KEY_2, ADS_API_KEY_3, ...).
$firstAccount = [
    'id' => 'real-account-1',
    'api_key' => env('ADS_API_KEY'),
    'label' => env('ADS_API_LABEL_1'),
    'currency' => env('ADS_API_CURRENCY_1', 'HUF'),
];

$restAccounts = array_map(
    fn (int $n) => [
        'id' => "real-account-{$n}",
        'api_key' => env("ADS_API_KEY_{$n}"),
        'label' => env("ADS_API_LABEL_{$n}"),
        'currency' => env("ADS_API_CURRENCY_{$n}", 'HUF'),
    ],
    range(2, (int) env('ADS_MAX_ACCOUNTS', 20))
);

return [
    'base_url' => env('ADS_API_BASE_URL', 'https://api.ads.openai.com/v1'),

    // An arbitrary-length list of real ChatGPT Ads accounts, built from env
    // vars rather than hand-written: to add another real account, set its
    // ADS_API_KEY_N (and optionally ADS_API_LABEL_N, ADS_API_CURRENCY_N) in
    // .env - no code change needed here. Each Ads API key is scoped to a
    // single account, so each entry is a fixed local id (used in the
    // account-to-industry mapping and never shown to the user), its own API
    // key, an optional label shown on the dashboard instead of the account's
    // real name (falls back to "Real Account #N", see
    // RealAdsSource::fromConfig()), and its currency (defaults to HUF;
    // GET /ad_account/insights doesn't return this, so it must be set
    // manually from that account's own currency_code - see GET /ad_account).
    // A slot with no key configured is skipped entirely - the dashboard runs
    // fine with however many of these are currently set. ADS_MAX_ACCOUNTS
    // (default 20) bounds how high the scan for ADS_API_KEY_N goes; raise it
    // if you ever need more real accounts than that.
    'accounts' => array_values(array_filter(
        array_merge([$firstAccount], $restAccounts),
        fn (array $a) => filled($a['api_key'])
    )),

    // Lightweight, manually-configured conversion to HUF for industry-level
    // aggregation (account/campaign-level figures stay in their own native
    // currency - see Aggregator). Not a live rate: update EUR_TO_HUF_RATE in
    // .env when it drifts meaningfully. Add another currency here (with its
    // own env var) if a real account in a different currency is onboarded;
    // Aggregator throws if it ever meets a currency with no rate configured,
    // rather than silently treating it as 1:1.
    'exchange_rates_to_huf' => [
        'HUF' => 1.0,
        'EUR' => (float) env('EUR_TO_HUF_RATE', 400.0),
    ],
];
