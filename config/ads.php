<?php

// Account #1 keeps its original env var name (ADS_API_KEY, no "_1" suffix) for
// backward compatibility with the existing .env; accounts #2 and up follow the
// numbered convention already in use (ADS_API_KEY_2, ADS_API_KEY_3, ...).
$firstAccount = [
    'id' => 'real-account-1',
    'api_key' => env('ADS_API_KEY'),
    'label' => env('ADS_API_LABEL_1'),
];

$restAccounts = array_map(
    fn (int $n) => [
        'id' => "real-account-{$n}",
        'api_key' => env("ADS_API_KEY_{$n}"),
        'label' => env("ADS_API_LABEL_{$n}"),
    ],
    range(2, (int) env('ADS_MAX_ACCOUNTS', 20))
);

return [
    'base_url' => env('ADS_API_BASE_URL', 'https://api.ads.openai.com/v1'),

    // An arbitrary-length list of real ChatGPT Ads accounts, built from env
    // vars rather than hand-written: to add another real account, set its
    // ADS_API_KEY_N (and optionally ADS_API_LABEL_N) in .env - no code change
    // needed here. Each Ads API key is scoped to a single account, so each
    // entry is a fixed local id (used in the account-to-industry mapping and
    // never shown to the user), its own API key, and an optional label shown
    // on the dashboard instead of the account's real name - if no label is
    // set, RealAdsSource::fromConfig() falls back to "Real Account #N".
    // A slot with no key configured is skipped entirely (same method) - the
    // dashboard runs fine with however many of these are currently set.
    // ADS_MAX_ACCOUNTS (default 20) bounds how high the scan for ADS_API_KEY_N
    // goes; raise it if you ever need more real accounts than that.
    'accounts' => array_values(array_filter(
        array_merge([$firstAccount], $restAccounts),
        fn (array $a) => filled($a['api_key'])
    )),
];
