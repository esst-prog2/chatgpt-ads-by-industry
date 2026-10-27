<?php

namespace App\Services;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use RuntimeException;

final class Aggregator
{
    public const METRICS = ['impressions', 'clicks', 'spend', 'ctr', 'cpc'];

    /**
     * @param  array<string, float>  $exchangeRatesToHuf  currency code => multiplier to HUF (e.g. ['HUF' => 1.0, 'EUR' => 400.0]).
     *      Plain constructor injection, not config() - this class stays usable
     *      without booting Laravel (see tests/Unit/AggregatorTest.php).
     */
    public function __construct(
        private readonly array $exchangeRatesToHuf = ['HUF' => 1.0],
    ) {}

    /**
     * Per-campaign totals. 'spend'/'cpc' stay in the campaign's own native
     * currency ('currency') - unambiguous, since one campaign belongs to one
     * account, which has one currency. 'spendHuf'/'cpcHuf' are always HUF,
     * for cross-currency aggregation (industryTotals, boxPlotValues).
     *
     * @param  list<PerformanceRecord>  $records
     * @return array<string, array{account: string, campaignId: string, campaign: string, currency: string, impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float, spendHuf: float, cpcHuf: ?float}>
     */
    public function campaignTotals(array $records): array
    {
        $totals = [];
        foreach ($records as $r) {
            $key = $r->account.'|'.$r->campaignId;
            $totals[$key] ??= [
                'account' => $r->account, 'campaignId' => $r->campaignId, 'campaign' => $r->campaign,
                'currency' => $r->currency,
                'impressions' => 0, 'clicks' => 0, 'spend' => 0.0,
            ];
            $totals[$key]['impressions'] += $r->impressions;
            $totals[$key]['clicks'] += $r->clicks;
            $totals[$key]['spend'] += $r->spend;
        }
        foreach ($totals as &$t) {
            $t['spend'] = round($t['spend'], 2);
            $t['ctr'] = $this->ctr($t['clicks'], $t['impressions']);
            $t['cpc'] = $this->cpc($t['clicks'], $t['spend']);
            $t['spendHuf'] = round($this->toHuf($t['spend'], $t['currency']), 2);
            $t['cpcHuf'] = $this->cpc($t['clicks'], $t['spendHuf']);
        }

        return $totals;
    }

    /**
     * Per-account totals, in that account's own native currency ('currency') -
     * every campaign under one account shares it, so the native sum is always
     * meaningful here, unlike industryTotals.
     *
     * @param  array<string, array>  $campaignTotals
     * @return array<string, array{impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float, currency: string}>
     */
    public function accountTotals(array $campaignTotals): array
    {
        return $this->sumBy($campaignTotals, fn (array $c) => $c['account']);
    }

    /**
     * Per-industry totals. An industry can span accounts of different
     * currencies, so 'spend'/'cpc' here are always HUF - not a raw sum of
     * mismatched currencies - computed from each campaign's spendHuf.
     *
     * @param  array<string, array>  $campaignTotals
     * @return array<string, array{impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float, currency: string}>
     */
    public function industryTotals(array $campaignTotals, IndustryMapping $mapping): array
    {
        $grouped = $this->sumByHuf($campaignTotals, fn (array $c) => $mapping->industryOf($c['account']));

        return array_map(fn (array $t) => [
            'impressions' => $t['impressions'],
            'clicks' => $t['clicks'],
            'spend' => $t['spendHuf'],
            'ctr' => $t['ctr'],
            'cpc' => $t['cpcHuf'],
            'currency' => 'HUF',
        ], $grouped);
    }

    /**
     * One value per campaign for the box plot; campaigns without a value (CTR with zero
     * impressions, CPC with zero clicks) are left out. The box plot compares industries
     * against each other, so spend/cpc read the HUF-normalized figures, not native currency.
     *
     * @param  array<string, array>  $campaignTotals
     * @return array<string, list<float|int>>
     */
    public function boxPlotValues(array $campaignTotals, IndustryMapping $mapping, string $metric): array
    {
        $field = ['spend' => 'spendHuf', 'cpc' => 'cpcHuf'][$metric] ?? $metric;

        $values = [];
        foreach ($campaignTotals as $c) {
            if ($c[$field] === null) {
                continue;
            }
            $values[$mapping->industryOf($c['account'])][] = $c[$field];
        }

        return $values;
    }

