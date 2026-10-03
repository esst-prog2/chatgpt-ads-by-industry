<?php

/**
 * HW4 spike (branch hw4-spike, see PLANNING_LOG.md 2026-10-03).
 *
 * Question: does Real Account #1's one real campaign swing more in CTR/CPC
 * between two disjoint 7-day windows than the dashboard's between-industry
 * median gap? Output is anonymized by construction: RealAdsSource never
 * exposes the real account/campaign name, only "Real Account #1" /
 * "Campaign #N", and nothing else here prints raw IDs.
 *
 * Run: php scripts/spike_ctr_cpc.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Data\SyntheticSource;
use App\Services\Aggregator;
use Carbon\CarbonImmutable;

config(['ads.api_key' => env('ADS_API_KEY')]);

function spike_pct_change(?float $from, ?float $to): ?float
{
    if ($from === null || $to === null || $from == 0.0) {
        return null;
    }

    return ($to - $from) / $from * 100;
}

function spike_median(array $values): ?float
{
    if (empty($values)) {
        return null;
    }
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
}

function spike_gap_pct(array $medians): array
{
    $vals = array_values(array_filter($medians, fn ($v) => $v !== null));
    if (count($vals) < 2) {
        return ['gap' => null, 'min' => null, 'max' => null];
    }
    $min = min($vals);
    $max = max($vals);

    return ['gap' => $min == 0.0 ? null : ($max - $min) / $min * 100, 'min' => $min, 'max' => $max];
}

function spike_fmt_pct(?float $v): string
{
    return $v === null ? 'n/a' : number_format($v * 100, 2).'%';
}

function spike_fmt_huf(?float $v): string
{
    return $v === null ? 'n/a' : number_format($v).' HUF';
}

function spike_fmt_signed(?float $v): string
{
    return $v === null ? 'n/a' : sprintf('%+.2f', $v);
}

// --- 1. Real campaign: week-over-week CTR/CPC swing, from whatever real delivery days exist ---

$real = new RealAdsSource;
$allRealRecords = $real->records('2026-01-01', CarbonImmutable::yesterday()->toDateString());

$byDate = [];
foreach ($allRealRecords as $r) {
    $byDate[$r->date]['impressions'] = ($byDate[$r->date]['impressions'] ?? 0) + $r->impressions;
    $byDate[$r->date]['clicks'] = ($byDate[$r->date]['clicks'] ?? 0) + $r->clicks;
    $byDate[$r->date]['spend'] = ($byDate[$r->date]['spend'] ?? 0) + $r->spend;
}
ksort($byDate);
$dates = array_keys($byDate);

if (count($dates) < 14) {
    fwrite(STDERR, 'Only '.count($dates)." day(s) of real delivery data available; need at least 14 for two 7-day windows.\n");
    exit(1);
}

$week1Dates = array_slice($dates, 0, 7);
$week2Dates = array_slice($dates, 7, 7);
$leftoverDates = array_slice($dates, 14);

$sumWindow = function (array $dates) use ($byDate): array {
    $imp = 0;
    $clk = 0;
    $spend = 0.0;
    foreach ($dates as $d) {
        $imp += $byDate[$d]['impressions'];
        $clk += $byDate[$d]['clicks'];
        $spend += $byDate[$d]['spend'];
    }

    return ['impressions' => $imp, 'clicks' => $clk, 'spend' => $spend];
};

$w1 = $sumWindow($week1Dates);
$w2 = $sumWindow($week2Dates);

$w1Ctr = $w1['impressions'] > 0 ? $w1['clicks'] / $w1['impressions'] : null;
$w2Ctr = $w2['impressions'] > 0 ? $w2['clicks'] / $w2['impressions'] : null;
$w1Cpc = $w1['clicks'] > 0 ? $w1['spend'] / $w1['clicks'] : null;
$w2Cpc = $w2['clicks'] > 0 ? $w2['spend'] / $w2['clicks'] : null;

$ctrSwing = spike_pct_change($w1Ctr, $w2Ctr);
$cpcSwing = spike_pct_change($w1Cpc, $w2Cpc);

// --- 2. Between-industry median gaps, from the same per-campaign data the box plot uses ---

$mapping = IndustryMapping::fromCsv(database_path('data/account_industry.csv'));
$aggregator = new Aggregator;

$to = CarbonImmutable::yesterday()->toDateString();
$from = CarbonImmutable::yesterday()->subDays(27)->toDateString();

$allRecords = array_merge(
    (new SyntheticSource)->records($from, $to),
    $real->records($from, $to),
);
$campaignTotals = $aggregator->campaignTotals($allRecords);

$ctrByIndustry = $aggregator->boxPlotValues($campaignTotals, $mapping, 'ctr');
$cpcByIndustry = $aggregator->boxPlotValues($campaignTotals, $mapping, 'cpc');

$ctrMedians = array_map('spike_median', $ctrByIndustry);
$cpcMedians = array_map('spike_median', $cpcByIndustry);

$ctrGap = spike_gap_pct($ctrMedians);
$cpcGap = spike_gap_pct($cpcMedians);

// --- 3. Print (anonymized throughout) ---

echo "=== HW4 Spike: Real Campaign Week-over-Week Swing vs Between-Industry Median Gap ===\n\n";

echo 'Real Account #1 / Campaign #1 - '.count($dates)." available delivery day(s) spanning {$dates[0]} to {$dates[count($dates) - 1]}:\n";
echo '  Week 1 ('.$week1Dates[0].' to '.end($week1Dates)."): CTR = ".spike_fmt_pct($w1Ctr).', CPC = '.spike_fmt_huf($w1Cpc)."\n";
echo '  Week 2 ('.$week2Dates[0].' to '.end($week2Dates)."): CTR = ".spike_fmt_pct($w2Ctr).', CPC = '.spike_fmt_huf($w2Cpc)."\n";
if ($leftoverDates) {
    echo '  ('.count($leftoverDates).' day(s) left over, not used: '.implode(', ', $leftoverDates).")\n";
}
echo '  CTR week-over-week swing: '.spike_fmt_signed($ctrSwing)."%\n";
echo '  CPC week-over-week swing: '.spike_fmt_signed($cpcSwing)."%\n\n";

echo "Between-industry median gaps (box plot medians, last 28 days, all industries incl. Education & Careers which contains Real Account #1):\n";
echo '  CTR medians by industry: ';
foreach ($ctrMedians as $industry => $v) {
    echo "{$industry}=".spike_fmt_pct($v).'  ';
}
echo "\n  CTR gap (max vs min median): ".spike_fmt_signed($ctrGap['gap'])."%\n";
echo '  CPC medians by industry: ';
foreach ($cpcMedians as $industry => $v) {
    echo "{$industry}=".spike_fmt_huf($v).'  ';
}
echo "\n  CPC gap (max vs min median): ".spike_fmt_signed($cpcGap['gap'])."%\n\n";

echo "Decision:\n";
$ctrOverlap = $ctrGap['gap'] !== null && abs($ctrSwing) >= abs($ctrGap['gap']);
$cpcOverlap = $cpcGap['gap'] !== null && abs($cpcSwing) >= abs($cpcGap['gap']);
printf(
    "Real Account #1's week-over-week swing (CTR %+.1f%%, CPC %+.1f%%) %s the CTR median gap (%.1f%%) and %s the CPC median gap (%.1f%%), so single-campaign variance %s overlap with the dashboard's between-industry baselines.\n",
    $ctrSwing,
    $cpcSwing,
    $ctrOverlap ? 'matches or exceeds' : 'is smaller than',
    $ctrGap['gap'],
    $cpcOverlap ? 'matches or exceeds' : 'is smaller than',
    $cpcGap['gap'],
    ($ctrOverlap || $cpcOverlap) ? 'does' : 'does not'
);
