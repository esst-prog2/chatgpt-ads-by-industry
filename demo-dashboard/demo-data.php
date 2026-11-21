<?php

/*
 * The ILLUSTRATIVE demo dataset shared by the promo video's screenshots
 * (promo-video/capture/render-dashboard.php) and the static demo dashboard
 * (demo-dashboard/export-demo-data.php -> public/demo/).
 *
 * Every account and number here is invented. The app's own SyntheticSource
 * is NOT used, because it is calibrated from real accounts' observed stats.
 * Accounts are "Demo Account A..." (labelled Real, standing in for the
 * anonymised real accounts) and "Demo Synthetic N"; campaigns "Campaign #N".
 * No API keys, no client names, no real figures.
 *
 * Requires the Laravel app to be bootstrapped first (it uses the app's own
 * PerformanceSource / DashboardService / Aggregator classes).
 */

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Data\PerformanceSource;
use App\Services\Aggregator;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;

const DEMO_EXCHANGE_RATES = ['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0];

/** A demo source: label + accounts => [name, industry, base impressions, ctr, cpc, campaign count]. */
final class DemoSource implements PerformanceSource
{
    public function __construct(private string $label, private array $accounts) {}

    public function label(): string
    {
        return $this->label;
    }

    public function accounts(): array
    {
        return array_map(fn ($a) => $a['name'], $this->accounts);
    }

    public function records(string $from, string $to): array
    {
        $out = [];
        foreach ($this->accounts as $id => $a) {
            for ($c = 1; $c <= $a['campaigns']; $c++) {
                // Deterministic per-campaign spread so the box plot has width.
                $cpc = $a['cpc'] * (0.82 + 0.12 * $c + 0.05 * (strlen($id) % 3));
                $ctr = $a['ctr'] * (0.9 + 0.07 * $c);
                for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
                    $wave = 1 + 0.18 * sin(($d->dayOfYear + $c * 3) / 2.7);
                    $imp = (int) round($a['imp'] * $wave / $a['campaigns'] * (1 + 0.1 * $c));
                    $clicks = max(1, (int) round($imp * $ctr));
                    $out[] = new PerformanceRecord($id, "{$id}-c{$c}", "Campaign #{$c}", $d->toDateString(), $imp, $clicks, round($clicks * $cpc, 2), 'HUF');
                }
            }
        }

        return $out;
    }
}

/** @return array{real: array<string, array>, synthetic: array<string, array>} */
function demo_accounts(): array
{
    return [
        'real' => [
            'demo-real-a' => ['name' => 'Demo Account A', 'industry' => 'Education & Careers', 'imp' => 1400, 'ctr' => 0.012, 'cpc' => 310, 'campaigns' => 3],
            'demo-real-b' => ['name' => 'Demo Account B', 'industry' => 'Professional Services', 'imp' => 1900, 'ctr' => 0.011, 'cpc' => 420, 'campaigns' => 2],
            'demo-real-c' => ['name' => 'Demo Account C', 'industry' => 'Professional Services', 'imp' => 1300, 'ctr' => 0.010, 'cpc' => 460, 'campaigns' => 2],
            'demo-real-d' => ['name' => 'Demo Account D', 'industry' => 'Health', 'imp' => 1600, 'ctr' => 0.014, 'cpc' => 230, 'campaigns' => 2],
            'demo-real-e' => ['name' => 'Demo Account E', 'industry' => 'Retail & eCommerce', 'imp' => 2600, 'ctr' => 0.016, 'cpc' => 180, 'campaigns' => 3],
            'demo-real-f' => ['name' => 'Demo Account F', 'industry' => 'Retail & eCommerce', 'imp' => 2100, 'ctr' => 0.015, 'cpc' => 210, 'campaigns' => 2],
            'demo-real-g' => ['name' => 'Demo Account G', 'industry' => 'Retail & eCommerce', 'imp' => 1700, 'ctr' => 0.017, 'cpc' => 160, 'campaigns' => 2],
        ],
        'synthetic' => [
            'demo-syn-1' => ['name' => 'Demo Synthetic 1', 'industry' => 'Education & Careers', 'imp' => 1100, 'ctr' => 0.011, 'cpc' => 360, 'campaigns' => 2],
            'demo-syn-2' => ['name' => 'Demo Synthetic 2', 'industry' => 'Education & Careers', 'imp' => 800, 'ctr' => 0.013, 'cpc' => 330, 'campaigns' => 2],
            'demo-syn-3' => ['name' => 'Demo Synthetic 3', 'industry' => 'Professional Services', 'imp' => 1500, 'ctr' => 0.012, 'cpc' => 390, 'campaigns' => 2],
            'demo-syn-4' => ['name' => 'Demo Synthetic 4', 'industry' => 'Health', 'imp' => 1200, 'ctr' => 0.013, 'cpc' => 260, 'campaigns' => 2],
            'demo-syn-5' => ['name' => 'Demo Synthetic 5', 'industry' => 'Health', 'imp' => 900, 'ctr' => 0.012, 'cpc' => 250, 'campaigns' => 2],
            'demo-syn-6' => ['name' => 'Demo Synthetic 6', 'industry' => 'Travel & Hospitality', 'imp' => 300, 'ctr' => 0.016, 'cpc' => 900, 'campaigns' => 2],
            'demo-syn-7' => ['name' => 'Demo Synthetic 7', 'industry' => 'Travel & Hospitality', 'imp' => 250, 'ctr' => 0.015, 'cpc' => 820, 'campaigns' => 2],
        ],
    ];
}

/** The app's real DashboardService, fed only with the demo accounts. */
function demo_dashboard_service(): DashboardService
{
    ['real' => $real, 'synthetic' => $synthetic] = demo_accounts();

    return new DashboardService(
        [new DemoSource('Synthetic', $synthetic), new DemoSource('Real', $real)],
        new IndustryMapping(array_map(fn ($a) => $a['industry'], $real + $synthetic)),
        new Aggregator(DEMO_EXCHANGE_RATES),
    );
}
