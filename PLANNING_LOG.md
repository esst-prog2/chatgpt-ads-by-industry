# Planning Log

One line per project decision (requirement, number, name, tool). Format:

`YYYY-MM-DD — decision — decided by: user | Claude`

Entries are append-only; earlier lines are never rewritten.
2026-09-24 — Data approach is Option C (hybrid): a DataSource interface with a synthetic implementation now and a single-key real Ads API adapter later — decided by: user
2026-09-24 — MVP core relies strictly on synthetic data so multi-account and multi-industry aggregation can be tested reliably — decided by: user
2026-09-24 — No integration of multiple real API accounts and no pushing for extra real keys without the user's explicit approval — decided by: user
2026-09-24 — Conversions are included directly in the synthetic fixtures alongside impressions, clicks and spend; no separate conversions endpoint flow in the MVP — decided by: user
2026-09-24 — MVP includes exactly 1 real Ads API key/account to see real key collection, auth and data fetching in practice; this supersedes the earlier lines saying the real adapter comes later and the core is strictly synthetic — decided by: user
2026-09-24 — All other accounts in the MVP are synthetic, supplementing the single real account so industry aggregation and comparison work — decided by: user
2026-09-24 — No multiple real keys and no demanding approved partner access at this stage (refines the earlier no-extra-real-keys line) — decided by: user
2026-09-24 — The real account shows "n/a" for conversions and is excluded from conversion totals — decided by: user
2026-09-24 — The real account is mapped to the industry "Education & Careers" — decided by: user
2026-09-24 — Each account is labelled "Real" or "Synthetic" in the drill-down view — decided by: user
2026-09-24 — Synthetic dataset size: 3 industries, 2-3 accounts each, 2-4 campaigns per account, 30 days of daily data — decided by: user
2026-09-24 — Account-to-industry mapping is a local CSV or JSON file (account ID to industry), not a live service — decided by: user
2026-09-24 — CTR is computed from summed totals: clicks / impressions — decided by: user
2026-09-24 — Industry comparison chart is a box plot (replaces the earlier single bar chart proposal) — decided by: user
2026-09-24 — A metric selector on the UI switches the comparison between impressions, clicks, spend and CTR — decided by: user
2026-09-24 — Date-range picker is kept, with the default range set to the last 28 days — decided by: user
2026-09-24 — Drill-down from an industry to its accounts and campaigns is kept — decided by: user
2026-09-24 — Each box plot point is one campaign (its total over the selected date range); CTR per campaign is its own summed clicks / impressions — decided by: user
2026-09-24 — Conversions are not compared across industries (not in the metric selector or the industry comparison), since they differ too much by industry and are measured inconsistently — decided by: user
2026-09-24 — Days with no real data for the real account are shown as empty, not as zero — decided by: user
2026-09-24 — Conversions are dropped entirely from the MVP (no fixture field, no UI, no "n/a" handling for the real account); this supersedes the earlier lines about conversions in the synthetic data and "n/a" for the real account — decided by: user
2026-09-24 — MVP metrics are impressions, clicks, spend and CTR — decided by: user
2026-09-24 — Laravel is the framework for the MVP — decided by: user
2026-09-24 — The industry list for the mapping and synthetic fixtures is the official 12: Retail & eCommerce, Consumer Goods, Software & Technology, Financial Services, Media & Entertainment, Professional Services, Local Services, Education & Careers, Automotive, Health, Travel & Hospitality, Other — decided by: user
2026-09-24 — Education & Careers holds the real account plus 1-2 synthetic accounts so multi-account aggregation and the acceptance tests work inside that industry — decided by: user
2026-09-24 — The OpenSpec change is named add-industry-dashboard-mvp — decided by: Claude
2026-09-24 — The synthetic fixtures use 3 industries: Education & Careers, Retail & eCommerce and Software & Technology (the last two picked by Claude, open to change) — decided by: Claude
2026-09-24 — Accounts with no entry in the mapping file are grouped under Other; an industry name outside the official list is an error — decided by: Claude
2026-09-24 — Impressions is the preselected metric in the selector — decided by: Claude
2026-09-24 — PHP, Composer and the Laravel installer come from Laravel Herd Lite (installed by the user) — decided by: user
2026-09-24 — "Last 28 days" means the 28 days ending yesterday (today's partial data is excluded); the synthetic dataset's 30-day window also ends yesterday — decided by: Claude
2026-09-24 — Box plot is drawn with Chart.js and the chartjs-chart-boxplot plugin, loaded from the jsDelivr CDN (no front-end build step) — decided by: Claude
2026-09-24 — The real account has the fixed id "real-account" in the mapping file, and the dashboard tests use PHPUnit — decided by: Claude
2026-09-24 — Spend is displayed with a "$" prefix in the UI, since the Ads API's currency isn't specified anywhere in the project; easy to change once the real account's currency is known — decided by: Claude
2026-09-24 — Spend is displayed in HUF (not USD), matching the real account's actual currency (confirmed via GET /ad_account, currency_code "HUF"); this supersedes the earlier "$" prefix decision — decided by: user
2026-09-24 — Every real Ads API insights request logs its query, HTTP status and raw response body (truncated to 4000 chars) to Laravel's default log channel, for debugging data issues — decided by: Claude
2026-09-24 — The real API key now belongs to "RUANDER Oktatási Kft." (an education/training provider), which genuinely fits Education & Careers; this resolves the earlier flagged mismatch with the previous (invalidated) key's hotel account — decided by: Claude
2026-09-24 — Task 2.4 (real-account source verified against the live API) is complete: confirmed real campaign data (15 days of delivery, one active campaign) flows through the app end to end — decided by: Claude
2026-09-24 — CPC (spend / clicks) is added as a fifth metric in the selector, formatted in HUF; campaigns with zero clicks have no CPC and are left out of the box plot, matching the existing CTR/zero-impressions pattern — decided by: user
2026-09-24 — The real account's name and its campaign names are never shown as received from the API; the dashboard always displays "Real Account #1" and "Campaign #N" instead, in tooltips and the drill-down alike — decided by: user
2026-09-24 — Campaigns are numbered in the order they are first seen in the API response for a given request; this order is not guaranteed stable across separate requests — decided by: Claude
2026-09-24 — Box plot shows only the standard Q1-Q3 box, median line and whiskers (to the most extreme value within 1.5x IQR); raw per-campaign points are hidden, only true outliers beyond the whiskers are drawn — decided by: user
2026-09-24 — Synthetic CPC targets realistic Hungarian PPC ranges per industry: Retail & eCommerce ~120-350 HUF, Software & Technology ~400-1200 HUF, Education & Careers ~250-600 HUF (Brightpath Academy tuned close to the real account's ~300 HUF); this supersedes the earlier unscaled CPC constants — decided by: user
2026-09-24 — CPC is added as a column in both the Industry totals and the Accounts & campaigns (drill-down) tables, formatted in HUF — decided by: user
2026-09-24 — Synthetic CPC is rounded to a whole HUF per campaign before spend is derived (spend = clicks * integer cpc), so spend/clicks equals the displayed CPC with zero rounding discrepancy at both the per-record and aggregate level; this supersedes the earlier float-CPC generator. The real account's CPC is left as genuine API-derived data (not forced to a whole number) — decided by: user
2026-09-24 — Outlier tooltips on the box plot show three separate lines: Account, Campaign, Value (with its unit), using the anonymized account/campaign names for the real source — decided by: user
2026-09-24 — Table CPC (Industry totals and Accounts & campaigns) is derived in the view from the same whole-HUF-rounded Spend value that is displayed, not from a separately-rounded field, so displayed Spend / displayed Clicks always reconciles exactly to displayed CPC with a single rounding step — decided by: user
2026-09-24 — Fixed a real bug found via actual browser interaction (Puppeteer + the existing Chrome, not static analysis): chartjs-chart-boxplot's tooltip context.element stores min/q1/median/q3/max/outliers as pixel y-coordinates, not data values, and context.raw is the plain input array, not a per-point value; the previous tooltip code used both incorrectly and rendered "NaN HUF" on outlier hover. Fixed by converting element pixel values back to data values via the y-scale's own getValueForPixel(), and by tracking real cursor position to distinguish an outlier hover from a box-body hover — decided by: Claude
2026-09-24 — README credits OpenSpec (https://openspec.dev) as the planning/spec tool used, with a pointer to the change folder and this log — decided by: user
