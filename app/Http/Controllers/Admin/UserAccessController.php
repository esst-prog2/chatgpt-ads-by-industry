<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    public function index(): View
    {
        return view('admin.users', ['users' => User::orderBy('email')->get()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', new Enum(UserRole::class)],
        ]);
        $newRole = UserRole::from($data['role']);

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
