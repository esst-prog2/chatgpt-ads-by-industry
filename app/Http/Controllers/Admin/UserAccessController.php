<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    public function index(): View
    {
        return view('admin.index', ['users' => User::orderBy('email')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'role' => ['required', new Enum(UserRole::class)],
        ]);

        if (! User::isAllowedDomainEmail($data['email'])) {
            return back()->withErrors(['email' => 'Only @'.config('services.google.allowed_domain').' emails can be added.']);
        }

        // Same rule as the login flow: an ADMIN_EMAILS-listed address is
        // always Admin, regardless of what the form says - consistent
        // whether the account is created here or on their first real login.
        $role = User::isEnvAdmin($data['email']) ? UserRole::Admin : UserRole::from($data['role']);

        // Inert until they actually sign in with Google: no google_id yet,
        // password is an unusable random hash (there's no password-login
        // route for it to ever matter) - same shape GoogleAuthController's
        // own firstOrCreate() produces. When this email does log in,
        // firstOrCreate() matches this existing row by email and leaves the
        // role set here untouched, exactly like any other pre-existing user.
        User::create([
            'name' => $data['email'],
            'email' => $data['email'],
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
        ]);

        return back()->with('status', "Added {$data['email']} as {$role->value}. They'll gain access once they sign in with Google.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', new Enum(UserRole::class)],
        ]);
        $newRole = UserRole::from($data['role']);

        // ADMIN_EMAILS accounts are permanently Admin - not even another
        // Admin can change this one, no exceptions. Checked before the
        // last-admin guard below since it's an absolute block regardless of
        // how many admins currently exist.
        if (User::isEnvAdmin($user->email)) {
            return back()->withErrors(['role' => "{$user->email} is a protected admin (set via ADMIN_EMAILS) and can't be changed here."]);
        }

        // At least one Admin must always exist - this has to hold regardless
        // of whether an admin is demoting themselves or someone else, not
        // just a narrower "can't touch your own account" rule.
        if ($newRole === UserRole::Viewer
            && $user->role === UserRole::Admin
            && User::where('role', UserRole::Admin)->count() <= 1) {
            return back()->withErrors(['role' => "You can't remove the last admin."]);
        }

        $user->update(['role' => $newRole]);

        return back()->with('status', "Updated {$user->email}'s role to {$newRole->value}.");
    }
}
