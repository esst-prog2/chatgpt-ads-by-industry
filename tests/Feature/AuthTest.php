<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Coming soon');
    }
}