    /**
     * Pre-computed box-plot statistics per industry, built on boxPlotValues().
     * 'outliers' is always empty and the whiskers always span the true
     * min/max of that industry's points - deliberately not Chart.js's default
     * "beyond 1.5x IQR" rule. A handful of blended real+synthetic campaigns is
     * too small a sample for that rule to mean "anomaly": it would flag an
     * ordinary point (or a real account's genuinely different currency/scale)
     * as a jarring orphan dot. An industry with no points for this metric
     * gets [] (same as boxPlotValues(), so the Blade "no data" check and an
     * empty box plot entry both still work unchanged).
     *
     * @param  array<string, array>  $campaignTotals
     * @return array<string, array{min: float, max: float, q1: float, median: float, q3: float, mean: float, items: list<float>, outliers: array{}, whiskerMin: float, whiskerMax: float}|list<never>>
     */
    public function boxPlotStats(array $campaignTotals, IndustryMapping $mapping, string $metric): array
    {
        $values = $this->boxPlotValues($campaignTotals, $mapping, $metric);

        return array_map(function (array $points) {
            if ($points === []) {
                return [];
            }
            sort($points);
            $min = $points[0];
            $max = $points[count($points) - 1];

            return [
                'min' => $min,
                'max' => $max,
                'q1' => $this->quantile($points, 0.25),
                'median' => $this->quantile($points, 0.5),
                'q3' => $this->quantile($points, 0.75),
                'mean' => array_sum($points) / count($points),
                'items' => $points,
                'outliers' => [],
                'whiskerMin' => $min,
                'whiskerMax' => $max,
            ];
        }, $values);
    }

    /** Linear-interpolation quantile (matches numpy's/Excel PERCENTILE.INC's default method). @param  list<float|int>  $sorted  already sorted ascending */
    private function quantile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $index = $p * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }

    /** @return array<string, array{impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float, currency: string}> */
    private function sumBy(array $campaignTotals, callable $groupOf): array
    {
        $out = [];
        foreach ($campaignTotals as $c) {
            $g = $groupOf($c);
            $out[$g] ??= ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'currency' => $c['currency']];
            $out[$g]['impressions'] += $c['impressions'];
            $out[$g]['clicks'] += $c['clicks'];
            $out[$g]['spend'] += $c['spend'];
        }
        foreach ($out as &$t) {
            $t['spend'] = round($t['spend'], 2);
            $t['ctr'] = $this->ctr($t['clicks'], $t['impressions']);
            $t['cpc'] = $this->cpc($t['clicks'], $t['spend']);
        }

        return $out;
    }

    /** @return array<string, array{impressions: int, clicks: int, spendHuf: float, ctr: ?float, cpcHuf: ?float}> */
    private function sumByHuf(array $campaignTotals, callable $groupOf): array
    {
        $out = [];
        foreach ($campaignTotals as $c) {
            $g = $groupOf($c);
            $out[$g] ??= ['impressions' => 0, 'clicks' => 0, 'spendHuf' => 0.0];
            $out[$g]['impressions'] += $c['impressions'];
            $out[$g]['clicks'] += $c['clicks'];
            $out[$g]['spendHuf'] += $c['spendHuf'];
        }
        foreach ($out as &$t) {
            $t['spendHuf'] = round($t['spendHuf'], 2);
            $t['ctr'] = $this->ctr($t['clicks'], $t['impressions']);
            $t['cpcHuf'] = $this->cpc($t['clicks'], $t['spendHuf']);
        }

        return $out;
    }

    private function ctr(int $clicks, int $impressions): ?float
    {
        return $impressions > 0 ? $clicks / $impressions : null;
    }

    private function cpc(int $clicks, float $spend): ?float
    {
        return $clicks > 0 ? round($spend / $clicks, 2) : null;
    }

    private function toHuf(float $amount, string $currency): float
    {
        if (! array_key_exists($currency, $this->exchangeRatesToHuf)) {
            throw new RuntimeException("no exchange rate configured for currency \"{$currency}\"");
        }

        return $amount * $this->exchangeRatesToHuf[$currency];
    }
}
