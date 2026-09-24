<?php

namespace Tests\Unit;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Services\Aggregator;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase
{
    private function rec(string $account, string $campaign, string $date, int $imp, int $clk, float $spend): PerformanceRecord
    {
        return new PerformanceRecord($account, $campaign, $campaign, $date, $imp, $clk, $spend);
    }

    public function test_campaign_totals_sum_days_and_compute_ctr_from_sums(): void
    {
        $totals = (new Aggregator)->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 1000, 10, 5.0),
            $this->rec('a1', 'c1', '2026-09-02', 3000, 90, 15.5),
        ]);

        $c = $totals['a1|c1'];
        $this->assertSame(4000, $c['impressions']);
        $this->assertSame(100, $c['clicks']);
        $this->assertEquals(20.5, $c['spend']);
        $this->assertEquals(0.025, $c['ctr']);
    }

    public function test_two_accounts_in_one_industry_are_summed_and_ctr_is_not_averaged(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Education & Careers', 'a2' => 'Education & Careers', 'b1' => 'Automotive']);
        $totals = $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 1000, 100, 50.0),
            $this->rec('a2', 'c1', '2026-09-01', 9000, 90, 40.0),
            $this->rec('b1', 'c1', '2026-09-01', 500, 5, 1.0),
        ]);

        $industry = $agg->industryTotals($totals, $mapping);

        $edu = $industry['Education & Careers'];
        $this->assertSame(10000, $edu['impressions']);
        $this->assertSame(190, $edu['clicks']);
        $this->assertEquals(90.0, $edu['spend']);
        $this->assertEquals(0.019, $edu['ctr']);
        $this->assertNotEquals((0.1 + 0.01) / 2, $edu['ctr']);
        $this->assertSame(500, $industry['Automotive']['impressions']);
    }

    public function test_account_totals_add_up_to_industry_totals(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Health', 'a2' => 'Health']);
        $totals = $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 100, 5, 2.5),
            $this->rec('a1', 'c2', '2026-09-01', 200, 6, 3.5),
            $this->rec('a2', 'c1', '2026-09-01', 300, 7, 4.5),
        ]);
        $accounts = $agg->accountTotals($totals);
        $industry = $agg->industryTotals($totals, $mapping)['Health'];

        foreach (['impressions', 'clicks', 'spend'] as $metric) {
            $this->assertEquals($industry[$metric], $accounts['a1'][$metric] + $accounts['a2'][$metric]);
        }
    }

    public function test_zero_impression_campaign_has_no_ctr_and_is_left_out_of_the_box_plot(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Health']);
        $totals = $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 0, 0, 3.0),
            $this->rec('a1', 'c2', '2026-09-01', 100, 5, 1.0),
        ]);

        $this->assertNull($totals['a1|c1']['ctr']);
        $this->assertSame(['Health' => [0.05]], $agg->boxPlotValues($totals, $mapping, 'ctr'));
        $this->assertCount(2, $agg->boxPlotValues($totals, $mapping, 'spend')['Health']);
    }

    public function test_cpc_is_spend_over_clicks_and_zero_clicks_has_no_cpc(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Health']);
        $totals = $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 100, 0, 3.0),
            $this->rec('a1', 'c2', '2026-09-01', 200, 8, 20.0),
        ]);

        $this->assertNull($totals['a1|c1']['cpc']);
        $this->assertEquals(2.5, $totals['a1|c2']['cpc']);
        $this->assertSame(['Health' => [2.5]], $agg->boxPlotValues($totals, $mapping, 'cpc'));
    }
}
