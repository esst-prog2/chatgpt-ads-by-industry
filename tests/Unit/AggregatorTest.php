<?php

namespace Tests\Unit;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Services\Aggregator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AggregatorTest extends TestCase
{
    private function rec(string $account, string $campaign, string $date, int $imp, int $clk, float $spend, string $currency = 'HUF'): PerformanceRecord
    {
        return new PerformanceRecord($account, $campaign, $campaign, $date, $imp, $clk, $spend, $currency);
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

    public function test_box_plot_stats_never_produce_outliers_even_for_a_textbook_outlier_shaped_dataset(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Health']);
        // Six tightly clustered CPC values plus one far outside them - under
        // the usual Tukey 1.5*IQR rule this shape would flag 700 as an
        // outlier. boxPlotStats() must never do that: whiskers always span
        // the true min/max and 'outliers' is always empty.
        $cpcs = [100.0, 105.0, 110.0, 108.0, 102.0, 98.0, 700.0];
        $records = [];
        foreach ($cpcs as $i => $cpc) {
            $records[] = $this->rec('a1', "c{$i}", '2026-09-01', 100, 1, $cpc);
        }
        $totals = $agg->campaignTotals($records);

        $stats = $agg->boxPlotStats($totals, $mapping, 'cpc')['Health'];

        $this->assertSame([], $stats['outliers']);
        $this->assertEquals(98.0, $stats['min']);
        $this->assertEquals(700.0, $stats['max']);
        $this->assertEquals(98.0, $stats['whiskerMin']);
        $this->assertEquals(700.0, $stats['whiskerMax']);
        $this->assertCount(7, $stats['items']);
    }

    public function test_box_plot_stats_omits_an_industry_with_no_matching_points_same_as_box_plot_values(): void
    {
        $agg = new Aggregator;
        $mapping = new IndustryMapping(['a1' => 'Health']);
        $totals = $agg->campaignTotals([$this->rec('a1', 'c1', '2026-09-01', 0, 0, 0.0)]); // no clicks -> no cpc

        // Mirrors boxPlotValues()'s own contract exactly: an industry with no
        // non-null points for this metric is absent from the map, not an
        // empty-array entry - DashboardService supplies the [] default for
        // every known industry on top of this, same as it already does for
        // boxPlotValues().
        $this->assertArrayNotHasKey('Health', $agg->boxPlotStats($totals, $mapping, 'cpc'));
    }

    public function test_campaign_totals_keep_native_currency_and_also_expose_huf_normalized_figures(): void
    {
        $agg = new Aggregator(['HUF' => 1.0, 'EUR' => 400.0]);
        $totals = $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 1000, 10, 50.0, 'EUR'),
        ]);

        $c = $totals['a1|c1'];
        $this->assertSame('EUR', $c['currency']);
        $this->assertEquals(50.0, $c['spend']); // native, untouched
        $this->assertEquals(5.0, $c['cpc']); // native: 50 / 10
        $this->assertEquals(20000.0, $c['spendHuf']); // 50 * 400
        $this->assertEquals(2000.0, $c['cpcHuf']); // 20000 / 10
    }

    public function test_industry_totals_normalize_mixed_currencies_to_huf_not_a_naive_raw_sum(): void
    {
        $agg = new Aggregator(['HUF' => 1.0, 'EUR' => 400.0]);
        $mapping = new IndustryMapping(['huf-acct' => 'Professional Services', 'eur-acct' => 'Professional Services']);
        $totals = $agg->campaignTotals([
            $this->rec('huf-acct', 'c1', '2026-09-01', 1000, 10, 3000.0, 'HUF'),
            $this->rec('eur-acct', 'c1', '2026-09-01', 2000, 20, 50.0, 'EUR'),
        ]);

        $industry = $agg->industryTotals($totals, $mapping)['Professional Services'];

        // 3000 HUF + (50 EUR * 400) = 23000 HUF - not 3000 + 50 = 3050.
        $this->assertEquals(23000.0, $industry['spend']);
        $this->assertEquals('HUF', $industry['currency']);
        $this->assertSame(30, $industry['clicks']);
        $this->assertEqualsWithDelta(23000.0 / 30, $industry['cpc'], 0.01);
    }

    public function test_account_totals_stay_in_the_accounts_own_native_currency(): void
    {
        $agg = new Aggregator(['HUF' => 1.0, 'EUR' => 400.0]);
        $totals = $agg->campaignTotals([
            $this->rec('eur-acct', 'c1', '2026-09-01', 1000, 10, 50.0, 'EUR'),
        ]);

        $account = $agg->accountTotals($totals)['eur-acct'];
        $this->assertEquals(50.0, $account['spend']); // not converted
        $this->assertSame('EUR', $account['currency']);
    }

    public function test_box_plot_spend_and_cpc_use_huf_normalized_values_in_a_mixed_currency_industry(): void
    {
        $agg = new Aggregator(['HUF' => 1.0, 'EUR' => 400.0]);
        $mapping = new IndustryMapping(['huf-acct' => 'Professional Services', 'eur-acct' => 'Professional Services']);
        $totals = $agg->campaignTotals([
            $this->rec('huf-acct', 'c1', '2026-09-01', 1000, 10, 3000.0, 'HUF'),
            $this->rec('eur-acct', 'c1', '2026-09-01', 2000, 20, 50.0, 'EUR'),
        ]);

        $spendPoints = $agg->boxPlotValues($totals, $mapping, 'spend')['Professional Services'];
        sort($spendPoints);
        $this->assertSame([3000.0, 20000.0], $spendPoints); // 50 EUR shown as 20000 HUF, not 50
    }

    public function test_throws_when_a_record_uses_a_currency_with_no_configured_exchange_rate(): void
    {
        $agg = new Aggregator(['HUF' => 1.0]); // no USD rate configured
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no exchange rate configured for currency "USD"');
        $agg->campaignTotals([
            $this->rec('a1', 'c1', '2026-09-01', 100, 5, 10.0, 'USD'),
        ]);
    }
}
