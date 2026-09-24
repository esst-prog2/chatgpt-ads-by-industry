<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final class SyntheticSource implements PerformanceSource
{
    public const DAYS = 30;

    // CPC (5th column) targets realistic Hungarian PPC ranges per industry:
    // Retail & eCommerce ~120-350 HUF, Software & Technology ~400-1200 HUF,
    // Education & Careers ~250-600 HUF (Brightpath Academy sits near the real
    // account's ~300 HUF level). See PLANNING_LOG.md.
    private const ACCOUNTS = [
        'syn-edu-1' => ['Brightpath Academy', 12000, 0.021, 330, ['Autumn Enrolment', 'Open Day', 'Online Courses']],
        'syn-edu-2' => ['CareerLift', 9000, 0.034, 480, ['Graduate Jobs', 'CV Workshop']],
        'syn-ret-1' => ['Urban Threads', 30000, 0.018, 150, ['Autumn Collection', 'Sale Weekend', 'New Arrivals', 'Loyalty Club']],
        'syn-ret-2' => ['HomeNest Store', 22000, 0.026, 230, ['Furniture Deals', 'Kitchen Range']],
        'syn-ret-3' => ['GadgetBay', 26000, 0.015, 300, ['Phones', 'Laptops', 'Accessories']],
        'syn-sw-1' => ['CloudPilot', 8000, 0.042, 950, ['Free Trial', 'Enterprise Demo', 'Developer Docs']],
        'syn-sw-2' => ['DevKit Pro', 6000, 0.051, 550, ['Launch Promo', 'Team Plan']],
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
