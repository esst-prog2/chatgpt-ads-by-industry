<?php

namespace App\Data;

use Illuminate\Support\Str;

/**
 * Immediate, generic synthetic fallback for any industry that has at least
 * one real account (.env-configured or database-registered) but no
 * hand-curated SyntheticSource coverage - see AppServiceProvider, which
 * computes that industry list and constructs this with it. Deliberately a
 * SEPARATE class from SyntheticSource, not an addition to its hardcoded
 * dataset: SyntheticSource's numbers are modeled on real observed
 * per-industry ranges (its own docblock), and this class's whole point is to
 * cover an industry BEFORE anyone has calibrated real numbers for it - using
 * a flat, clearly-generic profile (not pretending to be industry-specific)
 * is the honest choice, not a shortcut.
 *
 * "Clean up the synthetic data once the real count reaches 3+" needed no
 * separate deletion step: nothing here is ever stored - like
 * SyntheticSource, records() generates fresh output every call, and
 * DashboardService's existing Rule-of-3 filter (which already suppresses
 * SyntheticSource's own industries past the threshold, label() === 'Synthetic'
 * either way) suppresses this source's industries the exact same way, for
 * free, with no new code there at all.
 */
final class GenericSyntheticSource extends AbstractSyntheticSource
{
    private const ACCOUNTS_PER_INDUSTRY = 2;

    // One flat, honestly-generic profile, not per-industry-tuned: picked as
    // a plausible middle of SyntheticSource's own curated ranges (roughly
    // 500-1800 base impressions, ~1.1-1.7% CTR, 240-1650 HUF CPC) rather than
    // guessing at a brand-new industry's real character.
    private const BASE_IMPRESSIONS = [700, 500];

    private const BASE_CTR = 0.011;

    private const BASE_CPC = 350;

    private const CAMPAIGNS = ['General Awareness', 'Seasonal Promotion'];

    /** @param list<string> $industries industries with >=1 real account but no SyntheticSource::COVERED_INDUSTRIES entry */
    public function __construct(
        private readonly array $industries,
        ?string $endDate = null,
    ) {
        parent::__construct($endDate);
    }

    protected function accountDefinitions(): array
    {
        $definitions = [];
        foreach ($this->industries as $industry) {
            for ($n = 1; $n <= self::ACCOUNTS_PER_INDUSTRY; $n++) {
                $definitions[self::accountId($industry, $n)] = [
                    "Comparator Account {$n}",
                    self::BASE_IMPRESSIONS[$n - 1],
                    self::BASE_CTR,
                    self::BASE_CPC,
                    self::CAMPAIGNS,
                ];
            }
        }

        return $definitions;
    }

    public static function accountId(string $industry, int $n): string
    {
        return 'syn-generic-'.Str::slug($industry)."-{$n}";
    }

    /**
     * For merging into IndustryMapping, which otherwise has no way to know
     * these dynamically-built ids' industries - built from the exact same
     * accountId() as accountDefinitions() uses, so the two can never drift
     * out of sync with each other.
     *
     * @param  list<string>  $industries
     * @return array<string, string> account id => industry
     */
    public static function industryMap(array $industries): array
    {
        $map = [];
        foreach ($industries as $industry) {
            for ($n = 1; $n <= self::ACCOUNTS_PER_INDUSTRY; $n++) {
                $map[self::accountId($industry, $n)] = $industry;
            }
        }

        return $map;
    }
}
