<?php

namespace App\Services;

use App\Data\PerformanceRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shared, stateless fetch logic for the real ChatGPT Ads API's
 * GET /ad_account/insights endpoint - extracted from RealAdsSource (which
 * still calls this for the .env-configured accounts, unchanged behavior) so
 * AdsAccountSyncer can call the exact same logic for database-registered
 * accounts (app/Models/AdsAccount) without duplicating the pagination/field-
 * validation/campaign-numbering rules in two places.
 *
 * Deliberately always a live, uncached call here - caching belongs to each
 * caller's own usage pattern, not this shared low-level fetch: RealAdsSource
 * caches it (PLANNING_LOG.md 2026-11-05) because it's called repeatedly with
 * the same (account, range) on every dashboard render, where some staleness
 * is fine; AdsAccountSyncer must NEVER get a cached result here, since the
 * entire point of calling sync() again is to pick up changed live data (see
 * its own test, test_a_second_sync_replaces_the_first_rather_than_accumulating
 * - caching at this shared level broke that test, caught by running the
 * suite rather than assumed safe).
 */
final class AdsApiClient
{
    /**
     * The account and campaign names in the API response identify a real
     * advertiser, so they are never shown as-is: campaigns are numbered in
     * the order they first appear here, and that "Campaign #N" label is
     * what reaches the dashboard instead of the real name.
     *
     * @return list<PerformanceRecord>
     */
    public static function fetchInsights(
        string $accountId,
        string $apiKey,
        string $currency,
        string $from,
        string $to,
        string $logLabel,
    ): array {
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
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(20)
                ->get($url, $query);

            Log::channel(config('logging.default'))->info('ads_api.insights_response', [
                'account' => $logLabel,
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
                    $accountId,
                    $campaignId,
                    'Campaign #'.$campaignNumbers[$campaignId],
                    (string) $row['readable_time'],
                    $impressions,
                    $clicks,
                    $spend,
                    $currency,
                );
            }

            $after = $response->json('has_more') ? $response->json('last_id') : null;
        } while ($after !== null);

        return $records;
    }
}
