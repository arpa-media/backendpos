<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceDailySalesSnapshotService;
use App\Services\Finance\FinanceOverhandleSourceService;
use App\Support\BackofficeOutletScope;
use App\Support\Finance\FinanceScopeResolver;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceOverhandleReportController extends Controller
{
    private const SHIFT_TYPES = ['opening', 'over', 'closing'];

    public function __construct(
        private readonly FinanceDailySalesSnapshotService $salesSnapshot,
        private readonly FinanceOverhandleSourceService $source,
        private readonly FinanceScopeResolver $financeScope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);

        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $date = $validated['date'] ?? TransactionDate::businessTodayDateString($scope['timezone']);
        $entries = $this->loadEntries($date, $scope['outlet_id']);

        return response()->json(['data' => $this->buildDailyPayload($date, $scope, $entries)]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_type' => ['required', Rule::in(self::SHIFT_TYPES)],
            'snapshot_time' => ['required', 'date_format:H:i'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);

        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $snapshot = $this->salesSnapshot->snapshot(
            $scope['outlet_id'],
            $validated['date'],
            $validated['snapshot_time'],
        );

        $existing = DB::table('finance_overhandle_reports')
            ->where('outlet_id', $scope['outlet_id'])
            ->where('business_date', '=', $validated['date'])
            ->where('shift_type', $validated['shift_type'])
            ->first();

        $existingDetails = $existing
            ? DB::table('finance_overhandle_report_payments')
                ->where('report_id', $existing->id)
                ->get()
                ->keyBy(fn ($row) => mb_strtolower((string) $row->payment_method_name))
            : collect();

        $payments = collect($snapshot['payment_methods'])->map(function (array $row) use ($existingDetails): array {
            $existing = $existingDetails->get(mb_strtolower((string) $row['payment_method']));
            $pos = (int) ($row['tkj_pos'] ?? 0);

            return [
                'payment_method_id' => $row['payment_method_id'] ?? null,
                'payment_method' => (string) $row['payment_method'],
                'tkj_pos' => $pos,
                // Default aktual = POS. User hanya perlu mengubah bila fisik/bank berbeda.
                'actual' => $existing ? (int) $existing->actual_amount : $pos,
                'note' => $existing ? (string) ($existing->note ?? '') : '',
            ];
        })->values()->all();

        return response()->json(['data' => [
            'scope' => $scope,
            'date' => $validated['date'],
            'shift_type' => $validated['shift_type'],
            'snapshot_time' => $validated['snapshot_time'],
            'snapshot_at_local' => $snapshot['snapshot_at_local'],
            'snapshot_at_utc' => $snapshot['snapshot_at_utc'],
            'payment_methods' => $payments,
            'summary' => [
                'tkj_pos_total' => (int) $snapshot['tkj_pos_total'],
                'transaction_count' => (int) $snapshot['transaction_count'],
                'discount_total' => (int) $snapshot['discount_total'],
                'tax_total' => (int) $snapshot['tax_total'],
                'rounding_total' => (int) $snapshot['rounding_total'],
            ],
            'existing' => $existing ? $this->transformEntry($existing) : null,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'shift_type' => ['required', Rule::in(self::SHIFT_TYPES)],
            'snapshot_time' => ['required', 'date_format:H:i'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'modal_amount' => ['nullable', 'numeric', 'min:0'],
            'petty_cash_amount' => ['nullable', 'numeric', 'min:0'],
            'magic_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*.payment_method_id' => ['nullable', 'string', 'max:64'],
            'payment_methods.*.payment_method' => ['required', 'string', 'max:180'],
            'payment_methods.*.actual' => ['required', 'numeric', 'min:0'],
            'payment_methods.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $snapshot = $this->salesSnapshot->snapshot($scope['outlet_id'], $validated['date'], $validated['snapshot_time']);
        $snapshotByName = collect($snapshot['payment_methods'])->keyBy(fn ($row) => mb_strtolower((string) $row['payment_method']));
        $submitted = collect($validated['payment_methods'])
            ->keyBy(fn ($row) => mb_strtolower(trim((string) ($row['payment_method'] ?? ''))));

        $detailRows = [];
        $posTotal = 0;
        $actualTotal = 0;
        $sort = 0;
        foreach ($snapshotByName as $key => $snapshotRow) {
            $input = $submitted->get($key);
            if (! $input) {
                throw ValidationException::withMessages([
                    'payment_methods' => 'Semua payment method dari snapshot Cashier Report wajib memiliki nilai aktual.',
                ]);
            }

            $pos = (float) ($snapshotRow['tkj_pos'] ?? 0);
            $actual = (float) ($input['actual'] ?? 0);
            $posTotal += $pos;
            $actualTotal += $actual;
            $detailRows[] = [
                'payment_method_id' => ($snapshotRow['payment_method_id'] ?? null) ?: ($input['payment_method_id'] ?? null),
                'payment_method_name' => (string) $snapshotRow['payment_method'],
                'tkj_pos_amount' => $pos,
                'actual_amount' => $actual,
                'difference_amount' => $actual - $pos,
                'note' => trim((string) ($input['note'] ?? '')),
                'sort_order' => ++$sort,
            ];
        }

        $now = now();
        $existingId = DB::table('finance_overhandle_reports')
            ->where('outlet_id', $scope['outlet_id'])
            ->where('business_date', '=', $validated['date'])
            ->where('shift_type', $validated['shift_type'])
            ->value('id');
        $entryId = (string) ($existingId ?: Str::ulid());
        $userId = (string) ($request->user()?->getAuthIdentifier() ?? '');

        $payload = [
            'company_code' => $this->financeScope->companyForOutlet($scope['outlet_id']),
            'outlet_id' => $scope['outlet_id'],
            'business_date' => $validated['date'],
            'shift_type' => $validated['shift_type'],
            'snapshot_time' => $validated['snapshot_time'],
            'snapshot_at_utc' => $snapshot['snapshot_at_utc'],
            'timezone' => $scope['timezone'],
            'modal_amount' => (float) ($validated['modal_amount'] ?? 0),
            'petty_cash_amount' => (float) ($validated['petty_cash_amount'] ?? 0),
            'magic_amount' => (float) ($validated['magic_amount'] ?? 0),
            'tkj_pos_total' => $posTotal,
            'actual_total' => $actualTotal,
            'difference_total' => $actualTotal - $posTotal,
            'sales_total_at_snapshot' => (float) $snapshot['tkj_pos_total'],
            'transaction_count_at_snapshot' => (int) $snapshot['transaction_count'],
            'discount_total_at_snapshot' => (float) $snapshot['discount_total'],
            'tax_total_at_snapshot' => (float) $snapshot['tax_total'],
            'rounding_total_at_snapshot' => (float) $snapshot['rounding_total'],
            'note' => trim((string) ($validated['note'] ?? '')),
            'updated_by' => $userId ?: null,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($entryId, $payload, $detailRows, $now, $userId): void {
            if (DB::table('finance_overhandle_reports')->where('id', $entryId)->exists()) {
                DB::table('finance_overhandle_reports')->where('id', $entryId)->update($payload);
            } else {
                DB::table('finance_overhandle_reports')->insert($payload + [
                    'id' => $entryId,
                    'created_by' => $userId ?: null,
                    'created_at' => $now,
                ]);
            }

            DB::table('finance_overhandle_report_payments')->where('report_id', $entryId)->delete();
            foreach ($detailRows as $row) {
                DB::table('finance_overhandle_report_payments')->insert($row + [
                    'id' => (string) Str::ulid(),
                    'report_id' => $entryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return response()->json(['data' => $this->buildDailyPayload(
            $validated['date'],
            $scope,
            $this->loadEntries($validated['date'], $scope['outlet_id']),
        )]);
    }

    public function reconciliationSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);

        return response()->json(['data' => $this->source->closingForReconciliation($scope['outlet_id'], $validated['date'])]);
    }

    private function singleOutletScope(Request $request, ?string $rawFilter): array
    {
        // HF03G: admin-like users must see every physical outlet. Do not let
        // legacy outlet is_active flags or user.outlet_id narrow an administrator.
        $adminLike = $this->isAdminLike($request);

        if ($adminLike) {
            $rows = DB::table('outlets')
                ->where('type', 'outlet')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'timezone', 'is_active']);

            $options = $rows->map(fn ($row) => [
                'value' => (string) $row->id,
                'label' => (string) ($row->name ?: $row->code ?: $row->id),
                'kind' => 'outlet',
                'code' => (string) ($row->code ?? ''),
                'timezone' => TransactionDate::normalizeTimezone((string) ($row->timezone ?? ''), TransactionDate::appTimezone()),
                'is_active' => (bool) ($row->is_active ?? true),
            ])->values()->all();

            $requested = trim((string) ($rawFilter ?? ''));
            $selectedOption = collect($options)->first(fn ($row) => (string) ($row['value'] ?? '') === $requested)
                ?? collect($options)->first();
            if (! $selectedOption) {
                throw ValidationException::withMessages(['outlet_filter' => 'Tidak ada outlet yang tersedia untuk Overhandle.']);
            }

            $outletId = (string) $selectedOption['value'];
            return [
                'value' => $outletId,
                'label' => (string) $selectedOption['label'],
                'outlet_id' => $outletId,
                'outlet_ids' => [$outletId],
                'timezone' => (string) $selectedOption['timezone'],
                'company_code' => $this->financeScope->companyForOutlet($outletId),
                'can_adjust_scope' => true,
                'options' => $options,
            ];
        }

        // Non-admin follows the canonical access/outlet scope.
        $allScope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $allowed = array_values(array_filter(array_map('strval', $allScope['outlet_ids'] ?? [])));
        $options = array_values(array_filter($allScope['options'] ?? [], function ($row) use ($allowed): bool {
            return ($row['kind'] ?? '') === 'outlet' && in_array((string) ($row['value'] ?? ''), $allowed, true);
        }));

        $requested = trim((string) ($rawFilter ?? ''));
        $selected = $requested !== '' && in_array($requested, $allowed, true) ? $requested : null;
        if (! $selected) $selected = (string) (collect($options)->first()['value'] ?? '');
        if ($selected === '') throw ValidationException::withMessages(['outlet_filter' => 'Tidak ada outlet yang berada dalam scope akses user.']);

        $scope = BackofficeOutletScope::resolve($request, $selected, false);
        $outletIds = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        if (count($outletIds) !== 1 || (string) $outletIds[0] !== $selected) {
            throw ValidationException::withMessages(['outlet_filter' => 'Overhandle wajib menggunakan tepat satu outlet.']);
        }
        $selectedOption = collect($options)->firstWhere('value', $selected);

        return [
            'value' => $selected,
            'label' => (string) (is_array($selectedOption) ? ($selectedOption['label'] ?? 'Outlet') : ($scope['label'] ?? 'Outlet')),
            'outlet_id' => $selected,
            'outlet_ids' => [$selected],
            'timezone' => TransactionDate::normalizeTimezone((string) ($scope['timezone'] ?? ''), TransactionDate::appTimezone()),
            'company_code' => $this->financeScope->companyForOutlet($selected),
            'can_adjust_scope' => false,
            'options' => $options,
        ];
    }

    private function isAdminLike(Request $request): bool
    {
        $user = $request->user();
        if (! $user) return false;

        try {
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin', 'administrator', 'superadmin', 'super-admin'])) {
                return true;
            }
            if (method_exists($user, 'getRoleNames')) {
                return $user->getRoleNames()->map(fn ($name) => strtolower(trim((string) $name)))
                    ->intersect(['admin', 'administrator', 'superadmin', 'super-admin'])->isNotEmpty();
            }
        } catch (\Throwable) {
            // Fall through to access-scope signal below.
        }

        return (bool) $request->attributes->get('outlet_scope_can_adjust', false)
            && trim((string) ($user->outlet_id ?? '')) === '';
    }

    private function loadEntries(string $date, string $outletId): array
    {
        $rows = DB::table('finance_overhandle_reports')
            ->where('outlet_id', $outletId)
            ->where('business_date', '=', $date)
            ->orderByRaw("CASE shift_type WHEN 'opening' THEN 1 WHEN 'over' THEN 2 WHEN 'closing' THEN 3 ELSE 9 END")
            ->get();
        $details = $rows->isEmpty() ? collect() : DB::table('finance_overhandle_report_payments')
            ->whereIn('report_id', $rows->pluck('id')->all())
            ->orderBy('sort_order')
            ->get()
            ->groupBy('report_id');

        return $rows->map(function ($row) use ($details): array {
            $entry = $this->transformEntry($row);
            $entry['payment_methods'] = collect($details[(string) $row->id] ?? [])->map(fn ($detail) => [
                'payment_method_id' => $detail->payment_method_id ? (string) $detail->payment_method_id : null,
                'payment_method' => (string) $detail->payment_method_name,
                'tkj_pos' => (float) $detail->tkj_pos_amount,
                'actual' => (float) $detail->actual_amount,
                'difference' => (float) $detail->difference_amount,
                'note' => (string) ($detail->note ?? ''),
            ])->values()->all();
            return $entry;
        })->values()->all();
    }

    private function transformEntry(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'company_code' => $row->company_code ? (string) $row->company_code : null,
            'outlet_id' => (string) $row->outlet_id,
            'date' => (string) $row->business_date,
            'shift_type' => (string) $row->shift_type,
            'snapshot_time' => substr((string) $row->snapshot_time, 0, 5),
            'timezone' => (string) $row->timezone,
            'modal_amount' => (float) $row->modal_amount,
            'petty_cash_amount' => (float) $row->petty_cash_amount,
            'magic_amount' => (float) $row->magic_amount,
            'tkj_pos_total' => (float) $row->tkj_pos_total,
            'actual_total' => (float) $row->actual_total,
            'difference_total' => (float) $row->difference_total,
            'sales_total_at_snapshot' => (float) $row->sales_total_at_snapshot,
            'transaction_count_at_snapshot' => (int) $row->transaction_count_at_snapshot,
            'discount_total_at_snapshot' => (float) $row->discount_total_at_snapshot,
            'tax_total_at_snapshot' => (float) $row->tax_total_at_snapshot,
            'rounding_total_at_snapshot' => (float) $row->rounding_total_at_snapshot,
            'note' => (string) ($row->note ?? ''),
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function buildDailyPayload(string $date, array $scope, array $entries): array
    {
        $byShift = collect($entries)->keyBy('shift_type');
        $moneyRows = [];
        foreach ([['Modal', 'modal_amount'], ['Petty Cash', 'petty_cash_amount'], ['Magic', 'magic_amount']] as [$label, $key]) {
            $moneyRows[] = [
                'label' => $label,
                'opening' => (float) data_get($byShift, 'opening.'.$key, 0),
                'over' => (float) data_get($byShift, 'over.'.$key, 0),
                'closing' => (float) data_get($byShift, 'closing.'.$key, 0),
            ];
        }

        return [
            'date' => $date,
            'scope' => $scope,
            'entries' => array_values($entries),
            'recap' => [
                'money_rows' => $moneyRows,
                'has_opening' => $byShift->has('opening'),
                'has_over' => $byShift->has('over'),
                'has_closing' => $byShift->has('closing'),
                'closing_sales_total' => (float) data_get($byShift, 'closing.sales_total_at_snapshot', 0),
                'closing_actual_total' => (float) data_get($byShift, 'closing.actual_total', 0),
                'closing_difference_total' => (float) data_get($byShift, 'closing.difference_total', 0),
                'closing_discount_total' => (float) data_get($byShift, 'closing.discount_total_at_snapshot', 0),
                'closing_tax_total' => (float) data_get($byShift, 'closing.tax_total_at_snapshot', 0),
                'closing_rounding_total' => (float) data_get($byShift, 'closing.rounding_total_at_snapshot', 0),
            ],
            'reconciliation_source' => $this->source->closingForReconciliation($scope['outlet_id'], $date),
        ];
    }
}
