<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · ChatGPT Ads Industry Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap" rel="stylesheet">
    <style>
        {{-- Same brand tokens as resources/views/dashboard.blade.php - see DESIGN_AGENT.md. --}}
        :root { --bg:#F8FAFC; --fg:#19095B; --muted:#6E6B85; --card:#FFFFFF; --line:#E2E8F0; --accent:#0600FF; --accent-hover:#0500CC; --accent-contrast:#FFFFFF; --err:#D7263D; }
        @media (prefers-color-scheme: dark) { :root { --bg:#140B3C; --fg:#F2F1FA; --muted:#B5B2CC; --card:#1D1154; --line:#352A73; --accent:#7C83FF; --accent-hover:#959AFF; --accent-contrast:#19095B; --err:#FF6B81; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); color:var(--fg); font-family:'Montserrat', ui-sans-serif, system-ui, sans-serif; font-weight:300; font-size:15px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:40px; width:100%; max-width:360px; text-align:center; }
        .logo { height:48px; width:auto; object-fit:contain; margin-bottom:24px; }
        .logo-dark { display:none; }
        @media (prefers-color-scheme: dark) { .logo-light { display:none; } .logo-dark { display:inline-block; } }
        h1 { font-size:17px; font-weight:700; margin:0 0 24px; }
        .error { color:var(--err); font-size:13px; margin:0 0 16px; }
        .google-btn {
            display:inline-flex; align-items:center; justify-content:center; gap:10px;
            width:100%; height:44px; font:inherit; font-weight:700;
            background:var(--accent); color:var(--accent-contrast); border:1px solid var(--accent);
            border-radius:6px; cursor:pointer; text-decoration:none;
            transition: background-color .15s ease;
        }
        .google-btn:hover { background:var(--accent-hover); }
        .google-btn:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    </style>
</head>
<body>
    <div class="card">
        <img class="logo logo-light" src="{{ asset('images/brand-logo.png') }}" alt="Intren">
        <img class="logo logo-dark" src="{{ asset('images/logo_fekvő-09.png') }}" alt="Intren">
        <h1>ChatGPT Ads Industry Dashboard</h1>
        @if (session('error'))
            <p class="error">{{ session('error') }}</p>
        @endif
        <a class="google-btn" href="{{ url('/auth/google/redirect') }}">Sign in with Google</a>
    </div>
</body>
</html>
