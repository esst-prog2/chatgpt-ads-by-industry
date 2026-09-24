## 1. Setup

- [x] 1.1 Create the Laravel application in the repository and verify the default page loads locally
- [x] 1.2 Add a local configuration entry for the real API key (not committed) and verify the key is absent from git

## 2. Data sources

- [x] 2.1 Define the data-source interface returning daily records (account, campaign, date, impressions, clicks, spend) and verify a test double satisfies it
- [x] 2.2 Create the deterministic synthetic dataset (3 industries, 2 to 3 accounts each, 2 to 4 campaigns per account, 30 days) and verify a test asserts its shape and that two loads are identical
- [x] 2.3 Create the account-to-industry mapping file with the official 12-industry list, mapping the real account to Education & Careers, and verify a test rejects an unknown industry name and groups an unmapped account under Other
- [x] 2.4 Implement the real-account source against the Insights API for the one key and verify it returns records in the same shape (run once with the real key)
- [x] 2.5 Make the real source fail softly and verify a test shows the dashboard still loads the synthetic accounts with a notice when the key is missing or rejected
- [x] 2.6 Treat days with no real data as empty and verify a test that they are not counted as zero

## 3. Aggregation

- [x] 3.1 Implement per-campaign totals for a date range and verify tests cover impressions, clicks, spend and CTR (summed clicks / summed impressions)
- [x] 3.2 Implement per-industry totals and verify a test with two accounts in one industry, including Education & Careers, sums correctly and does not average CTRs
- [x] 3.3 Handle zero-impression campaigns for CTR and verify a test that they are left out without an error

## 4. Dashboard

- [x] 4.1 Add the date-range picker defaulting to the last 28 days and verify default, changed and invalid ranges in tests
- [x] 4.2 Add the metric selector (impressions, clicks, spend, CTR, impressions preselected, no conversions) and verify each option changes the plotted values
- [x] 4.3 Add the industry box plot with one point per campaign and verify in the browser that boxes appear for the 3 industries and an empty state for an industry with no data
- [x] 4.4 Add industry totals and verify they match the sums of the listed accounts
- [x] 4.5 Add the drill-down from an industry to its accounts and campaigns with a Real or Synthetic label per account, and verify Education & Careers shows one Real and its synthetic accounts

## 5. Wrap-up

- [x] 5.1 Update the README to match the final scope and verify the two acceptance checks in section 4 pass
- [x] 5.2 Run the whole dashboard end to end in the browser with the default range and with a custom range, and verify no errors appear
