<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the static, public demo dashboard (public/demo/), which GitHub Pages
 * serves without any sign-in: it may only ever contain the invented demo
 * accounts from demo-dashboard/demo-data.php, never real account names,
 * emails or anything key-like, and the promo page must point at it.
 */
class DemoDashboardTest extends TestCase
{
    private function raw(): string
    {
        $path = public_path('demo/data.js');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** data.js is "window.DEMO_DATA = {json};" - parse the JSON part. */
    private function data(): array
    {
        $this->assertSame(1, preg_match('/window\.DEMO_DATA = (\{.*\});\s*$/s', $this->raw(), $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_demo_data_is_flagged_as_demo_and_covers_the_october_2026_presets(): void
    {
        $data = $this->data();

        $this->assertTrue($data['demo']);
        $this->assertCount(8, $data['periods']);
        foreach ($data['periods'] as $period) {
            $this->assertStringStartsWith('2026-10-', $period['from']);
            $this->assertStringStartsWith('2026-10-', $period['to']);
            $this->assertSame($data['metrics'], array_keys($period['boxPlot']));
            $this->assertSame($period['industries'], array_keys($period['drilldown']));
        }
    }

    public function test_demo_data_contains_only_demo_accounts_and_nothing_sensitive(): void
    {
        $data = $this->data();

        foreach ($data['periods'] as $period) {
            foreach ($period['drilldown'] as $accounts) {
                foreach ($accounts as $account) {
                    $this->assertMatchesRegularExpression('/^Demo (Account [A-Z]|Synthetic \d+)$/', $account['name']);
                    $this->assertContains($account['label'], ['Real', 'Synthetic']);
                    foreach ($account['campaigns'] as $campaign) {
                        $this->assertMatchesRegularExpression('/^Campaign #\d+$/', $campaign['campaign']);
                    }
                }
            }
        }

        $this->assertDoesNotMatchRegularExpression('/api[_-]?key|sk-[a-z0-9]|bearer|password|@[a-z0-9-]+\.[a-z]{2,}/i', $this->raw());
    }

    public function test_demo_page_is_labelled_as_a_demo(): void
    {
        $html = file_get_contents(public_path('demo/index.html'));

        $this->assertStringContainsString('INTERACTIVE DEMO', $html);
        $this->assertStringContainsString('Illustrative snapshot data', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_promo_page_button_opens_the_demo_and_says_so(): void
    {
        $html = file_get_contents(public_path('promo.html'));

        $this->assertStringContainsString('<a class="cta" href="demo/index.html#metric=cpc">Open the dashboard</a>', $html);
        $this->assertStringContainsString('Note: opens an interactive demo version.', $html);
    }

    public function test_promo_page_lists_what_the_full_version_adds(): void
    {
        $html = file_get_contents(public_path('promo.html'));

        $this->assertStringContainsString('In the full version', $html);
        foreach (['Sign in with Google', 'Admin tools', 'Connect new ad accounts'] as $feature) {
            $this->assertStringContainsString($feature, $html);
        }
    }
}
