<?php

/*
 * Renders the real dashboard (DashboardController, DashboardService,
 * Aggregator, resources/views/dashboard.blade.php) with clearly
 * ILLUSTRATIVE demo data, for the promo video's screenshots.
 *
 * - No real figures: the app's own SyntheticSource is calibrated from real
 *   accounts' observed stats, so it is NOT used here; every number below is
 *   invented for the video.
 * - No client names: accounts are "Demo Account A..." / "Demo Synthetic N",
 *   campaigns "Campaign #N".
 * - Nothing touches the real database, .env API keys or live APIs: an
 *   in-memory SQLite database, array session/cache, a fixed exchange-rate
 *   provider, and no .env account keys.
 *
 * Usage (from the repo root):
 *   php promo-video/capture/render-dashboard.php <output-dir> <asset-base-url>
 * Writes overview.html, cpc.html and drilldown.html into <output-dir>.
 */

use App\Data\IndustryMapping;
use App\Data\PerformanceRecord;
use App\Data\PerformanceSource;
use App\Models\User;
use App\Services\Aggregator;
use App\Services\DashboardService;
use App\Services\ExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

[$script, $outDir, $assetBase] = $argv + [null, null, null];
if (! $outDir || ! $assetBase) {
    fwrite(STDERR, "usage: php render-dashboard.php <output-dir> <asset-base-url>\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

config([
    'app.url' => rtrim($assetBase, '/'),
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'session.driver' => 'array',
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'ads.accounts' => [],
]);
app('url')->forceRootUrl(rtrim($assetBase, '/'));
// The local .env may force https for tunnel use; the capture server is plain http.
app('url')->forceScheme(parse_url($assetBase, PHP_URL_SCHEME) ?: 'http');
$app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

// Illustrative rates for the footer and HUF conversion.
$app->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
{
    public function ratesToHuf(): array
    {
        return ['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0];
    }
});

/** A demo source: label + accounts => [industry, base impressions, ctr, cpc, campaign count]. */
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

$real = [
    'demo-real-a' => ['name' => 'Demo Account A', 'industry' => 'Education & Careers', 'imp' => 1400, 'ctr' => 0.012, 'cpc' => 310, 'campaigns' => 3],
    'demo-real-b' => ['name' => 'Demo Account B', 'industry' => 'Professional Services', 'imp' => 1900, 'ctr' => 0.011, 'cpc' => 420, 'campaigns' => 2],
    'demo-real-c' => ['name' => 'Demo Account C', 'industry' => 'Professional Services', 'imp' => 1300, 'ctr' => 0.010, 'cpc' => 460, 'campaigns' => 2],
    'demo-real-d' => ['name' => 'Demo Account D', 'industry' => 'Health', 'imp' => 1600, 'ctr' => 0.014, 'cpc' => 230, 'campaigns' => 2],
    'demo-real-e' => ['name' => 'Demo Account E', 'industry' => 'Retail & eCommerce', 'imp' => 2600, 'ctr' => 0.016, 'cpc' => 180, 'campaigns' => 3],
    'demo-real-f' => ['name' => 'Demo Account F', 'industry' => 'Retail & eCommerce', 'imp' => 2100, 'ctr' => 0.015, 'cpc' => 210, 'campaigns' => 2],
    'demo-real-g' => ['name' => 'Demo Account G', 'industry' => 'Retail & eCommerce', 'imp' => 1700, 'ctr' => 0.017, 'cpc' => 160, 'campaigns' => 2],
];
$synthetic = [
    'demo-syn-1' => ['name' => 'Demo Synthetic 1', 'industry' => 'Education & Careers', 'imp' => 1100, 'ctr' => 0.011, 'cpc' => 360, 'campaigns' => 2],
    'demo-syn-2' => ['name' => 'Demo Synthetic 2', 'industry' => 'Education & Careers', 'imp' => 800, 'ctr' => 0.013, 'cpc' => 330, 'campaigns' => 2],
    'demo-syn-3' => ['name' => 'Demo Synthetic 3', 'industry' => 'Professional Services', 'imp' => 1500, 'ctr' => 0.012, 'cpc' => 390, 'campaigns' => 2],
    'demo-syn-4' => ['name' => 'Demo Synthetic 4', 'industry' => 'Health', 'imp' => 1200, 'ctr' => 0.013, 'cpc' => 260, 'campaigns' => 2],
    'demo-syn-5' => ['name' => 'Demo Synthetic 5', 'industry' => 'Health', 'imp' => 900, 'ctr' => 0.012, 'cpc' => 250, 'campaigns' => 2],
    'demo-syn-6' => ['name' => 'Demo Synthetic 6', 'industry' => 'Travel & Hospitality', 'imp' => 300, 'ctr' => 0.016, 'cpc' => 900, 'campaigns' => 2],
    'demo-syn-7' => ['name' => 'Demo Synthetic 7', 'industry' => 'Travel & Hospitality', 'imp' => 250, 'ctr' => 0.015, 'cpc' => 820, 'campaigns' => 2],
];
$mapping = new IndustryMapping(array_map(fn ($a) => $a['industry'], $real + $synthetic));

$app->instance(DashboardService::class, new DashboardService(
    [new DemoSource('Synthetic', $synthetic), new DemoSource('Real', $real)],
    $mapping,
    new Aggregator(['HUF' => 1.0, 'EUR' => 400.0, 'USD' => 350.0]),
));

$user = User::factory()->create(['email' => 'demo.user@example.com', 'name' => 'Demo User']);
$http = $app->make(HttpKernel::class);

$pages = [
    'overview' => '/',
    'cpc' => '/?metric=cpc',
    'drilldown' => '/?metric=cpc&industry='.rawurlencode('Education & Careers'),
];
@mkdir($outDir, 0777, true);
foreach ($pages as $name => $uri) {
    $app['auth']->guard()->setUser($user);
    $request = Request::create($uri, 'GET');
    $response = $http->handle($request);
    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "{$name}: HTTP {$response->getStatusCode()}\n");
        exit(1);
    }
    file_put_contents("{$outDir}/{$name}.html", $response->getContent());
    $http->terminate($request, $response);
    echo "wrote {$outDir}/{$name}.html\n";
}
