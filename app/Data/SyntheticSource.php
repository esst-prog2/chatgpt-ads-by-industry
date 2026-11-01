<?php

namespace App\Data;

final class SyntheticSource extends AbstractSyntheticSource
{
    // The 4 industries this hand-curated dataset covers - used by
    // AppServiceProvider to decide which OTHER industries need
    // GenericSyntheticSource's immediate, uncalibrated fallback instead
    // (PLANNING_LOG.md 2026-11-01). Kept here, not re-derived from
    // self::ACCOUNTS + a mapping lookup, since it's simpler to read as a
    // plain list and this dataset only ever changes by deliberate edit.
    // Software & Technology was removed 2026-11-01: account_industry.csv's
    // mapping for its one synthetic account (syn-software-1) disappeared
    // externally, and rather than silently restoring it, the user chose to
    // treat Software & Technology as genuinely uncovered going forward - a
    // deliberate test case for GenericSyntheticSource once a real account
    // exists there again, not an oversight.
    public const COVERED_INDUSTRIES = [
        'Education & Careers', 'Professional Services', 'Health', 'Travel & Hospitality',
    ];

    // Synthetic data exists ONLY for industries that have at least one real
    // account (see account_industry.csv, PLANNING_LOG.md 2026-10-16) - it
    // supplements real industries for richer comparison, it does not stand in
    // for industries with no real presence at all. Each account's base
    // impressions/CTR/CPC (columns 2-4) are modeled on that same industry's
    // real accounts' own observed 28-day stats (pulled live 2026-10-16), not
    // arbitrary numbers:
    //   Education & Careers reals: per-campaign daily impressions 118-1548,
    //     CTR 0.83-1.33%, CPC 309-438 HUF.
    //   Professional Services reals: per-campaign daily impressions 1470-2949,
    //     CTR 1.00-1.29%, CPC 253 HUF (Professional Services Partner A) / 1.33 EUR
    //     ~ 532 HUF-equivalent (Professional Services B).
    //   Health reals: per-campaign daily impressions 824-1884, CTR 1.26-1.54%,
    //     CPC 236-238 HUF.
    //   Travel & Hospitality real (pulled live 2026-10-27): daily impressions
    //     ~9, CTR 1.61%, CPC 1589 HUF - a single very new, low-volume account
    //     (only 2 of 28 days had any delivery), so "range" here is really one
    //     thin data point, not an observed spread like the other industries.
    // All synthetic accounts are HUF, for simplicity - see PLANNING_LOG.md.
    // Synthetic account ids (syn-*) never collide with real ones
    // (real-account-N), so accountTotals() - grouped strictly by id - never
    // mixes a real account's own totals with its synthetic industry siblings;
    // only industryTotals() intentionally combines them.
    private const ACCOUNTS = [
        'syn-edu-1' => ['Bright Pathway Learning', 900, 0.011, 390, ['Autumn Enrolment', 'Open Day']],
        'syn-edu-2' => ['Horizon Career Academy', 500, 0.012, 360, ['Scholarship Drive', 'Info Session', 'Online Prep']],
        'syn-pro-1' => ['Keystone Consulting Group', 1800, 0.012, 320, ['Corporate Advisory', 'Market Entry Support']],
        'syn-pro-2' => ['Meridian Business Partners', 1200, 0.011, 400, ['Strategy Workshop', 'SME Consulting', 'Audit Services']],
        'syn-health-1' => ['VitaCare Pharmacy Group', 1500, 0.014, 240, ['Flu Season Campaign', 'Vitamin Range']],
        'syn-health-2' => ['Wellspring Clinics', 1000, 0.013, 250, ['Checkup Reminders', 'Dental Care']],
        'syn-travel-1' => ['Blue Horizon Travel', 25, 0.017, 1650, ['Summer Getaway Sale', 'Weekend Escape Promo']],
        'syn-travel-2' => ['Alpine Trail Tours', 15, 0.015, 1450, ['Adventure Package Deals', 'Group Booking Discount']],
    ];

    protected function accountDefinitions(): array
    {
        return self::ACCOUNTS;
    }
}
