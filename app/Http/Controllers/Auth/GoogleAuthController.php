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
        return Socialite::driver('google')
            ->with(['hd' => config('services.google.allowed_domain')])
            ->redirect();
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

        if (! $this->isAllowedDomain($email)) {
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
                'role' => $this->isSeededAdmin($email) ? UserRole::Admin : UserRole::Viewer,
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

        return redirect()->route('login');
    }

    private function isAllowedDomain(?string $email): bool
    {
        $allowedDomain = config('services.google.allowed_domain');
        if (blank($email) || blank($allowedDomain)) {
            return false;
        }

        $domain = substr((string) strrchr($email, '@'), 1);

        return strcasecmp($domain, $allowedDomain) === 0;
    }

    private function isSeededAdmin(string $email): bool
    {
        return in_array(strtolower($email), array_map('strtolower', config('services.google.admin_emails')), true);
    }
}
