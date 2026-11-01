<?php

namespace App\Data;

use App\Models\AdsAccount;

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
    public function label(): string
    {
        return 'Real';
    }

    /** @return array<string, string> account id => display name */
    public function accounts(): array
    {
        $accounts = [];
        foreach (AdsAccount::all() as $account) {
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
        $records = [];
        foreach (AdsAccount::all() as $account) {
            // whereDate(), not whereBetween() on the raw column: the 'date'
            // cast stores "Y-m-d 00:00:00" (Eloquent's date format default),
            // not plain "Y-m-d" - a raw string whereBetween against "Y-m-d"
            // bounds silently excludes a row whose date exactly equals $to
            // (confirmed the hard way: "2026-10-31 00:00:00" sorts AFTER the
            // plain string "2026-10-31" lexicographically). whereDate() casts
            // the column back to just its date part before comparing.
            $query = $account->performanceRecords()
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to);
            foreach ($query->get() as $row) {
                $records[] = new PerformanceRecord(
                    $account->internalId(),
                    $row->campaign_id,
                    $row->campaign,
                    $row->date->toDateString(),
                    $row->impressions,
                    $row->clicks,
                    $row->spend,
                    $row->currency,
                );
            }
        }

        return $records;
    }
}
