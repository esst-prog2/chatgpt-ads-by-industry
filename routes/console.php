<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every database-registered ads account (app/Models/AdsAccount) gets its
// live data re-synced nightly, independent of the immediate on-creation
// sync (AdsAccountController::store() dispatches SyncAdsAccountJob directly) -
// see app/Console/Commands/SyncAllAdsAccounts.php.
Schedule::command('ads-accounts:sync-all')->dailyAt('02:00');
