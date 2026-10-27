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

    public function test_admin_panel_is_forbidden_for_a_viewer(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_panel_is_accessible_for_an_admin_and_lists_users(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['email' => 'other@intren.hu']);

        $this->actingAs($admin)->get('/admin/users')
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
}
