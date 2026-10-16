<?php

namespace App\Data;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * the accounts actually present.
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
            $label = $account['label'] ?? 'Real Account #'.(count($sources) + 1);
            $sources[] = new self($account['id'], $account['api_key'], $label, $account['currency'] ?? 'HUF');
        }

        return $sources;
    }

    public function label(): string
    {
        return 'Real';
    }

    public function accounts(): array
    {
        return [$this->accountId => $this->displayLabel];
    }

    /**
     * The account and campaign names in the API response identify a real
     * advertiser, so they are never shown as-is: campaigns are numbered in
     * the order they first appear here, and that "Campaign #N" label is
     * what reaches the dashboard instead of the real name.
     */
    public function records(string $from, string $to): array
    {
        if (! $this->apiKey) {
            throw new RuntimeException('no API key is configured');
        }

        $records = [];
        $campaignNumbers = [];
        $after = null;
        do {
            $query = [
                'time_granularity' => 'daily',
                'aggregation_level' => 'campaign',
                'limit' => 2000,
                'fields' => [
                    'metadata.readable_time', 'campaign.id', 'campaign.name',
                    'campaign.clicks', 'campaign.impressions', 'campaign.spend',
                ],
                'time_ranges' => [json_encode(['type' => 'date_range', 'since' => $from, 'until' => $to])],
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }

            $url = rtrim(config('ads.base_url'), '/').'/ad_account/insights';
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(20)
                ->get($url, $query);

            Log::channel(config('logging.default'))->info('ads_api.insights_response', [
                'account' => $this->displayLabel,
                'url' => $url,
                'query' => $query,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 4000),
            ]);

            if ($response->failed()) {
                throw new RuntimeException('the Ads API returned HTTP '.$response->status());
            }

            foreach ($response->json('data', []) as $row) {
                // A missing field here means the API response shape changed (a
                // renamed/removed column), not "no delivery that day" - treating
                // it as 0 would silently understate real performance instead of
                // surfacing the integration break, so every expected field must
                // actually be present, even when its value is legitimately 0.
                foreach (['impressions', 'clicks', 'spend', 'campaign_id', 'readable_time'] as $field) {
                    if (! array_key_exists($field, $row)) {
                        throw new RuntimeException("the Ads API response is missing the expected field \"{$field}\"");
                    }
                }

                $impressions = (int) $row['impressions'];
                $clicks = (int) $row['clicks'];
                $spend = (float) $row['spend'];
                if ($impressions === 0 && $clicks === 0 && $spend == 0.0) {
                    continue;
                }
                $campaignId = (string) $row['campaign_id'];
                $campaignNumbers[$campaignId] ??= count($campaignNumbers) + 1;

                $records[] = new PerformanceRecord(
                    $this->accountId,
                    $campaignId,
                    'Campaign #'.$campaignNumbers[$campaignId],
                    (string) $row['readable_time'],
                    $impressions,
                    $clicks,
                    $spend,
                    $this->currency,
                );
            }

            $after = $response->json('has_more') ? $response->json('last_id') : null;
        } while ($after !== null);

        return $records;
    }
}
