<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ChatGPT Ads Industry Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;700&display=swap" rel="stylesheet">
    <style>
        /* Brand palette - see DESIGN_AGENT.md. Light theme is the brand default;
           the dark-mode block keeps the existing OS-preference support, retuned
           to the same navy/blue family instead of the old unbranded teal. */
        :root {
            --bg:#F8FAFC; --fg:#19095B; --muted:#6E6B85; --card:#FFFFFF; --line:#E2E8F0;
            --accent:#0600FF; --accent-hover:#0500CC; --accent-contrast:#FFFFFF;
            --success:#02B77F; --success-contrast:#19095B; --purple:#5B148B;
            --warn:#FF9100; --err:#D7263D; --nav-inactive:#19095B;
            --chart-accent:#0600FF; --chart-fill:rgba(6,0,255,0.22); --chart-grid:rgba(25,9,91,0.08);
            --chart-tick:#19095B; --chart-median:#19095B;
            {{-- Used only once an industry is actually selected (see the box-
                 plot JS below): every OTHER industry's box fades to this
                 neutral tone so the selected one visibly stands out, instead
                 of all boxes competing in the same brand blue. --}}
            --chart-muted-accent:#6E6B85; --chart-muted-fill:rgba(110,107,133,0.18); --chart-muted-median:#6E6B85;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg:#140B3C; --fg:#F2F1FA; --muted:#B5B2CC; --card:#1D1154; --line:#352A73;
                --accent:#7C83FF; --accent-hover:#959AFF; --accent-contrast:#19095B;
                --success:#34d3a8; --success-contrast:#19095B; --purple:#C090E0;
                --warn:#FF9100; --err:#FF6B81; --nav-inactive:#94A3B8;
                --chart-accent:#7C83FF; --chart-fill:rgba(124,131,255,0.45); --chart-grid:rgba(255,255,255,0.12);
                --chart-tick:#e5e7eb; --chart-median:#ffffff;
                --chart-muted-accent:#B5B2CC; --chart-muted-fill:rgba(181,178,204,0.22); --chart-muted-median:#B5B2CC;
            }
        }
        * { box-sizing: border-box; }
        body { margin:0; padding:24px 16px; background:var(--bg); color:var(--fg); font-family:'Montserrat', ui-sans-serif, system-ui, sans-serif; font-weight:300; font-size:15px; line-height:1.5; }
        main { max-width:1000px; margin:0 auto; }
        h1, h2, strong { font-weight:700; color:var(--fg); }
        h1 { font-size:22px; margin:0; } h2 { font-size:17px; margin:0 0 12px; }
        .site-header { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin:0 0 28px; }
        .site-header .logo { height:64px; width:auto; object-fit:contain; margin:4px 0; }
        .site-header h1 { flex:1; text-align:right; }
        /* Dynamic dark-mode logo swap (CSS-only, matches the rest of the page's
           prefers-color-scheme approach): the colored logo is for light
           backgrounds, logo_fekvő-09.png is the white/light variant for dark
           backgrounds - only one of the two is ever displayed. */
        .site-header .logo-dark { display:none; }
        @media (prefers-color-scheme: dark) {
            .site-header .logo-light { display:none; }
            .site-header .logo-dark { display:inline-block; }
        }
        .account-bar { display:flex; justify-content:flex-end; align-items:center; gap:8px; font-size:13px; color:var(--muted); margin:-20px 0 20px; }
        .account-bar a { color:var(--muted); text-decoration:none; }
        .account-bar a:hover { color:var(--warn); text-decoration:underline; }
        .account-bar .logout-button,
        .account-bar .logout-button:hover,
        .account-bar .logout-button:active,
        .account-bar .logout-button:focus-visible {
            background:none; border:none; outline:none; padding:0; margin:0; height:auto;
            font:inherit; font-weight:inherit; color:var(--muted); cursor:pointer; transform:none;
        }
        .account-bar .logout-button:hover { color:var(--warn); text-decoration:underline; }
        .account-bar .logout-button:focus-visible { text-decoration:underline; color:var(--accent); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
        form { display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
        label { display:flex; flex-direction:column; font-size:13px; color:var(--muted); gap:4px; }
        input, select, button {
            font:inherit; box-sizing:border-box; height:38px; padding:0 10px; margin:0;
            border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg);
            line-height:36px; vertical-align:middle;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease, transform .05s ease;
        }
        input:hover, select:hover { border-color:var(--accent); }
        input:focus-visible, select:focus-visible { outline:2px solid var(--accent); outline-offset:1px; border-color:var(--accent); }
        button { background:var(--accent); color:var(--accent-contrast); border-color:var(--accent); font-weight:700; cursor:pointer; }
        button:hover:not(:disabled) { background:var(--accent-hover); border-color:var(--accent-hover); }
        button:active:not(:disabled) { background:var(--success); border-color:var(--success); color:var(--success-contrast); transform:translateY(1px); }
        button:focus-visible { outline:3px solid var(--warn); outline-offset:2px; }
        button:disabled { opacity:.65; cursor:progress; }
        .notice { border-left:4px solid var(--warn); } .error { border-left:4px solid var(--err); }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:right; padding:6px 8px; border-bottom:1px solid var(--line); } th:first-child, td:first-child { text-align:left; }
        th { font-weight:700; }
        tbody tr:hover { background:var(--bg); }
        tr.active td:first-child a { color:var(--accent); font-weight:700; }
        .tag { font-size:12px; font-weight:700; padding:1px 8px; border-radius:99px; border:1px solid transparent; background:transparent; }
        .tag.real { color:var(--success); border-color:var(--success); }
        .tag.synthetic { color:var(--purple); border-color:var(--purple); }
        .chart-wrap { position:relative; height:340px; }
        .table-scroll { overflow-x:auto; }
        /* Industry links: the persistent selection state (which industry is
           drilled into) is blue-vs-navy per DESIGN_AGENT.md, not the usual
           link blue everywhere - see tr.active above for the selected one. */
        a { color:var(--nav-inactive); text-decoration:none; transition:color .15s ease; }
        a:hover { color:var(--warn); text-decoration:underline; }
        a:focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:2px; }
        .muted { color:var(--muted); }
        .empty-state { color:var(--muted); text-align:center; padding:20px; margin:0; border:1px dashed var(--line); border-radius:10px; }
    </style>
