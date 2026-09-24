## Purpose

Defines where the dashboard's advertising performance data comes from: a synthetic dataset, exactly one real ChatGPT Ads account, and a local mapping of each account to an industry.

## ADDED Requirements

### Requirement: Uniform performance records
The system SHALL expose performance data from every source as daily records of account, campaign, date, impressions, clicks and spend. The system SHALL NOT carry conversions.

#### Scenario: Records from any source look the same
- **WHEN** the dashboard reads data from the synthetic source or the real source
- **THEN** each record has the same fields (account, campaign, date, impressions, clicks, spend)

### Requirement: Synthetic dataset
The system SHALL provide a deterministic synthetic dataset covering 3 industries (Education & Careers, Retail & eCommerce, Software & Technology), 2 to 3 accounts per industry, 2 to 4 campaigns per account, and 30 days of daily records. The dataset SHALL be identical on every run. Education & Careers SHALL contain the real account plus 1 or 2 synthetic accounts.

#### Scenario: Multiple accounts in one industry
- **WHEN** the synthetic dataset is loaded
- **THEN** every one of the 3 industries has at least 2 accounts, including Education & Careers alongside the real account

#### Scenario: Repeatable data
- **WHEN** the dataset is loaded twice
- **THEN** both loads return identical records

### Requirement: Single real account
The system SHALL read performance data for exactly one real ChatGPT Ads account, using the one API key scoped to that account. The key SHALL be supplied through local configuration and SHALL NOT be stored in the repository. The system SHALL NOT read any other real account.

#### Scenario: Real data available
- **WHEN** a valid key is configured and the API is reachable
- **THEN** the real account's daily campaign-level impressions, clicks and spend for the requested dates appear on the dashboard

#### Scenario: Real data unavailable
- **WHEN** no key is configured, the key is rejected, or the API cannot be reached
- **THEN** the dashboard still shows the synthetic accounts and tells the user that the real account could not be loaded

#### Scenario: Days without real data
- **WHEN** the real account has no data for a day in the selected range
- **THEN** that day is treated as having no data, not as zero

### Requirement: Account-to-industry mapping
The system SHALL assign each account to an industry from a local CSV or JSON file of account ID and industry. Industry names SHALL come from the official list: Retail & eCommerce, Consumer Goods, Software & Technology, Financial Services, Media & Entertainment, Professional Services, Local Services, Education & Careers, Automotive, Health, Travel & Hospitality, Other. The real account SHALL be mapped to Education & Careers.

#### Scenario: Valid mapping
- **WHEN** the mapping file lists an account with an industry from the official list
- **THEN** that account's records are grouped under that industry

#### Scenario: Unknown industry name
- **WHEN** the mapping file contains an industry name that is not in the official list
- **THEN** the system reports an error naming the invalid value and does not silently regroup it

#### Scenario: Unmapped account
- **WHEN** an account has no entry in the mapping file
- **THEN** the account is grouped under Other

### Requirement: Source label
The system SHALL label every account as either Real or Synthetic.

#### Scenario: Label available for each account
- **WHEN** the dashboard lists an account
- **THEN** the account is marked Real if it comes from the real API account and Synthetic otherwise
