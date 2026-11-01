<?php

namespace Tests;

use App\Services\ExchangeRateProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    // Belt-and-suspenders on top of phpunit.xml's SESSION_DRIVER=array: force
    // it here too, so feature tests never touch a "sessions" DB table that no
    // migration creates, even if some runner/CI setup loads .env's
    // SESSION_DRIVER=database ahead of phpunit.xml's env block.
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'array']);

        // AppServiceProvider resolves DashboardService's exchange rates
        // through ExchangeRateProvider (MnbExchangeRateService in
        // production, which makes a real live SOAP call to MNB). Almost
        // every feature test resolves DashboardService (directly or via the
        // '/' route), so without a default rebind here EVERY such test would
        // attempt a real network call. Rebinding the interface - not faking
        // an MNB URL with Http::fake() - is deliberate: a URL-based fake
        // registered here would always win over one a test registers later
        // for the same host (Laravel's HTTP fake resolves the FIRST matching
        // registered stub, not the last), which would make it impossible for
        // tests/Unit/MnbExchangeRateServiceTest.php to exercise the real
        // class's own live-fetch/parsing/fallback behavior with its own
        // Http::fake(). This fake never touches Http at all, so it can't
        // collide with anything. Figures match the static config/ads.php
        // fallback, so tests that don't care about exchange rates see
        // unchanged behavior.
        $this->app->singleton(ExchangeRateProvider::class, fn () => new class implements ExchangeRateProvider
        {
            // Reads config('ads.exchange_rates_to_huf') on every call (not
            // captured once at bind time) so a test's own config([...])
            // override - the same mechanism used before this interface
            // existed - still works exactly as it did, instead of being
            // silently ignored in favor of a hardcoded literal here.
            public function ratesToHuf(): array
            {
                return config('ads.exchange_rates_to_huf', ['HUF' => 1.0]);
            }
        });

        // phpunit.xml sets QUEUE_CONNECTION=sync, which runs a dispatched job
        // immediately inline, not truly in the background - so without this,
        // any test that creates an AdsAccount through the real route would
        // make a real, unfaked HTTP call via SyncAdsAccountJob the moment
        // AdsAccountController::store() dispatches it. A dedicated test that
        // wants to verify dispatch
        // (Queue::assertPushed) or the job's own real behavior (constructing
        // it directly and calling handle() with its own Http::fake()) still
        // works fine under a fake queue - only jobs nobody explicitly runs
        // stay inert, exactly the same "don't touch real external systems
        // unless that's what's being tested" default already applied above.
        Queue::fake();
    }
}
