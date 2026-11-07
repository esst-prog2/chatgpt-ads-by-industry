<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SyncAdsAccountJob;
use App\Models\AdsAccount;
use App\Models\AdsAccountPerformanceRecord;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Extracted from AuthTest.php (PLANNING_LOG.md 2026-11-07) - these tests
// cover Admin\AdsAccountController and were previously filed under AuthTest
// purely by historical accident (the admin panel's "Ads Accounts" tab was
// built in the same session as its "Users" tab). No behavior change, no
// coverage change - same tests, same assertions, just a name that now
// matches what the file actually tests.
class AdsAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_an_ads_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test-12345', 'industry' => 'Health', 'currency' => 'HUF'])
            ->assertRedirect();

        $account = AdsAccount::first();
        $this->assertNotNull($account);
        $this->assertSame('Health', $account->industry);
        $this->assertSame('HUF', $account->currency);
        // Decrypts transparently via the model's 'encrypted' cast - confirms
        // the round trip (encrypt on write, decrypt on read) actually works,
        // not just that some value got stored.
        $this->assertSame('sk-test-12345', $account->ads_api_key);
    }

    public function test_adding_an_ads_account_immediately_dispatches_a_background_sync_job(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test-12345', 'industry' => 'Health', 'currency' => 'HUF']);

        $account = AdsAccount::first();
        Queue::assertPushed(SyncAdsAccountJob::class, fn (SyncAdsAccountJob $job) => $job->adsAccount->is($account));
    }

    public function test_adding_an_ads_account_actually_syncs_it_immediately_with_no_queue_worker_involved(): void
    {
        // Queue::fake()->except([...]) lets THIS specific job genuinely run
        // (dispatchSync() routes through the real 'sync' connection) while
        // everything else stays faked - the only way to prove the account's
        // data is actually populated by the time the request returns,
        // without any queue:work process ever running (confirmed live
        // against the real dev DB: a plain dispatch() left jobs sitting
        // unprocessed in the jobs table indefinitely with no worker).
        Queue::fake()->except([SyncAdsAccountJob::class]);
        $admin = User::factory()->admin()->create();
        Http::fake(['*' => Http::response(['data' => [[
            'campaign_id' => 'c1', 'campaign_name' => 'ignored', 'readable_time' => now()->yesterday()->toDateString(),
            'impressions' => 300, 'clicks' => 15, 'spend' => 60.0,
        ]], 'has_more' => false])]);

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-immediate-sync-test', 'industry' => 'Automotive', 'currency' => 'HUF'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'synced its live data immediately'));

        $account = AdsAccount::first();
        $this->assertSame(1, AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->count());
    }

    public function test_adding_an_ads_account_still_succeeds_when_its_immediate_sync_fails(): void
    {
        // A live-sync failure (bad key, API outage) must not turn adding the
        // account into a 500 - it's still registered, just flagged to retry
        // on the next nightly sync.
        Queue::fake()->except([SyncAdsAccountJob::class]);
        $admin = User::factory()->admin()->create();
        Http::fake(['*' => Http::response('', 401)]);

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-will-fail-sync', 'industry' => 'Automotive', 'currency' => 'HUF'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'initial sync failed'));

        $this->assertSame(1, AdsAccount::count());
    }

    public function test_ads_api_key_is_encrypted_at_rest_not_stored_as_plaintext(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/ads-accounts', [
            'ads_api_key' => 'sk-super-secret-value', 'industry' => 'Health', 'currency' => 'HUF',
        ]);

        $rawValue = DB::table('ads_accounts')->value('ads_api_key');
        $this->assertStringNotContainsString('sk-super-secret-value', $rawValue);
    }

    public function test_adding_an_ads_account_rejects_an_unknown_industry(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Not A Real Industry', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');

        $this->assertSame(0, AdsAccount::count());
    }

    public function test_adding_an_ads_account_rejects_the_other_catch_all_as_an_industry(): void
    {
        // Industries::OTHER is the fallback for an unmapped account, not a
        // real category anyone should deliberately onboard an account into.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Other', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');
    }

    public function test_adding_an_ads_account_rejects_an_unsupported_currency(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Health', 'currency' => 'GBP'])
            ->assertSessionHasErrors('currency');
    }

    public function test_the_accepted_currency_list_follows_the_configured_exchange_rates_not_a_hardcoded_list(): void
    {
        // Regression test for the currency-list centralization
        // (PLANNING_LOG.md 2026-11-07): AdsAccountController used to
        // validate against its own private CURRENCIES constant, separate
        // from config('ads.exchange_rates_to_huf') and from the admin
        // view's two currency dropdowns - four places that had to be kept
        // in sync by hand. Now they all read the same config array, proven
        // here by reconfiguring it to something that is NOT the original
        // USD/EUR/HUF set and checking that both validation and the
        // rendered dropdown follow it.
        config(['ads.exchange_rates_to_huf' => ['HUF' => 1.0, 'GBP' => 450.0]]);
        $admin = User::factory()->admin()->create();

        // GBP was rejected before this config existed - now it's accepted
        // purely because it's a key in the reconfigured map.
        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-gbp-test', 'industry' => 'Health', 'currency' => 'GBP'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('currency');

        // EUR/USD were always accepted before - now rejected, since they are
        // no longer keys in the reconfigured map, proving this isn't still
        // reading a separate hardcoded list underneath.
        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-eur-test', 'industry' => 'Health', 'currency' => 'EUR'])
            ->assertSessionHasErrors('currency');

        // The admin page's own dropdowns reflect the same reconfigured list.
        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('<option value="GBP"', false)
            ->assertDontSee('<option value="EUR"', false)
            ->assertDontSee('<option value="USD"', false);
    }

    public function test_a_viewer_cannot_add_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Health', 'currency' => 'HUF'])
            ->assertForbidden();

        $this->assertSame(0, AdsAccount::count());
    }

    public function test_admin_panel_lists_connected_real_accounts_anonymized_by_number(): void
    {
        // real-account-1/2 are genuinely mapped to Education & Careers in the
        // real database/data/account_industry.csv - no mapping override
        // needed, since UserAccessController reads that real file directly
        // (same as AppServiceProvider does), not an injectable instance.
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'sk-test-1', 'label' => 'Configured Label A', 'currency' => 'HUF'],
            ['id' => 'real-account-2', 'api_key' => 'sk-test-2', 'label' => 'Configured Label B', 'currency' => 'EUR'],
        ]]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Account #1')
            ->assertSee('Account #2')
            ->assertSee('Education & Careers')
            ->assertSee('HUF')
            ->assertSee('EUR')
            // This listing's whole point is a strictly anonymous admin
            // summary, independent of the dashboard's own configured label.
            ->assertDontSee('Configured Label A')
            ->assertDontSee('Configured Label B');
    }

    public function test_admin_panel_shows_a_message_when_no_accounts_are_connected_at_all(): void
    {
        // RefreshDatabase guarantees no AdsAccount rows either, so this
        // covers the unified table's genuinely-empty state.
        config(['ads.accounts' => []]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('No accounts connected yet.');
    }

    public function test_admin_can_edit_an_ads_accounts_industry_and_currency_without_touching_the_key(): void
    {
        $admin = User::factory()->admin()->create();
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-original-key']);

        $this->actingAs($admin)
            ->patch("/admin/ads-accounts/{$account->id}", ['industry' => 'Professional Services', 'currency' => 'EUR'])
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('Professional Services', $account->industry);
        $this->assertSame('EUR', $account->currency);
        $this->assertSame('sk-original-key', $account->ads_api_key);
    }

    public function test_editing_an_ads_account_cannot_change_its_api_key_even_if_one_is_submitted(): void
    {
        // The key is immutable once set - rotating it means removing the
        // account and re-adding it, not editing it in place. Even a raw PATCH
        // that includes ads_api_key (not just the UI, which no longer renders
        // the field at all) must leave the stored key untouched.
        $admin = User::factory()->admin()->create();
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-original-key']);

        $this->actingAs($admin)->patch("/admin/ads-accounts/{$account->id}", [
            'industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-sneaky-replacement',
        ]);

        $this->assertSame('sk-original-key', $account->refresh()->ads_api_key);
    }

    public function test_editing_an_ads_account_rejects_an_unknown_industry_and_leaves_it_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($admin)
            ->patch("/admin/ads-accounts/{$account->id}", ['industry' => 'Not A Real Industry', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');

        $this->assertSame('Health', $account->refresh()->industry);
    }

    public function test_a_viewer_cannot_edit_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($viewer)
            ->patch("/admin/ads-accounts/{$account->id}", ['industry' => 'Automotive', 'currency' => 'USD'])
            ->assertForbidden();

        $this->assertSame('Health', $account->refresh()->industry);
    }

    public function test_adding_an_ads_account_rejects_a_missing_industry(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');

        $this->assertSame(0, AdsAccount::count());
    }

    public function test_add_ads_account_form_has_no_preselected_industry_and_defaults_currency_to_huf(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            // disabled+selected placeholder, not any real industry auto-chosen.
            ->assertSee('<option value="" disabled selected>Select industry...</option>', false)
            ->assertSee('<option value="HUF" selected>HUF</option>', false);
    }

    public function test_admin_panel_shows_real_and_database_accounts_together_in_one_continuously_numbered_table(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'sk-test-1', 'label' => 'Configured Label A', 'currency' => 'HUF'],
        ]]);
        AdsAccount::create(['industry' => 'Health', 'currency' => 'EUR', 'ads_api_key' => 'sk-db-key']);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin')->assertOk();

        // One real account (Account #1) then one DB account (Account #2) -
        // continuous numbering across both groups, not two separate "#1"s.
        $response->assertSeeInOrder(['Account #1', 'Account #2'])
            ->assertSee('Read-only (.env)')
            ->assertDontSee('Configured Label A');
    }

    public function test_database_account_row_renders_collapsed_with_an_edit_toggle(): void
    {
        AdsAccount::create(['industry' => 'Health', 'currency' => 'EUR', 'ads_api_key' => 'sk-db-key']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            // The row carries the collapsible structure (Edit toggle present,
            // Save/Delete marked edit-only so CSS hides them until toggled) -
            // the actual show/hide interaction itself needs a real browser,
            // verified separately via Playwright. Checking for the class and
            // the button text separately, not one exact-whitespace string:
            // Blade's @if/@endif directive leaves its own surrounding
            // whitespace in the compiled output, making an exact span
            // fragile to incidental template formatting.
            ->assertSee('class="editable-row"', false)
            ->assertSee('class="view-only edit-toggle"', false)
            ->assertSee('Edit')
            ->assertSeeInOrder(['edit-toggle', 'edit-only']);
    }

    public function test_admin_can_delete_a_database_registered_ads_account(): void
    {
        $admin = User::factory()->admin()->create();
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($admin)
            ->delete("/admin/ads-accounts/{$account->id}")
            ->assertRedirect();

        $this->assertSame(0, AdsAccount::count());
    }

    public function test_a_viewer_cannot_delete_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $account = AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($viewer)
            ->delete("/admin/ads-accounts/{$account->id}")
            ->assertForbidden();

        $this->assertSame(1, AdsAccount::count());
    }

    public function test_adding_an_ads_account_rejects_a_key_already_registered_as_a_database_account(): void
    {
        $admin = User::factory()->admin()->create();
        AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-duplicate-key']);

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-duplicate-key', 'industry' => 'Automotive', 'currency' => 'EUR'])
            ->assertSessionHasErrors('ads_api_key');

        $this->assertSame(1, AdsAccount::count());
    }

    public function test_adding_an_ads_account_rejects_a_key_already_configured_as_a_live_env_account(): void
    {
        config(['ads.accounts' => [
            ['id' => 'real-account-1', 'api_key' => 'sk-env-key', 'label' => 'Configured Label A', 'currency' => 'HUF'],
        ]]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-env-key', 'industry' => 'Automotive', 'currency' => 'EUR'])
            ->assertSessionHasErrors('ads_api_key');

        $this->assertSame(0, AdsAccount::count());
    }

    public function test_the_database_itself_rejects_a_duplicate_api_key_hash_independent_of_app_level_validation(): void
    {
        // Proves the real safety net (migration 2026_11_01_143618's unique
        // index), not just the app-level pre-check above: inserting two rows
        // with the same ads_api_key_hash directly must fail at the database
        // layer, which is what actually protects against a race between two
        // concurrent requests that both pass the app-level check.
        AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-race-key']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('ads_accounts')->insert([
            'industry' => 'Automotive', 'currency' => 'EUR', 'ads_api_key' => 'ciphertext-does-not-matter-here',
            'ads_api_key_hash' => hash('sha256', 'sk-race-key'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
