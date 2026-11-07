<?php

namespace App\Http\Controllers\Admin;

use App\Data\Industries;
use App\Data\IndustryMapping;
use App\Data\RealAdsSource;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AdsAccount;
use App\Models\User;
use App\Notifications\UserInvited;
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
        return view('admin.index', [
            'users' => User::orderBy('email')->get(),
            'unifiedAccounts' => $this->unifiedAccounts(),
            'industries' => Industries::onboardable(),
            // Single source of truth for "currencies this app knows about" -
            // config('ads.exchange_rates_to_huf')'s keys, not a separately
            // hardcoded list here or in AdsAccountController: a currency
            // can't actually be aggregated (Aggregator::toHuf()) without a
            // configured rate anyway, so that map is already the real
            // authority on which currencies are usable. See PLANNING_LOG.md
            // 2026-11-07.
            'currencies' => array_keys(config('ads.exchange_rates_to_huf')),
        ]);
    }

    /**
     * One combined, anonymized list for the admin Ads Account tab: the
     * .env-configured real accounts (RealAdsSource) first, then the
     * database-registered AdsAccount rows - numbered "Account #1", "Account
     * #2"... continuously across both groups, never the dashboard's own
     * configured label (e.g. "Education Partner A"), since this listing's
     * whole purpose is a strictly anonymous admin-facing summary.
     *
     * Real accounts are 'editable' => false: there's no database row backing
     * them, so there is genuinely nothing here to PATCH/DELETE - their
     * industry comes from the CSV mapping file and their currency/key from
     * .env, changed by editing those directly, not through this table
     * (clarified with the user 2026-11-01 rather than building a database
     * override layer to fake live-editability for a source that isn't
     * actually database-backed).
     *
     * @return list<array{industry: string, currency: string, source: string, editable: bool, model: ?AdsAccount}>
     */
    private function unifiedAccounts(): array
    {
        $mapping = IndustryMapping::fromCsv(database_path('data/account_industry.csv'));
        $rows = [];

        foreach (RealAdsSource::fromConfig() as $source) {
            foreach (array_keys($source->accounts()) as $id) {
                $rows[] = [
                    'industry' => $mapping->industryOf($id),
                    'currency' => $source->currency(),
                    'source' => 'Live (.env)',
                    'editable' => false,
                    'model' => null,
                ];
            }
        }

        foreach (AdsAccount::orderBy('industry')->get() as $account) {
            $rows[] = [
                'industry' => $account->industry,
                'currency' => $account->currency,
                'source' => $account->created_at->toDateString(),
                'editable' => true,
                'model' => $account,
            ];
        }

        return $rows;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'role' => ['required', new Enum(UserRole::class)],
        ]);

        if (! User::isAllowedEmail($data['email'])) {
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
        $user = User::create([
            'name' => $data['email'],
            'email' => $data['email'],
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
        ]);

        // Genuinely queued (ShouldQueue), not dispatchSync()'d - see
        // UserInvited's own docblock for why this is one of the cases where
        // real backgrounding is the right call, not the "must work with zero
        // manual intervention" exception the ads-account sync needed.
        $user->notify(new UserInvited($role));

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

        // No admin can touch their own account through this panel at all -
        // a stricter, unconditional rule added 2026-11-01, not just "can't
        // demote yourself while you're the last admin". Checked before the
        // last-admin count guard below for the same reason as the
        // ADMIN_EMAILS check above: it's an absolute block, not a
        // conditional one.
        if ($user->is($request->user())) {
            return back()->withErrors(['role' => "You can't change your own role here."]);
        }

        // At least one Admin must always exist. In practice this guard is
        // now unreachable through this panel: only an Admin can reach this
        // route at all (EnsureUserIsAdmin), so if exactly one Admin exists,
        // that Admin IS the actor - and the self-protection check above
        // already blocks them from touching their own row before this ever
        // runs. Left in place anyway as a defensive backstop, not removed as
        // dead code: it's what would still catch this case if the
        // self-protection rule above were ever loosened or removed later.
        if ($newRole === UserRole::Viewer
            && $user->role === UserRole::Admin
            && User::where('role', UserRole::Admin)->count() <= 1) {
            return back()->withErrors(['role' => "You can't remove the last admin."]);
        }

        $user->update(['role' => $newRole]);

        return back()->with('status', "Updated {$user->email}'s role to {$newRole->value}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Same three guards as update(), in the same order (most absolute
        // first) - deleting a user is at least as consequential as changing
        // their role, so it gets identical protection, not a weaker set.
        if (User::isEnvAdmin($user->email)) {
            return back()->withErrors(['role' => "{$user->email} is a protected admin (set via ADMIN_EMAILS) and can't be removed here."]);
        }

        if ($user->is($request->user())) {
            return back()->withErrors(['role' => "You can't remove your own account."]);
        }

        if ($user->role === UserRole::Admin && User::where('role', UserRole::Admin)->count() <= 1) {
            return back()->withErrors(['role' => "You can't remove the last admin."]);
        }

        $email = $user->email;
        $user->delete();

        return back()->with('status', "Removed {$email}.");
    }
}
