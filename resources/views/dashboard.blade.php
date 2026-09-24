<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ChatGPT Ads Industry Dashboard</title>
    <style>
        :root { --bg:#f7f7f8; --fg:#1d1d1f; --muted:#6b6b70; --card:#fff; --line:#e2e2e6; --accent:#10a37f; --warn:#b45309; --err:#b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg:#161618; --fg:#f2f2f3; --muted:#a0a0a8; --card:#212124; --line:#34343a; --accent:#34d3a8; --warn:#fbbf24; --err:#f87171; } }
        * { box-sizing: border-box; }
        body { margin:0; padding:24px 16px; background:var(--bg); color:var(--fg); font:15px/1.5 system-ui, sans-serif; }
        main { max-width:1000px; margin:0 auto; }
        h1 { font-size:22px; margin:0 0 16px; } h2 { font-size:17px; margin:0 0 12px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
        form { display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
        label { display:flex; flex-direction:column; font-size:13px; color:var(--muted); gap:4px; }
        input, select, button { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--fg); }
        button { background:var(--accent); color:#fff; border-color:var(--accent); cursor:pointer; }
        .notice { border-left:4px solid var(--warn); } .error { border-left:4px solid var(--err); }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th, td { text-align:right; padding:6px 8px; border-bottom:1px solid var(--line); } th:first-child, td:first-child { text-align:left; }
        .tag { font-size:12px; padding:1px 8px; border-radius:99px; border:1px solid var(--line); color:var(--muted); }
        .tag.real { color:var(--accent); border-color:var(--accent); }
        .chart-wrap { position:relative; height:340px; }
        .table-scroll { overflow-x:auto; }
        a { color:var(--accent); }
        .muted { color:var(--muted); }
    </style>
</head>
<body>
<main>
    <h1>ChatGPT Ads Industry Dashboard</h1>

    @php($metricLabels = ['impressions' => 'Impressions', 'clicks' => 'Clicks', 'spend' => 'Spend', 'ctr' => 'CTR', 'cpc' => 'CPC'])
    {{--
        Displayed Spend is rounded to whole HUF for the table. CPC is derived from
        that SAME rounded Spend (not the unrounded total), so dividing the two
        numbers actually printed on screen always reproduces the printed CPC exactly,
        with only one rounding step - no double-rounding drift is possible.
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
            @if ($selectedIndustry)<input type="hidden" name="industry" value="{{ $selectedIndustry }}">@endif
            <button type="submit">Update</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ $metricLabels[$metric] }} per campaign, by industry</h2>
        <div class="chart-wrap"><canvas id="boxplot" aria-label="Box plot of {{ $metric }} per campaign by industry"></canvas></div>
        @php($empty = array_keys(array_filter($boxPlot, fn ($v) => count($v) === 0)))
        @if ($empty)<p class="muted">No campaign data in this range for: {{ implode(', ', $empty) }}.</p>@endif
    </div>

    <div class="card table-scroll">
        <h2>Industry totals</h2>
        <table>
            <thead><tr><th>Industry</th><th>Impressions</th><th>Clicks</th><th>Spend</th><th>CTR</th><th>CPC</th></tr></thead>
            <tbody>
            @foreach ($industries as $i)
                @php($t = $industryTotals[$i] ?? null)
                <tr>
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
                        <td><strong>{{ $a['name'] }}</strong> <span class="tag {{ $a['label'] === 'Real' ? 'real' : '' }}">{{ $a['label'] }}</span></td>
                        @if ($a['totals'])
                            <td>{{ number_format($a['totals']['impressions']) }}</td><td>{{ number_format($a['totals']['clicks']) }}</td>
                            <td>{{ number_format($displaySpend($a['totals'])) }} HUF</td><td>{{ $a['totals']['ctr'] === null ? '–' : number_format($a['totals']['ctr'] * 100, 2).'%' }}</td>
                            <td>{{ $displayCpc($a['totals']) === null ? '–' : number_format($displayCpc($a['totals'])).' HUF' }}</td>
                        @else
                            <td colspan="5" class="muted">no data in this range</td>
                        @endif
                    </tr>
                    @foreach ($a['campaigns'] as $c)
                        <tr>
                            <td class="muted">&nbsp;&nbsp;{{ $c['campaign'] }}</td>
                            <td>{{ number_format($c['impressions']) }}</td><td>{{ number_format($c['clicks']) }}</td>
                            <td>{{ number_format($displaySpend($c)) }} HUF</td><td>{{ $c['ctr'] === null ? '–' : number_format($c['ctr'] * 100, 2).'%' }}</td>
                            <td>{{ $displayCpc($c) === null ? '–' : number_format($displayCpc($c)).' HUF' }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="muted">Select an industry above to see its accounts and campaigns.</p>
    @endif
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@sgratzl/chartjs-chart-boxplot@4.4.4/build/index.umd.min.js"></script>
<script>
    document.getElementById('metric').addEventListener('change', () => document.getElementById('filters').submit());
    const plot = @json($boxPlot);
    const pointLabels = @json($boxPlotLabels);
    const metric = @json($metric);
    const industryNames = Object.keys(plot);
    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;

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
    // A hovered value (an outlier) doesn't say which account/campaign it came from;
    // match it back to the closest value plotted for that industry to recover its
    // (already-anonymized, for the real source) account and campaign name.
    function campaignFor(industryIndex, value) {
        const values = plot[industryNames[industryIndex]] || [];
        const labels = pointLabels[industryNames[industryIndex]] || [];
        let best = -1, bestDiff = Infinity;
        values.forEach((v, i) => { const d = Math.abs(v - value); if (d < bestDiff) { bestDiff = d; best = i; } });
        return best >= 0 ? labels[best] : null;
    }

    // Chart.js only reports "which box" was hovered (context.dataIndex), not which
    // exact sub-element (the box body vs. one specific outlier dot) - context.raw for
    // this library's boxplot controller is the plain input array, not a per-point
    // value or stats object; the real stats live on context.element instead. To tell
    // an outlier hover apart from a box-body hover, track the cursor's pixel position
    // ourselves and compare it to each outlier's own pixel position.
    let hoverY = null;
    const canvas = document.getElementById('boxplot');
    canvas.addEventListener('mousemove', (evt) => {
        hoverY = evt.clientY - canvas.getBoundingClientRect().top;
    });
    canvas.addEventListener('mouseleave', () => { hoverY = null; });

    if (window.Chart && Chart.registry.getController('boxplot')) {
        const accent = dark ? '#5eead4' : '#0d8f6e';
        const fill = dark ? 'rgba(94,234,212,0.45)' : 'rgba(16,163,127,0.35)';
        const gridColor = dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)';
        const tickColor = dark ? '#e5e7eb' : '#3f3f46';

        new Chart(canvas, {
            type: 'boxplot',
            data: {
                labels: industryNames,
                datasets: [{
                    label: metric,
                    data: Object.values(plot),
                    backgroundColor: fill,
                    borderColor: accent,
                    borderWidth: 2,
                    medianColor: dark ? '#ffffff' : '#0b3b2e',
                    // Only true outliers (beyond 1.5x IQR from Q1/Q3) are drawn as points;
                    // itemRadius: 0 hides the raw per-campaign scatter the box already summarizes.
                    itemRadius: 0,
                    outlierBackgroundColor: accent,
                    outlierBorderColor: dark ? '#ffffff' : '#0b3b2e',
                    outlierRadius: 5,
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
                                const outlierPixels = el?.outliers || [];

                                if (outlierPixels.length && hoverY !== null) {
                                    let nearestPx = null, nearestDist = Infinity;
                                    outlierPixels.forEach((px) => {
                                        const d = Math.abs(px - hoverY);
                                        if (d < nearestDist) { nearestDist = d; nearestPx = px; }
                                    });
                                    // Only treat it as "hovering that outlier" if the cursor is
                                    // actually near its dot, not just anywhere in the box column.
                                    if (nearestPx !== null && nearestDist <= 10) {
                                        const value = toValue(nearestPx);
                                        const info = campaignFor(context.dataIndex, value);
                                        return info
                                            ? [`Account: ${info.account}`, `Campaign: ${info.campaign}`, `Value: ${fmt(value)}`]
                                            : `Value: ${fmt(value)}`;
                                    }
                                }

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
