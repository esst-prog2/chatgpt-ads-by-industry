<?php

namespace Tests\Feature;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Data\PerformanceSource;
use App\Data\SyntheticSource;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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

        $this->assertGreaterThan($one['Retail & eCommerce']['impressions'], $seven['Retail & eCommerce']['impressions']);
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

    public function test_box_plot_has_two_synthetic_industries_with_one_value_per_campaign_and_no_real_key(): void
    {
        // Education & Careers has no synthetic placeholder: with no real key
        // configured, it has no accounts at all and so does not appear.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $box = $this->get('/')->viewData('boxPlot');

        $this->assertSame(['Retail & eCommerce', 'Software & Technology'], array_keys($box));
        $this->assertCount(9, $box['Retail & eCommerce']);
    }

    public function test_education_careers_appears_only_once_a_real_account_is_configured(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $box = $this->get('/')->viewData('boxPlot');

        $this->assertSame(['Retail & eCommerce', 'Software & Technology', 'Education & Careers'], array_keys($box));
        $this->assertCount(1, $box['Education & Careers']); // one real campaign, no synthetic placeholder
    }

    public function test_metric_switch_changes_plotted_values(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);
        $spend = $this->get('/?metric=spend')->viewData('boxPlot');
        $clicks = $this->get('/?metric=clicks')->viewData('boxPlot');

        $this->assertNotEquals($spend['Retail & eCommerce'], $clicks['Retail & eCommerce']);
        $this->assertLessThan(1, max($this->get('/?metric=ctr')->viewData('boxPlot')['Retail & eCommerce']));
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
            ->assertSee('Retail &amp; eCommerce', false);
    }

    public function test_dashboard_still_loads_synthetic_accounts_when_a_configured_real_key_is_rejected(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'rejected']]]);
        Http::fake(['*' => Http::response([], 401)]);
        $this->get('/')->assertOk()
            ->assertSee('The Real account could not be loaded')
            ->assertSee('Retail &amp; eCommerce', false);
    }

    public function test_drilldown_labels_the_real_education_account_and_sums_match_totals(): void
    {
        // Education & Careers is exclusively real accounts (no synthetic
        // placeholder), so with just one key configured its drilldown has
        // exactly one account, labelled Real, with no Synthetic entries at all.
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => 'test-key', 'label' => 'Education Partner A']]]);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();
        $response->assertSee('>Real</span>', false)->assertDontSee('>Synthetic</span>', false)
            ->assertSee('Education Partner A')->assertSee('Campaign #1')
            ->assertDontSee('Spring launch');

        $drill = $response->viewData('drilldown');
        $labels = array_column($drill, 'label');
        $this->assertSame(['Real'], array_values($labels));

        $industry = $response->viewData('industryTotals')['Education & Careers'];
        foreach (['impressions', 'clicks', 'spend'] as $metric) {
            $this->assertEquals($industry[$metric], round(array_sum(array_map(fn ($a) => $a['totals'][$metric] ?? 0, $drill)), 2));
        }
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
        // Education & Careers is exclusively these two real accounts - no
        // synthetic placeholder - and each is labelled as an education partner
        // (config('ads.accounts').label), not the generic "Real Account #N" or
        // any name that could identify the real advertiser.
        $this->assertCount(2, $drill);
        $this->assertSame(['Real', 'Real'], array_column($drill, 'label'));
        $this->assertEqualsCanonicalizing(['Education Partner A', 'Education Partner B'], array_column($drill, 'name'));

        // sum(clicks)/sum(impressions) and sum(spend)/sum(clicks) across both
        // real accounts, not an average of each account's own CTR/CPC.
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
     * HW5: checks the dashboard's Education & Careers weighted CTR/CPC for
     * 2026-09-08 to 2026-09-22 against the manually-calculated external
     * expected value in PLANNING_LOG.md. The fake HTTP responses below carry
     * the real per-account totals pulled live from both accounts for this
     * exact window on 2026-10-10 (impressions/clicks/spend only - no campaign
     * name or account identity): combined, impressions=46453, clicks=558,
     * spend=155501.64 HUF, giving CTR 1.2012% and CPC 278.68 HUF.
     *
     * That does not match the manually-calculated external expected value
     * (CTR 1.21%, CPC 272 Ft) - CTR is within rounding, CPC is off by about
     * 2.4%, more than rounding. Per 2026-10-10 PLANNING_LOG.md, logged as the
     * manual calculation being in error: this test asserts the real,
     * reproduced figures, not the external 272 Ft value.
     */
    public function test_education_segment_weighted_ctr_and_cpc_for_sept_8_to_22(): void
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
                    ? $this->realRow('2026-09-08', imp: 26262, clk: 265, spend: 77820.05)
                    : $this->realRow('2026-09-08', imp: 20191, clk: 293, spend: 77681.59);

                return Http::response(['data' => [$row], 'has_more' => false]);
            },
        ]);

        $response = $this->get('/?from=2026-09-08&to=2026-09-22&industry='.urlencode('Education & Careers'))->assertOk();
        $industry = $response->viewData('industryTotals')['Education & Careers'];

        $this->assertSame(46453, $industry['impressions']);
        $this->assertSame(558, $industry['clicks']);
        $this->assertEqualsWithDelta(155501.64, $industry['spend'], 0.01);
        $this->assertEqualsWithDelta(0.012012, $industry['ctr'], 0.000001);
        $this->assertEqualsWithDelta(278.68, $industry['cpc'], 0.01);

        // Documents the mismatch against the external expected value rather than
        // hiding it: the real figures are not within simple-rounding distance of
        // the manually-calculated 272 Ft.
        $this->assertGreaterThan(1.0, abs($industry['cpc'] - 272));
    }
}
