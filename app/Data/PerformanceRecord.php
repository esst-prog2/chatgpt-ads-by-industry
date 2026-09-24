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
    ) {}
}
