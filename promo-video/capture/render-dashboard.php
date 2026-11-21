<?php

/*
 * Renders the real dashboard (DashboardController, DashboardService,
 * Aggregator, resources/views/dashboard.blade.php) with clearly
 * ILLUSTRATIVE demo data, for the promo video's screenshots.
 *
 * - No real figures: the demo accounts come from demo-dashboard/demo-data.php
 *   (shared with the static demo dashboard); every number there is invented.
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

use App\Models\User;
use App\Services\DashboardService;
use App\Services\ExchangeRateProvider;
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
require_once $root.'/demo-dashboard/demo-data.php';
$app->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
{
    public function ratesToHuf(): array
    {
        return DEMO_EXCHANGE_RATES;
    }
});

// Demo accounts and data: shared with the static demo dashboard.
require_once $root.'/demo-dashboard/demo-data.php';
$app->instance(DashboardService::class, demo_dashboard_service());

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
