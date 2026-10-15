<?php

namespace Tests\Unit;

use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Services\Aggregator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Isolated check of the weighted CTR/CPC aggregation math (App\Services\Aggregator)
 * against two mocked accounts, independent of any live API response. Account A and
 * Account B are driven through RealAdsSource (the same code path real accounts use)
 * with Http::fake(), so this exercises the real request/response handling without
 * a network call, then asserts the combined segment totals.
 */
class SegmentAggregationTest extends TestCase
{
    public function test_weighted_ctr_and_cpc_aggregate_correctly_across_two_mocked_accounts(): void
    {
        // Account A: impressions=100, clicks=10, spend=500.
        // Account B: impressions=200, clicks=30, spend=900.
        Http::fake([
            '*' => function ($request) {
                $isAccountA = $request->hasHeader('Authorization', 'Bearer account-a-key');
                $row = $isAccountA
                    ? ['campaign_id' => 'cmpn_a', 'campaign_name' => 'Account A Campaign', 'readable_time' => '2026-09-08', 'impressions' => 100, 'clicks' => 10, 'spend' => 500]
                    : ['campaign_id' => 'cmpn_b', 'campaign_name' => 'Account B Campaign', 'readable_time' => '2026-09-08', 'impressions' => 200, 'clicks' => 30, 'spend' => 900];

                return Http::response(['data' => [$row], 'has_more' => false]);
            },
        ]);

        $accountA = new RealAdsSource('account-a', 'account-a-key', 'Account A');
        $accountB = new RealAdsSource('account-b', 'account-b-key', 'Account B');

        $records = array_merge(
            $accountA->records('2026-09-08', '2026-09-08'),
            $accountB->records('2026-09-08', '2026-09-08'),
        );

        // Both accounts grouped into one segment so the aggregation combines them,
        // not a real industry - this test is independent of the dashboard's own mapping.
        $mapping = new IndustryMapping(['account-a' => 'Other', 'account-b' => 'Other']);
        $aggregator = new Aggregator;

        $campaignTotals = $aggregator->campaignTotals($records);
        $segment = $aggregator->industryTotals($campaignTotals, $mapping)['Other'];

        $this->assertSame(300, $segment['impressions']); // 100 + 200
        $this->assertSame(40, $segment['clicks']); // 10 + 30
        $this->assertEqualsWithDelta(1400.0, $segment['spend'], 0.01); // 500 + 900

        // Weighted CTR = total clicks / total impressions = 40 / 300 = 13.33%
        $this->assertEqualsWithDelta(13.33, $segment['ctr'] * 100, 0.01);

        // Weighted CPC = total spend / total clicks = 1400 / 40 = 35.00 HUF
        $this->assertEqualsWithDelta(35.00, $segment['cpc'], 0.01);
    }
}
