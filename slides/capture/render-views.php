<?php

/*
 * Renders the real dashboard and admin panel (the app's own controllers and
 * Blade views) with INVENTED data, for the presentation's demo walkthrough
 * (slides/index.html, slide 2). Same approach as
 * promo-video/capture/render-dashboard.php.
 *
 * - No real figures: dashboard numbers come from demo-dashboard/demo-data.php
 *   (every number invented). Its "Demo Account A..G" (the stand-ins for real
 *   accounts) are relabelled real-account-1..7, the project's anonymised label
 *   convention; synthetic accounts keep their "Demo Synthetic N" names.
 * - No real people or clients: users are @example.com, ads accounts are shown
 *   by the app itself as "Account #N", API keys are fake.
 * - Nothing touches the real database, .env accounts/admins or any live API:
 *   in-memory SQLite, array session/cache, faked HTTP and job bus.
 *
 * Usage (from the repo root; <asset-base-url> must serve public/):
 *   php slides/capture/render-views.php <output-dir> <asset-base-url>
 * Writes dash-cpc.html, dash-drill.html, admin-base.html, admin-role-saved.html
 * and admin-account-added.html into <output-dir>.
 */

use App\Enums\UserRole;
use App\Models\AdsAccount;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ExchangeRateProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

[$script, $outDir, $assetBase] = $argv + [null, null, null];
if (! $outDir || ! $assetBase) {
    fwrite(STDERR, "usage: php slides/capture/render-views.php <output-dir> <asset-base-url>\n");
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
    'ads.accounts' => [],                       // no .env real accounts
    'services.google.admin_emails' => [],       // no .env admins
    'services.google.allowed_domain' => 'example.com',
]);
app('url')->forceRootUrl(rtrim($assetBase, '/'));
app('url')->forceScheme(parse_url($assetBase, PHP_URL_SCHEME) ?: 'http');
$app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

Http::preventStrayRequests();
Http::fake();
Bus::fake();

require_once $root.'/demo-dashboard/demo-data.php';
$app->instance(ExchangeRateProvider::class, new class implements ExchangeRateProvider
{
    public function ratesToHuf(): array
    {
        return DEMO_EXCHANGE_RATES;
    }
});
$app->instance(DashboardService::class, demo_dashboard_service());

// Invented people. The signed-in admin is the one presenting.
$admin = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Demo Admin', 'role' => UserRole::Admin]);
$manager = User::factory()->create(['email' => 'ppc.manager@example.com', 'name' => 'PPC Manager', 'role' => UserRole::Viewer]);
User::factory()->create(['email' => 'analyst@example.com', 'name' => 'Analyst', 'role' => UserRole::Viewer]);

// Invented ads accounts, fake keys, fixed dates.
$accounts = [
    ['Education & Careers', 'HUF', '2026-09-24'],
    ['Education & Careers', 'HUF', '2026-10-10'],
    ['Professional Services', 'HUF', '2026-10-15'],
    ['Professional Services', 'EUR', '2026-10-16'],
    ['Health', 'HUF', '2026-10-15'],
];
$addAccount = function (string $industry, string $currency, string $date, int $n) {
    $a = AdsAccount::create(['industry' => $industry, 'currency' => $currency, 'ads_api_key' => "demo-key-{$n}"]);
    DB::table('ads_accounts')->where('id', $a->id)->update(['created_at' => "{$date} 10:00:00", 'updated_at' => "{$date} 10:00:00"]);
};
foreach ($accounts as $n => [$industry, $currency, $date]) {
    $addAccount($industry, $currency, $date, $n + 1);
}

$http = $app->make(HttpKernel::class);
$render = function (string $name, string $uri) use ($app, $http, $admin, $outDir) {
    $app['auth']->guard()->setUser($admin->fresh());
    $request = Request::create($uri, 'GET');
    $response = $http->handle($request);
    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "{$name}: HTTP {$response->getStatusCode()}\n");
        exit(1);
    }
    $html = $response->getContent();
    // real-account-<n> labels for the demo's stand-ins for real accounts.
    $html = preg_replace_callback('/Demo Account ([A-G])/', fn ($m) => 'real-account-'.(ord($m[1]) - 64), $html);
    file_put_contents("{$outDir}/{$name}.html", $html);
    $http->terminate($request, $response);
    echo "wrote {$outDir}/{$name}.html\n";
};

@mkdir($outDir, 0777, true);
$render('dash-cpc', '/?metric=cpc');
$render('dash-drill', '/?metric=cpc&industry='.rawurlencode('Education & Careers'));
$render('admin-base', '/admin');

$manager->update(['role' => UserRole::Admin]);
$render('admin-role-saved', '/admin');
$manager->update(['role' => UserRole::Viewer]);

$addAccount('Travel & Hospitality', 'HUF', '2026-11-27', 6);
$render('admin-account-added', '/admin');
