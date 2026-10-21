<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final class SyntheticSource implements PerformanceSource
{
    public const DAYS = 30;

    // Synthetic data exists ONLY for industries that have at least one real
    // account (see account_industry.csv, PLANNING_LOG.md 2026-10-16) - it
    // supplements real industries for richer comparison, it does not stand in
    // for industries with no real presence at all. Each account's base
    // impressions/CTR/CPC (columns 2-4) are modeled on that same industry's
    // real accounts' own observed 28-day stats (pulled live 2026-10-16), not
    // arbitrary numbers:
    //   Education & Careers reals: per-campaign daily impressions 118-1548,
    //     CTR 0.83-1.33%, CPC 309-438 HUF.
    //   Professional Services reals: per-campaign daily impressions 1470-2949,
    //     CTR 1.00-1.29%, CPC 253 HUF (Professional Services Partner A) / 1.33 EUR
//     ~ 532 HUF-equivalent (Professional Services B).
    //   Health reals: per-campaign daily impressions 824-1884, CTR 1.26-1.54%,
    //     CPC 236-238 HUF.
    // All synthetic accounts are HUF, for simplicity - see PLANNING_LOG.md.
    // Synthetic account ids (syn-*) never collide with real ones
    // (real-account-N), so accountTotals() - grouped strictly by id - never
    // mixes a real account's own totals with its synthetic industry siblings;
    // only industryTotals() intentionally combines them.
    private const ACCOUNTS = [
        'syn-edu-1' => ['Bright Pathway Learning', 900, 0.011, 390, ['Autumn Enrolment', 'Open Day']],
        'syn-edu-2' => ['Horizon Career Academy', 500, 0.012, 360, ['Scholarship Drive', 'Info Session', 'Online Prep']],
        'syn-pro-1' => ['Keystone Consulting Group', 1800, 0.012, 320, ['Corporate Advisory', 'Market Entry Support']],
        'syn-pro-2' => ['Meridian Business Partners', 1200, 0.011, 400, ['Strategy Workshop', 'SME Consulting', 'Audit Services']],
        'syn-health-1' => ['VitaCare Pharmacy Group', 1500, 0.014, 240, ['Flu Season Campaign', 'Vitamin Range']],
        'syn-health-2' => ['Wellspring Clinics', 1000, 0.013, 250, ['Checkup Reminders', 'Dental Care']],
    ];

    private readonly string $endDate;

    public function __construct(?string $endDate = null)
    {
        $this->endDate = $endDate ?? CarbonImmutable::yesterday()->toDateString();
    }

    public function label(): string
    {
        return 'Synthetic';
    }

    public function accounts(): array
    {
        return array_map(fn (array $a) => $a[0], self::ACCOUNTS);
    }

    public function records(string $from, string $to): array
    {
        $end = CarbonImmutable::parse($this->endDate);
        $start = $end->subDays(self::DAYS - 1);
        $first = max($start, CarbonImmutable::parse($from));
        $last = min($end, CarbonImmutable::parse($to));

        $records = [];
        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();
            foreach (self::ACCOUNTS as $accountId => [$name, $baseImpressions, $baseCtr, $baseCpc, $campaigns]) {
                foreach ($campaigns as $index => $campaign) {
                    $key = "{$accountId}|{$campaign}";
                    $impressions = (int) round(
                        $baseImpressions * (0.4 + $this->unit($key, 'volume') * 1.2) * (0.8 + $this->unit("{$key}|{$date}", 'imp') * 0.4)
                    );
                    $ctr = $baseCtr * (0.6 + $this->unit($key, 'ctr') * 0.8) * (0.85 + $this->unit("{$key}|{$date}", 'clk') * 0.3);
                    $clicks = (int) round($impressions * $ctr);
                    // CPC varies per campaign (not per day) within +/-15% of the target, rounded to
                    // a whole HUF up front. Spend is then clicks * cpc with both factors already
                    // integers, so it is exact - no rounding step, no discrepancy to reconcile.
                    $cpc = (int) round($baseCpc * (0.85 + $this->unit($key, 'cpc') * 0.3));
                    $records[] = new PerformanceRecord(
                        $accountId,
                        "{$accountId}-c".($index + 1),
                        $campaign,
                        $date,
                        $impressions,
                        $clicks,
                        (float) ($clicks * $cpc),
                        'HUF',
                    );
                }
            }
        }

        return $records;
    }

    private function unit(string $key, string $salt): float
    {
        return (crc32("{$salt}|{$key}") % 10000) / 10000;
    }
}
