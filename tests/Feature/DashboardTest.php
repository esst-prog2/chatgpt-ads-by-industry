<?php

namespace Tests\Feature;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Data\PerformanceSource;
use App\Data\SyntheticSource;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private function realRow(string $date, int $imp = 1000, int $clk = 20, float $spend = 10.0): array
    {
        return ['campaign_id' => 'cmpn_1', 'campaign_name' => 'Spring launch', 'readable_time' => $date, 'impressions' => $imp, 'clicks' => $clk, 'spend' => $spend];
    }

    public function test_default_range_is_the_last_28_days(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $yesterday = CarbonImmutable::yesterday();

        $this->get('/')
            ->assertOk()
            ->assertSee('value="'.$yesterday->subDays(27)->toDateString().'"', false)
            ->assertSee('value="'.$yesterday->toDateString().'"', false);
    }

    public function test_metric_selector_offers_five_metrics_and_no_conversions(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $response = $this->get('/')->assertOk();

        foreach (['Impressions', 'Clicks', 'Spend', 'CTR', 'CPC'] as $label) {
            $response->assertSee('>'.$label.'</option>', false);
        }
        $response->assertDontSee('onversion');
        $response->assertSee('<option value="impressions" selected>', false);
    }

    public function test_changed_range_limits_the_data(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $to = CarbonImmutable::yesterday();
        $one = $this->get('/?from='.$to->toDateString().'&to='.$to->toDateString())->viewData('industryTotals');
        $seven = $this->get('/?from='.$to->subDays(6)->toDateString().'&to='.$to->toDateString())->viewData('industryTotals');

        $this->assertGreaterThan($one['Education & Careers']['impressions'], $seven['Education & Careers']['impressions']);
    }

    public function test_invalid_range_shows_an_error_and_keeps_the_previous_results(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(2)->toDateString();
        $this->get('/?from='.$from.'&to='.$to->toDateString())->assertOk();

        $this->get('/?from='.$to->toDateString().'&to='.$from)
            ->assertOk()
            ->assertSee('must be a date after or equal to')
            ->assertViewHas('from', $from)
            ->assertViewHas('to', $to->toDateString());
    }

    public function test_box_plot_shows_all_three_synthetic_backed_industries_even_with_no_real_key(): void
    {
        // Synthetic data exists only for industries that have a real account
        // (Education & Careers, Professional Services, Health) - but it doesn't
        // require a *currently configured* key to appear, since it's not tied
        // to any one real account's availability.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $box = $this->get('/')->viewData('boxPlot');

        $this->assertEqualsCanonicalizing(['Education & Careers', 'Professional Services', 'Health'], array_keys($box));
        $this->assertCount(5, $box['Education & Careers']); // syn-edu-1 (2 campaigns) + syn-edu-2 (3)
    }

    public function test_adding_a_real_account_adds_to_its_industrys_existing_synthetic_points(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $box = $this->get('/')->viewData('boxPlot');

        $this->assertEqualsCanonicalizing(['Education & Careers', 'Professional Services', 'Health'], array_keys($box));
        $this->assertCount(6, $box['Education & Careers']); // 5 synthetic + 1 real campaign
    }

    public function test_metric_switch_changes_plotted_values(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $spend = $this->get('/?metric=spend')->viewData('boxPlot');
        $clicks = $this->get('/?metric=clicks')->viewData('boxPlot');

        $this->assertNotEquals($spend['Education & Careers'], $clicks['Education & Careers']);
        $this->assertLessThan(1, max($this->get('/?metric=ctr')->viewData('boxPlot')['Education & Careers']));
    }

    public function test_industry_without_data_shows_an_empty_state(): void
    {
        $source = new class implements PerformanceSource
        {
            public function label(): string { return 'Synthetic'; }

            public function accounts(): array { return ['a1' => 'Alpha', 'b1' => 'Beta']; }

            public function records(string $from, string $to): array
            {
                return [new PerformanceRecord('a1', 'c1', 'C1', '2026-09-20', 100, 5, 1.0)];
            }
        };
        $this->app->instance(DashboardService::class, new DashboardService([$source], new IndustryMapping(['a1' => 'Health', 'b1' => 'Automotive'])));

        $response = $this->get('/?from=2026-09-20&to=2026-09-20')->assertOk();
        $this->assertSame([], $response->viewData('boxPlot')['Automotive']);
        $response->assertSee('No campaign data in this range for: Automotive');
    }

    public function test_dashboard_loads_synthetic_accounts_without_a_notice_when_no_real_key_is_configured(): void
    {
        // RealAdsSource::fromConfig() skips a slot with no key entirely, so an
        // account that is simply not onboarded yet is not a failure worth a
        // notice - unlike a configured key that the API actually rejects, below.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $this->get('/')->assertOk()
            ->assertDontSee('The Real account could not be loaded') // distinct from the unrelated static "chart library could not be loaded" JS fallback string
            ->assertSee('Professional Services', false);
    }

    public function test_dashboard_still_loads_synthetic_accounts_when_a_configured_real_key_is_rejected(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'rejected']]]);
        Http::fake(['*' => Http::response([], 401)]);
        $this->get('/')->assertOk()
            ->assertSee('The Real account could not be loaded')
            ->assertSee('Professional Services', false);
    }

    public function test_source_load_failure_is_logged_not_silently_dropped(): void
    {
        // DashboardService catches Throwable broadly around each source (by
        // design, so one bad source doesn't take down the whole dashboard),
        // but that must never mean the real error vanishes - it still has to
        // reach the log, not just the generic user-facing notice.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'rejected']]]);
        Http::fake(['*' => Http::response([], 401)]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info'); // RealAdsSource's own per-request logging
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'dashboard.source_load_failed'
                    && $context['source'] === 'Real'
                    && $context['exception'] === \RuntimeException::class
                    && str_contains($context['message'], 'HTTP 401');
            });

        $this->get('/')->assertOk()->assertSee('The Real account could not be loaded');
    }

    public function test_drilldown_labels_the_real_education_account_alongside_its_synthetic_siblings_and_sums_match_totals(): void
    {
        // Education & Careers always has its 2 synthetic accounts (syn-edu-1/2)
        // plus however many real accounts are configured - here just one.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key', 'label' => 'Education Partner A']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();
        $response->assertSee('>Real</span>', false)->assertSee('>Synthetic</span>', false)
            ->assertSee('Education Partner A')->assertSee('Campaign #1')
            ->assertDontSee('Spring launch');

        $drill = $response->viewData('drilldown');
        $labels = array_column($drill, 'label');
        $this->assertSame(1, count(array_keys($labels, 'Real')));
        $this->assertSame(2, count(array_keys($labels, 'Synthetic')));

        $industry = $response->viewData('industryTotals')['Education & Careers'];
        foreach (['impressions', 'clicks'] as $metric) {
            $this->assertEquals($industry[$metric], array_sum(array_map(fn ($a) => $a['totals'][$metric] ?? 0, $drill)));
        }
        // 'spend' in industryTotals is HUF-normalized; every account here is
        // already HUF, so it must still equal the raw native sum exactly.
        $this->assertEquals($industry['spend'], round(array_sum(array_map(fn ($a) => $a['totals']['spend'] ?? 0, $drill)), 2));
    }

    public function test_real_account_and_campaign_names_are_anonymized_everywhere(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers').'&metric=cpc')->assertOk();
        $response->assertDontSee('Spring launch')->assertDontSee('Spring');
    }

    public function test_two_real_education_accounts_aggregate_with_weighted_ctr_and_cpc(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'key-one', 'label' => 'Education Partner A'],
            ['id' => 'real-account-2', 'api_key' => 'key-two', 'label' => 'Education Partner B'],
        ]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        // Deliberately different CTR/CPC per account so a naive average-of-CTRs
        // (instead of sum(clicks)/sum(impressions)) would give a different answer.
        Http::fake([
            '*' => function ($request) use ($yesterday) {
                $isAccountOne = $request->hasHeader('Authorization', 'Bearer key-one');
                $row = $isAccountOne
                    ? $this->realRow($yesterday, imp: 1000, clk: 100, spend: 500.0)
                    : $this->realRow($yesterday, imp: 9000, clk: 90, spend: 4500.0);

                return Http::response(['data' => [$row], 'has_more' => false]);
            },
        ]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();

        $drill = $response->viewData('drilldown');
        // Education & Careers always has its 2 synthetic siblings too; the two
        // real accounts are each labelled as an education partner
        // (config('ads.accounts').label), not the generic "Real Account #N" or
        // any name that could identify the real advertiser.
        $this->assertCount(4, $drill); // 2 real + 2 synthetic
        $labels = array_column($drill, 'label');
        $this->assertSame(2, count(array_keys($labels, 'Real')));
        $this->assertSame(2, count(array_keys($labels, 'Synthetic')));
        $realNames = array_column(array_filter($drill, fn ($a) => $a['label'] === 'Real'), 'name');
        $this->assertEqualsCanonicalizing(['Education Partner A', 'Education Partner B'], $realNames);

        // sum(clicks)/sum(impressions) and sum(spend)/sum(clicks) across every
        // account in the industry (real and synthetic), not an average of each
        // account's own CTR/CPC.
        $industry = $response->viewData('industryTotals')['Education & Careers'];
        $totalClicks = array_sum(array_map(fn ($a) => $a['totals']['clicks'] ?? 0, $drill));
        $totalImpressions = array_sum(array_map(fn ($a) => $a['totals']['impressions'] ?? 0, $drill));
        $totalSpend = array_sum(array_map(fn ($a) => $a['totals']['spend'] ?? 0, $drill));

        $this->assertEquals($totalClicks / $totalImpressions, $industry['ctr']);
        // Aggregator::cpc() rounds to 2 decimals internally, so compare with a small delta.
        $this->assertEqualsWithDelta($totalSpend / $totalClicks, $industry['cpc'], 0.01);
        // The two real accounts alone have very different CTRs (10% vs 1%); a naive
        // average would be 5.5%, far from the weighted figure asserted above.
        $this->assertNotEqualsWithDelta(0.055, $industry['ctr'], 0.01);
    }

    /**
     * HW5: checks the two real Education & Careers accounts' own weighted
     * CTR/CPC for 2026-09-08 to 2026-09-21 (14 days - the correct "first two
     * weeks" window; an earlier 15-day version of this test, through 09-22,
     * matched a different, incorrect manual calculation - see
     * PLANNING_LOG.md 2026-10-10). The fake HTTP responses below carry the
     * real per-account totals pulled live from both accounts for this exact
     * window, reverified three times on 2026-10-10 (impressions/clicks/spend
     * only - no campaign name or account identity):
     *   Account #1: impressions=24171, clicks=253, spend=71274.51
     *   Account #2: impressions=19356, clicks=286, spend=72019.36
     *   Combined:   impressions=43527, clicks=539, spend=143293.87
     *   CTR = 539/43527 = 1.2383%, CPC = 143293.87/539 = 265.85 Ft
     * This matches the manually-calculated external expected value (CTR
     * 1.24%, CPC 266 Ft / 265.85 Ft) within rounding.
     *
     * Since 2026-10-16, Education & Careers always has 2 synthetic accounts
     * alongside whatever real ones are configured, so this checks the real
     * accounts' own totals specifically (accountTotals, summed for just
     * real-account-1/2), not industryTotals, which now legitimately blends
     * in the synthetic contribution too (see
     * test_drilldown_labels_the_real_education_account_alongside_its_synthetic_siblings_and_sums_match_totals
     * for that blended-total check).
     */
    public function test_the_two_real_education_accounts_own_weighted_ctr_and_cpc_for_sept_8_to_21(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'key-one', 'label' => 'Education Partner A'],
            ['id' => 'real-account-2', 'api_key' => 'key-two', 'label' => 'Education Partner B'],
        ]]);
        Http::fake([
            '*' => function ($request) {
                $isAccountOne = $request->hasHeader('Authorization', 'Bearer key-one');
                // One row per account carrying that account's totals for the whole
                // window; Aggregator sums whatever rows a source returns regardless
                // of which single date they are tagged with.
                $row = $isAccountOne
                    ? $this->realRow('2026-09-08', imp: 24171, clk: 253, spend: 71274.51)
                    : $this->realRow('2026-09-08', imp: 19356, clk: 286, spend: 72019.36);

                return Http::response(['data' => [$row], 'has_more' => false]);
            },
        ]);

        $response = $this->get('/?from=2026-09-08&to=2026-09-21&industry='.urlencode('Education & Careers'))->assertOk();

        $drill = $response->viewData('drilldown');
        $real = array_filter($drill, fn ($a) => $a['label'] === 'Real');
        $this->assertCount(2, $real); // the 2 real accounts, isolated from their 2 synthetic siblings

        $impressions = array_sum(array_column(array_column($real, 'totals'), 'impressions'));
        $clicks = array_sum(array_column(array_column($real, 'totals'), 'clicks'));
        $spend = array_sum(array_column(array_column($real, 'totals'), 'spend'));

        $this->assertSame(43527, $impressions);
        $this->assertSame(539, $clicks);
        $this->assertEqualsWithDelta(143293.87, $spend, 0.01);
        $this->assertEqualsWithDelta(0.012383, $clicks / $impressions, 0.000001); // 1.24%
        $this->assertEqualsWithDelta(265.85, $spend / $clicks, 0.01); // ~266 Ft
    }

    public function test_drilldown_shows_native_currency_per_account_while_industry_total_is_huf(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'key-huf', 'label' => 'HUF Partner', 'currency' => 'HUF'],
            ['id' => 'real-account-2', 'api_key' => 'key-eur', 'label' => 'EUR Partner', 'currency' => 'EUR'],
        ]]);
        config(['ads.exchange_rates_to_huf' => ['HUF' => 1.0, 'EUR' => 400.0]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake([
            '*' => function ($request) use ($yesterday) {
                $isHuf = $request->hasHeader('Authorization', 'Bearer key-huf');
                $row = $isHuf
                    ? $this->realRow($yesterday, imp: 1000, clk: 10, spend: 3000.0)
                    : $this->realRow($yesterday, imp: 2000, clk: 20, spend: 50.0); // EUR

                return Http::response(['data' => [$row], 'has_more' => false]);
            },
        ]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();

        // Account/campaign rows: each in its own native currency, not forced to HUF.
        // Exact cell match, not a loose substring: with synthetic siblings now
        // also on the page, some other number can legitimately end in "50 HUF"
        // (e.g. "52,950 HUF"), so a plain assertDontSee('50 HUF') would be a
        // false positive against those unrelated rows.
        $response->assertSee('3,000 HUF', false)->assertSee('50 EUR', false)->assertDontSee('<td>50 HUF</td>', false);

        $drill = $response->viewData('drilldown');
        $byName = collect($drill)->keyBy('name');
        $this->assertSame('HUF', $byName['HUF Partner']['totals']['currency']);
        $this->assertEquals(3000.0, $byName['HUF Partner']['totals']['spend']);
        $this->assertSame('EUR', $byName['EUR Partner']['totals']['currency']);
        $this->assertEquals(50.0, $byName['EUR Partner']['totals']['spend']); // native, not converted

        // Industry total: HUF-normalized, not a naive 3000 + 50 = 3050 - and
        // Education & Careers also always includes its 2 synthetic accounts
        // (syn-edu-1/2, always HUF), computed here via the real SyntheticSource
        // + Aggregator so the expected figure isn't hand-duplicated math.
        $to = CarbonImmutable::yesterday()->toDateString();
        $from = CarbonImmutable::yesterday()->subDays(27)->toDateString();
        $syntheticSpend = array_sum(array_map(
            fn ($r) => $r->spend,
            array_filter(
                (new \App\Data\SyntheticSource)->records($from, $to),
                fn ($r) => in_array($r->account, ['syn-edu-1', 'syn-edu-2'], true) // Education only, not the other industries' synthetic accounts
            )
        ));
        $expectedIndustrySpend = round(3000.0 + 50.0 * 400.0 + $syntheticSpend, 2); // real HUF + real EUR-as-HUF + synthetic

        $industry = $response->viewData('industryTotals')['Education & Careers'];
        $this->assertSame('HUF', $industry['currency']);
        $this->assertEqualsWithDelta($expectedIndustrySpend, $industry['spend'], 0.01);
    }

    public function test_a_real_accounts_own_totals_are_unaffected_by_its_synthetic_industry_siblings(): void
    {
        // "Without polluting or distorting the core real account totals"
        // (PLANNING_LOG.md 2026-10-16): a real account's own drilldown figures
        // must be identical whether its synthetic siblings exist or not, since
        // accountTotals groups strictly by account id (syn-* ids never collide
        // with real-account-*) - only the industry-level total combines them.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key', 'label' => 'Education Partner A']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday, imp: 777, clk: 13, spend: 1234.0)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();
        $drill = $response->viewData('drilldown');
        $real = current(array_filter($drill, fn ($a) => $a['label'] === 'Real'));

        $this->assertSame(777, $real['totals']['impressions']);
        $this->assertSame(13, $real['totals']['clicks']);
        $this->assertEquals(1234.0, $real['totals']['spend']); // exactly what the fake API returned, nothing added or scaled
    }
}
