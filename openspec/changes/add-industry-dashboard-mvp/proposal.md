## Why

ChatGPT Ads became self-service in Hungary on 2026-08-31 and is still in beta. There is no simple way to see whether advertising performance differs between industries. This change delivers a first useful dashboard that compares industries, built so it can be demonstrated reliably in class.

## What Changes

- Add a Laravel web dashboard that compares industries on impressions, clicks, spend and CTR.
- Add a data-source interface with two implementations: a synthetic dataset (the default) and an adapter for exactly one real ChatGPT Ads API key and account.
- Add a local CSV or JSON mapping from account ID to industry, using the official 12-industry list.
- Show a box plot per industry where each point is one campaign's total over the selected date range, with a metric selector (impressions, clicks, spend, CTR).
- Add a date-range picker that defaults to the last 28 days.
- Add a drill-down from an industry to its accounts and campaigns, with each account labelled "Real" or "Synthetic".
- Non-goals: conversions (dropped from the MVP), more than one real API key, partner access, creating or editing campaigns, automatic optimisation, predictive analytics.

## Capabilities

### New Capabilities
- `data-sources`: the data-source interface, the synthetic dataset, the single real-account adapter and the account-to-industry mapping.
- `industry-comparison`: the dashboard views: metric selector, box plot, date range and drill-down.

### Modified Capabilities

## Impact

- New Laravel application in this repository (no code exists yet).
- Depends on the ChatGPT Ads Insights API (`/ad_account/insights`) for the one real account; each Ads API key is scoped to a single ad account.
- Needs a local synthetic fixtures file and a local mapping file.
- Every decision is logged in PLANNING_LOG.md.
