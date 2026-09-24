<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\UserManagementService;
use App\Support\BackofficeOutletScope;
use App\Support\Finance\FinanceScopeResolver;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceExpenseReportController extends Controller
{
    public function __construct(
        private readonly FinanceScopeResolver $financeScope,
        private readonly UserManagementService $userManagement,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.view', 'can_view');

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $date = $validated['date'] ?? TransactionDate::businessTodayDateString($scope['timezone']);
        $report = $this->ensureReport($request, $date, $scope, null, false);

        return response()->json(['data' => $this->buildPayload($date, $scope, $report)]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.view', 'can_view');

        $validated = $request->validate([
            'scope' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();
        if ($to < $from) [$from, $to] = [$to, $from];
        $scope = $this->resolvePreviewScope($request, (string) ($validated['scope'] ?? 'ALL'));

        $query = DB::table('finance_expense_report_items as i')
            ->join('finance_expense_reports as r', 'r.id', '=', 'i.report_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->whereBetween('r.business_date', [$from, $to]);

        if ($scope['outlet_ids'] === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('r.outlet_id', $scope['outlet_ids']);
        }
        $rows = $query->orderBy('r.business_date')->orderBy('i.entry_time')->get([
            'i.id', 'r.business_date as date', 'r.company_code', 'r.outlet_id', 'i.entry_time',
            DB::raw("COALESCE(o.name, '-') as outlet"), 'i.description', 'i.account_id', 'i.coa_code', 'i.coa_name',
            'i.amount', 'i.marking', 'i.note',
        ]);

        return response()->json(['data' => [
            'items' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'date' => (string) $row->date,
                'time' => substr((string) $row->entry_time, 0, 5),
                'company_code' => $row->company_code ? (string) $row->company_code : null,
                'outlet_id' => (string) $row->outlet_id,
                'outlet' => (string) $row->outlet,
                'description' => (string) $row->description,
                'account_id' => $row->account_id ? (string) $row->account_id : null,
                'coa_code' => (string) ($row->coa_code ?? ''),
                'coa_name' => (string) ($row->coa_name ?? ''),
                'amount' => (float) $row->amount,
                'marking' => (string) $row->marking,
                'source_type' => 'EXPENSE_REPORT',
                'source_key' => 'PETTY_CASH_EXPENSE_ITEM:'.(string) $row->id,
                'note' => (string) ($row->note ?? ''),
            ])->values()->all(),
            'summary' => [
                'scope' => $scope['label'],
                'date_from' => $from,
                'date_to' => $to,
                'total_rows' => $rows->count(),
                'total_expense' => (float) $rows->sum('amount'),
            ],
        ]]);
    }

    public function coaOptions(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.view', 'can_view');

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = trim((string) ($validated['q'] ?? ''));
        if (! Schema::hasTable('finance_chart_of_accounts')) {
            return response()->json(['data' => []]);
        }

        $query = DB::table('finance_chart_of_accounts')
            ->select('id', 'code', 'name', 'account_type')
            ->whereIn('account_type', ['EXPENSE', 'OTHER_EXPENSE']);
        if (Schema::hasColumn('finance_chart_of_accounts', 'is_active')) $query->where('is_active', true);
        if (Schema::hasColumn('finance_chart_of_accounts', 'is_postable')) $query->where('is_postable', true);
        if ($q !== '') {
            $query->where(fn ($inner) => $inner->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
        }

        return response()->json(['data' => $query->orderBy('code')->limit(150)->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'account_type' => (string) $row->account_type,
            'label' => trim((string) $row->code.' - '.(string) $row->name),
        ])->values()->all()]);
    }

    public function storeHeader(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.update', 'can_edit');

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $report = $this->ensureReport($request, $validated['date'], $scope, (float) $validated['opening_balance'], true, (string) ($validated['note'] ?? ''));

        return response()->json(['data' => $this->buildPayload($validated['date'], $scope, $report)]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.create', 'can_create');

        $validated = $this->validateItem($request);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $report = $this->ensureReport($request, $validated['date'], $scope, null, false);
        $coa = $this->resolveExpenseCoa((string) $validated['coa_code']);
        $now = now();
        $userId = (string) ($request->user()?->getAuthIdentifier() ?? '');

        DB::table('finance_expense_report_items')->insert([
            'id' => (string) Str::ulid(),
            'report_id' => (string) $report->id,
            'entry_time' => $validated['entry_time'],
            'entry_at_utc' => $this->entryAtUtc($validated['date'], $validated['entry_time'], $scope['timezone']),
            'description' => trim((string) $validated['description']),
            'account_id' => $coa['id'],
            'coa_code' => $coa['code'],
            'coa_name' => $coa['name'],
            'amount' => (float) $validated['amount'],
            'marking' => 'MARKING',
            'note' => trim((string) ($validated['note'] ?? '')),
            'created_by' => $userId ?: null,
            'updated_by' => $userId ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fresh = DB::table('finance_expense_reports')->where('id', $report->id)->first();
        return response()->json(['data' => $this->buildPayload($validated['date'], $scope, $fresh)]);
    }

    public function updateItem(Request $request, string $item): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.update', 'can_edit');

        $validated = $this->validateItem($request);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $report = $this->ensureReport($request, $validated['date'], $scope, null, false);
        $this->assertItemMutable($item, (string) $report->id);
        $coa = $this->resolveExpenseCoa((string) $validated['coa_code']);

        DB::table('finance_expense_report_items')->where('id', $item)->where('report_id', $report->id)->update([
            'entry_time' => $validated['entry_time'],
            'entry_at_utc' => $this->entryAtUtc($validated['date'], $validated['entry_time'], $scope['timezone']),
            'description' => trim((string) $validated['description']),
            'account_id' => $coa['id'],
            'coa_code' => $coa['code'],
            'coa_name' => $coa['name'],
            'amount' => (float) $validated['amount'],
            'marking' => 'MARKING',
            'note' => trim((string) ($validated['note'] ?? '')),
            'updated_by' => (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null,
            'updated_at' => now(),
        ]);

        $fresh = DB::table('finance_expense_reports')->where('id', $report->id)->first();
        return response()->json(['data' => $this->buildPayload($validated['date'], $scope, $fresh)]);
    }

    public function destroyItem(Request $request, string $item): JsonResponse
    {
        $this->authorizeCapability($request, 'report.expense_request.delete', 'can_delete');

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);
        $scope = $this->singleOutletScope($request, $validated['outlet_filter'] ?? null);
        $report = $this->ensureReport($request, $validated['date'], $scope, null, false);
        $this->assertItemMutable($item, (string) $report->id);
        DB::table('finance_expense_report_items')->where('id', $item)->where('report_id', $report->id)->delete();

        return response()->json(['data' => $this->buildPayload(
            $validated['date'],
            $scope,
            DB::table('finance_expense_reports')->where('id', $report->id)->first(),
        )]);
    }


    private function authorizeCapability(Request $request, string $permission, string $matrixKey): void
    {
        abort_unless($this->hasCapability($request, $permission, $matrixKey), 403, 'Anda tidak memiliki akses Petty Cash untuk aksi ini.');
    }

    private function hasCapability(Request $request, string $permission, string $matrixKey): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            $path = '/' . ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') !== '/report/expense-request') continue;
            if (($menu[$matrixKey] ?? false) === true) return true;
        }

        return false;
    }

    private function assertItemMutable(string $itemId, string $reportId): void
    {
        if (! Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) return;

        $row = DB::table('finance_expense_report_items')
            ->where('id', $itemId)
            ->where('report_id', $reportId)
            ->first(['id', 'general_posting_id']);
        if ($row && $row->general_posting_id) {
            throw ValidationException::withMessages([
                'item' => 'Petty Cash yang sudah masuk General Posting tidak dapat diedit/dihapus. Gunakan flow Unpost/Edit di General Posting bila perlu koreksi jurnal.',
            ]);
        }
    }

    private function validateItem(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'entry_time' => ['required', 'date_format:H:i'],
            'description' => ['required', 'string', 'max:500'],
            'coa_code' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function ensureReport(Request $request, string $date, array $scope, ?float $openingBalance, bool $forceManual, ?string $note = null): object
    {
        $existing = DB::table('finance_expense_reports')->where('outlet_id', $scope['outlet_id'])->where('business_date', $date)->first();
        $suggested = $this->openingPettyCash($date, $scope['outlet_id']);
        $now = now();
        $company = $this->financeScope->companyForOutlet($scope['outlet_id']);

        if ($existing) {
            $changes = [];
            if ($openingBalance !== null) {
                $changes['opening_balance'] = $openingBalance;
                $changes['opening_source'] = $forceManual ? 'manual' : ($existing->opening_source ?: 'overhandle');
            }
            if ($note !== null) $changes['note'] = $note;
            if ($company && $existing->company_code !== $company) $changes['company_code'] = $company;
            if ($changes !== []) {
                $changes['updated_by'] = (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null;
                $changes['updated_at'] = $now;
                DB::table('finance_expense_reports')->where('id', $existing->id)->update($changes);
                return DB::table('finance_expense_reports')->where('id', $existing->id)->first();
            }
            return $existing;
        }

        $id = (string) Str::ulid();
        DB::table('finance_expense_reports')->insert([
            'id' => $id,
            'company_code' => $company,
            'outlet_id' => $scope['outlet_id'],
            'business_date' => $date,
            'timezone' => $scope['timezone'],
            'opening_balance' => $openingBalance ?? $suggested,
            'opening_source' => $openingBalance !== null ? 'manual' : ($suggested > 0 ? 'overhandle' : 'manual'),
            'note' => $note ?? '',
            'created_by' => (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null,
            'updated_by' => (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('finance_expense_reports')->where('id', $id)->first();
    }

    private function buildPayload(string $date, array $scope, object $report): array
    {
        $items = DB::table('finance_expense_report_items')->where('report_id', $report->id)->orderBy('entry_time')->orderBy('created_at')->get();
        $balance = (float) $report->opening_balance;
        $rows = [[
            'id' => 'opening-balance', 'type' => 'opening_balance', 'date' => $date, 'time' => '00:00',
            'outlet' => $scope['label'], 'description' => 'Saldo Awal Petty Cash', 'account_id' => null,
            'coa_code' => null, 'coa_name' => null, 'debit' => $balance, 'credit' => 0, 'balance' => $balance,
            'marking' => null, 'note' => (string) ($report->note ?? ''), 'editable' => false,
        ]];

        foreach ($items as $item) {
            $credit = (float) $item->amount;
            $balance -= $credit;
            $rows[] = [
                'id' => (string) $item->id, 'type' => 'expense', 'date' => $date,
                'time' => substr((string) $item->entry_time, 0, 5), 'outlet' => $scope['label'],
                'description' => (string) $item->description, 'account_id' => $item->account_id ? (string) $item->account_id : null,
                'coa_code' => (string) ($item->coa_code ?? ''), 'coa_name' => (string) ($item->coa_name ?? ''),
                'debit' => 0, 'credit' => $credit, 'balance' => $balance, 'marking' => (string) $item->marking,
                'source_type' => 'EXPENSE_REPORT', 'source_key' => 'PETTY_CASH_EXPENSE_ITEM:'.(string) $item->id,
                'general_posting_id' => property_exists($item, 'general_posting_id') && $item->general_posting_id ? (string) $item->general_posting_id : null,
                'posted_at' => property_exists($item, 'posted_at') && $item->posted_at ? (string) $item->posted_at : null,
                'note' => (string) ($item->note ?? ''),
                'editable' => ! (property_exists($item, 'general_posting_id') && $item->general_posting_id),
            ];
        }

        return [
            'date' => $date,
            'scope' => $scope,
            'report' => [
                'id' => (string) $report->id,
                'company_code' => $report->company_code ? (string) $report->company_code : null,
                'opening_balance' => (float) $report->opening_balance,
                'opening_source' => (string) ($report->opening_source ?? ''),
                'suggested_opening_balance' => $this->openingPettyCash($date, $scope['outlet_id']),
                'total_expense' => (float) $items->sum('amount'),
                'ending_balance' => $balance,
                'posting_ready_rows' => $items->count(),
            ],
            'rows' => $rows,
        ];
    }

    private function resolvePreviewScope(Request $request, string $rawScope): array
    {
        $authorized = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
        $authorizedIds = array_values(array_filter(array_map('strval', $authorized['outlet_ids'] ?? [])));
        $scope = strtoupper(trim($rawScope));

        if (str_starts_with($scope, 'OUTLET:')) {
            $id = substr($rawScope, 7);
            if (! in_array($id, $authorizedIds, true)) return ['label' => 'Outlet', 'outlet_ids' => []];
            $name = DB::table('outlets')->where('id', $id)->value('name');
            return ['label' => (string) ($name ?: 'Outlet'), 'outlet_ids' => [$id]];
        }

        if (in_array($scope, ['BKJB', 'MDMF'], true) && Schema::hasTable('finance_outlet_company_mappings')) {
            $ids = DB::table('finance_outlet_company_mappings')->where('company_code', $scope)->where('is_active', true)
                ->pluck('outlet_id')->map(fn ($id) => (string) $id)->filter(fn ($id) => in_array($id, $authorizedIds, true))->values()->all();
            return ['label' => 'PT '.$scope, 'outlet_ids' => $ids];
        }

        return ['label' => 'All Outlet', 'outlet_ids' => $authorizedIds];
    }

    private function openingPettyCash(string $date, string $outletId): float
    {
        if (! Schema::hasTable('finance_overhandle_reports')) return 0;
        return (float) (DB::table('finance_overhandle_reports')->where('outlet_id', $outletId)
            ->where('business_date', $date)->where('shift_type', 'opening')->value('petty_cash_amount') ?: 0);
    }

    private function resolveExpenseCoa(string $code): array
    {
        $row = DB::table('finance_chart_of_accounts')->where('code', trim($code))->first();
        if (! $row) {
            throw ValidationException::withMessages(['coa_code' => 'COA tidak ditemukan pada Chart of Account Finance.']);
        }
        if (! in_array((string) $row->account_type, ['EXPENSE', 'OTHER_EXPENSE'], true)) {
            throw ValidationException::withMessages(['coa_code' => 'Expense Report hanya dapat menggunakan COA bertipe EXPENSE / OTHER_EXPENSE.']);
        }
        if (property_exists($row, 'is_active') && ! $row->is_active) {
            throw ValidationException::withMessages(['coa_code' => 'COA sudah tidak aktif.']);
        }
        if (property_exists($row, 'is_postable') && ! $row->is_postable) {
            throw ValidationException::withMessages(['coa_code' => 'COA header/non-postable tidak dapat dipakai sebagai transaksi expense.']);
        }

        return ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name];
    }

    private function entryAtUtc(string $date, string $time, string $timezone): string
    {
        return CarbonImmutable::parse($date.' '.$time.':00', $timezone)->setTimezone('UTC')->toDateTimeString();
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
                throw ValidationException::withMessages(['outlet_filter' => 'Tidak ada outlet yang tersedia untuk Expense Report.']);
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
            throw ValidationException::withMessages(['outlet_filter' => 'Expense Report wajib menggunakan tepat satu outlet.']);
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

}
