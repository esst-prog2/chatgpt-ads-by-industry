<?php

namespace App\Data;

interface PerformanceSource
{
    /** "Real" or "Synthetic". */
    public function label(): string;

    /** @return array<string, string> account id => display name */
    public function accounts(): array;

    /** @return list<PerformanceRecord> daily records with date between $from and $to inclusive (Y-m-d) */
    public function records(string $from, string $to): array;
}
