<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function redirect(): RedirectResponse
    {
        // 'hd' only pre-filters which Google accounts the picker *offers* -
        // it's a UX nicety, not enforcement (a malicious client can send any
        // value). The actual access decision happens in callback(), against
        // the email Google itself returns.
        $params = ['hd' => config('services.google.allowed_domain')];

        // One-shot: set by logout() on the fresh post-logout session, pulled
        // (read + cleared) here so it only affects the very next login
        // attempt. Google keeps its own separate session in the browser, so
        // without 'prompt', a user who just logged out of the dashboard
        // could click "Sign in with Google" and be signed straight back in
        // as the same account with no chooser at all - this forces Google's
        // account picker that one time instead.
        if (session()->pull('force_google_reauth', false)) {
            $params['prompt'] = 'select_account';
        }

        return Socialite::driver('google')->with($params)->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            /** @var SocialiteUser $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            // Socialite stores a one-time 'state' value in the session during
            // redirect() and consumes (pulls) it here - hitting this callback
            // URL a second time (back button, page refresh, a double click
            // on "Sign in with Google", link prefetching) always lands here
            // legitimately, since the state was already used up by the first
            // hit. Recoverable by just trying again - not a 500, not a bug.
            return redirect()->route('login')
                ->with('error', 'Your sign-in attempt expired or was already used. Please try signing in again.');
        }
        $email = $googleUser->getEmail();

        if (! User::isAllowedDomainEmail($email)) {
            // Never creates/touches a User row for a rejected domain - and
            // Auth::login() hasn't run yet, so this person never gets an
            // app session even though they completed Google's own OAuth flow.
            return redirect()->route('login')
                ->with('error', "Your Google account isn't authorized for this dashboard.");
        }

        // firstOrCreate's second argument (name/password/role/google_id)
        // only applies the FIRST time this email is ever seen - admin_emails
        // seeds the role once, not on every login (see PLANNING_LOG.md
        // 2026-10-27), and an existing user's role always comes from the
        // access panel from then on, never silently re-derived here.
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $googleUser->getName() ?? $email,
                'password' => Hash::make(Str::random(40)),
                'google_id' => $googleUser->getId(),
                'role' => User::isEnvAdmin($email) ? UserRole::Admin : UserRole::Viewer,
            ]
        );

        Auth::login($user, remember: true);

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Set AFTER invalidate(): that call wipes all session data and
        // issues a new session ID, so setting this any earlier would just
        // get thrown away before it could ever reach the next request.
        $request->session()->put('force_google_reauth', true);

        return redirect()->route('login');
    }
}
