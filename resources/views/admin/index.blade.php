<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin · ChatGPT Ads Industry Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap" rel="stylesheet">
    <style>
        {{-- Same brand tokens as dashboard.blade.php/auth/login.blade.php - see DESIGN_AGENT.md. --}}
        :root { --bg:#F8FAFC; --fg:#19095B; --muted:#6E6B85; --card:#FFFFFF; --line:#E2E8F0; --accent:#0600FF; --accent-hover:#0500CC; --accent-contrast:#FFFFFF; --success:#02B77F; --purple:#5B148B; --err:#D7263D; }
        @media (prefers-color-scheme: dark) { :root { --bg:#140B3C; --fg:#F2F1FA; --muted:#B5B2CC; --card:#1D1154; --line:#352A73; --accent:#7C83FF; --accent-hover:#959AFF; --accent-contrast:#19095B; --success:#34d3a8; --purple:#C090E0; --err:#FF6B81; } }
        * { box-sizing: border-box; }
        body { margin:0; padding:24px 16px; background:var(--bg); color:var(--fg); font-family:'Montserrat', ui-sans-serif, system-ui, sans-serif; font-weight:300; font-size:15px; line-height:1.5; }
        main { max-width:800px; margin:0 auto; }
        h1 { font-size:22px; font-weight:700; margin:0 0 4px; }
        h2 { font-size:16px; font-weight:700; margin:0 0 12px; }
        .back { font-size:13px; color:var(--muted); text-decoration:none; }
        .back:hover { color:var(--accent); }
        .status { color:var(--success); font-size:14px; margin:16px 0 0; }
        .error { color:var(--err); font-size:14px; margin:16px 0 0; }
        .tabs { display:flex; gap:4px; margin-top:20px; border-bottom:1px solid var(--line); }
        .tab-button {
            font:inherit; font-weight:700; padding:10px 16px; border:none; border-bottom:2px solid transparent;
            background:none; color:var(--muted); cursor:pointer; margin-bottom:-1px;
        }
        .tab-button.active { color:var(--accent); border-bottom-color:var(--accent); }
        {{-- Explicit background:none on :hover/:active, not just the base
             rule: .tab-button:hover:not(.active) alone doesn't match once
             .active is present, so without this the page's generic
             button:hover/button:active rules (for Save/Add user) leak
             through onto the selected tab the moment the mouse is over it -
             confirmed via getComputedStyle, not just a screenshot guess. --}}
        .tab-button:hover { background:none; }
        .tab-button:hover:not(.active) { color:var(--fg); }
        .tab-button:active { background:none; }
        .tab-button:focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:4px; background:none; }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-top:16px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:8px; border-bottom:1px solid var(--line); }
        th { font-weight:700; }
        .protected-badge { font-size:12px; font-weight:700; color:var(--purple); border:1px solid var(--purple); border-radius:99px; padding:2px 10px; }
        input, select, button { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg); }
        button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        button:hover { background:var(--accent-hover); }
        .muted { color:var(--muted); }
        {{-- Clean responsive 3-column grid (email / role / button), collapsing
             to one column on narrow screens - every field and the submit
             button share one explicit height/padding/radius/font-size rule
             below, so none of them can drift out of alignment with the others. --}}
        .add-user-form, .add-account-form { display:grid; grid-template-columns:1fr 160px auto; gap:12px; align-items:end; }
        .add-account-form { grid-template-columns:1fr 160px 120px auto; }
        .row-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .row-actions input, .row-actions select { padding:6px 8px; }
        {{-- Collapsible edit flow, shared by the Ads accounts AND Users
             tables: an editable row starts showing plain text + an "Edit"
             button; clicking it reveals the select(s) and Save/Delete -
             toggled by a class on the <tr> rather than inline styles, so CSS
             (not JS) decides what each state looks like, and the one JS
             handler below (`.edit-toggle` click listener) works for any
             table using this same class pair with no table-specific code.
             display:revert, not a hardcoded display value, so each element
             (select/button/span) falls back to its own normal default
             regardless of tag. --}}
        .editable-row .edit-only { display:none; }
        .editable-row.editing .edit-only { display:revert; }
        .editable-row.editing .view-only { display:none; }
        {{-- background set explicitly in both states, not just filter on
             hover: .danger-button:hover and the page's generic button:hover
             (accent blue) have equal class+pseudo vs type+pseudo specificity
             on different properties, so without this the button would flash
             blue on hover instead of staying red - the same class of bug
             already caught once in this file (2026-10-30, tab-button hover). --}}
        .danger-button { background:var(--err); border-color:var(--err); }
        .danger-button:hover { background:var(--err); border-color:var(--err); filter:brightness(0.9); }
        .add-user-form .field, .add-account-form .field { display:flex; flex-direction:column; gap:4px; }
        .add-user-form label, .add-account-form label { font-size:13px; color:var(--muted); }
        .add-user-form input, .add-user-form select, .add-user-form button,
        .add-account-form input, .add-account-form select, .add-account-form button {
            height:40px; box-sizing:border-box; padding:0 12px;
            border:1px solid var(--line); border-radius:8px; font-size:14px;
            background:var(--card); color:var(--fg);
        }
        .add-user-form button, .add-account-form button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        .add-user-form button:hover, .add-account-form button:hover { background:var(--accent-hover); }
        @media (max-width:680px) {
            .add-user-form, .add-account-form { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<main>
    <a class="back" href="{{ url('/') }}">&larr; Back to dashboard</a>
    <h1>Admin</h1>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @foreach ($errors->all() as $message)
        <p class="error">{{ $message }}</p>
    @endforeach

    <div class="tabs" role="tablist">
        <button type="button" class="tab-button active" data-tab="users">User Management</button>
        <button type="button" class="tab-button" data-tab="ads">Ads Account Management</button>
    </div>

    <div class="tab-panel active" id="tab-users">
        <div class="card">
            <h2>Users</h2>
            <table>
                <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Actions</th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    @php($isEnvAdmin = \App\Models\User::isEnvAdmin($user->email))
                    @php($isSelf = $user->is(auth()->user()))
                    @php($protected = $isEnvAdmin || $isSelf)
                    <tr @if (! $protected) class="editable-row" @endif>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->name }}</td>
                        @if ($protected)
                            <td>{{ $isSelf ? $user->role->value.' (you)' : $user->role->value }}</td>
                            <td>
                                <span class="protected-badge" title="{{ $isEnvAdmin ? 'Set via ADMIN_EMAILS - cannot be changed here' : 'You cannot edit or delete your own account here' }}">Protected</span>
                            </td>
                        @else
                            @php($formId = 'user-edit-'.$user->id)
                            <td>
                                <span class="view-only">{{ $user->role->value }}</span>
                                <select class="edit-only" form="{{ $formId }}" name="role">
                                    @foreach (\App\Enums\UserRole::cases() as $role)
                                        <option value="{{ $role->value }}" @selected($role === $user->role)>{{ ucfirst($role->value) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="row-actions">
                                <button type="button" class="view-only edit-toggle">Edit</button>
                                <span class="edit-only">
                                    <button form="{{ $formId }}" type="submit">Save</button>
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                          onsubmit="return confirm('Remove this user?');" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="danger-button">Delete</button>
                                    </form>
                                </span>
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($users->isEmpty())
                <p class="muted">No users yet.</p>
            @endif
        </div>

        {{-- Same external-form technique as the Ads accounts table below:
             the Role select and the Save button now live in separate
             columns, so the editable row's actual PATCH <form> can't wrap
             them both directly - referenced by the form="" attribute above. --}}
        @foreach ($users as $user)
            @if (! \App\Models\User::isEnvAdmin($user->email) && ! $user->is(auth()->user()))
                <form id="user-edit-{{ $user->id }}" method="POST" action="{{ route('admin.users.update', $user) }}">
                    @csrf
                    @method('PATCH')
                </form>
            @endif
        @endforeach

        <div class="card">
            <h2>Add a user</h2>
            <form class="add-user-form" method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="field">
                    <label for="add-user-email">Email</label>
                    {{-- "name@" right before "{{" would be Blade's @{{ escape
                         sequence (literal output, for JS frameworks) and never
                         interpolate - built as one expression instead. --}}
                    <input type="email" id="add-user-email" name="email" required placeholder="{{ 'name@'.config('services.google.allowed_domain') }}">
                </div>
                <div class="field">
                    <label for="add-user-role">Role</label>
                    {{-- Defaults to Viewer, not the first enum case (Admin) -
                         minimal permissions until deliberately escalated,
                         rather than an admin accidentally leaving the
                         pre-filled value as-is for a new user. --}}
                    <select id="add-user-role" name="role">
                        @foreach (\App\Enums\UserRole::cases() as $role)
                            <option value="{{ $role->value }}" @selected($role === \App\Enums\UserRole::Viewer)>{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit">Add user</button>
            </form>
            <p class="muted" style="margin-bottom:0;margin-top:12px;">They'll gain access once they sign in with Google using this email.</p>
        </div>
    </div>

    <div class="tab-panel" id="tab-ads">
        <div class="card">
            <h2>Ads accounts</h2>
            <p class="muted" style="margin-top:0;">
                Every connected account, anonymized - .env-configured real
                accounts (read-only here: edit their industry via the CSV
                mapping file and their currency/key via .env) and
                database-registered accounts (editable below).
            </p>
            <table>
                <thead><tr><th>Account</th><th>Industry</th><th>Currency</th><th>Added / Source</th><th>Actions</th></tr></thead>
                <tbody>
                @foreach ($unifiedAccounts as $i => $row)
                    <tr @if ($row['editable']) class="editable-row" @endif>
                        <td>Account #{{ $i + 1 }}</td>
                        @if ($row['editable'])
                            @php($formId = 'ads-edit-'.$row['model']->id)
                            <td>
                                <span class="view-only">{{ $row['industry'] }}</span>
                                <select class="edit-only" form="{{ $formId }}" name="industry">
                                    @foreach ($industries as $ind)
                                        <option value="{{ $ind }}" @selected($ind === $row['industry'])>{{ $ind }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <span class="view-only">{{ $row['currency'] }}</span>
                                {{-- Pre-selects the account's own actual stored
                                     currency (never forced to HUF regardless of
                                     that) - HUF is only the Add-account form's
                                     default for a brand-new row, not something
                                     an edit should override onto an existing
                                     EUR/USD account. --}}
                                <select class="edit-only" form="{{ $formId }}" name="currency">
                                    @foreach ($currencies as $c)
                                        <option value="{{ $c }}" @selected($c === $row['currency'])>{{ $c }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>{{ $row['source'] }}</td>
                            <td class="row-actions">
                                <button type="button" class="view-only edit-toggle">Edit</button>
                                <span class="edit-only">
                                    {{-- The API key is immutable once set - no edit
                                         field here at all (AdsAccountController::update()
                                         no longer accepts ads_api_key either; rotating a
                                         key means removing this account and re-adding it). --}}
                                    <button form="{{ $formId }}" type="submit">Save</button>
                                    <form method="POST" action="{{ route('admin.ads-accounts.destroy', $row['model']) }}"
                                          onsubmit="return confirm('Remove this ads account?');" style="display:inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="danger-button">Delete</button>
                                    </form>
                                </span>
                            </td>
                        @else
                            <td>{{ $row['industry'] }}</td>
                            <td>{{ $row['currency'] }}</td>
                            <td>{{ $row['source'] }}</td>
                            <td><span class="muted">Read-only (.env)</span></td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if (empty($unifiedAccounts))
                <p class="muted">No accounts connected yet.</p>
            @endif
        </div>

        {{-- A <form> can't wrap table cells directly (confirmed earlier in
             this file's history), and an editable row's controls now span
             separate Industry/Currency/Actions columns rather than one
             merged cell - so each editable row's actual PATCH <form> lives
             here instead, referenced by the matching inputs' form="" attribute
             above. Laravel's e() default-escapes $row['model']->id (an int),
             so this is safe even though it's built with string concatenation. --}}
        @foreach ($unifiedAccounts as $row)
            @if ($row['editable'])
                <form id="ads-edit-{{ $row['model']->id }}" method="POST" action="{{ route('admin.ads-accounts.update', $row['model']) }}">
                    @csrf
                    @method('PATCH')
                </form>
            @endif
        @endforeach

        <div class="card">
            <h2>Add an ads account</h2>
            <p class="muted" style="margin-top:0;">
                Registers an account for the Rule of 3 (3+ real accounts in an
                industry switches that industry to real data only). A background
                job fetches its live data right after you add it, and every
                registered account re-syncs automatically every night.
            </p>
            <form class="add-account-form" method="POST" action="{{ route('admin.ads-accounts.store') }}">
                @csrf
                <div class="field">
                    <label for="add-account-key">Ads API Key</label>
                    <input type="password" id="add-account-key" name="ads_api_key" required autocomplete="off">
                </div>
                <div class="field">
                    <label for="add-account-industry">Industry</label>
                    <select id="add-account-industry" name="industry" required>
                        <option value="" disabled selected>Select industry...</option>
                        @foreach ($industries as $i)
                            <option value="{{ $i }}">{{ $i }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="add-account-currency">Currency</label>
                    <select id="add-account-currency" name="currency">
                        @foreach ($currencies as $c)
                            <option value="{{ $c }}" @selected($c === 'HUF')>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit">Add account</button>
            </form>
        </div>
    </div>
</main>
<script>
    document.querySelectorAll('.tab-button').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.tab-button').forEach((b) => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
            button.classList.add('active');
            document.getElementById('tab-' + button.dataset.tab).classList.add('active');
        });
    });
    document.querySelectorAll('.edit-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            button.closest('tr').classList.add('editing');
        });
    });
</script>
</body>
</html>
