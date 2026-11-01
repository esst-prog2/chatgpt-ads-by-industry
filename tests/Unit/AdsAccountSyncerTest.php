<?php

namespace Tests\Unit;

use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use App\Services\AdsAccountSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdsAccountSyncerTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $date, int $imp, int $clk, float $spend, string $campaignId = 'cmpn_1'): array
    {
        return ['campaign_id' => $campaignId, 'campaign_name' => 'ignored', 'readable_time' => $date, 'impressions' => $imp, 'clicks' => $clk, 'spend' => $spend];
    }

    public function test_sync_stores_fetched_records_against_the_account(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-sync-test']);
        Http::fake(['*' => Http::response(['data' => [$this->row('2026-10-01', 100, 10, 500.0)], 'has_more' => false])]);

        (new AdsAccountSyncer)->sync($account, '2026-09-01', '2026-10-31');

        $stored = AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->get();
        $this->assertCount(1, $stored);
        $this->assertSame('2026-10-01', $stored->first()->date->toDateString());
        $this->assertSame(100, $stored->first()->impressions);
        $this->assertSame(10, $stored->first()->clicks);
        $this->assertEqualsWithDelta(500.0, $stored->first()->spend, 0.01);
        $this->assertSame('HUF', $stored->first()->currency);
        $this->assertSame('Campaign #1', $stored->first()->campaign);
    }

    public function test_a_second_sync_replaces_the_first_rather_than_accumulating(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-sync-test']);
        $syncer = new AdsAccountSyncer;

        // A second Http::fake(['*' => ...]) call doesn't replace the first -
        // Laravel's HTTP fake stack resolves the FIRST matching registered
        // stub, so both responses must be registered as one sequence instead
        // (confirmed by reading Illuminate\Http\Client\PendingRequest's
        // source earlier this session, not assumed).
        Http::fakeSequence()
            ->push(['data' => [$this->row('2026-10-01', 100, 10, 500.0)], 'has_more' => false])
            ->push(['data' => [$this->row('2026-10-01', 999, 99, 999.0)], 'has_more' => false]);

        $syncer->sync($account, '2026-09-01', '2026-10-31');
        $syncer->sync($account, '2026-09-01', '2026-10-31');

        $stored = AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->get();
        $this->assertCount(1, $stored); // not 2 - the stale row for that window was deleted first
        $this->assertSame(999, $stored->first()->impressions);
    }

    public function test_sync_only_replaces_records_within_the_synced_window(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-sync-test']);
        AdsAccountPerformanceRecord::create([
            'ads_account_id' => $account->id, 'campaign_id' => 'cmpn_old', 'campaign' => 'Campaign #1',
            'date' => '2025-01-01', 'impressions' => 1, 'clicks' => 1, 'spend' => 1.0, 'currency' => 'HUF',
        ]);
        Http::fake(['*' => Http::response(['data' => [$this->row('2026-10-01', 100, 10, 500.0)], 'has_more' => false])]);

        (new AdsAccountSyncer)->sync($account, '2026-09-01', '2026-10-31');

        $this->assertSame(2, AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->count());
    }
}
