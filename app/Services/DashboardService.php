<?php

namespace App\Services;

use App\Data\Industries;
use App\Data\IndustryMapping;
use App\Data\PerformanceSource;
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

        foreach ($this->sources as $source) {
            foreach ($source->accounts() as $id => $name) {
                $labels[$id] = $source->label();
                $names[$id] = $name;
            }
            try {
                array_push($records, ...$source->records($from, $to));
            } catch (Throwable $e) {
                $notices[] = "The {$source->label()} account could not be loaded: {$e->getMessage()}.";
            }
        }

        $campaigns = $this->aggregator->campaignTotals($records);
        $accountTotals = $this->aggregator->accountTotals($campaigns);
        $industryTotals = $this->aggregator->industryTotals($campaigns, $this->mapping);
        $boxValues = $this->aggregator->boxPlotValues($campaigns, $this->mapping, $metric);

        // Mirrors Aggregator::boxPlotValues' order and null-skip so index i here
        // matches index i in $boxValues, letting the chart identify a hovered outlier
        // by its (already-anonymized, for the real source) account and campaign name.
        $boxLabels = [];
        foreach ($campaigns as $c) {
            if ($c[$metric] === null) {
                continue;
            }
            $boxLabels[$this->mapping->industryOf($c['account'])][] = [
                'account' => $names[$c['account']] ?? $c['account'],
                'campaign' => $c['campaign'],
            ];
        }

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
            'boxPlot' => array_combine($industries, array_map(fn ($i) => $boxValues[$i] ?? [], $industries)),
            'boxPlotLabels' => array_combine($industries, array_map(fn ($i) => $boxLabels[$i] ?? [], $industries)),
            'selectedIndustry' => $drilldown === null ? null : $industry,
            'drilldown' => $drilldown,
        ];
    }
}
