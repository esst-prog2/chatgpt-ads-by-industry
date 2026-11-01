<?php

namespace Tests\Unit;

use App\Data\GenericSyntheticSource;
use Tests\TestCase;

class GenericSyntheticSourceTest extends TestCase
{
    private const END = '2026-09-23';

    public function test_label_is_synthetic_so_the_rule_of_3_filter_applies_to_it_too(): void
    {
        $this->assertSame('Synthetic', (new GenericSyntheticSource(['Automotive']))->label());
    }

    public function test_generates_two_accounts_per_industry(): void
    {
        $source = new GenericSyntheticSource(['Automotive', 'Local Services']);

        $accounts = $source->accounts();

        $this->assertCount(4, $accounts);
        $this->assertArrayHasKey(GenericSyntheticSource::accountId('Automotive', 1), $accounts);
        $this->assertArrayHasKey(GenericSyntheticSource::accountId('Automotive', 2), $accounts);
        $this->assertArrayHasKey(GenericSyntheticSource::accountId('Local Services', 1), $accounts);
        $this->assertArrayHasKey(GenericSyntheticSource::accountId('Local Services', 2), $accounts);
    }

    public function test_generates_no_accounts_when_no_industries_are_passed(): void
    {
        $source = new GenericSyntheticSource([]);

        $this->assertSame([], $source->accounts());
        $this->assertSame([], $source->records('2026-09-01', '2026-09-23'));
    }

    public function test_records_are_generated_immediately_with_no_stored_data_involved(): void
    {
        $source = new GenericSyntheticSource(['Automotive'], self::END);

        $records = $source->records('2026-09-01', '2026-09-23');

        $this->assertNotEmpty($records);
        $accountIds = array_values(array_unique(array_map(fn ($r) => $r->account, $records)));
        $this->assertEqualsCanonicalizing([
            GenericSyntheticSource::accountId('Automotive', 1),
            GenericSyntheticSource::accountId('Automotive', 2),
        ], $accountIds);
        foreach ($records as $r) {
            $this->assertSame('HUF', $r->currency);
            $this->assertGreaterThan(0, $r->impressions);
        }
    }

    public function test_industry_map_matches_the_ids_account_definitions_actually_produces(): void
    {
        // Built from the exact same accountId() both places use - this test
        // exists to catch the two ever drifting apart, not just restate them.
        $source = new GenericSyntheticSource(['Automotive', 'Local Services']);

        $map = GenericSyntheticSource::industryMap(['Automotive', 'Local Services']);

        $this->assertEqualsCanonicalizing(array_keys($source->accounts()), array_keys($map));
        $this->assertSame('Automotive', $map[GenericSyntheticSource::accountId('Automotive', 1)]);
        $this->assertSame('Local Services', $map[GenericSyntheticSource::accountId('Local Services', 2)]);
    }

    public function test_two_calls_produce_identical_output_deterministically(): void
    {
        $a = (new GenericSyntheticSource(['Automotive'], self::END))->records('2026-09-01', '2026-09-23');
        $b = (new GenericSyntheticSource(['Automotive'], self::END))->records('2026-09-01', '2026-09-23');

        $this->assertEquals($a, $b);
    }
}
