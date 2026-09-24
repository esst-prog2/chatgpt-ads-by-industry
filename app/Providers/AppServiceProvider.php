<?php

namespace App\Providers;

use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Data\SyntheticSource;
use App\Services\DashboardService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DashboardService::class, fn () => new DashboardService(
            [new SyntheticSource, new RealAdsSource],
            IndustryMapping::fromCsv(database_path('data/account_industry.csv')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
