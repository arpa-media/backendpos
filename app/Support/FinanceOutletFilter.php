<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceOutletFilter
{
    public const FILTER_ALL = 'ALL';
    public const FILTER_GROUP_BKJB = 'GROUP:PT_BKJB';
    public const FILTER_GROUP_MDMF = 'GROUP:PT_MDMF';

    private const GROUP_LABELS = [
        self::FILTER_GROUP_BKJB => 'PT. BKJB',
        self::FILTER_GROUP_MDMF => 'PT. MDMF',
    ];

    private const GROUP_CODES = [
        self::FILTER_GROUP_BKJB => ['KJN', 'SWJ', 'IJN', 'SMR', 'FEB', 'KPD', 'SKN', 'SHT', 'MOG', 'DPN', 'BGN', 'CFT', 'MDC'],
        self::FILTER_GROUP_MDMF => ['TNS', 'BD', 'FIA', 'BJ', 'KTA'],
    ];

    public static function resolve(?string $rawFilter): array
    {
        $defaultTimezone = config('app.timezone', 'Asia/Jakarta');
        $normalizedFilter = trim((string) $rawFilter);
        $outlets = self::outletRows();

        if ($normalizedFilter === '' || strtoupper($normalizedFilter) === self::FILTER_ALL) {
            return [
                'value' => self::FILTER_ALL,
                'label' => 'All Outlet',
                'timezone' => TransactionDate::normalizeTimezone($defaultTimezone, $defaultTimezone),
                'outlet_ids' => $outlets->pluck('id')->filter()->values()->all(),
                'options' => self::optionList($outlets),
            ];
        }

        if (isset(self::GROUP_CODES[$normalizedFilter])) {
            $selected = $outlets
                ->filter(fn ($outlet) => in_array((string) ($outlet['code'] ?? ''), self::GROUP_CODES[$normalizedFilter], true))
                ->values();

            return [
                'value' => $normalizedFilter,
                'label' => self::GROUP_LABELS[$normalizedFilter] ?? $normalizedFilter,
                'timezone' => TransactionDate::normalizeTimezone($defaultTimezone, $defaultTimezone),
                'outlet_ids' => $selected->pluck('id')->filter()->values()->all(),
                'options' => self::optionList($outlets),
            ];
        }

        $selectedOutlet = $outlets->first(fn ($outlet) => (string) ($outlet['id'] ?? '') === $normalizedFilter);
        if ($selectedOutlet) {
            return [
                'value' => (string) $selectedOutlet['id'],
                'label' => (string) $selectedOutlet['name'],
                'timezone' => TransactionDate::normalizeTimezone((string) ($selectedOutlet['timezone'] ?: $defaultTimezone), $defaultTimezone),
                'outlet_ids' => [(string) $selectedOutlet['id']],
                'options' => self::optionList($outlets),
            ];
        }

        return self::resolve(self::FILTER_ALL);
    }

    public static function optionList(?Collection $outlets = null): array
    {
        $rows = $outlets ?: self::outletRows();

        $base = [
            ['value' => self::FILTER_ALL, 'label' => 'All Outlet', 'kind' => 'all'],
            ['value' => self::FILTER_GROUP_BKJB, 'label' => self::GROUP_LABELS[self::FILTER_GROUP_BKJB], 'kind' => 'group'],
            ['value' => self::FILTER_GROUP_MDMF, 'label' => self::GROUP_LABELS[self::FILTER_GROUP_MDMF], 'kind' => 'group'],
        ];

        $outletOptions = $rows->map(fn ($outlet) => [
            'value' => (string) ($outlet['id'] ?? ''),
            'label' => (string) ($outlet['name'] ?? '-'),
            'kind' => 'outlet',
            'code' => (string) ($outlet['code'] ?? ''),
            'timezone' => TransactionDate::normalizeTimezone((string) ($outlet['timezone'] ?? ''), config('app.timezone', 'Asia/Jakarta')),
            'is_active' => (bool) ($outlet['is_active'] ?? false),
        ])->values()->all();

        return [...$base, ...$outletOptions];
    }

    private static function outletRows(): Collection
    {
        return DB::table('outlets')
            ->select(['id', 'code', 'name', 'timezone', 'is_active'])
            ->where('type', 'outlet')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) ($row->id ?? ''),
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? '-'),
                'timezone' => (string) ($row->timezone ?? ''),
                'is_active' => (bool) ($row->is_active ?? false),
            ]);
    }
}
