<?php

namespace App\Console\Commands;

use App\Jobs\SyncAdsAccountJob;
use App\Models\AdsAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled nightly (routes/console.php) to re-sync every database-registered
 * ads account - runs the same SyncAdsAccountJob that fires immediately on
 * creation, so both triggers share identical fetch/store behavior.
 * dispatchSync(), not dispatch(): a regular queued dispatch would sit
 * unprocessed in the jobs table unless a persistent queue worker happens to
 * be running (the same gap that caused new accounts not to sync immediately -
 * see AdsAccountController::store()) - this command itself IS the process
 * that should do the syncing when the scheduler invokes it, not something
 * that hands work off to a separate worker that may not exist. Looping with
 * a try/catch per account (not one dispatch() call per account relying on
 * the queue's own per-job isolation) keeps the same "one account's failure
 * doesn't stop the others" guarantee explicitly, now that nothing here goes
 * through a real queue to provide that isolation for free.
 */
#[Signature('ads-accounts:sync-all')]
#[Description('Immediately sync every database-registered ads account')]
class SyncAllAdsAccounts extends Command
{
    public function handle(): void
    {
        $accounts = AdsAccount::all();
        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                SyncAdsAccountJob::dispatchSync($account);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel(config('logging.default'))->error('ads_account.nightly_sync_failed', [
                    'ads_account_id' => $account->id,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Synced {$synced} ads account(s), {$failed} failed (see logs).");
    }
}
