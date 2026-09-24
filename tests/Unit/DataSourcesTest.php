<?php

namespace Tests\Unit;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Data\PerformanceSource;
use App\Data\RealAdsSource;
use App\Data\SyntheticSource;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DataSourcesTest extends TestCase
{
    private const END = '2026-09-23';

    private function mapping(): IndustryMapping
    {
        return IndustryMapping::fromCsv(database_path('data/account_industry.csv'));
    }

    public function test_sources_share_one_record_shape(): void
    {
        $sources = [new SyntheticSource(self::END), new RealAdsSource];
        foreach ($sources as $source) {
            $this->assertInstanceOf(PerformanceSource::class, $source);
        }
        $record = (new SyntheticSource(self::END))->records('2026-09-23', '2026-09-23')[0];
        $this->assertInstanceOf(PerformanceRecord::class, $record);
        $this->assertFalse(property_exists($record, 'conversions'));
    }

    public function test_synthetic_spend_exactly_equals_clicks_times_cpc_with_no_rounding_drift(): void
    {
        $records = (new SyntheticSource(self::END))->records('2000-01-01', '2100-01-01');
        $bands = [
            'Retail & eCommerce' => [120, 350],
            'Software & Technology' => [400, 1200],
            'Education & Careers' => [250, 600],
        ];

        foreach ($records as $r) {
            if ($r->clicks === 0) {
                continue;
            }
            $cpc = $r->spend / $r->clicks;
            $this->assertSame(0.0, fmod($cpc, 1.0), "CPC for {$r->account}/{$r->campaign} on {$r->date} is not a whole HUF amount");
            $this->assertEqualsWithDelta($cpc, round($cpc), 0.0, 'spend / clicks must exactly equal the whole-HUF CPC, no rounding artifact');

            [$low, $high] = $bands[$this->mapping()->industryOf($r->account)];
            $this->assertGreaterThanOrEqual($low * 0.5, $cpc);
            $this->assertLessThanOrEqual($high * 1.5, $cpc);
        }
    }

    public function test_synthetic_dataset_shape(): void
    {
        $source = new SyntheticSource(self::END);
        $records = $source->records('2000-01-01', '2100-01-01');

        $this->assertCount(30, array_unique(array_map(fn ($r) => $r->date, $records)));

        $industries = [];
        $campaigns = [];
        foreach ($records as $r) {
            $industries[$this->mapping()->industryOf($r->account)][$r->account] = true;
            $campaigns[$r->account][$r->campaignId] = true;
        }
        $this->assertCount(3, $industries);
        foreach ($industries as $accounts) {
            $this->assertGreaterThanOrEqual(1, count($accounts));
        }
        foreach ($campaigns as $set) {
            $this->assertGreaterThanOrEqual(2, count($set));
            $this->assertLessThanOrEqual(4, count($set));
        }
    }

    public function test_every_industry_has_at_least_two_accounts_counting_the_real_one(): void
    {
        $accounts = array_unique(array_merge(array_keys((new SyntheticSource(self::END))->accounts()), [RealAdsSource::ACCOUNT_ID]));
        $perIndustry = [];
        foreach ($accounts as $account) {
            $perIndustry[$this->mapping()->industryOf($account)][] = $account;
        }
        $this->assertCount(3, $perIndustry);
        foreach ($perIndustry as $industry => $list) {
            $this->assertGreaterThanOrEqual(2, count($list), $industry);
            $this->assertLessThanOrEqual(3, count($list), $industry);
        }
        $this->assertContains(RealAdsSource::ACCOUNT_ID, $perIndustry['Education & Careers']);
    }

    public function test_synthetic_loads_are_identical(): void
    {
        $a = (new SyntheticSource(self::END))->records('2026-09-01', '2026-09-23');
        $b = (new SyntheticSource(self::END))->records('2026-09-01', '2026-09-23');
        $this->assertEquals($a, $b);
    }

    public function test_synthetic_respects_requested_range(): void
    {
        $records = (new SyntheticSource(self::END))->records('2026-09-20', '2026-09-22');
        $this->assertEqualsCanonicalizing(['2026-09-20', '2026-09-21', '2026-09-22'], array_values(array_unique(array_map(fn ($r) => $r->date, $records))));
    }

    public function test_mapping_rejects_unknown_industry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Space Mining');
        new IndustryMapping(['acc-1' => 'Space Mining']);
    }

    public function test_mapping_groups_unmapped_account_under_other(): void
    {
        $this->assertSame('Other', $this->mapping()->industryOf('nobody'));
        $this->assertSame('Education & Careers', $this->mapping()->industryOf(RealAdsSource::ACCOUNT_ID));
    }

    public function test_real_source_returns_records_in_the_common_shape(): void
    {
        config(['ads.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['data' => [[
            'campaign_id' => 'cmpn_1', 'campaign_name' => 'Spring', 'readable_time' => '2026-09-20',
            'impressions' => 1200, 'clicks' => 36, 'spend' => 18.42,
        ]], 'has_more' => false])]);

        $records = (new RealAdsSource)->records('2026-09-20', '2026-09-21');

        $this->assertCount(1, $records);
        $this->assertEquals(new PerformanceRecord('real-account', 'cmpn_1', 'Campaign #1', '2026-09-20', 1200, 36, 18.42), $records[0]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-key') && str_contains($r->url(), '/ad_account/insights'));
    }

    public function test_real_source_anonymizes_the_account_and_numbers_campaigns_in_order_seen(): void
    {
        config(['ads.api_key' => 'test-key']);
        $row = fn (string $id, string $name) => ['campaign_id' => $id, 'campaign_name' => $name, 'readable_time' => '2026-09-20', 'impressions' => 10, 'clicks' => 1, 'spend' => 1.0];
        Http::fake(['*' => Http::response(['data' => [
            $row('cmpn_9', 'Acme Autumn Sale'), $row('cmpn_2', 'Acme Retargeting'), $row('cmpn_9', 'Acme Autumn Sale'),
        ], 'has_more' => false])]);

        $records = (new RealAdsSource)->records('2026-09-20', '2026-09-20');

        $this->assertSame('Real Account #1', (new RealAdsSource)->accounts()[RealAdsSource::ACCOUNT_ID]);
        $this->assertSame(['Campaign #1', 'Campaign #2', 'Campaign #1'], array_map(fn ($r) => $r->campaign, $records));
        foreach ($records as $r) {
            $this->assertStringNotContainsString('Acme', $r->campaign);
        }
    }

    public function test_real_source_follows_pagination(): void
    {
        config(['ads.api_key' => 'test-key']);
        $row = fn ($id) => ['campaign_id' => $id, 'campaign_name' => $id, 'readable_time' => '2026-09-20', 'impressions' => 10, 'clicks' => 1, 'spend' => 1.0];
        Http::fakeSequence()
            ->push(['data' => [$row('a')], 'has_more' => true, 'last_id' => 'cursor-1'])
            ->push(['data' => [$row('b')], 'has_more' => false]);

        $this->assertCount(2, (new RealAdsSource)->records('2026-09-20', '2026-09-20'));
        Http::assertSent(fn ($r) => ($r->data()['after'] ?? null) === 'cursor-1');
    }

    public function test_real_source_treats_days_without_data_as_empty_not_zero(): void
    {
        config(['ads.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['data' => [
            ['campaign_id' => 'a', 'campaign_name' => 'A', 'readable_time' => '2026-09-20', 'impressions' => 0, 'clicks' => 0, 'spend' => 0],
            ['campaign_id' => 'a', 'campaign_name' => 'A', 'readable_time' => '2026-09-21', 'impressions' => 50, 'clicks' => 2, 'spend' => 1.5],
        ], 'has_more' => false])]);

        $records = (new RealAdsSource)->records('2026-09-20', '2026-09-21');
        $this->assertCount(1, $records);
        $this->assertSame('2026-09-21', $records[0]->date);
    }

    public function test_real_source_fails_without_key_or_on_rejection(): void
    {
        config(['ads.api_key' => null]);
        try {
            (new RealAdsSource)->records('2026-09-20', '2026-09-20');
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no API key', $e->getMessage());
        }

        config(['ads.api_key' => 'bad']);
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);
        $this->expectException(RuntimeException::class);
        (new RealAdsSource)->records('2026-09-20', '2026-09-20');
    }
}
