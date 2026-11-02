<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SyncAdsAccountJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.allowed_domain' => 'intren.hu',
            'services.google.admin_emails' => ['admin@intren.hu'],
            // The literal exception email under test here, not a dummy
            // stand-in - set explicitly rather than relying on the real
            // config/services.php default resolving to the same value.
            'services.google.allowed_external_emails' => ['approved.exception@gmail.com'],
            // Dummy, deliberately not read from the real .env: redirect()
            // only builds a URL locally (no network call), so these never
            // need to be real credentials, and hardcoding them here keeps
            // the test hermetic regardless of what's actually configured
            // on whatever machine runs the suite.
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    public function test_login_page_shows_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in with Google');
    }

    public function test_dashboard_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_already_authenticated_user_hitting_login_is_redirected_away(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_google_callback_creates_a_viewer_by_default(): void
    {
        Socialite::fake('google', SocialiteUser::fake(['email' => 'new@intren.hu', 'name' => 'New Person']));

        $response = $this->get('/auth/google/callback');

        $user = User::where('email', 'new@intren.hu')->first();
        $this->assertNotNull($user);
        $this->assertSame(UserRole::Viewer, $user->role);
        $this->assertNotNull($user->google_id);
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_google_callback_creates_an_admin_when_email_is_admin_listed(): void
    {
        Socialite::fake('google', SocialiteUser::fake(['email' => 'admin@intren.hu']));

        $this->get('/auth/google/callback');

        $this->assertSame(UserRole::Admin, User::where('email', 'admin@intren.hu')->first()->role);
    }

    /**
     * A real user hit this in production-ish local testing: refreshing or
     * revisiting the callback URL (back button, double-click, link prefetch)
     * throws Socialite's InvalidStateException since the one-time 'state'
     * value is already consumed - must recover gracefully, not show a raw
     * 500 (see PLANNING_LOG.md 2026-10-27).
     */
    public function test_google_callback_recovers_gracefully_from_an_expired_or_replayed_state(): void
    {
        Socialite::fake('google', function () {
            throw new InvalidStateException;
        });

        $response = $this->get('/auth/google/callback');

        $this->assertSame(0, User::count());
        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
    }

    public function test_google_callback_rejects_a_non_company_domain_email_and_creates_no_user(): void
    {
        Socialite::fake('google', SocialiteUser::fake(['email' => 'someone@gmail.com']));

        $response = $this->get('/auth/google/callback');

        $this->assertSame(0, User::count());
        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHas('error');
    }

    public function test_an_existing_users_role_is_not_reset_by_admin_emails_on_a_later_login(): void
    {
        // Not admin-listed, but manually promoted via the access panel -
        // admin_emails must never silently undo that on a later login.
        $user = User::factory()->create(['email' => 'promoted@intren.hu', 'role' => UserRole::Viewer]);
        $user->update(['role' => UserRole::Admin]);

        Socialite::fake('google', SocialiteUser::fake(['email' => 'promoted@intren.hu']));
        $this->get('/auth/google/callback');

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
    }

    public function test_logout_logs_the_user_out_and_redirects_to_login(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_logout_sets_the_force_reauth_flag_for_the_next_login_attempt(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/logout');

        // Set on the FRESH post-logout session (logout() invalidates the old
        // one first) - this is the session the next request actually sees,
        // which is what assertSessionHas checks against here.
        $response->assertSessionHas('force_google_reauth', true);
    }

    /**
     * redirect() only builds a URL locally (session writes + http_build_query)
     * and never makes a network call, so exercising the REAL Socialite driver
     * here is safe and fast - Socialite::fake()'s FakeProvider::redirect()
     * ignores with()/scopes entirely and always returns a fixed fake URL, so
     * faking it would hide the exact logic this test exists to check.
     */
    public function test_redirect_includes_the_account_chooser_prompt_right_after_a_logout(): void
    {
        session(['force_google_reauth' => true]);

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('prompt=select_account', $response->headers->get('Location'));
        // One-shot: consumed by redirect(), so it doesn't force the chooser
        // on every login attempt forever after a single logout.
        $this->assertFalse(session()->has('force_google_reauth'));
    }

    public function test_redirect_does_not_force_the_prompt_on_an_ordinary_login(): void
    {
        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringNotContainsString('prompt=', $response->headers->get('Location'));
    }

    public function test_login_redirect_still_works_when_google_redirect_uri_env_var_is_unset(): void
    {
        // Regression test for a real bug found live, not in this suite:
        // setUp() above always sets 'services.google.redirect' to a dummy
        // value, which masked this from ever being exercised here. The real
        // ALLOWED_EXTERNAL_EMAILS... no, GOOGLE_REDIRECT_URI env var is
        // genuinely unset now (removed from .env/.env.example deliberately,
        // since it's dynamically computed per request instead - see
        // config/services.php's own comment) - env('GOOGLE_REDIRECT_URI')
        // then evaluates to null, which is exactly what this simulates.
        // Socialite's SocialiteManager::buildProvider() only requires the
        // ARRAY KEY 'redirect' to be PRESENT (checked via array_keys(),
        // confirmed by reading its source, not assumed) - it tolerates a
        // null/empty VALUE just fine, which is why config/services.php
        // keeps `'redirect' => env('GOOGLE_REDIRECT_URI')` as a key that's
        // always present even though its value is never actually used (see
        // GoogleAuthController::callbackUrl(), which always overrides it).
        config(['services.google.redirect' => null]);

        $response = $this->get('/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString(
            'redirect_uri='.urlencode(route('auth.google.callback')),
            $response->headers->get('Location')
        );
    }

    public function test_the_google_redirect_uri_matches_the_named_callback_route_exactly(): void
    {
        $response = $this->get('/auth/google/redirect');

        $this->assertStringContainsString(
            'redirect_uri='.urlencode(route('auth.google.callback')),
            $response->headers->get('Location')
        );
    }

    public function test_the_google_redirect_uri_uses_https_when_force_https_urls_is_active(): void
    {
        // Regression test for a real gap found while adding tunnel support
        // (README.md "LAN access via a tunnel", PLANNING_LOG.md 2026-11-02):
        // a tunnel client like `npx localtunnel` is a raw TCP pipe with no
        // X-Forwarded-Proto header, so without forcing the scheme, route()
        // would generate http://... even though the public visitor actually
        // connected over https://... - and Google rejects a redirect_uri
        // that doesn't exactly match what's registered (https required for
        // any non-localhost host). This simulates what
        // AppServiceProvider::boot() does when config('app.force_https_urls')
        // is true, without booting a second application instance. Asserts
        // against route()'s own live output rather than a hardcoded host:
        // PHPUnit's test HTTP client resolves the dispatched request's URL
        // from config('app.url') before the request object exists
        // (MakesHttpRequests::prepareUrlForRequest()), so a custom Host
        // header passed here wouldn't actually reach route() the way a real
        // request's Host header does (same harness limitation documented
        // for test_the_google_redirect_uri_matches_the_named_callback_route_exactly
        // above) - what's actually under test is that forcing the scheme
        // works at all, confirmed by asserting route() itself now starts
        // with https://.
        \Illuminate\Support\Facades\URL::forceScheme('https');

        $this->assertStringStartsWith('https://', route('auth.google.callback'));

        $response = $this->get('/auth/google/redirect');

        $this->assertStringContainsString(
            'redirect_uri='.urlencode(route('auth.google.callback')),
            $response->headers->get('Location')
        );
    }

    public function test_the_google_redirect_uri_ignores_a_stale_services_google_redirect_config_value(): void
    {
        // Proves the old static-config mechanism is genuinely gone, not
        // just coincidentally unused: a deliberately wrong leftover value in
        // services.google.redirect must have zero effect on what's actually
        // sent to Google now (see GoogleAuthController::callbackUrl(), which
        // no longer reads this key at all).
        config(['services.google.redirect' => 'http://totally-different-host.example/callback']);

        $response = $this->get('/auth/google/redirect');

        $this->assertStringNotContainsString('totally-different-host.example', $response->headers->get('Location'));
        $this->assertStringContainsString(
            'redirect_uri='.urlencode(route('auth.google.callback')),
            $response->headers->get('Location')
        );
    }

    public function test_admin_panel_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_panel_is_accessible_for_an_admin_and_lists_users(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['email' => 'other@intren.hu']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee($admin->email)
            ->assertSee('other@intren.hu');
    }

    public function test_a_viewer_cannot_patch_a_role_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $other = User::factory()->create();

        $this->actingAs($viewer)
            ->patch("/admin/users/{$other->id}/role", ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_admin_can_promote_a_viewer_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->patch("/admin/users/{$viewer->id}/role", ['role' => 'admin'])
            ->assertRedirect();

        $this->assertSame(UserRole::Admin, $viewer->fresh()->role);
    }

    public function test_admin_cannot_demote_the_last_remaining_admin(): void
    {
        $onlyAdmin = User::factory()->admin()->create();

        $this->actingAs($onlyAdmin)
            ->patch("/admin/users/{$onlyAdmin->id}/role", ['role' => 'viewer'])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Admin, $onlyAdmin->fresh()->role);
    }

    public function test_admin_can_demote_another_admin_when_a_second_admin_still_remains(): void
    {
        $admin1 = User::factory()->admin()->create();
        $admin2 = User::factory()->admin()->create();

        $this->actingAs($admin1)->patch("/admin/users/{$admin2->id}/role", ['role' => 'viewer']);

        $this->assertSame(UserRole::Viewer, $admin2->fresh()->role);
    }

    public function test_an_admin_emails_protected_admin_cannot_be_demoted_by_another_admin(): void
    {
        $protected = User::factory()->admin()->create(['email' => 'admin@intren.hu']); // matches setUp()'s admin_emails
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($otherAdmin)
            ->patch("/admin/users/{$protected->id}/role", ['role' => 'viewer'])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Admin, $protected->fresh()->role);
    }

    public function test_an_admin_emails_protected_admin_cannot_be_demoted_even_with_plenty_of_other_admins(): void
    {
        // Distinct from the last-admin guard: this must block regardless of
        // how many OTHER admins exist, not just when it's the only one.
        $protected = User::factory()->admin()->create(['email' => 'admin@intren.hu']);
        $otherAdmin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->actingAs($otherAdmin)->patch("/admin/users/{$protected->id}/role", ['role' => 'viewer']);

        $this->assertSame(UserRole::Admin, $protected->fresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role_even_with_other_admins_present(): void
    {
        // Stricter than the last-admin guard: blocks regardless of how many
        // OTHER admins exist, same shape as the ADMIN_EMAILS protection test
        // above but for the new, unconditional self-protection rule.
        $self = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->actingAs($self)
            ->patch("/admin/users/{$self->id}/role", ['role' => 'viewer'])
            ->assertSessionHasErrors('role');

        $this->assertSame(UserRole::Admin, $self->fresh()->role);
    }

    public function test_admin_can_delete_a_viewer(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($admin)
            ->delete("/admin/users/{$viewer->id}")
            ->assertRedirect();

        $this->assertSame(0, User::where('id', $viewer->id)->count());
    }

    public function test_admin_cannot_delete_an_admin_emails_protected_user(): void
    {
        $protected = User::factory()->admin()->create(['email' => 'admin@intren.hu']);
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($otherAdmin)
            ->delete("/admin/users/{$protected->id}")
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::where('id', $protected->id)->count());
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $self = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->actingAs($self)
            ->delete("/admin/users/{$self->id}")
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::where('id', $self->id)->count());
    }

    public function test_admin_cannot_delete_the_last_remaining_admin(): void
    {
        $onlyAdmin = User::factory()->admin()->create();

        $this->actingAs($onlyAdmin)
            ->delete("/admin/users/{$onlyAdmin->id}")
            ->assertSessionHasErrors('role');

        $this->assertSame(1, User::where('id', $onlyAdmin->id)->count());
    }

    public function test_a_viewer_cannot_delete_a_user_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $target = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)
            ->delete("/admin/users/{$target->id}")
            ->assertForbidden();

        $this->assertSame(1, User::where('id', $target->id)->count());
    }

    public function test_the_logged_in_admins_own_row_shows_protected_with_no_edit_toggle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('You cannot edit or delete your own account here', false);
    }

    public function test_a_non_protected_user_row_renders_collapsed_with_an_edit_toggle(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['role' => UserRole::Viewer, 'email' => 'plain.viewer@intren.hu']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('class="editable-row"', false)
            ->assertSeeInOrder(['edit-toggle', 'edit-only']);
    }

    public function test_admin_can_add_a_new_user_with_a_valid_domain_email(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'new.person@intren.hu', 'role' => 'viewer'])
            ->assertRedirect();

        $created = User::where('email', 'new.person@intren.hu')->first();
        $this->assertNotNull($created);
        $this->assertSame(UserRole::Viewer, $created->role);
        $this->assertNull($created->google_id); // inert until they actually sign in
    }

    public function test_adding_a_user_dispatches_an_invitation_notification(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'new.person@intren.hu', 'role' => 'viewer']);

        $created = User::where('email', 'new.person@intren.hu')->first();
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $created,
            \App\Notifications\UserInvited::class,
            fn ($notification) => $notification->toMail($created)->subject === 'You now have access to the ChatGPT Ads Industry Dashboard'
        );
    }

    public function test_the_invitation_notification_is_genuinely_queued_not_sent_inline(): void
    {
        $notification = new \App\Notifications\UserInvited(UserRole::Admin);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }

    public function test_the_invitation_notification_mentions_the_granted_role(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $notification = new \App\Notifications\UserInvited(UserRole::Admin);

        $mail = $notification->toMail($user);

        $this->assertStringContainsString('admin', strtolower(implode(' ', $mail->introLines)));
    }

    public function test_adding_a_user_with_an_admin_emails_forced_role_mentions_admin_in_the_notification(): void
    {
        // The role passed to the notification must be the FINAL, resolved
        // role (after the admin_emails force-to-Admin rule), not whatever
        // the submitted form said - same source of truth as the stored role.
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'admin@intren.hu', 'role' => 'viewer']);

        $created = User::where('email', 'admin@intren.hu')->first();
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $created,
            \App\Notifications\UserInvited::class,
            fn ($notification) => str_contains(strtolower(implode(' ', $notification->toMail($created)->introLines)), 'admin')
        );
    }

    public function test_admin_cannot_add_a_user_with_a_non_company_domain_email(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'someone@gmail.com', 'role' => 'viewer'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::where('email', 'someone@gmail.com')->count());
    }

    public function test_adding_an_admin_emails_listed_address_forces_admin_role_regardless_of_the_form(): void
    {
        $admin = User::factory()->admin()->create();

        // Form says viewer - admin_emails wins, same rule as a real login.
        $this->actingAs($admin)->post('/admin/users', ['email' => 'admin@intren.hu', 'role' => 'viewer']);

        $this->assertSame(UserRole::Admin, User::where('email', 'admin@intren.hu')->first()->role);
    }

    public function test_admin_can_invite_the_explicitly_allowed_external_test_email(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'approved.exception@gmail.com', 'role' => 'viewer'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors('email');

        $created = User::where('email', 'approved.exception@gmail.com')->first();
        $this->assertNotNull($created);
        $this->assertSame(UserRole::Viewer, $created->role);
    }

    public function test_admin_still_cannot_add_a_different_external_email_even_one_character_off_the_allowed_one(): void
    {
        // The exception is one named email, not a pattern - a lookalike gmail
        // address must still be rejected exactly like any other outsider.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'approved.exceptionn@gmail.com', 'role' => 'viewer'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, User::where('email', 'approved.exceptionn@gmail.com')->count());
    }

    public function test_the_allowed_external_email_can_actually_sign_in_via_google(): void
    {
        // The admin-invite exception alone would be useless "for testing
        // purposes" if this same email still couldn't complete the actual
        // Google OAuth gate - both enforcement points share one rule
        // (User::isAllowedEmail()) specifically so this works end to end.
        Socialite::fake('google', SocialiteUser::fake(['email' => 'approved.exception@gmail.com', 'name' => 'Test Account']));

        $response = $this->get('/auth/google/callback');

        $user = User::where('email', 'approved.exception@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
    }

    public function test_a_standard_external_email_still_cannot_sign_in_via_google_despite_the_named_exception(): void
    {
        Socialite::fake('google', SocialiteUser::fake(['email' => 'someone.else@gmail.com']));

        $response = $this->get('/auth/google/callback');

        $this->assertSame(0, User::where('email', 'someone.else@gmail.com')->count());
        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_admin_cannot_add_a_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'existing@intren.hu']);

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'existing@intren.hu', 'role' => 'viewer'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_viewer_cannot_add_a_user_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)
            ->post('/admin/users', ['email' => 'sneaky@intren.hu', 'role' => 'admin'])
            ->assertForbidden();

        $this->assertSame(0, User::where('email', 'sneaky@intren.hu')->count());
    }

    public function test_admin_panel_shows_both_tabs(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('User Management')
            ->assertSee('Ads Account Management')
            ->assertSee('Add an ads account');
    }

    public function test_add_user_forms_role_dropdown_defaults_to_viewer_not_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('<option value="viewer" selected>Viewer</option>', false)
            ->assertSee('<option value="admin" >Admin</option>', false);
    }

    public function test_submitting_the_add_user_form_with_no_explicit_role_still_requires_one(): void
    {
        // The HTML default is Viewer, but the server-side rule stays
        // 'required' regardless - a raw request with the field missing
        // entirely must still be rejected, not silently default to Viewer
        // itself (that would hide a client bypassing the form).
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', ['email' => 'no.role@intren.hu'])
            ->assertSessionHasErrors('role');

        $this->assertSame(0, User::where('email', 'no.role@intren.hu')->count());
    }

    public function test_admin_can_add_an_ads_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test-12345', 'industry' => 'Health', 'currency' => 'HUF'])
            ->assertRedirect();

        $account = \App\Models\AdsAccount::first();
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

        $account = \App\Models\AdsAccount::first();
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

        $account = \App\Models\AdsAccount::first();
        $this->assertSame(1, \App\Models\AdsAccountPerformanceRecord::where('ads_account_id', $account->id)->count());
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

        $this->assertSame(1, \App\Models\AdsAccount::count());
    }

    public function test_ads_api_key_is_encrypted_at_rest_not_stored_as_plaintext(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/ads-accounts', [
            'ads_api_key' => 'sk-super-secret-value', 'industry' => 'Health', 'currency' => 'HUF',
        ]);

        $rawValue = \Illuminate\Support\Facades\DB::table('ads_accounts')->value('ads_api_key');
        $this->assertStringNotContainsString('sk-super-secret-value', $rawValue);
    }

    public function test_adding_an_ads_account_rejects_an_unknown_industry(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Not A Real Industry', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');

        $this->assertSame(0, \App\Models\AdsAccount::count());
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

    public function test_a_viewer_cannot_add_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-test', 'industry' => 'Health', 'currency' => 'HUF'])
            ->assertForbidden();

        $this->assertSame(0, \App\Models\AdsAccount::count());
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
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-original-key']);

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
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-original-key']);

        $this->actingAs($admin)->patch("/admin/ads-accounts/{$account->id}", [
            'industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-sneaky-replacement',
        ]);

        $this->assertSame('sk-original-key', $account->refresh()->ads_api_key);
    }

    public function test_editing_an_ads_account_rejects_an_unknown_industry_and_leaves_it_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($admin)
            ->patch("/admin/ads-accounts/{$account->id}", ['industry' => 'Not A Real Industry', 'currency' => 'HUF'])
            ->assertSessionHasErrors('industry');

        $this->assertSame('Health', $account->refresh()->industry);
    }

    public function test_a_viewer_cannot_edit_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

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

        $this->assertSame(0, \App\Models\AdsAccount::count());
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
        \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'EUR', 'ads_api_key' => 'sk-db-key']);
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
        \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'EUR', 'ads_api_key' => 'sk-db-key']);
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
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($admin)
            ->delete("/admin/ads-accounts/{$account->id}")
            ->assertRedirect();

        $this->assertSame(0, \App\Models\AdsAccount::count());
    }

    public function test_a_viewer_cannot_delete_an_ads_account_even_by_hitting_the_route_directly(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $account = \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-key']);

        $this->actingAs($viewer)
            ->delete("/admin/ads-accounts/{$account->id}")
            ->assertForbidden();

        $this->assertSame(1, \App\Models\AdsAccount::count());
    }

    public function test_adding_an_ads_account_rejects_a_key_already_registered_as_a_database_account(): void
    {
        $admin = User::factory()->admin()->create();
        \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-duplicate-key']);

        $this->actingAs($admin)
            ->post('/admin/ads-accounts', ['ads_api_key' => 'sk-duplicate-key', 'industry' => 'Automotive', 'currency' => 'EUR'])
            ->assertSessionHasErrors('ads_api_key');

        $this->assertSame(1, \App\Models\AdsAccount::count());
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

        $this->assertSame(0, \App\Models\AdsAccount::count());
    }

    public function test_the_database_itself_rejects_a_duplicate_api_key_hash_independent_of_app_level_validation(): void
    {
        // Proves the real safety net (migration 2026_11_01_143618's unique
        // index), not just the app-level pre-check above: inserting two rows
        // with the same ads_api_key_hash directly must fail at the database
        // layer, which is what actually protects against a race between two
        // concurrent requests that both pass the app-level check.
        \App\Models\AdsAccount::create(['industry' => 'Health', 'currency' => 'HUF', 'ads_api_key' => 'sk-race-key']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \Illuminate\Support\Facades\DB::table('ads_accounts')->insert([
            'industry' => 'Automotive', 'currency' => 'EUR', 'ads_api_key' => 'ciphertext-does-not-matter-here',
            'ads_api_key_hash' => hash('sha256', 'sk-race-key'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
