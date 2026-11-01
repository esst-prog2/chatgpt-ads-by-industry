<?php

namespace Tests\Feature;

use App\Jobs\SyncAdsAccountJob;
use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use App\Services\AdsAccountSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdsAccountSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_all_command_dispatches_a_job_per_registered_account(): void
    {
        $a = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-a']);
        $b = AdsAccount::create(['industry' => 'Automotive', 'currency' => 'EUR', 'ads_api_key' => 'sk-b']);

        Artisan::call('ads-accounts:sync-all');

        Queue::assertPushed(SyncAdsAccountJob::class, 2);
        Queue::assertPushed(SyncAdsAccountJob::class, fn (SyncAdsAccountJob $job) => $job->adsAccount->is($a));
        Queue::assertPushed(SyncAdsAccountJob::class, fn (SyncAdsAccountJob $job) => $job->adsAccount->is($b));
    }

    public function test_sync_all_command_dispatches_nothing_when_no_accounts_are_registered(): void
    {
        Artisan::call('ads-accounts:sync-all');

        Queue::assertNotPushed(SyncAdsAccountJob::class);
    }

    public function test_the_jobs_handle_method_calls_the_syncer_against_the_default_window(): void
    {
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);
        Http::fake(['*' => Http::response(['data' => [[
            'campaign_id' => 'c1', 'campaign_name' => 'ignored', 'readable_time' => now()->yesterday()->toDateString(),
            'impressions' => 500, 'clicks' => 25, 'spend' => 100.0,
        ]], 'has_more' => false])]);

        (new SyncAdsAccountJob($account))->handle(new AdsAccountSyncer);

        $this->assertSame(1, AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->count());
    }

    public function test_nightly_schedule_registers_the_sync_all_command(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command ?? '', 'ads-accounts:sync-all')));
    }

    public function test_sync_all_command_actually_syncs_every_account_with_no_queue_worker_involved(): void
    {
        // Queue::fake()->except([...]) lets the job genuinely run - proves
        // the command itself does the real work (dispatchSync()), not just
        // that it hands something off to a worker that may not exist.
        Queue::fake()->except([SyncAdsAccountJob::class]);
        $a = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-a']);
        $b = AdsAccount::create(['industry' => 'Automotive', 'currency' => 'EUR', 'ads_api_key' => 'sk-b']);
        Http::fake(['*' => Http::response(['data' => [[
            'campaign_id' => 'c1', 'campaign_name' => 'ignored', 'readable_time' => now()->yesterday()->toDateString(),
            'impressions' => 200, 'clicks' => 10, 'spend' => 40.0,
        ]], 'has_more' => false])]);

        Artisan::call('ads-accounts:sync-all');

        $this->assertSame(1, AdsAccountPerformanceRecord::where('ads_account_id', $a->id)->count());
        $this->assertSame(1, AdsAccountPerformanceRecord::where('ads_account_id', $b->id)->count());
    }

    public function test_sync_all_command_continues_past_one_accounts_sync_failure(): void
    {
        Queue::fake()->except([SyncAdsAccountJob::class]);
        $failing = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-fails']);
        $healthy = AdsAccount::create(['industry' => 'Automotive', 'currency' => 'EUR', 'ads_api_key' => 'sk-ok']);
        $goodResponse = ['data' => [[
            'campaign_id' => 'c1', 'campaign_name' => 'ignored', 'readable_time' => now()->yesterday()->toDateString(),
            'impressions' => 200, 'clicks' => 10, 'spend' => 40.0,
        ]], 'has_more' => false];
        Http::fake([
            '*' => function ($request) use ($goodResponse) {
                return $request->hasHeader('Authorization', 'Bearer sk-fails')
                    ? Http::response('', 500)
                    : Http::response($goodResponse);
            },
        ]);

        \Illuminate\Support\Facades\Artisan::call('ads-accounts:sync-all');

        $this->assertSame(0, AdsAccountPerformanceRecord::where('ads_account_id', $failing->id)->count());
        $this->assertSame(1, AdsAccountPerformanceRecord::where('ads_account_id', $healthy->id)->count());
    }
}
