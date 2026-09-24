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
        config(['ads.api_key' => null]);
        $yesterday = CarbonImmutable::yesterday();

        $this->get('/')
            ->assertOk()
            ->assertSee('value="'.$yesterday->subDays(27)->toDateString().'"', false)
            ->assertSee('value="'.$yesterday->toDateString().'"', false);
    }

    public function test_metric_selector_offers_five_metrics_and_no_conversions(): void
    {
        config(['ads.api_key' => null]);
        $response = $this->get('/')->assertOk();

        foreach (['Impressions', 'Clicks', 'Spend', 'CTR', 'CPC'] as $label) {
            $response->assertSee('>'.$label.'</option>', false);
        }
        $response->assertDontSee('onversion');
        $response->assertSee('<option value="impressions" selected>', false);
    }

    public function test_changed_range_limits_the_data(): void
    {
        config(['ads.api_key' => null]);
        $to = CarbonImmutable::yesterday();
        $one = $this->get('/?from='.$to->toDateString().'&to='.$to->toDateString())->viewData('industryTotals');
        $seven = $this->get('/?from='.$to->subDays(6)->toDateString().'&to='.$to->toDateString())->viewData('industryTotals');

        $this->assertGreaterThan($one['Retail & eCommerce']['impressions'], $seven['Retail & eCommerce']['impressions']);
    }

    public function test_invalid_range_shows_an_error_and_keeps_the_previous_results(): void
    {
        config(['ads.api_key' => null]);
        $to = CarbonImmutable::yesterday();
        $from = $to->subDays(2)->toDateString();
        $this->get('/?from='.$from.'&to='.$to->toDateString())->assertOk();

        $this->get('/?from='.$to->toDateString().'&to='.$from)
            ->assertOk()
            ->assertSee('must be a date after or equal to')
            ->assertViewHas('from', $from)
            ->assertViewHas('to', $to->toDateString());
    }

    public function test_box_plot_has_three_industries_with_one_value_per_campaign(): void
    {
        config(['ads.api_key' => null]);
        $box = $this->get('/')->viewData('boxPlot');

        $this->assertSame(['Retail & eCommerce', 'Software & Technology', 'Education & Careers'], array_keys($box));
        $this->assertCount(9, $box['Retail & eCommerce']);
        $this->assertCount(5, $box['Education & Careers']);
    }

    public function test_metric_switch_changes_plotted_values(): void
    {
        config(['ads.api_key' => null]);
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

    public function test_dashboard_still_loads_synthetic_accounts_when_the_real_source_fails(): void
    {
        config(['ads.api_key' => null]);
        $this->get('/')->assertOk()
            ->assertSee('The Real account could not be loaded: no API key is configured.')
            ->assertSee('Retail &amp; eCommerce', false);

        config(['ads.api_key' => 'rejected']);
        Http::fake(['*' => Http::response([], 401)]);
        $this->get('/')->assertOk()->assertSee('The Real account could not be loaded');
    }

    public function test_drilldown_labels_real_and_synthetic_accounts_and_sums_match_totals(): void
    {
        config(['ads.api_key' => 'test-key']);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers'))->assertOk();
        $response->assertSee('>Real</span>', false)->assertSee('>Synthetic</span>', false)
            ->assertSee('Real Account #1')->assertSee('Campaign #1')
            ->assertDontSee('Spring launch');

        $drill = $response->viewData('drilldown');
        $labels = array_column($drill, 'label');
        $this->assertSame(1, count(array_keys($labels, 'Real')));
        $this->assertSame(2, count(array_keys($labels, 'Synthetic')));

        $industry = $response->viewData('industryTotals')['Education & Careers'];
        foreach (['impressions', 'clicks', 'spend'] as $metric) {
            $this->assertEquals($industry[$metric], round(array_sum(array_map(fn ($a) => $a['totals'][$metric] ?? 0, $drill)), 2));
        }
    }

    public function test_real_account_and_campaign_names_are_anonymized_everywhere(): void
    {
        config(['ads.api_key' => 'test-key']);
        $yesterday = CarbonImmutable::yesterday()->toDateString();
        Http::fake(['*' => Http::response(['data' => [$this->realRow($yesterday)], 'has_more' => false])]);

        $response = $this->get('/?industry='.urlencode('Education & Careers').'&metric=cpc')->assertOk();
        $response->assertDontSee('Spring launch')->assertDontSee('Spring');
    }
}
