<?php

namespace App\Services;

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;

final class Aggregator
{
    public const METRICS = ['impressions', 'clicks', 'spend', 'ctr', 'cpc'];

    /**
     * @param  list<PerformanceRecord>  $records
     * @return array<string, array{account: string, campaignId: string, campaign: string, impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float}>
     */
    public function campaignTotals(array $records): array
    {
        $totals = [];
        foreach ($records as $r) {
            $key = $r->account.'|'.$r->campaignId;
            $totals[$key] ??= [
                'account' => $r->account, 'campaignId' => $r->campaignId, 'campaign' => $r->campaign,
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
        }

        return $totals;
    }

    /**
     * @param  array<string, array>  $campaignTotals
     * @return array<string, array{impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float}>
     */
    public function accountTotals(array $campaignTotals): array
    {
        return $this->sumBy($campaignTotals, fn (array $c) => $c['account']);
    }

    /**
     * @param  array<string, array>  $campaignTotals
     * @return array<string, array{impressions: int, clicks: int, spend: float, ctr: ?float, cpc: ?float}>
     */
    public function industryTotals(array $campaignTotals, IndustryMapping $mapping): array
    {
        return $this->sumBy($campaignTotals, fn (array $c) => $mapping->industryOf($c['account']));
    }

    /**
     * One value per campaign for the box plot; campaigns without a value (CTR with zero
     * impressions, CPC with zero clicks) are left out.
     *
     * @param  array<string, array>  $campaignTotals
     * @return array<string, list<float|int>>
     */
    public function boxPlotValues(array $campaignTotals, IndustryMapping $mapping, string $metric): array
    {
        $values = [];
        foreach ($campaignTotals as $c) {
            if ($c[$metric] === null) {
                continue;
            }
            $values[$mapping->industryOf($c['account'])][] = $c[$metric];
        }

        return $values;
    }

    private function sumBy(array $campaignTotals, callable $groupOf): array
    {
        $out = [];
        foreach ($campaignTotals as $c) {
            $g = $groupOf($c);
            $out[$g] ??= ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0];
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

    private function ctr(int $clicks, int $impressions): ?float
    {
        return $impressions > 0 ? $clicks / $impressions : null;
    }

    private function cpc(int $clicks, float $spend): ?float
    {
        return $clicks > 0 ? round($spend / $clicks, 2) : null;
    }
}
