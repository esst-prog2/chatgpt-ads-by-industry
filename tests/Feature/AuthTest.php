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

    public function test_app_service_provider_boot_forces_https_when_the_config_flag_is_true(): void
    {
        // Closes a gap the test above left documented but open
        // (PLANNING_LOG.md 2026-11-07): that test proves URL::forceScheme()
        // itself works, by calling it directly - it never proves that
        // AppServiceProvider::boot() is what actually calls it when
        // config('app.force_https_urls') is true, which is the real,
        // env-driven path a user flips on in .env (see README.md "LAN
        // access via a tunnel"). This calls boot() directly instead of
        // re-booting a second Application (which would hijack the global
        // Facade state every other facade call in this process relies on,
        // for no real gain - the point is to prove boot()'s own logic runs
        // correctly, not to re-prove Laravel's own bootstrapping works).
        $this->assertStringStartsWith('http://', route('auth.google.callback'));

        config(['app.force_https_urls' => true]);
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', route('auth.google.callback'));
    }

    public function test_app_service_provider_boot_leaves_the_scheme_alone_when_the_config_flag_is_false(): void
    {
        // The other half of the same proof: boot() must NOT force https when
        // the flag is false (the default for plain localhost/LAN-IP use,
        // where the dev server genuinely has no TLS) - without this, the
        // test above could pass for the wrong reason (e.g. boot() always
        // forcing https regardless of the flag).
        config(['app.force_https_urls' => false]);
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', route('auth.google.callback'));
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

    // AdsAccountController's tests moved to tests/Feature/AdsAccountControllerTest.php
    // (PLANNING_LOG.md 2026-11-07) - they were filed here by historical
    // accident, not because they belong to auth/user-management.
}
