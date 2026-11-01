<?php

namespace App\Services;

use App\Data\Industries;
use App\Data\IndustryMapping;
use App\Data\PerformanceSource;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DashboardService
{
    /** @param list<PerformanceSource> $sources */
    public function __construct(
        private readonly array $sources,
        private readonly IndustryMapping $mapping,
        private readonly Aggregator $aggregator = new Aggregator,
    ) {}

    public function build(string $from, string $to, string $metric, ?string $industry): array
    {
        $records = [];
        $labels = [];
        $names = [];
        $notices = [];

        // Rule of 3 (PLANNING_LOG.md 2026-11-01): once an industry has 3+
        // currently-active real accounts, it's shown real-only - synthetic
        // accounts/records for that industry are excluded entirely below,
        // not just from one summary table, so no "Synthetic" ghost rows
        // with zero data appear either. "Active" means any source labelled
        // 'Real' - both RealAdsSource (.env) and, as of 2026-11-01,
        // DbRegisteredAdsSource (database-registered AdsAccount rows) count
        // here generically, with no change needed to this method when the
        // latter was wired in: accounts() is a cheap synchronous call for
        // both (DbRegisteredAdsSource reads its own already-synced snapshot
        // table, never the live API), so counting them costs nothing extra.
        $realAccountCounts = $this->activeRealAccountCountsByIndustry();

        foreach ($this->sources as $source) {
            $isSynthetic = $source->label() === 'Synthetic';

            foreach ($source->accounts() as $id => $name) {
                if ($isSynthetic && ($realAccountCounts[$this->mapping->industryOf($id)] ?? 0) >= 3) {
                    continue;
                }
                $labels[$id] = $source->label();
                $names[$id] = $name;
            }

            try {
                $sourceRecords = $source->records($from, $to);
            } catch (Throwable $e) {
                // Caught broadly and turned into a soft notice by design - a missing
                // or rejected API key is expected and the dashboard should still
                // render with the other sources - but it must never be silently
                // dropped: log it so a real bug (not just "no key configured")
                // leaves a trace instead of only ever showing as a generic notice.
                Log::channel(config('logging.default'))->error('dashboard.source_load_failed', [
                    'source' => $source->label(),
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $notices[] = "The {$source->label()} account could not be loaded: {$e->getMessage()}.";
                continue;
            }

            if ($isSynthetic) {
                $sourceRecords = array_values(array_filter(
                    $sourceRecords,
                    fn ($r) => ($realAccountCounts[$this->mapping->industryOf($r->account)] ?? 0) < 3
                ));
            }
            array_push($records, ...$sourceRecords);
        }

        $campaigns = $this->aggregator->campaignTotals($records);
        $accountTotals = $this->aggregator->accountTotals($campaigns);
        $industryTotals = $this->aggregator->industryTotals($campaigns, $this->mapping);
        $boxStats = $this->aggregator->boxPlotStats($campaigns, $this->mapping, $metric);

        $known = array_unique(array_map(fn ($id) => $this->mapping->industryOf($id), array_keys($labels)));
        $industries = array_values(array_filter(Industries::ALL, fn ($i) => in_array($i, $known, true)));

        $drilldown = null;
        if ($industry !== null && in_array($industry, $industries, true)) {
            $drilldown = [];
            foreach ($labels as $id => $label) {
                if ($this->mapping->industryOf($id) !== $industry) {
                    continue;
                }
                $drilldown[] = [
                    'id' => $id,
                    'name' => $names[$id],
                    'label' => $label,
                    'totals' => $accountTotals[$id] ?? null,
                    'campaigns' => array_values(array_filter($campaigns, fn ($c) => $c['account'] === $id)),
                ];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'metric' => $metric,
            'notices' => $notices,
            'industries' => $industries,
            'industryTotals' => $industryTotals,
            'boxPlot' => array_combine($industries, array_map(fn ($i) => $boxStats[$i] ?? [], $industries)),
            'selectedIndustry' => $drilldown === null ? null : $industry,
            'drilldown' => $drilldown,
        ];
    }

    /** @return array<string, int> industry => count of currently-active ('Real'-labeled) accounts */
    private function activeRealAccountCountsByIndustry(): array
    {
        $counts = [];
        foreach ($this->sources as $source) {
            if ($source->label() !== 'Real') {
                continue;
            }
            foreach (array_keys($source->accounts()) as $id) {
                $i = $this->mapping->industryOf($id);
                $counts[$i] = ($counts[$i] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
