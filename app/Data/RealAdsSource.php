<?php

namespace App\Data;

use App\Services\AdsApiClient;
use RuntimeException;

/**
 * One real ChatGPT Ads account. Each Ads API key is scoped to a single
 * account, so a second real account is a second instance of this class with
 * its own id, key, display label and currency - see AppServiceProvider,
 * which builds one instance per configured key in config('ads.accounts').
 * Currency is configured, not fetched from the API: it's a fixed property of
 * an account that doesn't change, and GET /ad_account/insights (what records()
 * actually calls) doesn't return it anyway.
 */
final class RealAdsSource implements PerformanceSource
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $apiKey,
        private readonly string $displayLabel,
        private readonly string $currency = 'HUF',
    ) {}

    /**
     * Builds one RealAdsSource per configured account that actually has an API
     * key set (config('ads.accounts')); an account slot with no key - e.g. a
     * second real account not yet onboarded - is skipped, not constructed, so
     * the dashboard runs fine with however many real keys are currently
     * available. An account uses its configured 'label' if set (e.g. the
     * Education & Careers accounts use "Education Partner A/B"); otherwise it
     * falls back to "Real Account #N", numbered in config order counting only
     * the accounts actually present. An account WITH a key but no configured
     * currency (ADS_API_CURRENCY_N) is a misconfiguration, not an assumed
     * HUF account: it throws rather than silently defaulting, same as a
     * missing field in the API response itself (see PLANNING_LOG.md 2026-10-20).
     *
     * @return list<RealAdsSource>
     */
    public static function fromConfig(): array
    {
        $sources = [];
        foreach (config('ads.accounts', []) as $account) {
            if (empty($account['api_key'])) {
                continue;
            }
            if (empty($account['currency'])) {
                throw new RuntimeException(
                    "Account \"{$account['id']}\" has an API key but no configured currency - set ADS_API_CURRENCY_N in .env."
                );
            }
            $label = $account['label'] ?? 'Real Account #'.(count($sources) + 1);
            $sources[] = new self($account['id'], $account['api_key'], $label, $account['currency']);
        }

        return $sources;
    }

    public function label(): string
    {
        return 'Real';
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function accounts(): array
    {
        return [$this->accountId => $this->displayLabel];
    }

    /**
     * Delegates to AdsApiClient::fetchInsights() (extracted 2026-11-01 so
     * AdsAccountSyncer can reuse the exact same pagination/field-validation/
     * campaign-numbering logic for database-registered accounts) - this
     * method's own behavior is unchanged, confirmed by the existing test
     * suite passing unmodified after the extraction.
     */
    public function records(string $from, string $to): array
    {
        if (! $this->apiKey) {
            throw new RuntimeException('no API key is configured');
        }

        return AdsApiClient::fetchInsights(
            $this->accountId, $this->apiKey, $this->currency, $from, $to, $this->displayLabel
        );
    }
}
