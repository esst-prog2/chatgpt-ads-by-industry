<?php

namespace App\Data;

final class PerformanceRecord
{
    public function __construct(
        public readonly string $account,
        public readonly string $campaignId,
        public readonly string $campaign,
        public readonly string $date,
        public readonly int $impressions,
        public readonly int $clicks,
        public readonly float $spend,
        // Spend's currency (ISO 4217-ish code, e.g. "HUF", "EUR"). One campaign
        // always belongs to one account, which has one currency, so this is
        // unambiguous even though industries can mix accounts of different
        // currencies - see Aggregator for where that mixing gets normalized.
        // No default: every caller must say explicitly which currency a
        // record is in, rather than a silent HUF assumption creeping into
        // the data type itself (see PLANNING_LOG.md 2026-10-20).
        public readonly string $currency,
    ) {}
}
