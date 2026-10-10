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
 * The window-splitting and metric math live in App\Services\SpikeAnalyzer,
 * which is unit tested in tests/Feature/SpikeCalculationTest.php - this
 * script is just that class driven with real data and formatted for print.
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
use App\Services\SpikeAnalyzer;
use Carbon\CarbonImmutable;

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

$spike = new SpikeAnalyzer;

// --- 1. Real campaign: week-over-week CTR/CPC swing, from whatever real delivery days exist ---
// This spike is scoped to one real campaign (see the question above), so it only
// uses the first configured real account, even if a second one is now available.

$realSources = RealAdsSource::fromConfig();
if (empty($realSources)) {
    fwrite(STDERR, "No real Ads API key is configured (ADS_API_KEY in .env).\n");
    exit(1);
}
$real = $realSources[0];
$allRealRecords = $real->records('2026-01-01', CarbonImmutable::yesterday()->toDateString());

$windows = $spike->splitIntoTwoWindows($allRealRecords, 7);
$allDates = array_merge($windows['week1'], $windows['week2'], $windows['leftover']);

$w1 = $spike->windowTotals($windows['byDate'], $windows['week1']);
$w2 = $spike->windowTotals($windows['byDate'], $windows['week2']);

$w1Ctr = $spike->weightedCtr($w1);
$w2Ctr = $spike->weightedCtr($w2);
$w1Cpc = $spike->weightedCpc($w1);
$w2Cpc = $spike->weightedCpc($w2);

$ctrSwing = $spike->percentChange($w1Ctr, $w2Ctr);
$cpcSwing = $spike->percentChange($w1Cpc, $w2Cpc);

// --- 2. Between-industry median gaps, from the same per-campaign data the box plot uses ---

$mapping = IndustryMapping::fromCsv(database_path('data/account_industry.csv'));
$aggregator = new Aggregator;

$to = CarbonImmutable::yesterday()->toDateString();
$from = CarbonImmutable::yesterday()->subDays(27)->toDateString();

// Uses every configured real account (not just the one this spike is scoped to
// above), so the median gap matches what the live dashboard actually shows.
$allRecords = array_merge(
    (new SyntheticSource)->records($from, $to),
    ...array_map(fn ($source) => $source->records($from, $to), $realSources),
);
$campaignTotals = $aggregator->campaignTotals($allRecords);

$ctrByIndustry = $aggregator->boxPlotValues($campaignTotals, $mapping, 'ctr');
$cpcByIndustry = $aggregator->boxPlotValues($campaignTotals, $mapping, 'cpc');

$ctrMedians = array_map([$spike, 'median'], $ctrByIndustry);
$cpcMedians = array_map([$spike, 'median'], $cpcByIndustry);

$ctrGap = $spike->gapPercent($ctrMedians);
$cpcGap = $spike->gapPercent($cpcMedians);

// --- 3. Print (anonymized throughout) ---

echo "=== HW4 Spike: Real Campaign Week-over-Week Swing vs Between-Industry Median Gap ===\n\n";

echo 'Real Account #1 / Campaign #1 - '.count($allDates)." available delivery day(s) spanning {$allDates[0]} to {$allDates[count($allDates) - 1]}:\n";
echo '  Week 1 ('.$windows['week1'][0].' to '.end($windows['week1'])."): CTR = ".spike_fmt_pct($w1Ctr).', CPC = '.spike_fmt_huf($w1Cpc)."\n";
echo '  Week 2 ('.$windows['week2'][0].' to '.end($windows['week2'])."): CTR = ".spike_fmt_pct($w2Ctr).', CPC = '.spike_fmt_huf($w2Cpc)."\n";
if ($windows['leftover']) {
    echo '  ('.count($windows['leftover']).' day(s) left over, not used: '.implode(', ', $windows['leftover']).")\n";
}
echo '  CTR week-over-week swing: '.spike_fmt_signed($ctrSwing)."%\n";
echo '  CPC week-over-week swing: '.spike_fmt_signed($cpcSwing)."%\n\n";

echo "Between-industry median gaps (box plot medians, last 28 days; Education & Careers is exclusively real accounts, no synthetic placeholder):\n";
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
