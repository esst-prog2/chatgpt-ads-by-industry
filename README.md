# ChatGPT Ads Industry Dashboard

ChatGPT Ads has only recently become available for self-service advertising in Hungary. OpenAI made Ads Manager self-service access available across 31 European markets, including Hungary, on August 31, 2026. Since the advertising platform is still in an early beta stage, it could be interesting to observe how advertising performance develops on the platform and whether performance differs between industries.

## 1. The demo

I sign in with my Google account, scoped to the company domain. The dashboard defaults to the last 28 days and compares industries in a box plot, one point per campaign, switchable between impressions, clicks, spend, CTR and CPC. I can drill into an industry to see its accounts and campaigns, each labelled Real or Synthetic, with a footer showing the live HUF exchange rate behind the numbers. Real data comes from six live ChatGPT Ads accounts across four industries, configured in `.env` or added live through a tabbed admin panel that also manages users and roles. Repeat dashboard views load from cache in about 5ms instead of re-fetching the live API every time, and 177 automated tests cover the sign-in, aggregation and account-management logic end to end.

## 2. The shape

|  |  |
|------------------|------------------------------------------------------|
| in | ChatGPT Ads performance data from multiple advertising accounts + an external mapping of each account to an industry |
| out | web dashboard comparing advertising performance across industries |
| in between | retrieve and aggregate performance data from the Ads API, associate each account with its industry, calculate industry-level metrics, and display the results |

ChatGPT Ads API: <https://developers.openai.com/ads>

## 3. The size

### First useful version

-   Built with Laravel.
-   Read performance data from exactly one real ChatGPT Ads account (one API key), plus a synthetic dataset for the other accounts (3 industries, 2-3 accounts each, 2-4 campaigns per account, 30 days of daily data). Education & Careers holds the real account and 1-2 synthetic accounts.
-   Maintain an account-to-industry mapping in a local CSV or JSON file, using the official industry list.
-   Aggregate impressions, clicks, spend and CTR (clicks / impressions from summed totals) by industry.
-   Allow the user to select a date range, defaulting to the last 28 days.
-   Show a box plot comparing industries (one point per campaign) with a selector to switch the metric.
-   Drill down from an industry to its accounts and campaigns, with each account labelled Real or Synthetic.

### Not this term

-   Conversions.
-   More than one real API key, or partner access.
-   Creating, editing or launching advertising campaigns.
-   Automatic campaign optimisation.
-   Predictive analytics or machine-learning-based predictions.

## 4. How we would know it works

-   Given the synthetic dataset and an account-to-industry mapping, the dashboard displays the correct aggregated metrics for each industry.
-   Given two accounts belonging to the same industry (including Education & Careers, which has the real account and synthetic ones), their performance data is combined correctly in the industry-level results.

## 5. What could stop this

-   The main technical risk is that I have only basic PHP knowledge and no previous experience with Laravel. Learning and using the framework may make the project significantly harder to implement within the available time.

-   Another risk is access to the ChatGPT Ads API. Each API key covers a single ad account, so the MVP uses exactly one real key and synthetic data for all other accounts. If the real account cannot be shown in class or has little data, the dashboard still works with the synthetic accounts.

-   The account-to-industry mapping is a small local file that needs some manual work.

## 6. Running the MVP

1.  Install PHP and Composer (for example with Laravel Herd), then run `composer install` and `cp .env.example .env && php artisan key:generate`.
2.  Optional: put a real ChatGPT Ads API key in `.env` as `ADS_API_KEY` (first real account) and/or `ADS_API_KEY_2` (second real account, both mapped to Education & Careers). Each key maps to its own account automatically (see `config/ads.php`, `App\Data\RealAdsSource::fromConfig()`); without any key, the dashboard runs on the synthetic accounts alone. A key that is rejected by the API still shows a notice; a key that is simply absent does not, since an account not yet onboarded isn't a failure.
3.  `php artisan serve`, then open <http://127.0.0.1:8000>.
4.  `php artisan test` runs the tests.


### LAN access via a tunnel 

Google Cloud Console will not accept a raw LAN IP (like
`192.168.1.50`) as an Authorized redirect URI at all - it requires the
host to be `localhost`/`127.0.0.1`, or a domain ending in a valid public
top-level domain (`.com`, `.org`, etc.). A **tunnel** gives you a real
public hostname instead, which Google does accept, and also works from
outside your own network (e.g. a phone on cellular data), not just
same-Wi-Fi devices.

