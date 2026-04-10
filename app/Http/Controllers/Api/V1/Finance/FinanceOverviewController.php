<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListFinanceOverviewRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\CashierAlignedSaleScopeService;
use App\Support\FinanceOutletFilter;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

class FinanceOverviewController extends Controller
{
    public function __construct(private readonly CashierAlignedSaleScopeService $cashierAlignedSaleScope)
    {
    }

    private const PAYMENT_BUCKETS = [
        'cash' => 'Tunai',
        'qris_bca' => 'Qris BCA',
        'edc_bca' => 'EDC BCA',
        'tf_bca' => 'TF BCA',
        'qris_bri' => 'Qris BRI',
        'edc_bri' => 'EDC BRI',
        'tf_bri' => 'TF BRI',
        'gofood' => 'Gofood',
        'grabfood' => 'Grabfood',
        'debit_card' => 'Debit/Card',
    ];

    public function index(ListFinanceOverviewRequest $request)
    {
        $v = $request->validated();
        $isExport = filter_var($v['export'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        $window = TransactionDate::businessDateWindow(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = [$window['requested_from'], $window['requested_to'], $window['from_utc'], $window['to_utc']];

        $eligibleSaleIds = $this->cashierAlignedSaleScope->eligibleSaleIds($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);

        $base = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds))
            ->when(empty($eligibleSaleIds), fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('s.id', $eligibleSaleIds));

        $summaryRow = (clone $base)
            ->selectRaw('COALESCE(SUM(' . DeliveryNoTaxReadModel::sqlGrandTotal('s') . '), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(CAST(s.marking AS SIGNED), 0) = 1 THEN ' . DeliveryNoTaxReadModel::sqlGrandTotal('s') . ' ELSE 0 END), 0) as marking_gross_sales')
            ->selectRaw('COALESCE(SUM(' . DeliveryNoTaxReadModel::sqlTaxTotal('s') . '), 0) as total_tax')
            ->selectRaw('COALESCE(SUM(s.discount_total), 0) as total_discount')
            ->first();

        $paymentSelects = [];
        foreach (self::PAYMENT_BUCKETS as $key => $label) {
            $paymentSelects[] = 'COALESCE(SUM(CASE WHEN ' . $this->bucketSqlCondition($key) . ' THEN ' . DeliveryNoTaxReadModel::sqlGrandTotal('s') . ' ELSE 0 END), 0) as ' . $key;
        }

        $agg = (clone $base)
            ->selectRaw('s.outlet_id as outlet_id')
            ->selectRaw(implode(",\n", $paymentSelects))
            ->groupBy('s.outlet_id');

        $rows = DB::table('outlets as o')
            ->leftJoinSub($agg, 'agg', fn ($join) => $join->on('agg.outlet_id', '=', 'o.id'))
            ->where('o.type', 'outlet')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('o.id', $outletIds))
            ->orderBy('o.name')
            ->get(array_merge([
                'o.id as outlet_id',
                'o.name as outlet_name',
            ], array_map(fn ($key) => DB::raw('COALESCE(agg.' . $key . ', 0) as ' . $key), array_keys(self::PAYMENT_BUCKETS))))
            ->map(function ($row) {
                $payload = [
                    'outlet_id' => (string) ($row->outlet_id ?? ''),
                    'outlet_name' => (string) ($row->outlet_name ?? '-'),
                ];
                foreach (array_keys(self::PAYMENT_BUCKETS) as $bucket) {
                    $payload[$bucket] = (int) round((float) ($row->{$bucket} ?? 0));
                }
                return $payload;
            })
            ->values();

        $paymentTotals = [];
        foreach (self::PAYMENT_BUCKETS as $key => $label) {
            $paymentTotals[] = [
                'key' => $key,
                'label' => $label,
                'amount' => (int) $rows->sum($key),
            ];
        }

        $payload = [
            'summary' => [
                'gross_sales' => (int) round((float) ($summaryRow->gross_sales ?? 0)),
                'marking_gross_sales' => (int) round((float) ($summaryRow->marking_gross_sales ?? 0)),
                'total_tax' => (int) round((float) ($summaryRow->total_tax ?? 0)),
                'total_discount' => (int) round((float) ($summaryRow->total_discount ?? 0)),
            ],
            'payment_method_totals' => $paymentTotals,
            'items' => $rows->all(),
            'filters' => [
                'date_from' => $fromLocal->format('Y-m-d'),
                'date_to' => $toLocal->format('Y-m-d'),
                'outlet_filter' => $outletFilter['value'],
            ],
            'filter_options' => [
                'outlet_filters' => $outletFilter['options'],
                'payment_method_columns' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::PAYMENT_BUCKETS), array_values(self::PAYMENT_BUCKETS)),
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_name' => $outletFilter['label'],
                'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
        ];

        if ($isExport) {
            $payload['export'] = [
                'filename' => $this->buildFilename($outletFilter['label'], $fromLocal->format('Y-m-d'), $toLocal->format('Y-m-d')),
                'total_rows' => $rows->count(),
                'columns' => array_merge(['Nama Outlet'], array_values(self::PAYMENT_BUCKETS)),
            ];
        }

        return ApiResponse::ok($payload, 'OK');
    }

    private function bucketSqlCondition(string $bucket): string
    {
        $name = "LOWER(TRIM(COALESCE(s.payment_method_name, '')))";
        $type = "LOWER(TRIM(COALESCE(s.payment_method_type, '')))";

        return match ($bucket) {
            'cash' => "{$name} IN ('tunai', 'cash') OR {$type} = 'cash'",
            'qris_bca' => "{$name} = 'qris bca'",
            'edc_bca' => "{$name} = 'edc bca'",
            'tf_bca' => "{$name} IN ('tf bca', 'transfer bca')",
            'qris_bri' => "{$name} = 'qris bri'",
            'edc_bri' => "{$name} = 'edc bri'",
            'tf_bri' => "{$name} IN ('tf bri', 'transfer bri')",
            'gofood' => "{$name} = 'gofood'",
            'grabfood' => "{$name} = 'grabfood'",
            'debit_card' => "{$name} LIKE '%debit%' OR {$name} LIKE '%card%' OR {$name} LIKE '%credit%'",
            default => '1 = 0',
        };
    }

    private function buildFilename(string $label, string $dateFrom, string $dateTo): string
    {
        $safe = trim(preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($label)), '_');
        return 'finance_overview_' . ($safe !== '' ? $safe : 'all_outlet') . '_' . $dateFrom . '_to_' . $dateTo . '.csv';
    }
}
