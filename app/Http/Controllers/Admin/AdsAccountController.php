<?php

namespace App\Http\Controllers\Admin;

use App\Data\Industries;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAdsAccountJob;
use App\Models\AdsAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdsAccountController extends Controller
{
    private const DUPLICATE_MESSAGE = 'This API key is already registered - each Ads API key identifies one account, so it can only be added once.';

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'industry' => ['required', Rule::in(Industries::onboardable())],
            // Validated against the same single source of truth the admin
            // view's currency dropdowns use - config('ads.exchange_rates_to_huf')'s
            // keys, not a separately maintained list (PLANNING_LOG.md 2026-11-07).
            'currency' => ['required', Rule::in(array_keys(config('ads.exchange_rates_to_huf')))],
            'ads_api_key' => ['required', 'string'],
        ]);

        $this->ensureApiKeyIsNotADuplicate($data['ads_api_key']);

        try {
            $account = AdsAccount::create($data);
        } catch (UniqueConstraintViolationException) {
            // The app-level check above closed the normal case; this is only
            // reached if two requests submitted the same key concurrently
            // and both passed that check before either INSERT committed -
            // the unique index (migration 2026_11_01_143618) is what
            // actually guarantees no duplicate ever gets stored.
            throw ValidationException::withMessages(['ads_api_key' => self::DUPLICATE_MESSAGE]);
        }

        // dispatchSync(), not dispatch(): a regular queued dispatch only
        // actually runs once something is consuming QUEUE_CONNECTION=database
        // (a persistent `php artisan queue:work`/`queue:listen` process, e.g.
        // via `composer dev`) - with no such worker running (confirmed live:
        // `php artisan serve` alone leaves dispatched jobs sitting unprocessed
        // in the jobs table indefinitely), the industry would show up in the
        // dropdown immediately but with no data at all until someone noticed
        // and ran the worker by hand. dispatchSync() still goes through
        // Illuminate\Bus\Dispatcher (confirmed by reading its source, not
        // assumed: a ShouldQueue job's dispatchSync() calls
        // dispatchToQueue($job->onConnection('sync')), which is Laravel's
        // built-in connection that runs a job immediately in the current
        // process with no queue table involved at all) - genuinely
        // synchronous, no worker ever required, and still intercepted by
        // Queue::fake()/assertPushed() in tests exactly like dispatch() is
        // (confirmed directly, not assumed).
        try {
            SyncAdsAccountJob::dispatchSync($account);
            $syncNote = 'and synced its live data immediately';
        } catch (Throwable $e) {
            // A transient failure here (bad key, API outage) must not turn
            // adding the account into a 500 - it's still registered and will
            // be retried on the next nightly sync (ads-accounts:sync-all),
            // same graceful-degradation pattern as DashboardService's own
            // source-loading failures.
            Log::channel(config('logging.default'))->error('ads_account.immediate_sync_failed', [
                'ads_account_id' => $account->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $syncNote = 'but its initial sync failed - it will retry on the next nightly sync';
        }

        return back()->with('status', "Added a {$data['currency']} ads account for {$data['industry']} {$syncNote}.");
    }

    public function update(Request $request, AdsAccount $adsAccount): RedirectResponse
    {
        // ads_api_key is deliberately not accepted here at all: it's
        // immutable once set at creation - rotating a key means removing
        // this account and re-adding it (which re-triggers a fresh sync),
        // not editing it in place. Only industry/currency are editable.
        $data = $request->validate([
            'industry' => ['required', Rule::in(Industries::onboardable())],
            'currency' => ['required', Rule::in(array_keys(config('ads.exchange_rates_to_huf')))],
        ]);

        $adsAccount->update($data);

        return back()->with('status', "Updated the {$adsAccount->currency} ads account for {$adsAccount->industry}.");
    }

    public function destroy(AdsAccount $adsAccount): RedirectResponse
    {
        $industry = $adsAccount->industry;
        $currency = $adsAccount->currency;
        $adsAccount->delete();

        return back()->with('status', "Removed the {$currency} ads account for {$industry}.");
    }

    /**
     * Checked against both existing ads_accounts rows and the currently-
     * configured .env real accounts - registering the same key twice
     * through this table would be a genuine duplicate either way (double-
     * counted in the Rule of 3, shown twice on the dashboard under two
     * different ids), not just a database-level concern. No exceptAccountId
     * exclusion is needed any more: this is only ever called from store()
     * now that update() doesn't accept ads_api_key at all.
     */
    private function ensureApiKeyIsNotADuplicate(string $apiKey): void
    {
        if (AdsAccount::where('ads_api_key_hash', hash('sha256', $apiKey))->exists()) {
            throw ValidationException::withMessages(['ads_api_key' => self::DUPLICATE_MESSAGE]);
        }

        foreach (config('ads.accounts', []) as $envAccount) {
            if (! empty($envAccount['api_key']) && hash_equals($envAccount['api_key'], $apiKey)) {
                throw ValidationException::withMessages(['ads_api_key' => self::DUPLICATE_MESSAGE]);
            }
        }
    }
}
