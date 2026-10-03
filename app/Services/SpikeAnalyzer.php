<?php

namespace App\Services;

use App\Data\PerformanceRecord;
use RuntimeException;

/**
 * Shared logic behind scripts/spike_ctr_cpc.php (HW4 spike, branch hw4-spike,
 * see PLANNING_LOG.md 2026-10-03), kept here instead of inline in the script
 * so it can be unit tested directly.
 */
final class SpikeAnalyzer
{
    /**
     * Groups records by date (summing same-day records), sorts ascending, and
     * splits the resulting dates into two disjoint windows of $windowDays
     * dates each, taken from the earliest available data. Any dates beyond
     * the two windows are returned as leftover, unused.
     *
     * @param  list<PerformanceRecord>  $records
     * @return array{week1: list<string>, week2: list<string>, leftover: list<string>, byDate: array<string, array{impressions: int, clicks: int, spend: float}>}
     */
    public function splitIntoTwoWindows(array $records, int $windowDays = 7): array
    {
        $byDate = [];
        foreach ($records as $r) {
            $byDate[$r->date] ??= ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];
            $byDate[$r->date]['impressions'] += $r->impressions;
            $byDate[$r->date]['clicks'] += $r->clicks;
            $byDate[$r->date]['spend'] += $r->spend;
        }
        ksort($byDate);
        $dates = array_keys($byDate);

        if (count($dates) < $windowDays * 2) {
            throw new RuntimeException(
                'Need at least '.($windowDays * 2)." days of data for two {$windowDays}-day windows, got ".count($dates).'.'
            );
        }

        return [
            'week1' => array_slice($dates, 0, $windowDays),
            'week2' => array_slice($dates, $windowDays, $windowDays),
            'leftover' => array_slice($dates, $windowDays * 2),
            'byDate' => $byDate,
        ];
    }

    /**
     * @param  array<string, array{impressions: int, clicks: int, spend: float}>  $byDate
     * @param  list<string>  $dates
     * @return array{impressions: int, clicks: int, spend: float}
     */
    public function windowTotals(array $byDate, array $dates): array
    {
        $impressions = 0;
        $clicks = 0;
        $spend = 0.0;
        foreach ($dates as $d) {
            $impressions += $byDate[$d]['impressions'];
            $clicks += $byDate[$d]['clicks'];
            $spend += $byDate[$d]['spend'];
        }

        return ['impressions' => $impressions, 'clicks' => $clicks, 'spend' => $spend];
    }

    /** @param array{impressions: int, clicks: int, spend: float} $totals */
    public function weightedCtr(array $totals): ?float
    {
        return $totals['impressions'] > 0 ? $totals['clicks'] / $totals['impressions'] : null;
    }

    /** @param array{impressions: int, clicks: int, spend: float} $totals */
    public function weightedCpc(array $totals): ?float
    {
        return $totals['clicks'] > 0 ? $totals['spend'] / $totals['clicks'] : null;
    }

    public function percentChange(?float $from, ?float $to): ?float
    {
        if ($from === null || $to === null || $from == 0.0) {
            return null;
        }

        return ($to - $from) / $from * 100;
    }

    /** @param list<float> $values */
    public function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /**
     * @param  array<string, ?float>  $medians
     * @return array{gap: ?float, min: ?float, max: ?float}
     */
    public function gapPercent(array $medians): array
    {
        $vals = array_values(array_filter($medians, fn ($v) => $v !== null));
        if (count($vals) < 2) {
            return ['gap' => null, 'min' => null, 'max' => null];
        }
        $min = min($vals);
        $max = max($vals);

        return ['gap' => $min == 0.0 ? null : ($max - $min) / $min * 100, 'min' => $min, 'max' => $max];
    }
}
