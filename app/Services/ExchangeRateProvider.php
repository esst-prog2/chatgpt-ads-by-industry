<?php

namespace App\Services;

/**
 * Seam between AppServiceProvider and whatever actually supplies HUF
 * exchange rates (MnbExchangeRateService in production). Lets tests rebind
 * this interface to a trivial, network-free fake instead of needing to fake
 * the real HTTP/XML/caching behavior of MnbExchangeRateService on every
 * single test that merely resolves DashboardService - MnbExchangeRateService
 * itself is still tested directly and in full (tests/Unit/MnbExchangeRateServiceTest.php).
 */
interface ExchangeRateProvider
{
    /** @return array<string, float> currency => HUF rate */
    public function ratesToHuf(): array;
}
