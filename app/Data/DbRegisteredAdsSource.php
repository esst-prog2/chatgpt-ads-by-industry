<?php

namespace App\Data;

use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * Feeds the dashboard from the database-registered AdsAccount rows'
 * synced performance snapshots (ads_account_performance_records), the same
 * way RealAdsSource feeds it from .env-configured accounts - the difference
 * is entirely in WHEN the live Ads API gets called: RealAdsSource calls it
 * synchronously inside records() on every dashboard page render; this class
 * never calls the live API at all, it only reads what AdsAccountSyncer
 * already stored in the background (SyncAdsAccountJob, dispatched on
 * creation and nightly - see routes/console.php). label() is 'Real', same
 * as RealAdsSource: these genuinely are real accounts, just managed via this
 * admin table instead of .env, and DashboardService's Rule-of-3 count
 * (activeRealAccountCountsByIndustry()) already counts any source labelled
 * 'Real' generically, so no change was needed there to include these.
 */
final class DbRegisteredAdsSource implements PerformanceSource
{
    /**
     * Memoized across accounts()/records() - DashboardService::build()
     * calls both on the same instance every request, and previously each
     * independently ran its own AdsAccount::all() (PLANNING_LOG.md
     * 2026-11-05), querying the same table twice for no reason.
     *
     * @var Collection<int, AdsAccount>|null
     */
    private ?Collection $accounts = null;

    public function label(): string
    {
        return 'Real';
    }

    /** @return array<string, string> account id => display name */
    public function accounts(): array
    {
        $accounts = [];
        foreach ($this->loadAccounts() as $account) {
            // Anonymized here too, consistent with every other real account
            // on the dashboard - the admin's own "Account #N" numbering
            // (resources/views/admin/index.blade.php) is a separate,
            // purely-display-layer concern; this is the id/name pair that
            // would appear in the drilldown if this becomes the sole source
            // mentioning the account at all.
            $accounts[$account->internalId()] = "DB Account #{$account->id}";
        }

        return $accounts;
    }

    /** @return list<PerformanceRecord> */
    public function records(string $from, string $to): array
    {
        $accounts = $this->loadAccounts();
        if ($accounts->isEmpty()) {
            return [];
        }

        // One combined query across every account, not one query per
        // account in a loop (PLANNING_LOG.md 2026-11-05) - the result is
        // grouped back by ads_account_id in PHP via $accounts->find()
        // below. whereDate(), not whereBetween() on the raw column: the
        // 'date' cast stores "Y-m-d 00:00:00" (Eloquent's date format
        // default), not plain "Y-m-d" - a raw string whereBetween against
        // "Y-m-d" bounds silently excludes a row whose date exactly equals
        // $to (confirmed the hard way: "2026-10-31 00:00:00" sorts AFTER
        // the plain string "2026-10-31" lexicographically). whereDate()
        // casts the column back to just its date part before comparing.
        $rows = AdsAccountPerformanceRecord::whereIn('ads_account_id', $accounts->modelKeys())
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();

        return $rows->map(fn (AdsAccountPerformanceRecord $row) => new PerformanceRecord(
            $accounts->find($row->ads_account_id)->internalId(),
            $row->campaign_id,
            $row->campaign,
            $row->date->toDateString(),
            $row->impressions,
            $row->clicks,
            $row->spend,
            $row->currency,
        ))->values()->all();
    }

    /** @return Collection<int, AdsAccount> */
    private function loadAccounts(): Collection
    {
        return $this->accounts ??= AdsAccount::all();
    }
}
