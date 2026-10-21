<?php

namespace App\Support\HumanResource;

/** Pure, auditable logic shared by the dashboard's region and drill-down totals. */
final class HrV13I05ManpowerAggregator
{
    public const REGIONS = ['Malang', 'Bali', 'Bandung', 'Borneo'];

    public static function aggregate(array $outlets, array $staffingByOutlet): array
    {
        $regions = [];
        foreach (self::REGIONS as $region) {
            $regions[$region] = ['region' => $region, 'outlets_gap' => 0,
                'positions_gap' => 0, 'shortage_people' => 0, 'surplus_people' => 0, 'items' => []];
        }
        $unmapped = 0;
        foreach ($outlets as $outlet) {
            $outlet = (array) $outlet;
            $region = (string) ($outlet['hr_region'] ?? '');
            if (! isset($regions[$region])) { $unmapped++; continue; }
            $id = (string) ($outlet['id'] ?? '');
            $hasGap = false;
            foreach (($staffingByOutlet[$id]['rows'] ?? []) as $row) {
                $slot = (int) ($row['slot'] ?? 0);
                if ($slot <= 0) continue; // Always ignore roles whose target is zero.
                $actual = (int) ($row['actual'] ?? 0);
                $gap = $actual - $slot;
                if ($gap === 0) continue;
                $hasGap = true;
                $regions[$region]['positions_gap']++;
                $regions[$region]['shortage_people'] += max(0, -$gap);
                $regions[$region]['surplus_people'] += max(0, $gap);
                $regions[$region]['items'][] = [
                    'outlet_id' => $id, 'outlet_code' => (string) ($outlet['code'] ?? ''),
                    'outlet_name' => (string) ($outlet['name'] ?? ''),
                    'role' => (string) ($row['role'] ?? ''), 'slot' => $slot,
                    'actual' => $actual, 'gap' => $gap,
                    'status' => $gap < 0 ? 'shortage' : 'surplus',
                ];
            }
            if ($hasGap) $regions[$region]['outlets_gap']++;
        }
        foreach ($regions as &$region) {
            usort($region['items'], static fn ($a, $b) =>
                strcmp($a['outlet_code'], $b['outlet_code']) ?: strcmp($a['role'], $b['role']));
        }
        unset($region);
        return [
            'regions' => array_values($regions),
            'totals' => [
                'outlets_gap' => array_sum(array_column($regions, 'outlets_gap')),
                'positions_gap' => array_sum(array_column($regions, 'positions_gap')),
                'shortage_people' => array_sum(array_column($regions, 'shortage_people')),
                'surplus_people' => array_sum(array_column($regions, 'surplus_people')),
            ],
            'unmapped_outlets' => $unmapped,
            'rule' => 'slot > 0; gap = actual - slot; actual < slot shortage; actual > slot surplus',
        ];
    }
}
