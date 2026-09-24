<?php

namespace App\Data;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class RealAdsSource implements PerformanceSource
{
    public const ACCOUNT_ID = 'real-account';

    public function label(): string
    {
        return 'Real';
    }

    public function accounts(): array
    {
        return [self::ACCOUNT_ID => 'Real Account #1'];
    }

    /**
     * The account and campaign names in the API response identify a real
     * advertiser, so they are never shown as-is: campaigns are numbered in
     * the order they first appear here, and that "Campaign #N" label is
     * what reaches the dashboard instead of the real name.
     */
    public function records(string $from, string $to): array
    {
        $key = config('ads.api_key');
        if (! $key) {
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
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(20)
                ->get($url, $query);

            Log::channel(config('logging.default'))->info('ads_api.insights_response', [
                'url' => $url,
                'query' => $query,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 4000),
            ]);

            if ($response->failed()) {
                throw new RuntimeException('the Ads API returned HTTP '.$response->status());
            }

            foreach ($response->json('data', []) as $row) {
                $impressions = (int) ($row['impressions'] ?? 0);
                $clicks = (int) ($row['clicks'] ?? 0);
                $spend = (float) ($row['spend'] ?? 0);
                if ($impressions === 0 && $clicks === 0 && $spend == 0.0) {
                    continue;
                }
                $campaignId = (string) $row['campaign_id'];
                $campaignNumbers[$campaignId] ??= count($campaignNumbers) + 1;

                $records[] = new PerformanceRecord(
                    self::ACCOUNT_ID,
                    $campaignId,
                    'Campaign #'.$campaignNumbers[$campaignId],
                    (string) $row['readable_time'],
                    $impressions,
                    $clicks,
                    $spend,
                );
            }

            $after = $response->json('has_more') ? $response->json('last_id') : null;
        } while ($after !== null);

        return $records;
    }
}
