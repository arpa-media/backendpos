<?php

namespace App\Services\Warehouse\FinanceV4;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseFinanceFoundationV4Service
{
    public function __construct(private readonly WarehouseGeneralPostingEngine $engine)
    {
    }

    public function options(array $allowedWarehouseIds): array
    {
        $warehouses = DB::table('outlets')
            ->whereIn('id', $allowedWarehouseIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])->values()->all();

        return [
            'warehouses' => $warehouses,
            'coa' => $this->coaList(['active_only' => true]),
            'templates' => $this->templateList(['active_only' => true]),
            'account_types' => ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'],
            'normal_balances' => ['DEBIT', 'CREDIT'],
            'posting_statuses' => ['DRAFT', 'POSTED', 'REVERSED'],
        ];
    }

    public function coaList(array $filters = []): array
    {
        $q = DB::table('wh_v4_finance_coa');

        if (! empty($filters['active_only'])) {
            $q->where('is_active', true);
        }
        if (! empty($filters['q'])) {
            $search = '%'.trim((string) $filters['q']).'%';
            $q->where(fn ($x) => $x->where('code', 'like', $search)->orWhere('name', 'like', $search));
        }

        return $q->orderBy('sort_order')->orderBy('code')->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'account_type' => (string) $row->account_type,
            'normal_balance' => (string) $row->normal_balance,
            'is_header' => (bool) $row->is_header,
            'is_postable' => (bool) $row->is_postable,
            'is_system' => (bool) $row->is_system,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
            'description' => $row->description,
        ])->values()->all();
    }

    public function createCoa(array $payload, string $userId): array
    {
        $code = strtoupper(trim((string) $payload['code']));
        if (DB::table('wh_v4_finance_coa')->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['Kode COA Warehouse sudah digunakan.']]);
        }

        $id = (string) Str::ulid();
        DB::table('wh_v4_finance_coa')->insert([
            'id' => $id,
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'account_type' => strtoupper((string) $payload['account_type']),
            'normal_balance' => strtoupper((string) $payload['normal_balance']),
            'is_header' => (bool) ($payload['is_header'] ?? false),
            'is_postable' => (bool) ($payload['is_postable'] ?? true),
            'is_system' => false,
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'description' => $payload['description'] ?? null,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return collect($this->coaList())->firstWhere('id', $id) ?? [];
    }

    public function updateCoa(string $id, array $payload, string $userId): array
    {
        $row = DB::table('wh_v4_finance_coa')->where('id', $id)->first();
        if (! $row) {
            abort(404, 'COA Warehouse tidak ditemukan.');
        }

        $code = strtoupper(trim((string) $payload['code']));
        if (DB::table('wh_v4_finance_coa')->where('code', $code)->where('id', '!=', $id)->exists()) {
            throw ValidationException::withMessages(['code' => ['Kode COA Warehouse sudah digunakan.']]);
        }

        DB::table('wh_v4_finance_coa')->where('id', $id)->update([
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'account_type' => strtoupper((string) $payload['account_type']),
            'normal_balance' => strtoupper((string) $payload['normal_balance']),
            'is_header' => (bool) ($payload['is_header'] ?? false),
            'is_postable' => (bool) ($payload['is_postable'] ?? true),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'description' => $payload['description'] ?? null,
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ]);

        return collect($this->coaList())->firstWhere('id', $id) ?? [];
    }

    public function templateList(array $filters = []): array
    {
        $q = DB::table('wh_v4_finance_posting_templates');
        if (! empty($filters['active_only'])) {
            $q->where('is_active', true);
        }
        if (! empty($filters['q'])) {
            $search = '%'.trim((string) $filters['q']).'%';
            $q->where(fn ($x) => $x->where('code', 'like', $search)->orWhere('name', 'like', $search)->orWhere('source_type', 'like', $search));
        }

        $templates = $q->orderBy('source_type')->orderBy('code')->get();

        $ids = $templates->pluck('id')->map(fn ($id) => (string) $id)->all();
        $lines = $ids === [] ? collect() : DB::table('wh_v4_finance_posting_template_lines as l')
            ->join('wh_v4_finance_coa as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.template_id', $ids)
            ->orderBy('l.sort_order')
            ->get([
                'l.id', 'l.template_id', 'l.sort_order', 'l.account_id', 'l.side',
                'l.amount_key', 'l.multiplier', 'l.memo_template',
                'a.code as account_code', 'a.name as account_name',
            ])->groupBy(fn ($row) => (string) $row->template_id);

        return $templates->map(function ($row) use ($lines) {
            return [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'source_type' => (string) $row->source_type,
                'description' => $row->description,
                'is_system' => (bool) $row->is_system,
                'is_active' => (bool) $row->is_active,
                'lines' => collect($lines->get((string) $row->id, []))->map(fn ($line) => [
                    'id' => (string) $line->id,
                    'sort_order' => (int) $line->sort_order,
                    'account_id' => (string) $line->account_id,
                    'account_code' => (string) $line->account_code,
                    'account_name' => (string) $line->account_name,
                    'side' => (string) $line->side,
                    'amount_key' => (string) $line->amount_key,
                    'multiplier' => (float) $line->multiplier,
                    'memo_template' => $line->memo_template,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    public function createTemplate(array $payload, string $userId): array
    {
        return DB::transaction(function () use ($payload, $userId): array {
            $code = strtoupper(trim((string) $payload['code']));
            if (DB::table('wh_v4_finance_posting_templates')->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => ['Kode Template Posting Warehouse sudah digunakan.']]);
            }

            $id = (string) Str::ulid();
            DB::table('wh_v4_finance_posting_templates')->insert([
                'id' => $id,
                'code' => $code,
                'name' => trim((string) $payload['name']),
                'source_type' => strtoupper(trim((string) $payload['source_type'])),
                'description' => $payload['description'] ?? null,
                'is_system' => false,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->replaceTemplateLines($id, $payload['lines'], $userId);

            return collect($this->templateList())->firstWhere('id', $id) ?? [];
        }, 5);
    }

    public function updateTemplate(string $id, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id, $payload, $userId): array {
            $row = DB::table('wh_v4_finance_posting_templates')->where('id', $id)->lockForUpdate()->first();
            if (! $row) {
                abort(404, 'Template Posting Warehouse tidak ditemukan.');
            }

            $code = strtoupper(trim((string) $payload['code']));
            if (DB::table('wh_v4_finance_posting_templates')->where('code', $code)->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['code' => ['Kode Template Posting Warehouse sudah digunakan.']]);
            }

            DB::table('wh_v4_finance_posting_templates')->where('id', $id)->update([
                'code' => $code,
                'name' => trim((string) $payload['name']),
                'source_type' => strtoupper(trim((string) $payload['source_type'])),
                'description' => $payload['description'] ?? null,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            $this->replaceTemplateLines($id, $payload['lines'], $userId);

            return collect($this->templateList())->firstWhere('id', $id) ?? [];
        }, 5);
    }

    public function postingList(array $allowedWarehouseIds, array $filters = []): array
    {
        $q = DB::table('wh_v4_finance_general_postings as p')
            ->leftJoin('outlets as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('wh_v4_finance_posting_templates as t', 't.id', '=', 'p.template_id')
            ->whereIn('p.warehouse_id', $allowedWarehouseIds);

        if (! empty($filters['status'])) {
            $q->where('p.status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['source_type'])) {
            $q->where('p.source_type', strtoupper((string) $filters['source_type']));
        }
        if (! empty($filters['date_from'])) {
            $q->where('p.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('p.business_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['q'])) {
            $search = '%'.trim((string) $filters['q']).'%';
            $q->where(fn ($x) => $x
                ->where('p.posting_no', 'like', $search)
                ->orWhere('p.reference_no', 'like', $search)
                ->orWhere('p.description', 'like', $search)
                ->orWhere('p.source_type', 'like', $search));
        }

        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $summaryQuery = clone $q;
        $summary = $summaryQuery->selectRaw('COUNT(*) as posting_count')
            ->selectRaw("SUM(CASE WHEN p.status='POSTED' THEN p.total_debit ELSE 0 END) as posted_debit")
            ->selectRaw("SUM(CASE WHEN p.status='POSTED' THEN p.total_credit ELSE 0 END) as posted_credit")
            ->selectRaw("SUM(CASE WHEN p.status='DRAFT' THEN 1 ELSE 0 END) as draft_count")
            ->first();

        $paginator = $q->select(
                'p.*',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                't.code as template_code',
                't.name as template_name',
            )
            ->orderByDesc('p.business_date')
            ->orderByDesc('p.created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($paginator->items())->map(fn ($row) => [
                'id' => (string) $row->id,
                'posting_no' => (string) $row->posting_no,
                'source_type' => (string) $row->source_type,
                'source_id' => $row->source_id,
                'reference_no' => $row->reference_no,
                'warehouse_id' => (string) $row->warehouse_id,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_name' => $row->warehouse_name,
                'business_date' => (string) $row->business_date,
                'journal_date' => (string) $row->journal_date,
                'template_code' => $row->template_code,
                'template_name' => $row->template_name,
                'description' => $row->description,
                'status' => (string) $row->status,
                'total_debit' => (float) $row->total_debit,
                'total_credit' => (float) $row->total_credit,
                'reversal_of_posting_id' => $row->reversal_of_posting_id,
                'reversal_posting_id' => $row->reversal_posting_id,
                'posted_at' => $row->posted_at,
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'posting_count' => (int) ($summary->posting_count ?? 0),
                'draft_count' => (int) ($summary->draft_count ?? 0),
                'posted_debit' => (float) ($summary->posted_debit ?? 0),
                'posted_credit' => (float) ($summary->posted_credit ?? 0),
            ],
        ];
    }

    public function createManualPosting(string $warehouseId, array $payload, string $userId): array
    {
        return $this->engine->createManualDraft($warehouseId, $payload, $userId);
    }

    public function createTemplatePosting(string $warehouseId, string $templateCode, array $payload, string $userId): array
    {
        return $this->engine->createFromTemplate($warehouseId, $templateCode, $payload, $userId);
    }

    public function post(string $postingId, array $allowedWarehouseIds, string $userId): array
    {
        return $this->engine->post($postingId, $allowedWarehouseIds, $userId);
    }

    public function reverse(string $postingId, array $allowedWarehouseIds, string $reason, string $userId): array
    {
        return $this->engine->reverse($postingId, $allowedWarehouseIds, $reason, $userId);
    }

    public function detail(string $postingId, array $allowedWarehouseIds): array
    {
        return $this->engine->detail($postingId, $allowedWarehouseIds);
    }

    private function replaceTemplateLines(string $templateId, array $lines, string $userId): void
    {
        $accountIds = collect($lines)->pluck('account_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $accounts = DB::table('wh_v4_finance_coa')
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $balanceByKey = [];
        foreach ($lines as $index => $line) {
            if (! in_array((string) $line['account_id'], $accounts, true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account_id" => ['COA aktif dan postable wajib digunakan.'],
                ]);
            }

            $key = trim((string) $line['amount_key']);
            $side = strtoupper((string) $line['side']);
            $multiplier = (float) ($line['multiplier'] ?? 1);
            $balanceByKey[$key] ??= ['DEBIT' => 0.0, 'CREDIT' => 0.0];
            $balanceByKey[$key][$side] += $multiplier;
        }

        foreach ($balanceByKey as $key => $totals) {
            if (abs((float) $totals['DEBIT'] - (float) $totals['CREDIT']) > 0.000001) {
                throw ValidationException::withMessages([
                    'lines' => ["Template tidak balance untuk amount_key '{$key}'. Multiplier debit dan credit harus sama."],
                ]);
            }
        }

        DB::table('wh_v4_finance_posting_template_lines')->where('template_id', $templateId)->delete();
        $now = now();

        foreach ($lines as $index => $line) {
            DB::table('wh_v4_finance_posting_template_lines')->insert([
                'id' => (string) Str::ulid(),
                'template_id' => $templateId,
                'sort_order' => $index + 1,
                'account_id' => (string) $line['account_id'],
                'side' => strtoupper((string) $line['side']),
                'amount_key' => trim((string) $line['amount_key']),
                'multiplier' => (float) ($line['multiplier'] ?? 1),
                'memo_template' => $line['memo_template'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
