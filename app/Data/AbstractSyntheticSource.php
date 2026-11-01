<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Shared deterministic generation algorithm behind both SyntheticSource (the
 * 5 hand-curated industries, modeled on real observed per-industry ranges -
 * see its own docblock) and GenericSyntheticSource (an immediate, neutral
 * fallback for any OTHER industry that gains a real account - see its own
 * docblock for why a separate class, not a change to SyntheticSource's own
 * curated data). Extracted 2026-11-01 so the exact same per-account/
 * per-campaign/per-day variance formula - not a re-derived approximation of
 * it - backs both, instead of risking subtle drift between two copies.
 */
abstract class AbstractSyntheticSource implements PerformanceSource
{
    public const DAYS = 30;

    protected readonly string $endDate;

    public function __construct(?string $endDate = null)
    {
        $this->endDate = $endDate ?? CarbonImmutable::yesterday()->toDateString();
    }

    public function label(): string
    {
        return 'Synthetic';
    }

    /** @return array<string, array{0: string, 1: int, 2: float, 3: int, 4: list<string>}> account id => [name, baseImpressions, baseCtr, baseCpc, campaigns] */
    abstract protected function accountDefinitions(): array;

    public function accounts(): array
    {
        return array_map(fn (array $a) => $a[0], $this->accountDefinitions());
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
            foreach ($this->accountDefinitions() as $accountId => [$name, $baseImpressions, $baseCtr, $baseCpc, $campaigns]) {
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
