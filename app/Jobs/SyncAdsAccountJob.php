<?php

namespace App\Jobs;

use App\Models\AdsAccount;
use App\Services\AdsAccountSyncer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched immediately when an AdsAccount is created
 * (AdsAccountController::store()) and nightly for every existing row (the
 * ads-accounts:sync-all command, scheduled in routes/console.php) - the one
 * place that actually triggers a live fetch for a database-registered
 * account; AdsAccountSyncer does the real work so both triggers share
 * identical behavior.
 *
 * Both callers use dispatchSync(), not dispatch(): a genuine queued dispatch
 * only runs once something is actively consuming QUEUE_CONNECTION (a
 * persistent `queue:work`/`queue:listen` process) - with none running,
 * dispatched jobs just sit in the jobs table indefinitely (confirmed live;
 * this is why a newly-added account's industry would appear immediately but
 * stay empty until someone noticed and ran the worker by hand).
 * dispatchSync() still implements ShouldQueue deliberately, not just as a
 * leftover: it's what makes Illuminate\Bus\Dispatcher route the call through
 * dispatchToQueue($job->onConnection('sync')) rather than calling handle()
 * directly, which is what keeps it visible to Queue::fake()/assertPushed()
 * in tests - confirmed directly, not assumed. $tries/backoff below are
 * therefore dormant under the current callers (the 'sync' connection runs
 * once, inline, with no retry loop) but kept as working, tested groundwork
 * for a possible future deployment that dispatches this for real async
 * processing instead.
 */
class SyncAdsAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly AdsAccount $adsAccount) {}

    /** @return list<int> seconds before each retry - only meaningful under a real async dispatch() with a worker, see class docblock */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AdsAccountSyncer $syncer): void
    {
        [$from, $to] = AdsAccountSyncer::defaultWindow();
        $syncer->sync($this->adsAccount, $from, $to);
    }
}
