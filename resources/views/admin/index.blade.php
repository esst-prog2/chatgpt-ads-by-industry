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
        .role-form { display:flex; gap:8px; align-items:center; }
        .protected-badge { font-size:12px; font-weight:700; color:var(--purple); border:1px solid var(--purple); border-radius:99px; padding:2px 10px; }
        input, select, button { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg); }
        button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        button:hover { background:var(--accent-hover); }
        .muted { color:var(--muted); }
        {{-- Clean responsive 3-column grid (email / role / button), collapsing
             to one column on narrow screens - every field and the submit
             button share one explicit height/padding/radius/font-size rule
             below, so none of them can drift out of alignment with the others. --}}
        .add-user-form { display:grid; grid-template-columns:1fr 160px auto; gap:12px; align-items:end; }
        .add-user-form .field { display:flex; flex-direction:column; gap:4px; }
        .add-user-form label { font-size:13px; color:var(--muted); }
        .add-user-form input,
        .add-user-form select,
        .add-user-form button {
            height:40px; box-sizing:border-box; padding:0 12px;
            border:1px solid var(--line); border-radius:8px; font-size:14px;
            background:var(--card); color:var(--fg);
        }
        .add-user-form button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        .add-user-form button:hover { background:var(--accent-hover); }
        @media (max-width:560px) {
            .add-user-form { grid-template-columns:1fr; }
        }
        .placeholder { text-align:center; padding:40px 16px; color:var(--muted); }
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
                <thead><tr><th>Email</th><th>Name</th><th>Role</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->is(auth()->user()) ? $user->role->value.' (you)' : $user->role->value }}</td>
                        <td>
                            @if (\App\Models\User::isEnvAdmin($user->email))
                                <span class="protected-badge" title="Set via ADMIN_EMAILS - can't be changed here">Protected</span>
                            @else
                                <form class="role-form" method="POST" action="{{ route('admin.users.update', $user) }}">
                                    @csrf
                                    @method('PATCH')
                                    <select name="role">
                                        @foreach (\App\Enums\UserRole::cases() as $role)
                                            <option value="{{ $role->value }}" @selected($role === $user->role)>{{ ucfirst($role->value) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit">Save</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($users->isEmpty())
                <p class="muted">No users yet.</p>
            @endif
        </div>

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
                    <select id="add-user-role" name="role">
                        @foreach (\App\Enums\UserRole::cases() as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
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
            <div class="placeholder">
                <h2>Ads Account Management</h2>
                <p style="margin:0;">Coming soon - this is where real Ads API accounts will be added and managed directly from the dashboard.</p>
            </div>
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
</script>
</body>
</html>
