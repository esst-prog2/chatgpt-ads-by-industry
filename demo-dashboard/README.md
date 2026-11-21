# Static demo dashboard

A login-free, interactive copy of the dashboard for GitHub Pages:
`public/demo/index.html` + `public/demo/data.js`, linked from the promo page's
main button and published by `.github/workflows/promo-pages.yml`.

- **Data is illustrative.** `demo-data.php` defines invented accounts
  ("Demo Account A…" stand in for the anonymised Real accounts, "Demo Synthetic N"
  for synthetic ones) and invented numbers. The app's own `SyntheticSource` is not
  used, because it is calibrated from real accounts' stats. The same dataset feeds
  the promo video's screenshots (`promo-video/capture/render-dashboard.php`).
- **Numbers come from the real app logic.** `export-demo-data.php` runs the app's
  `DashboardService` / `Aggregator` over the demo accounts for a fixed demo period,
  **October 2026**, in eight presets (full month, each half, each week, last 7 days),
  for every metric and industry. The page only renders that data; the real dashboard
  is only read from, never changed.
- **No fetch.** The data ships as `data.js` (`window.DEMO_DATA = {...}`), loaded with a
  plain `<script>` tag, so the demo works on GitHub Pages, through the Laravel app and
  opened straight from disk.
- **Privacy guard.** The export refuses to write if any account or campaign is not a
  demo one or if anything key- or email-like appears, and
  `tests/Feature/DemoDashboardTest.php` checks the committed file the same way.

## Refreshing the snapshot

```bash
php demo-dashboard/export-demo-data.php
php artisan test --filter DemoDashboardTest
```

Commit `public/demo/data.js` and push; the Pages workflow republishes it. The demo
period is fixed (`DEMO_PERIODS` in the export script), so re-running only matters when
the demo accounts or the app's calculations change. The banner shows the demo period
and the export date.

Options for keeping it fresh, lightest first:

1. **Fixed period + manual export** (current): October 2026, one command when
   something changes, nothing to maintain.
2. **Scheduled GitHub Action:** a weekly workflow that installs PHP + Composer,
   runs the export and commits `data.js` when it changed. Keeps the dates
   current without anyone remembering; costs a CI setup and bot commits.
3. **Client-side date shifting:** store the data relative to "day 0" and let the
   page label it relative to today. Always looks current, but the page then
   claims dates the export never produced, so it is not recommended.

Because the data is invented, none of these is "real time" by design: the demo
shows how the dashboard works, never live figures. The live dashboard stays
behind Google sign-in.
