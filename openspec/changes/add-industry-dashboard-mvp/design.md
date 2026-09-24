## Context

See proposal.md for motivation. No code exists yet. The author has basic PHP knowledge and no Laravel experience, which is the main schedule risk. Each ChatGPT Ads API key is scoped to one ad account, so only one real account is available. The Insights endpoint `/ad_account/insights` returns impressions, clicks, spend and CTR with date ranges and breakdown to campaign level.

## Goals / Non-Goals

**Goals:**
- Make the industry aggregation testable without any API access.
- Let the one real account appear in the same views as synthetic accounts.

**Non-Goals:**
- Conversions, a second real account, a database of historical data, authentication for dashboard users.

## Decisions

- **Framework: Laravel.** Chosen by the user. Alternative: plain PHP, which has less to learn but no structure for routes, views or tests. The learning curve stays the main risk.
- **One data-source interface, two implementations.** Both return the same daily records (account, campaign, date, impressions, clicks, spend). The synthetic source is the default; the real source covers one account. Alternative: call the API directly from the aggregation code, which would tie every test to API access.
- **Synthetic data from a fixed file.** A checked-in fixtures file (or a seeded generator) gives identical data every run, so tests can assert exact totals.
- **Mapping as a local CSV or JSON file.** Account ID to industry, validated against the official 12-industry list. Alternative: a live classification service, which is far more work for no MVP benefit.
- **Aggregate in the application, no database.** With 30 days of data for about 8 accounts, summing in memory is enough. CTR is always computed from summed clicks and impressions.
- **Box plot unit is the campaign.** One point per campaign gives about 4 to 12 points per industry. Alternatives: per day (28 points, measures stability instead of spread) and per account (2 to 3 points, too few).
- **Charting in the browser.** Use a charting library that supports box plots. The specific library is left to the tasks.
- **API key in local configuration only.** It is never committed. If it is missing or fails, the page still works with synthetic data and shows a notice.

## Risks / Trade-offs

- [Laravel is new to the author] → Keep the architecture small: one page, one interface, one aggregation service.
- [The real account may have little or no data] → Show empty days as empty, keep the synthetic Education & Careers accounts so the industry still has data.
- [Real and synthetic numbers mix in one industry] → Label every account Real or Synthetic in the drill-down.
- [Small boxes for industries with few campaigns] → Accepted trade-off; the synthetic size (2 to 4 campaigns per account) keeps every box readable.
- [The API index page did not confirm date filtering details] → Verify the request format against the API reference before writing the real adapter.
