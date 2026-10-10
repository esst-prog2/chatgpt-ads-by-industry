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

    private function realSource(string $apiKey = 'test-key', string $id = 'real-account-1', string $label = 'Real Account #1'): RealAdsSource
    {
        return new RealAdsSource($id, $apiKey, $label);
    }

    public function test_sources_share_one_record_shape(): void
    {
        $sources = [new SyntheticSource(self::END), $this->realSource()];
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
        // Retail & eCommerce and Software & Technology only: Education & Careers
        // has no synthetic placeholder since 2026-10-10 - it's exclusively the
        // two real accounts (see account_industry.csv, PLANNING_LOG.md).
        $this->assertCount(2, $industries);
        $this->assertArrayNotHasKey('Education & Careers', $industries);
        foreach ($industries as $accounts) {
            $this->assertGreaterThanOrEqual(1, count($accounts));
        }
        foreach ($campaigns as $set) {
            $this->assertGreaterThanOrEqual(2, count($set));
            $this->assertLessThanOrEqual(4, count($set));
        }
    }

    public function test_every_industry_has_at_least_two_accounts_counting_both_real_accounts(): void
    {
        // Education & Careers is exclusively the two real accounts, so it needs
        // both, not a synthetic placeholder, to reach the two-account minimum.
        $accounts = array_unique(array_merge(array_keys((new SyntheticSource(self::END))->accounts()), ['real-account-1', 'real-account-2']));
        $perIndustry = [];
        foreach ($accounts as $account) {
            $perIndustry[$this->mapping()->industryOf($account)][] = $account;
        }
        $this->assertCount(3, $perIndustry);
        foreach ($perIndustry as $industry => $list) {
            $this->assertGreaterThanOrEqual(2, count($list), $industry);
        }
        $this->assertEqualsCanonicalizing(['real-account-1', 'real-account-2'], $perIndustry['Education & Careers']);
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
        $this->assertSame('Education & Careers', $this->mapping()->industryOf('real-account-1'));
        $this->assertSame('Education & Careers', $this->mapping()->industryOf('real-account-2'));
    }

    public function test_real_source_returns_records_in_the_common_shape(): void
    {
        Http::fake(['*' => Http::response(['data' => [[
            'campaign_id' => 'cmpn_1', 'campaign_name' => 'Spring', 'readable_time' => '2026-09-20',
            'impressions' => 1200, 'clicks' => 36, 'spend' => 18.42,
        ]], 'has_more' => false])]);

        $records = $this->realSource()->records('2026-09-20', '2026-09-21');

        $this->assertCount(1, $records);
        $this->assertEquals(new PerformanceRecord('real-account-1', 'cmpn_1', 'Campaign #1', '2026-09-20', 1200, 36, 18.42), $records[0]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-key') && str_contains($r->url(), '/ad_account/insights'));
    }

    public function test_real_source_anonymizes_the_account_and_numbers_campaigns_in_order_seen(): void
    {
        $row = fn (string $id, string $name) => ['campaign_id' => $id, 'campaign_name' => $name, 'readable_time' => '2026-09-20', 'impressions' => 10, 'clicks' => 1, 'spend' => 1.0];
        Http::fake(['*' => Http::response(['data' => [
            $row('cmpn_9', 'Acme Autumn Sale'), $row('cmpn_2', 'Acme Retargeting'), $row('cmpn_9', 'Acme Autumn Sale'),
        ], 'has_more' => false])]);

        $source = $this->realSource();
        $records = $source->records('2026-09-20', '2026-09-20');

        $this->assertSame('Real Account #1', $source->accounts()['real-account-1']);
        $this->assertSame(['Campaign #1', 'Campaign #2', 'Campaign #1'], array_map(fn ($r) => $r->campaign, $records));
        foreach ($records as $r) {
            $this->assertStringNotContainsString('Acme', $r->campaign);
        }
    }

    public function test_real_source_follows_pagination(): void
    {
        $row = fn ($id) => ['campaign_id' => $id, 'campaign_name' => $id, 'readable_time' => '2026-09-20', 'impressions' => 10, 'clicks' => 1, 'spend' => 1.0];
        Http::fakeSequence()
            ->push(['data' => [$row('a')], 'has_more' => true, 'last_id' => 'cursor-1'])
            ->push(['data' => [$row('b')], 'has_more' => false]);

        $this->assertCount(2, $this->realSource()->records('2026-09-20', '2026-09-20'));
        Http::assertSent(fn ($r) => ($r->data()['after'] ?? null) === 'cursor-1');
    }

    public function test_real_source_treats_days_without_data_as_empty_not_zero(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['campaign_id' => 'a', 'campaign_name' => 'A', 'readable_time' => '2026-09-20', 'impressions' => 0, 'clicks' => 0, 'spend' => 0],
            ['campaign_id' => 'a', 'campaign_name' => 'A', 'readable_time' => '2026-09-21', 'impressions' => 50, 'clicks' => 2, 'spend' => 1.5],
        ], 'has_more' => false])]);

        $records = $this->realSource()->records('2026-09-20', '2026-09-21');
        $this->assertCount(1, $records);
        $this->assertSame('2026-09-21', $records[0]->date);
    }

    public function test_real_source_fails_without_key_or_on_rejection(): void
    {
        try {
            $this->realSource('')->records('2026-09-20', '2026-09-20');
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no API key', $e->getMessage());
        }

        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);
        $this->expectException(RuntimeException::class);
        $this->realSource('bad-key')->records('2026-09-20', '2026-09-20');
    }

    public function test_from_config_skips_accounts_with_no_key_and_numbers_the_rest_in_order(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'key-one'],
            ['id' => 'real-account-2', 'api_key' => ''],
            ['id' => 'real-account-3', 'api_key' => 'key-three'],
        ]]);

        $sources = RealAdsSource::fromConfig();

        $this->assertCount(2, $sources);
        $this->assertSame(['real-account-1' => 'Real Account #1'], $sources[0]->accounts());
        $this->assertSame(['real-account-3' => 'Real Account #2'], $sources[1]->accounts());
    }

    public function test_from_config_returns_no_sources_when_no_key_is_set(): void
    {
        config(['ads.accounts' => [['id' => 'real-account-1', 'api_key' => null]]]);

        $this->assertSame([], RealAdsSource::fromConfig());
    }
}
