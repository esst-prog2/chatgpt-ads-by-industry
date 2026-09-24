## Purpose

Defines the dashboard views that let a user compare advertising performance across industries and inspect the accounts and campaigns behind each industry.

## ADDED Requirements

### Requirement: Date range selection
The system SHALL let the user pick a start and end date, and SHALL default to the last 28 days. All figures on the page SHALL respect the selected range.

#### Scenario: Default range
- **WHEN** the user opens the dashboard
- **THEN** the date range is the last 28 days and the figures cover only those days

#### Scenario: Changed range
- **WHEN** the user selects a different start and end date
- **THEN** the box plot and the drill-down update to cover only that range

#### Scenario: Invalid range
- **WHEN** the start date is after the end date
- **THEN** the system shows an error and keeps the previous results

### Requirement: Metric selector
The system SHALL let the user switch the industry comparison between impressions, clicks, spend and CTR. Impressions SHALL be preselected. Conversions SHALL NOT be offered.

#### Scenario: Switching the metric
- **WHEN** the user selects spend
- **THEN** the box plot shows spend per campaign for each industry

### Requirement: Industry box plot
The system SHALL show one box plot per industry for the selected metric and range. Each point SHALL be one campaign's total over the selected range. A campaign's CTR SHALL be its summed clicks divided by its summed impressions. Campaigns with no data in the range SHALL be left out.

#### Scenario: Campaign totals
- **WHEN** a campaign has records on several days in the range
- **THEN** it contributes a single point whose value is the sum of its impressions, clicks or spend, or its summed clicks divided by summed impressions for CTR

#### Scenario: Industry without data
- **WHEN** an industry has no campaign data in the selected range
- **THEN** it shows an empty state instead of a box

#### Scenario: Campaign with zero impressions
- **WHEN** a campaign has zero impressions in the range and the metric is CTR
- **THEN** the campaign is left out and the page does not fail

### Requirement: Industry totals
The system SHALL show total impressions, clicks, spend and CTR per industry. Industry CTR SHALL be the industry's summed clicks divided by its summed impressions across all its accounts, and SHALL NOT be an average of per-account CTRs.

#### Scenario: Two accounts in one industry
- **WHEN** two accounts belong to the same industry
- **THEN** the industry's impressions, clicks and spend equal the sums over both accounts and its CTR equals total clicks divided by total impressions

### Requirement: Drill-down to accounts and campaigns
The system SHALL let the user select an industry to see its accounts and their campaigns, each with impressions, clicks, spend and CTR for the selected range. Each account SHALL be labelled Real or Synthetic.

#### Scenario: Opening an industry
- **WHEN** the user selects Education & Careers
- **THEN** the page lists its accounts, one marked Real and the others Synthetic, with their campaigns and metrics

#### Scenario: Consistent figures
- **WHEN** the user compares the drill-down with the industry totals
- **THEN** the sum of the listed accounts equals the industry total for every additive metric
