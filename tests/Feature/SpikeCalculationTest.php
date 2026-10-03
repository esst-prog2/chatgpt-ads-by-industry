<?php

namespace Tests\Feature;

use App\Data\PerformanceRecord;
use App\Services\SpikeAnalyzer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpikeCalculationTest extends TestCase
{
    private function recordsOverDays(int $days, callable $valuesForDay, string $startDate = '2026-09-08'): array
    {
        $records = [];
        $start = new \DateTimeImmutable($startDate);
        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify("+{$i} days")->format('Y-m-d');
            [$impressions, $clicks, $spend] = $valuesForDay($i);
            $records[] = new PerformanceRecord('real-account', 'cmpn_1', 'Campaign #1', $date, $impressions, $clicks, $spend);
        }

        return $records;
    }

    public function test_fifteen_days_split_into_two_disjoint_seven_day_windows_with_one_left_over(): void
    {
        $records = $this->recordsOverDays(15, fn ($i) => [1000, 10, 100.0]);

        $windows = (new SpikeAnalyzer)->splitIntoTwoWindows($records, 7);

        $this->assertCount(7, $windows['week1']);
        $this->assertCount(7, $windows['week2']);
        $this->assertCount(1, $windows['leftover']);
        $this->assertSame(array_merge($windows['week1'], $windows['week2'], $windows['leftover']), array_unique(array_merge($windows['week1'], $windows['week2'], $windows['leftover'])), 'the three groups of dates must be disjoint');
        $this->assertSame('2026-09-08', $windows['week1'][0]);
        $this->assertSame('2026-09-14', end($windows['week1']));
        $this->assertSame('2026-09-15', $windows['week2'][0]);
        $this->assertSame('2026-09-21', end($windows['week2']));
        $this->assertSame(['2026-09-22'], $windows['leftover']);
    }

    public function test_splitting_uses_the_earliest_data_regardless_of_input_order(): void
    {
        $records = $this->recordsOverDays(15, fn ($i) => [1000, 10, 100.0]);
        shuffle($records); // the real API can return pages out of order

        $windows = (new SpikeAnalyzer)->splitIntoTwoWindows($records, 7);

        $this->assertSame('2026-09-08', $windows['week1'][0]);
        $this->assertSame('2026-09-22', $windows['leftover'][0]);
    }

    public function test_fewer_than_two_full_windows_throws(): void
    {
        $records = $this->recordsOverDays(13, fn ($i) => [1000, 10, 100.0]);

        $this->expectException(RuntimeException::class);
        (new SpikeAnalyzer)->splitIntoTwoWindows($records, 7);
    }

    public function test_weighted_ctr_and_cpc_are_computed_from_summed_totals_not_averaged_per_day(): void
    {
        // Day 1: 1000 impressions, 5 clicks (CTR 0.5%). Day 2: 100 impressions, 10 clicks (CTR 10%).
        // A naive average of daily CTRs would give 5.25%; the weighted total must not.
        $records = [
            new PerformanceRecord('real-account', 'cmpn_1', 'Campaign #1', '2026-09-08', 1000, 5, 50.0),
            new PerformanceRecord('real-account', 'cmpn_1', 'Campaign #1', '2026-09-09', 100, 10, 200.0),
        ];
        $spike = new SpikeAnalyzer;
        $padding = $this->recordsOverDays(12, fn ($i) => [1, 0, 0.0], '2026-09-10'); // fills the window requirement without overlapping the two known dates
        $windows = $spike->splitIntoTwoWindows(array_merge($records, $padding), 7);
        // Isolate just the two known days by summing them directly, mirroring what the script does per window.
        $totals = $spike->windowTotals($windows['byDate'], ['2026-09-08', '2026-09-09']);

        $this->assertSame(1100, $totals['impressions']);
        $this->assertSame(15, $totals['clicks']);
        $this->assertEqualsWithDelta(15 / 1100, $spike->weightedCtr($totals), 1e-9);
        $this->assertEqualsWithDelta(250.0 / 15, $spike->weightedCpc($totals), 1e-9);
    }

    public function test_swing_percentage_is_non_null_when_both_windows_have_data(): void
    {
        $week1 = $this->recordsOverDays(7, fn ($i) => [1000, 10, 500.0]); // CTR 1%, CPC 50
        $week2 = array_map(
            fn (PerformanceRecord $r) => new PerformanceRecord($r->account, $r->campaignId, $r->campaign, (new \DateTimeImmutable($r->date))->modify('+7 days')->format('Y-m-d'), 1000, 20, 1800.0), // CTR 2%, CPC 90
            $week1
        );
        $spike = new SpikeAnalyzer;
        $windows = $spike->splitIntoTwoWindows(array_merge($week1, $week2), 7);

        $w1 = $spike->windowTotals($windows['byDate'], $windows['week1']);
        $w2 = $spike->windowTotals($windows['byDate'], $windows['week2']);
        $ctrSwing = $spike->percentChange($spike->weightedCtr($w1), $spike->weightedCtr($w2));
        $cpcSwing = $spike->percentChange($spike->weightedCpc($w1), $spike->weightedCpc($w2));

        $this->assertNotNull($ctrSwing);
        $this->assertNotNull($cpcSwing);
        $this->assertEqualsWithDelta(100.0, $ctrSwing, 1e-9); // CTR doubled: 1% -> 2%
        $this->assertEqualsWithDelta(80.0, $cpcSwing, 1e-9); // CPC: 50 -> 90
    }

    public function test_median_and_gap_percent(): void
    {
        $spike = new SpikeAnalyzer;

        $this->assertEquals(3.0, $spike->median([1.0, 3.0, 5.0]));
        $this->assertEquals(3.0, $spike->median([1.0, 5.0, 3.0, 2.0, 4.0]));
        $this->assertEquals(2.5, $spike->median([1.0, 2.0, 3.0, 4.0]));
        $this->assertNull($spike->median([]));

        $gap = $spike->gapPercent(['a' => 100.0, 'b' => 150.0, 'c' => 200.0]);
        $this->assertEqualsWithDelta(100.0, $gap['gap'], 1e-9); // (200-100)/100 * 100
        $this->assertNull($spike->gapPercent(['only' => 1.0])['gap']);
    }
}
