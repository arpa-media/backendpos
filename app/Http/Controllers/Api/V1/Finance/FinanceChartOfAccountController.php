<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinanceChartOfAccountController extends Controller
{
    private const ACCOUNT_TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'COGS', 'EXPENSE', 'OTHER_INCOME', 'OTHER_EXPENSE', 'TAX'];
    private const NORMAL_BALANCES = ['DEBIT', 'CREDIT'];

    public function options()
    {
        return ApiResponse::ok([
            'account_types' => self::ACCOUNT_TYPES,
            'normal_balances' => self::NORMAL_BALANCES,
            'coas' => $this->catalog(),
            'legacy_seed_count' => is_file(database_path('data/finance_iter03_legacy_coa.php'))
                ? count(require database_path('data/finance_iter03_legacy_coa.php'))
                : 0,
        ]);
    }

    public function index(Request $request)
    {
        if (! Schema::hasTable('finance_chart_of_accounts')) {
            return ApiResponse::error('Tabel Chart of Account belum tersedia. Jalankan migration Iterasi 03.', 'MISSING_TABLE', 503);
        }

        $perPage = max(10, min(200, (int) $request->input('per_page', 25)));
        $query = DB::table('finance_chart_of_accounts as coa')
            ->leftJoin('finance_chart_of_accounts as parent', 'parent.id', '=', 'coa.parent_id')
            ->select(['coa.*', 'parent.code as parent_code', 'parent.name as parent_name']);

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('coa.code', 'like', "%{$search}%")
                    ->orWhere('coa.name', 'like', "%{$search}%");
            });
        }
        if ($request->filled('account_type')) {
            $query->where('coa.account_type', strtoupper((string) $request->input('account_type')));
        }
        if ($request->filled('is_active')) {
            $query->where('coa.is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderBy('coa.code')->paginate($perPage);
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($row) => $this->shape($row))->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $id = (string) Str::ulid();
        DB::table('finance_chart_of_accounts')->insert($this->payload($data) + [
            'id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ApiResponse::ok(['id' => $id], 'Chart of Account berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id)
    {
        $existing = DB::table('finance_chart_of_accounts')->where('id', $id)->first();
        if (! $existing) {
            return ApiResponse::error('Chart of Account tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $request->validate($this->rules($id));
        if (($data['parent_id'] ?? null) === $id) {
            return ApiResponse::error('COA tidak dapat menjadi parent untuk dirinya sendiri.', 'INVALID_PARENT', 422);
        }

        DB::table('finance_chart_of_accounts')->where('id', $id)->update($this->payload($data) + ['updated_at' => now()]);
        return ApiResponse::ok(['id' => $id], 'Chart of Account berhasil diperbarui.');
    }

    public function destroy(string $id)
    {
        $existing = DB::table('finance_chart_of_accounts')->where('id', $id)->first();
        if (! $existing) {
            return ApiResponse::ok(['id' => $id], 'Chart of Account sudah tidak tersedia.');
        }

        $used = DB::table('finance_journal_entry_lines')->where('account_id', $id)->exists()
            || DB::table('finance_posting_template_lines')->where('account_id', $id)->exists()
            || DB::table('finance_chart_of_accounts')->where('parent_id', $id)->exists();
        if ($used) {
            return ApiResponse::error('COA sudah direferensikan jurnal/template/child account. Nonaktifkan COA, jangan hapus.', 'COA_IN_USE', 422);
        }

        DB::table('finance_chart_of_accounts')->where('id', $id)->delete();
        return ApiResponse::ok(['id' => $id], 'Chart of Account berhasil dihapus.');
    }

    private function rules(?string $ignoreId = null): array
    {
        $unique = Rule::unique('finance_chart_of_accounts', 'code');
        if ($ignoreId) {
            $unique = $unique->ignore($ignoreId, 'id');
        }
        return [
            'code' => ['required', 'string', 'max:32', $unique],
            'name' => ['required', 'string', 'max:180'],
            'account_type' => ['required', Rule::in(self::ACCOUNT_TYPES)],
            'normal_balance' => ['required', Rule::in(self::NORMAL_BALANCES)],
            'parent_id' => ['nullable', 'string', 'size:26', 'exists:finance_chart_of_accounts,id'],
            'level_no' => ['nullable', 'integer', 'min:1', 'max:9'],
            'is_header' => ['nullable', 'boolean'],
            'is_postable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function payload(array $data): array
    {
        $isHeader = (bool) ($data['is_header'] ?? false);
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'account_type' => strtoupper((string) $data['account_type']),
            'normal_balance' => strtoupper((string) $data['normal_balance']),
            'parent_id' => $data['parent_id'] ?? null,
            'level_no' => (int) ($data['level_no'] ?? 1),
            'is_header' => $isHeader,
            'is_postable' => $isHeader ? false : (bool) ($data['is_postable'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function catalog(): array
    {
        if (! Schema::hasTable('finance_chart_of_accounts')) {
            return [];
        }
        return DB::table('finance_chart_of_accounts')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_postable', 'is_header'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function shape(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'account_type' => (string) $row->account_type,
            'normal_balance' => (string) $row->normal_balance,
            'parent_id' => $row->parent_id ? (string) $row->parent_id : null,
            'parent' => $row->parent_id ? ['code' => (string) $row->parent_code, 'name' => (string) $row->parent_name] : null,
            'level_no' => (int) $row->level_no,
            'is_header' => (bool) $row->is_header,
            'is_postable' => (bool) $row->is_postable,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