</head>
<body>
<main>
    <header class="site-header">
        <img class="logo logo-light" src="{{ asset('images/brand-logo.png') }}" alt="Intren">
        <img class="logo logo-dark" src="{{ asset('images/logo_fekvő-09.png') }}" alt="Intren">
        <h1>ChatGPT Ads Industry Dashboard</h1>
    </header>
    <div class="account-bar">
        Signed in as {{ auth()->user()->email }}
        @if (auth()->user()->isAdmin())
            <span>&middot;</span>
            <a href="{{ route('admin.index') }}">Admin</a>
        @endif
        <span>&middot;</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="logout-button">Logout</button>
        </form>
    </div>

    @php($metricLabels = ['impressions' => 'Impressions', 'clicks' => 'Clicks', 'spend' => 'Spend', 'ctr' => 'CTR', 'cpc' => 'CPC'])
    {{--
        Displayed Spend is rounded to a whole unit of its own currency for the table.
        CPC is derived from that SAME rounded Spend (not the unrounded total), so
        dividing the two numbers actually printed on screen always reproduces the
        printed CPC exactly, with only one rounding step - no double-rounding drift
        is possible. Industry totals are always HUF (accounts of different
        currencies are normalized before summing - see Aggregator); account and
        campaign rows stay in that account's own native currency.
    --}}
    @php($displaySpend = fn (array $t) => (int) round($t['spend']))
    @php($displayCpc = fn (array $t) => $t['clicks'] > 0 ? (int) round($displaySpend($t) / $t['clicks']) : null)

    @foreach ($errors_list as $message)
        <div class="card error">{{ $message }} Showing the previous results.</div>
    @endforeach
    @foreach ($notices as $message)
        <div class="card notice">{{ $message }} Synthetic accounts are still shown.</div>
    @endforeach

    <div class="card">
        <form method="get" action="/" id="filters">
            <label>From <input type="date" name="from" value="{{ $from }}"></label>
            <label>To <input type="date" name="to" value="{{ $to }}"></label>
            <label>Metric
                <select name="metric" id="metric">
                    @foreach ($metrics as $m)
                        <option value="{{ $m }}" @selected($m === $metric)>{{ $metricLabels[$m] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Industry
                {{-- $industries is already filtered server-side (DashboardService)
                     to only the industries that currently have at least one real
                     or synthetic account with data - never a static list. --}}
                <select name="industry" id="industry">
                    <option value="" @selected(! $selectedIndustry)>All industries</option>
                    @foreach ($industries as $i)
                        <option value="{{ $i }}" @selected($i === $selectedIndustry)>{{ $i }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">Update</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ $metricLabels[$metric] }} per campaign, by industry</h2>
        <div class="chart-wrap"><canvas id="boxplot" aria-label="Box plot of {{ $metric }} per campaign by industry"></canvas></div>
        @php($empty = array_keys(array_filter($boxPlot, fn ($v) => count($v) === 0)))
        @if ($empty)<p class="empty-state">No campaign data in this range for: {{ implode(', ', $empty) }}.</p>@endif
    </div>

    <div class="card table-scroll">
        <h2>Industry totals</h2>
        <table>
            <thead><tr><th>Industry</th><th>Impressions</th><th>Clicks</th><th>Spend</th><th>CTR</th><th>CPC</th></tr></thead>
            <tbody>
            @foreach ($industries as $i)
                @php($t = $industryTotals[$i] ?? null)
                <tr @class(['active' => $i === $selectedIndustry])>
                    <td><a href="/?{{ http_build_query(['from' => $from, 'to' => $to, 'metric' => $metric, 'industry' => $i]) }}">{{ $i }}</a></td>
                    @if ($t)
                        <td>{{ number_format($t['impressions']) }}</td><td>{{ number_format($t['clicks']) }}</td>
                        <td>{{ number_format($displaySpend($t)) }} HUF</td><td>{{ $t['ctr'] === null ? '–' : number_format($t['ctr'] * 100, 2).'%' }}</td>
                        <td>{{ $displayCpc($t) === null ? '–' : number_format($displayCpc($t)).' HUF' }}</td>
                    @else
                        <td colspan="5" class="muted">no data</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($drilldown !== null)
        <div class="card table-scroll" id="drilldown">
            <h2>{{ $selectedIndustry }}: accounts and campaigns</h2>
            <table>
                <thead><tr><th>Account / campaign</th><th>Impressions</th><th>Clicks</th><th>Spend</th><th>CTR</th><th>CPC</th></tr></thead>
                <tbody>
                @foreach ($drilldown as $a)
                    <tr>
                        <td><strong>{{ $a['name'] }}</strong> <span class="tag {{ strtolower($a['label']) }}">{{ $a['label'] }}</span></td>
                        @if ($a['totals'])
                            <td>{{ number_format($a['totals']['impressions']) }}</td><td>{{ number_format($a['totals']['clicks']) }}</td>
                            <td>{{ number_format($displaySpend($a['totals'])) }} {{ $a['totals']['currency'] }}</td><td>{{ $a['totals']['ctr'] === null ? '–' : number_format($a['totals']['ctr'] * 100, 2).'%' }}</td>
                            <td>{{ $displayCpc($a['totals']) === null ? '–' : number_format($displayCpc($a['totals'])).' '.$a['totals']['currency'] }}</td>
                        @else
                            <td colspan="5" class="muted">no data in this range</td>
                        @endif
                    </tr>
                    @foreach ($a['campaigns'] as $c)
                        <tr>
                            <td class="muted">&nbsp;&nbsp;{{ $c['campaign'] }}</td>
                            <td>{{ number_format($c['impressions']) }}</td><td>{{ number_format($c['clicks']) }}</td>
                            <td>{{ number_format($displaySpend($c)) }} {{ $c['currency'] }}</td><td>{{ $c['ctr'] === null ? '–' : number_format($c['ctr'] * 100, 2).'%' }}</td>
                            <td>{{ $displayCpc($c) === null ? '–' : number_format($displayCpc($c)).' '.$c['currency'] }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="card"><p class="empty-state">Select an industry above to see its accounts and campaigns.</p></div>
    @endif
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@sgratzl/chartjs-chart-boxplot@4.4.4/build/index.umd.min.js"></script>
<script>
    // Full-page GET navigation has no built-in loading indicator, so give explicit
    // feedback (disabled button + "Loading…" label) for the moment between submit
    // and the next page actually arriving.
    const filtersForm = document.getElementById('filters');
    const updateButton = filtersForm.querySelector('button[type="submit"]');
    filtersForm.addEventListener('submit', () => {
        updateButton.disabled = true;
        updateButton.textContent = 'Loading…';
    });
    document.getElementById('metric').addEventListener('change', () => filtersForm.requestSubmit());
    document.getElementById('industry').addEventListener('change', () => filtersForm.requestSubmit());
    const plot = @json($boxPlot);
    const metric = @json($metric);
    const selectedIndustry = @json($selectedIndustry);
    const industryNames = Object.keys(plot);

    const axisTitle = { impressions: 'Impressions', clicks: 'Clicks', spend: 'Spend (HUF)', ctr: 'CTR (%)', cpc: 'CPC (HUF)' }[metric];
    const isCurrency = metric === 'spend' || metric === 'cpc';
    function fmt(v) {
        if (metric === 'ctr') return (v * 100).toFixed(2) + '%';
        if (isCurrency) return new Intl.NumberFormat('en-US', { maximumFractionDigits: metric === 'cpc' ? 2 : 0 }).format(v) + ' HUF';
        return new Intl.NumberFormat('en-US').format(Math.round(v));
    }
    function fmtCompact(v) {
        if (metric === 'ctr') return (v * 100).toFixed(1) + '%';
        const n = new Intl.NumberFormat('en-US', { notation: 'compact' }).format(v);
        return isCurrency ? n + ' HUF' : n;
    }
    const canvas = document.getElementById('boxplot');

    if (window.Chart && Chart.registry.getController('boxplot')) {
        // Single source of truth for chart colors: the same --chart-* custom
        // properties the :root/dark-mode CSS already defines, read live instead
        // of duplicating each hex here - a palette change only has to happen
        // in the CSS now.
        const rootStyle = getComputedStyle(document.documentElement);
        const cssVar = (name) => rootStyle.getPropertyValue(name).trim();
        const accent = cssVar('--chart-accent');
        const fill = cssVar('--chart-fill');
        const gridColor = cssVar('--chart-grid');
        const tickColor = cssVar('--chart-tick');
        const medianColor = cssVar('--chart-median');
        const mutedAccent = cssVar('--chart-muted-accent');
        const mutedFill = cssVar('--chart-muted-fill');
        const mutedMedian = cssVar('--chart-muted-median');

        // Per-box colors (Chart.js's boxplot controller accepts an array here,
        // one entry per data point, same as any other Chart.js dataset option)
        // only diverge from the single accent color once an industry is
        // actually selected - with nothing selected, every box stays the
        // normal brand blue, unchanged from before this feature existed.
        const isHighlighted = (name) => !selectedIndustry || name === selectedIndustry;
        const perBoxBorder = industryNames.map((name) => isHighlighted(name) ? accent : mutedAccent);
        const perBoxFill = industryNames.map((name) => isHighlighted(name) ? fill : mutedFill);
        const perBoxMedian = industryNames.map((name) => isHighlighted(name) ? medianColor : mutedMedian);

        new Chart(canvas, {
            type: 'boxplot',
            data: {
                labels: industryNames,
                datasets: [{
                    label: metric,
                    data: Object.values(plot),
                    backgroundColor: perBoxFill,
                    borderColor: perBoxBorder,
                    borderWidth: 2,
                    medianColor: perBoxMedian,
                    // Server-computed stats (Aggregator::boxPlotStats) force whiskers to
                    // the true min/max and outliers to always be empty - a handful of
                    // blended real+synthetic campaigns is too small a sample for the
                    // library's default "beyond 1.5x IQR" rule to mean "anomaly" rather
                    // than just an ordinary point. itemRadius: 0 hides the raw
                    // per-campaign scatter the box already summarizes.
                    itemRadius: 0,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                // The rendered box element carries the computed stats, but as
                                // PIXEL y-coordinates (rendering geometry), not data values -
                                // context.raw is just the plain input array. getValueForPixel()
                                // is the scale's own inverse transform back to real values, so
                                // this matches exactly what is visually drawn, regardless of
                                // which quartile algorithm the library uses internally.
                                const el = context.element;
                                const yScale = context.chart.scales.y;
                                const toValue = (px) => yScale.getValueForPixel(px);

                                return [
                                    `Max: ${fmt(toValue(el.max))}`,
                                    `Q3: ${fmt(toValue(el.q3))}`,
                                    `Median: ${fmt(toValue(el.median))}`,
                                    `Q1: ${fmt(toValue(el.q1))}`,
                                    `Min: ${fmt(toValue(el.min))}`,
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: axisTitle, color: tickColor },
                        ticks: { color: tickColor, callback: fmtCompact },
                        grid: { color: gridColor },
                    },
                    x: {
                        ticks: { color: tickColor },
                        grid: { color: gridColor },
                    },
                },
            },
        });
    } else {
        document.getElementById('boxplot').replaceWith(document.createTextNode('The chart library could not be loaded.'));
    }
</script>
</body>
</html>
