<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Access · ChatGPT Ads Industry Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap" rel="stylesheet">
    <style>
        {{-- Same brand tokens as dashboard.blade.php/auth/login.blade.php - see DESIGN_AGENT.md. --}}
        :root { --bg:#F8FAFC; --fg:#19095B; --muted:#6E6B85; --card:#FFFFFF; --line:#E2E8F0; --accent:#0600FF; --accent-hover:#0500CC; --accent-contrast:#FFFFFF; --success:#02B77F; --err:#D7263D; }
        @media (prefers-color-scheme: dark) { :root { --bg:#140B3C; --fg:#F2F1FA; --muted:#B5B2CC; --card:#1D1154; --line:#352A73; --accent:#7C83FF; --accent-hover:#959AFF; --accent-contrast:#19095B; --success:#34d3a8; --err:#FF6B81; } }
        * { box-sizing: border-box; }
        body { margin:0; padding:24px 16px; background:var(--bg); color:var(--fg); font-family:'Montserrat', ui-sans-serif, system-ui, sans-serif; font-weight:300; font-size:15px; line-height:1.5; }
        main { max-width:800px; margin:0 auto; }
        h1 { font-size:22px; font-weight:700; margin:0 0 4px; }
        .back { font-size:13px; color:var(--muted); text-decoration:none; }
        .back:hover { color:var(--accent); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-top:20px; }
        .status { color:var(--success); font-size:14px; margin:0 0 16px; }
        .error { color:var(--err); font-size:14px; margin:0 0 16px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:left; padding:8px; border-bottom:1px solid var(--line); }
        th { font-weight:700; }
        .role-form { display:flex; gap:8px; align-items:center; }
        select, button { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg); }
        button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        button:hover { background:var(--accent-hover); }
        .muted { color:var(--muted); }
    </style>
</head>
<body>
<main>
    <a class="back" href="{{ url('/') }}">&larr; Back to dashboard</a>
    <h1>Manage Access</h1>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @error('role')
        <p class="error">{{ $message }}</p>
    @enderror

    <div class="card">
        <table>
            <thead><tr><th>Email</th><th>Name</th><th>Role</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->is(auth()->user()) ? $user->role->value.' (you)' : $user->role->value }}</td>
                    <td>
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
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($users->isEmpty())
            <p class="muted">No users yet.</p>
        @endif
    </div>
</main>
</body>
</html>
