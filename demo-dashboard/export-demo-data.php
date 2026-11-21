<?php

/*
 * Exports the static demo dashboard's dataset: public/demo/data.js
 * (window.DEMO_DATA = {...}; loaded with a plain <script> tag, so the demo
 * works on GitHub Pages, through the Laravel app and opened from disk).
 *
 * Runs the app's real DashboardService / Aggregator over the ILLUSTRATIVE
 * demo accounts (demo-dashboard/demo-data.php) for every combination the demo
 * page offers - a fixed set of October 2026 periods, every metric, every
 * industry - so the static page shows exactly what the live app would compute
 * for that data, without re-implementing any aggregation in JavaScript.
 * The real dashboard is only read from, never changed.
 *
 * No database, no .env API keys, no live API calls are touched. A privacy
 * guard refuses to write the file if any account is not a demo account or if
 * anything key-like ends up in the output.
 *
 * Usage (from the repo root):
 *   php demo-dashboard/export-demo-data.php
 */

use App\Services\Aggregator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
config(['ads.accounts' => [], 'cache.default' => 'array']);
require_once __DIR__.'/demo-data.php';

// Fixed demo period: October 2026. Visitors pick one of these presets.
const DEMO_PERIODS = [
    ['id' => 'oct', 'label' => 'Full month (1–31 Oct)', 'from' => '2026-10-01', 'to' => '2026-10-31'],
    ['id' => 'oct-h1', 'label' => 'First half (1–15 Oct)', 'from' => '2026-10-01', 'to' => '2026-10-15'],
    ['id' => 'oct-h2', 'label' => 'Second half (16–31 Oct)', 'from' => '2026-10-16', 'to' => '2026-10-31'],
    ['id' => 'oct-w1', 'label' => 'Week 1 (1–7 Oct)', 'from' => '2026-10-01', 'to' => '2026-10-07'],
    ['id' => 'oct-w2', 'label' => 'Week 2 (8–14 Oct)', 'from' => '2026-10-08', 'to' => '2026-10-14'],
    ['id' => 'oct-w3', 'label' => 'Week 3 (15–21 Oct)', 'from' => '2026-10-15', 'to' => '2026-10-21'],
    ['id' => 'oct-w4', 'label' => 'Week 4 (22–31 Oct)', 'from' => '2026-10-22', 'to' => '2026-10-31'],
    ['id' => 'oct-last7', 'label' => 'Last 7 days (25–31 Oct)', 'from' => '2026-10-25', 'to' => '2026-10-31'],
];
$dashboard = demo_dashboard_service();
$metrics = Aggregator::METRICS;

$keepTotals = fn (?array $t) => $t === null ? null : [
    'impressions' => $t['impressions'], 'clicks' => $t['clicks'], 'spend' => $t['spend'],
    'ctr' => $t['ctr'], 'currency' => $t['currency'] ?? 'HUF',
];

$periods = [];
foreach (DEMO_PERIODS as $preset) {
    ['from' => $from, 'to' => $to] = $preset;
    $base = $dashboard->build($from, $to, 'cpc', null);

    $boxPlot = [];
    foreach ($metrics as $metric) {
        $boxPlot[$metric] = $dashboard->build($from, $to, $metric, null)['boxPlot'];
    }

    $drilldown = [];
    foreach ($base['industries'] as $industry) {
        $drilldown[$industry] = array_map(fn ($a) => [
            'name' => $a['name'],
            'label' => $a['label'],
            'totals' => $keepTotals($a['totals']),
            'campaigns' => array_map(fn ($c) => ['campaign' => $c['campaign']] + $keepTotals($c), $a['campaigns']),
        ], $dashboard->build($from, $to, 'cpc', $industry)['drilldown'] ?? []);
    }

    $periods[] = [
        'id' => $preset['id'],
        'label' => $preset['label'],
        'from' => $from,
        'to' => $to,
        'industries' => $base['industries'],
        'industryTotals' => array_map($keepTotals, $base['industryTotals']),
        'boxPlot' => $boxPlot,
        'drilldown' => $drilldown,
    ];
}

$data = [
    'demo' => true,
    'note' => 'Illustrative demo data: invented accounts and figures, not real campaign results.',
    'generatedAt' => CarbonImmutable::now()->toDateString(),
    'metrics' => $metrics,
    'exchangeRates' => ['EUR' => DEMO_EXCHANGE_RATES['EUR'], 'USD' => DEMO_EXCHANGE_RATES['USD']],
    'periods' => $periods,
];

// ---- Privacy guard: only demo accounts, nothing key-like.
foreach ($periods as $p) {
    foreach ($p['drilldown'] as $accounts) {
        foreach ($accounts as $a) {
            if (! preg_match('/^Demo (Account [A-Z]|Synthetic \d+)$/', $a['name'])) {
                fwrite(STDERR, "Refusing to export: non-demo account name \"{$a['name']}\".\n");
                exit(1);
            }
            foreach ($a['campaigns'] as $c) {
                if (! preg_match('/^Campaign #\d+$/', $c['campaign'])) {
                    fwrite(STDERR, "Refusing to export: unexpected campaign name \"{$c['campaign']}\".\n");
                    exit(1);
                }
            }
        }
    }
}
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (preg_match('/api[_-]?key|sk-[a-z0-9]|bearer|password|@[a-z0-9-]+\.[a-z]{2,}/i', $json)) {
    fwrite(STDERR, "Refusing to export: key-like or email-like content found in the output.\n");
    exit(1);
}

$out = $root.'/public/demo/data.js';
@mkdir(dirname($out), 0755, true);
file_put_contents($out, "// Generated by demo-dashboard/export-demo-data.php - illustrative demo data, do not edit.\nwindow.DEMO_DATA = ".$json.";\n");
@unlink($root.'/public/demo/data.json'); // superseded by data.js
printf("wrote %s (%d KB): %d October 2026 periods\n", $out, strlen($json) / 1024, count($periods));
