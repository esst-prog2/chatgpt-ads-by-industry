<?php

/**
 * Manual spike check (HW4, branch hw4-spike, see PLANNING_LOG.md 2026-10-03).
 *
 * Calls the real project logic instead of hardcoded example values:
 * RealAdsSource for the real campaign's delivery data, App\Services\SpikeAnalyzer
 * for the Week 1/Week 2 split and weighted CTR/CPC math (the same class used by
 * scripts/spike_ctr_cpc.php and unit tested in tests/Feature/SpikeCalculationTest.php),
 * and Aggregator for the between-industry median gap from the box plot's own
 * per-campaign data.
 *
 * Run from the project root: php scripts/manual_test/test_spike_manual.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Data\SyntheticSource;
use App\Services\Aggregator;
use App\Services\SpikeAnalyzer;
use Carbon\CarbonImmutable;

echo "=== MANUÁLIS SPIKE ELLENŐRZÉS ===\n\n";

$spike = new SpikeAnalyzer;

// 1. A valódi kampány 1-7. és 8-14. napjának lekérése és a heti CTR/CPC kiszámítása.
// Ez a spike egyetlen valódi kampányra vonatkozik, ezért csak az első beállított
// valódi fiókot használja, akkor is, ha időközben egy második is elérhetővé vált.

$realSources = RealAdsSource::fromConfig();
if (empty($realSources)) {
    echo "[HIBA] Nincs beállítva valódi Ads API kulcs (ADS_API_KEY a .env-ben).\n";
    exit(1);
}
$real = $realSources[0];
$realRecords = $real->records('2026-01-01', CarbonImmutable::yesterday()->toDateString());

try {
    $windows = $spike->splitIntoTwoWindows($realRecords, 7);
} catch (\RuntimeException $e) {
    echo "[HIBA] {$e->getMessage()}\n";
    exit(1);
}

$week1Totals = $spike->windowTotals($windows['byDate'], $windows['week1']);
$week2Totals = $spike->windowTotals($windows['byDate'], $windows['week2']);

$week1Ctr = $spike->weightedCtr($week1Totals);
$week2Ctr = $spike->weightedCtr($week2Totals);
$week1Cpc = $spike->weightedCpc($week1Totals);
$week2Cpc = $spike->weightedCpc($week2Totals);

$ctrSwing = $spike->percentChange($week1Ctr, $week2Ctr);
$cpcSwing = $spike->percentChange($week1Cpc, $week2Cpc);

echo "Valós kampány (Real Account #1 / Campaign #1):\n";
echo '  1. hét ('.$windows['week1'][0].' - '.end($windows['week1']).'): CTR = '.number_format($week1Ctr * 100, 2).'%, CPC = '.number_format($week1Cpc).' HUF'."\n";
echo '  2. hét ('.$windows['week2'][0].' - '.end($windows['week2']).'): CTR = '.number_format($week2Ctr * 100, 2).'%, CPC = '.number_format($week2Cpc).' HUF'."\n";
echo '  Heti ingadozás (Swing) - CTR: '.sprintf('%+.2f', $ctrSwing)."%\n";
echo '  Heti ingadozás (Swing) - CPC: '.sprintf('%+.2f', $cpcSwing)."%\n\n";

// 2. Az iparágak közötti medián eltérés lekérése ugyanabból az adatból, amit a boxplot használ.

$mapping = IndustryMapping::fromCsv(database_path('data/account_industry.csv'));
$aggregator = new Aggregator;

$to = CarbonImmutable::yesterday()->toDateString();
$from = CarbonImmutable::yesterday()->subDays(27)->toDateString();
// Minden beállított valódi fiókot felhasznál (nem csak a fenti egy kampányt), hogy
// az iparági medián megegyezzen azzal, amit az éles dashboard ténylegesen mutat.
$allRecords = array_merge(
    (new SyntheticSource)->records($from, $to),
    ...array_map(fn ($source) => $source->records($from, $to), $realSources),
);
$campaignTotals = $aggregator->campaignTotals($allRecords);

$ctrMedians = array_map([$spike, 'median'], $aggregator->boxPlotValues($campaignTotals, $mapping, 'ctr'));
$cpcMedians = array_map([$spike, 'median'], $aggregator->boxPlotValues($campaignTotals, $mapping, 'cpc'));
$ctrGap = $spike->gapPercent($ctrMedians)['gap'];
$cpcGap = $spike->gapPercent($cpcMedians)['gap'];

echo "Iparágak közötti medián eltérés (a boxplot statisztikáiból):\n";
echo '  Iparági medián eltérés (Gap) - CTR: '.number_format($ctrGap, 2)."%\n";
echo '  Iparági medián eltérés (Gap) - CPC: '.number_format($cpcGap, 2)."%\n\n";

// 3. Automatikus állítás (assertion) ellenőrzése - a mérés valódi, nem nulla értékeket adott-e.

$valid = $ctrSwing !== null && $cpcSwing !== null && $ctrGap !== null && $cpcGap !== null
    && $week1Ctr > 0 && $week2Ctr > 0 && $week1Cpc > 0 && $week2Cpc > 0;

if ($valid) {
    echo "[OK] A mérés sikeresen lefutott, érvényes számokat kaptunk.\n\n";
} else {
    echo "[HIBA] A számítás hibás vagy 0/null értéket adott vissza!\n";
    exit(1);
}

foreach (['CTR' => [$ctrSwing, $ctrGap], 'CPC' => [$cpcSwing, $cpcGap]] as $metric => [$swing, $gap]) {
    if (abs($swing) >= abs($gap)) {
        echo "[EREDMÉNY] ({$metric}) A kampány heti ingadozása NAGYOBB vagy EGYENLŐ az iparági különbségnél (Boxplot = zaj).\n";
    } else {
        echo "[EREDMÉNY] ({$metric}) Az iparági különbség NAGYOBB a heti ingadozásnál (Boxplot = érvényes trend).\n";
    }
}