This uses [localtunnel](https://github.com/localtunnel/localtunnel), a
free tunnel with no signup, via Node's `npx` (already needed for this
project's frontend build, so nothing extra to install).

1.  Start the server normally (plain `php artisan serve` is enough -
    the tunnel connects to your machine's own `localhost`, so you don't
    need `serve:lan` / `--host=0.0.0.0` for this).

2.  In a **separate terminal**, run:

    ```
    npx localtunnel --port 8000
    ```

    It prints a URL like `your url is: https://sweet-sloths-visit.loca.lt`
    - note it down; a free run gets a **random** subdomain each time,
    so expect this to change on every restart.

3.  Add one line to your `.env`, then restart the `php artisan serve`
    from step 1:

    ```
    FORCE_HTTPS_URLS=true
    ```

    **Why this is needed:** localtunnel's client is a plain TCP relay -
    it forwards the raw bytes of each request (including the real
    `Host` header, so the app already knows it's being reached as
    `sweet-sloths-visit.loca.lt`), but it does *not* add an
    `X-Forwarded-Proto` header, so the app has no other way to know the
    public visitor connected over `https://` rather than `http://`.
    Without this flag, the Google redirect URI the app generates would
    say `http://`, which Google rejects as not matching the `https://`
    URI you register in step 4 below. Leave this flag off (the default)
    for plain localhost or LAN-IP use, where the dev server genuinely
    has no TLS and generated URLs should stay `http://`. See
    `config/app.php` and `PLANNING_LOG.md` (2026-11-02) for the full
    explanation.

4.  **Google Cloud Console, one-time step:** go to
    [Google Cloud Console](https://console.cloud.google.com/) →
    **APIs & Services → Credentials** → your OAuth 2.0 Client ID →
    **Authorized redirect URIs** → **Add URI** → the URL from step 2
    plus the callback path, e.g.
    `https://sweet-sloths-visit.loca.lt/auth/google/callback` → **Save**.
    This is additive, your existing `localhost`/LAN entries stay.

5.  From any device, open the URL from step 2 (e.g.
    `https://sweet-sloths-visit.loca.lt`). The dashboard, admin panel,
    and Google Sign-In all work over the tunnel.

6.  **When you're done:** stop the tunnel (`Ctrl+C` in its terminal) and
    remove (or set to `false`) `FORCE_HTTPS_URLS` in `.env`, then
    restart `php artisan serve` to go back to normal localhost/LAN use.
    A running tunnel makes your dev server reachable by anyone who has
    or guesses the URL, so only run it while you're actually using it.

    Since the free subdomain changes on every restart, you'll need to
    repeat step 4 with the new URL each time you start a fresh tunnel
    (old, unused entries can be deleted from Console whenever you like,
    but don't have to be - they just stop being used).

## 7. Data Architecture & MVP Validation (HW4 Spike)

**Architecture.** The MVP's data layer (`App\Data\PerformanceSource`) has two implementations: one real ChatGPT Ads account (`RealAdsSource`, exactly one API key, mapped to Education & Careers) and a deterministic synthetic dataset (`SyntheticSource`) covering the other accounts and industries. This is an intentional placeholder, not the end state: it lets the dashboard's cross-industry features - the box plot, the metric selector, the drill-down - be built and demonstrated now, against realistic-shaped data, without waiting on multiple real client accounts to be onboarded.

**Spike question.** On branch `hw4-spike`, a spike checks an assumption behind that placeholder: is the one real campaign's own week-over-week variance big enough to explain away the differences the dashboard shows *between* industries? If a single campaign's normal noise is as large as the gaps between industry medians, those between-industry comparisons would not be meaningful yet.

**Method:** `scripts/spike_ctr_cpc.php` takes the real campaign's earliest 14 days of delivery data (fixed, for reproducibility - see PLANNING_LOG.md), splits them into two disjoint 7-day windows, and computes weighted CTR (`sum(clicks)/sum(impressions)`) and CPC (`sum(spend)/sum(clicks)`) for each. It compares the week-over-week swing to the between-industry median gap (`(max - min) / min * 100`) computed from the same per-campaign values the box plot uses. The window-splitting and metric logic live in `app/Services/SpikeAnalyzer.php`, unit tested in `tests/Feature/SpikeCalculationTest.php` (36/36 passing). Run it with `php scripts/spike_ctr_cpc.php`, or `php "scripts/manual test/test_spike_manual.php"` for the same check with Hungarian-language CLI output.

**Measured result** (exact script output, reproduced 2026-10-03):

```
Week 1 (2026-09-08 to 2026-09-15): CTR = 0.79%, CPC = 390 HUF
Week 2 (2026-09-16 to 2026-09-22): CTR = 1.22%, CPC = 235 HUF
CTR week-over-week swing: +55.67%
CPC week-over-week swing: -39.78%

CTR medians by industry: Education & Careers=2.43%  Retail & eCommerce=1.75%  Software & Technology=4.26%
CTR gap (max vs min median): +143.01%
CPC medians by industry: Education & Careers=338 HUF  Retail & eCommerce=201 HUF  Software & Technology=893 HUF
CPC gap (max vs min median): +344.28%
```

The real campaign's week-over-week swing (CTR +55.67%, CPC -39.78%) is **smaller** than the between-industry median gap for both metrics (CTR 143.01%, CPC 344.28%). By the spike's own success criterion, that means this measurement does **not** show single-campaign noise explaining away the between-industry differences.

**Limitation.** This result is reassuring, not conclusive. It comes from exactly one real account, in one industry, over one specific pair of weeks - not enough to rule out that a different two-week window, a different campaign, or a different industry's real account would swing differently. Until more real accounts are onboarded across more industries, the dashboard's cross-industry comparisons should be read as a demonstration of the architecture and UI, not yet as a validated claim about real-world cross-industry performance differences.

---

This project follows the course guidelines at [esst-prog2.github.io](https://esst-prog2.github.io).

Planned and specified with [OpenSpec](https://openspec.dev); the change proposal, specs, design and tasks live under `openspec/changes/add-industry-dashboard-mvp/`. Every project decision is logged in [PLANNING_LOG.md](PLANNING_LOG.md).

