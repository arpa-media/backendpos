<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Ticket;
use App\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class GeneralAffairDashboardService
{
    private const STATUSES = [
        'PENINJAUAN' => 'Peninjauan',
        'PENGAJUAN' => 'Pengajuan',
        'PROSES_PENGERJAAN' => 'Proses Pengerjaan',
        'REPORT' => 'Report',
        'SELESAI' => 'Selesai',
        'PENDING' => 'Pending',
    ];

    public function build(array $filters): array
    {
        $timezone = (string) ($filters['timezone'] ?? 'Asia/Jakarta');
        $nowLocal = CarbonImmutable::now($timezone);
        $dateFrom = (string) ($filters['date_from'] ?? $nowLocal->startOfMonth()->toDateString());
        $dateTo = (string) ($filters['date_to'] ?? $nowLocal->toDateString());

        $startUtc = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateFrom.' 00:00:00', $timezone)->utc();
        $endUtc = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateTo.' 23:59:59', $timezone)->utc();

        $base = Ticket::query()
            ->whereBetween('created_at', [$startUtc, $endUtc]);

        if (! empty($filters['outlet_id'])) {
            $base->where('outlet_id', (string) $filters['outlet_id']);
        }

        $statusRows = (clone $base)
            ->selectRaw('status, COUNT(*) AS ticket_count')
            ->groupBy('status')
            ->get();

        $statusCounts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($statusRows as $row) {
            $status = strtoupper((string) $row->status);
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = (int) $row->ticket_count;
            }
        }

        $categoryRows = (clone $base)
            ->selectRaw("COALESCE(category_code_snapshot, 'UNMAPPED') AS group_key")
            ->selectRaw("MAX(COALESCE(NULLIF(category_name_snapshot, ''), 'Tanpa Kategori')) AS group_label")
            ->selectRaw('COUNT(*) AS ticket_count')
            ->selectRaw('COALESCE(SUM(estimate_fee), 0) AS estimate_fee')
            ->groupBy('category_code_snapshot')
            ->orderByDesc('ticket_count')
            ->get();

        $outletRows = (clone $base)
            ->selectRaw("COALESCE(outlet_id, 'UNMAPPED') AS group_key")
            ->selectRaw("MAX(COALESCE(NULLIF(outlet_code_snapshot, ''), '-')) AS outlet_code")
            ->selectRaw("MAX(COALESCE(NULLIF(outlet_name_snapshot, ''), 'Tanpa Outlet')) AS group_label")
            ->selectRaw('COUNT(*) AS ticket_count')
            ->selectRaw('COALESCE(SUM(estimate_fee), 0) AS estimate_fee')
            ->groupBy('outlet_id')
            ->orderByDesc('ticket_count')
            ->get();

        $statusChart = collect(self::STATUSES)->map(function (string $label, string $status) use ($statusCounts): array {
            return [
                'key' => $status,
                'label' => $label,
                'value' => (int) ($statusCounts[$status] ?? 0),
            ];
        })->values()->all();

        $categoryCount = $categoryRows->map(fn ($row): array => [
            'key' => (string) $row->group_key,
            'label' => (string) $row->group_label,
            'value' => (int) $row->ticket_count,
        ])->values()->all();

        $categoryFee = $categoryRows->sortByDesc(fn ($row) => (float) $row->estimate_fee)->map(fn ($row): array => [
            'key' => (string) $row->group_key,
            'label' => (string) $row->group_label,
            'value' => round((float) $row->estimate_fee, 2),
        ])->values()->all();

        $outletCount = $outletRows->map(fn ($row): array => [
            'key' => (string) $row->group_key,
            'label' => trim((string) $row->outlet_code.' · '.(string) $row->group_label, ' ·'),
            'value' => (int) $row->ticket_count,
        ])->values()->all();

        $outletFee = $outletRows->sortByDesc(fn ($row) => (float) $row->estimate_fee)->map(fn ($row): array => [
            'key' => (string) $row->group_key,
            'label' => trim((string) $row->outlet_code.' · '.(string) $row->group_label, ' ·'),
            'value' => round((float) $row->estimate_fee, 2),
        ])->values()->all();

        $total = array_sum($statusCounts);
        $totalEstimateFee = $categoryRows->sum(fn ($row) => (float) $row->estimate_fee);

        return [
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $timezone,
            ],
            'summary' => [
                'ticketing_masuk' => $total,
                'peninjauan' => (int) $statusCounts['PENINJAUAN'],
                'pengajuan' => (int) $statusCounts['PENGAJUAN'],
                'proses_pengerjaan' => (int) $statusCounts['PROSES_PENGERJAAN'],
                'report' => (int) $statusCounts['REPORT'],
                'selesai' => (int) $statusCounts['SELESAI'],
                'pending' => (int) $statusCounts['PENDING'],
                'total_estimate_fee' => round($totalEstimateFee, 2),
            ],
            'charts' => [
                'ticketing_by_category' => $categoryCount,
                'ticketing_by_outlet' => $outletCount,
                'ticketing_by_status' => $statusChart,
                'estimate_fee_by_category' => $categoryFee,
                'estimate_fee_by_outlet' => $outletFee,
            ],
            'filters' => [
                'outlets' => Outlet::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Outlet $outlet): array => [
                        'id' => (string) $outlet->id,
                        'code' => (string) $outlet->code,
                        'name' => (string) $outlet->name,
                    ])->values()->all(),
            ],
        ];
    }
}
