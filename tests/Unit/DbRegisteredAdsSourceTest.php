<?php

namespace Tests\Unit;

use App\Data\DbRegisteredAdsSource;
use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbRegisteredAdsSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_is_real(): void
    {
        $this->assertSame('Real', (new DbRegisteredAdsSource)->label());
    }

    public function test_accounts_are_anonymized_and_keyed_by_internal_id(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $accounts = (new DbRegisteredAdsSource)->accounts();

        $this->assertSame(["db-account-{$account->id}" => "DB Account #{$account->id}"], $accounts);
    }

    public function test_records_reads_stored_snapshots_within_the_requested_window(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'EUR', 'ads_api_key' => 'sk-key']);
        AdsAccountPerformanceRecord::create([
            'ads_account_id' => $account->id, 'campaign_id' => 'c1', 'campaign' => 'Campaign #1',
            'date' => '2026-10-15', 'impressions' => 100, 'clicks' => 10, 'spend' => 50.0, 'currency' => 'EUR',
        ]);
        AdsAccountPerformanceRecord::create([
            'ads_account_id' => $account->id, 'campaign_id' => 'c1', 'campaign' => 'Campaign #1',
            'date' => '2025-01-01', 'impressions' => 1, 'clicks' => 1, 'spend' => 1.0, 'currency' => 'EUR',
        ]);

        $records = (new DbRegisteredAdsSource)->records('2026-10-01', '2026-10-31');

        $this->assertCount(1, $records); // the 2025 row is outside the window
        $this->assertSame("db-account-{$account->id}", $records[0]->account);
        $this->assertSame('2026-10-15', $records[0]->date);
        $this->assertSame('EUR', $records[0]->currency);
    }

    public function test_records_returns_empty_when_no_accounts_are_registered(): void
    {
        $this->assertSame([], (new DbRegisteredAdsSource)->records('2026-10-01', '2026-10-31'));
    }

    public function test_accounts_and_records_on_the_same_instance_query_ads_accounts_only_once(): void
    {
        // Regression test (PLANNING_LOG.md 2026-11-05): DashboardService::
        // build() calls accounts() then records() on the same instance
        // every request - previously each independently ran its own
        // AdsAccount::all(), querying the table twice for no reason.
        AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key-1']);
        AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key-2']);
        $source = new DbRegisteredAdsSource;

        DB::enableQueryLog();
        $source->accounts();
        $source->records('2026-10-01', '2026-10-31');
        $adsAccountQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"ads_accounts"'));
        DB::disableQueryLog();

        $this->assertCount(1, $adsAccountQueries);
    }

    public function test_records_with_multiple_accounts_runs_one_combined_query_not_one_per_account(): void
    {
        // Regression test (PLANNING_LOG.md 2026-11-05): previously records()
        // ran one performanceRecords() query per account in a loop.
        foreach (range(1, 3) as $n) {
            $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => "sk-key-{$n}"]);
            AdsAccountPerformanceRecord::create([
                'ads_account_id' => $account->id, 'campaign_id' => 'c1', 'campaign' => 'Campaign #1',
                'date' => '2026-10-15', 'impressions' => 10, 'clicks' => 1, 'spend' => 1.0, 'currency' => 'HUF',
            ]);
        }

        DB::enableQueryLog();
        $records = (new DbRegisteredAdsSource)->records('2026-10-01', '2026-10-31');
        $recordQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"ads_account_performance_records"'));
        DB::disableQueryLog();

        $this->assertCount(3, $records); // all 3 accounts' data is still present
        $this->assertCount(1, $recordQueries); // but fetched in one query, not three
    }
}
