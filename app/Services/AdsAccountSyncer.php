<?php

namespace App\Services;

use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fetches one database-registered AdsAccount's live performance data and
 * stores it in ads_account_performance_records, replacing whatever was
 * stored for that account's synced window. Called by SyncAdsAccountJob
 * (dispatched on AdsAccount creation, and nightly for every existing row via
 * the ads-accounts:sync-all command) - never called synchronously during a
 * dashboard page render, which is the whole point of doing this in a
 * background job rather than how RealAdsSource fetches .env accounts.
 */
final class AdsAccountSyncer
{
    // Generous rolling window, not unbounded: comfortably covers the
    // dashboard's default 28-day range and most custom ranges a user might
    // pick, without the stored table growing forever. A date outside this
    // window simply shows as no data for that account, same as any other
    // source with no delivery on a given day (PLANNING_LOG.md 2026-09-24).
    private const SYNC_WINDOW_DAYS = 90;

    /** @return array{0: string, 1: string} [$from, $to], both Y-m-d, ending yesterday */
    public static function defaultWindow(): array
    {
        $to = Carbon::yesterday();
        $from = $to->copy()->subDays(self::SYNC_WINDOW_DAYS - 1);

        return [$from->toDateString(), $to->toDateString()];
    }

    public function sync(AdsAccount $account, string $from, string $to): void
    {
        $records = AdsApiClient::fetchInsights(
            $account->internalId(),
            $account->ads_api_key,
            $account->currency,
            $from,
            $to,
            "AdsAccount #{$account->id}",
        );

        DB::transaction(function () use ($account, $from, $to, $records) {
            // whereDate(), not whereBetween() - see the matching comment in
            // DbRegisteredAdsSource::records() for why a raw whereBetween
            // against the 'date'-cast column silently misses a row whose
            // date exactly equals $to.
            $account->performanceRecords()
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->delete();

            foreach ($records as $record) {
                AdsAccountPerformanceRecord::create([
                    'ads_account_id' => $account->id,
                    'campaign_id' => $record->campaignId,
                    'campaign' => $record->campaign,
                    'date' => $record->date,
                    'impressions' => $record->impressions,
                    'clicks' => $record->clicks,
                    'spend' => $record->spend,
                    'currency' => $record->currency,
                ]);
            }
        });
    }
}
