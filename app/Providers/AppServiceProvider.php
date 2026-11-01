<?php

namespace App\Providers;

use App\Data\DbRegisteredAdsSource;
use App\Data\GenericSyntheticSource;
use App\Data\Industries;
use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Data\SyntheticSource;
use App\Models\AdsAccount;
use App\Services\Aggregator;
use App\Services\DashboardService;
use App\Services\ExchangeRateProvider;
use App\Services\MnbExchangeRateService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ExchangeRateProvider::class, fn () => new MnbExchangeRateService(
            config('ads.exchange_rates_to_huf', ['HUF' => 1.0])
        ));

        $this->app->singleton(DashboardService::class, function () {
            $realSources = RealAdsSource::fromConfig();

            // Database-registered accounts' industry is already known from
            // their own column (set at creation, editable via the admin
            // panel) - not looked up from the CSV, which only ever covers
            // .env-configured accounts. Merged here rather than changing
            // IndustryMapping's single CSV-based source of truth.
            $dbAccounts = AdsAccount::all();
            $dbIndustries = $dbAccounts->mapWithKeys(fn (AdsAccount $a) => [$a->internalId() => $a->industry])->all();
            $baseMapping = IndustryMapping::fromCsv(database_path('data/account_industry.csv'), $dbIndustries);

            // Every industry that has at least one real account right now
            // (.env or database-registered), excluding Industries::OTHER
            // (the fallback for a genuinely unmapped id, never a real
            // category to generate comparison data for) and excluding
            // whatever SyntheticSource already hand-curates - these are the
            // industries GenericSyntheticSource covers instead, so a brand
            // new industry's first real account has *something* to compare
            // against on the very next dashboard load, not nothing until
            // someone hand-tunes real numbers for it (PLANNING_LOG.md
            // 2026-11-01). DashboardService's existing Rule-of-3 filter
            // already suppresses this the same way it suppresses
            // SyntheticSource once an industry's real count reaches 3 - no
            // new filtering logic needed for that half of the requirement.
            $realIndustries = collect($realSources)
                ->flatMap(fn (RealAdsSource $s) => array_keys($s->accounts()))
                ->map(fn (string $id) => $baseMapping->industryOf($id))
                ->merge($dbAccounts->pluck('industry'))
                ->unique()
                ->reject(fn (string $i) => $i === Industries::OTHER)
                ->values();
            $newIndustries = $realIndustries->diff(SyntheticSource::COVERED_INDUSTRIES)->values()->all();

            $mapping = IndustryMapping::fromCsv(
                database_path('data/account_industry.csv'),
                [...$dbIndustries, ...GenericSyntheticSource::industryMap($newIndustries)]
            );

            return new DashboardService(
                [new SyntheticSource, new GenericSyntheticSource($newIndustries), ...$realSources, new DbRegisteredAdsSource],
                $mapping,
                new Aggregator($this->app->make(ExchangeRateProvider::class)->ratesToHuf()),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
