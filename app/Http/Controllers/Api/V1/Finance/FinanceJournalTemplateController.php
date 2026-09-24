<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceFormulaEvaluator;
use App\Services\Finance\FinancePostingTemplateEngine;
use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FinanceJournalTemplateController extends Controller
{
    private const SOURCE_TYPES = [
        'MANUAL_JOURNAL',
        'RECONCILIATION',
        'SETTLEMENT',
        'COGS',
        'PURCHASING',
        'PAYROLL',
        'GENERAL',
    ];

    public function __construct(
        private readonly FinanceScopeResolver $scopeResolver,
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceFormulaEvaluator $formulaEvaluator,
    ) {
    }

    public function options()
    {
        return ApiResponse::ok([
            'companies' => $this->scopeResolver->companies(),
            'outlets' => $this->scopeResolver->outletMappings(true),
            'markings' => FinanceScopeResolver::MARKINGS,
            'source_types' => self::SOURCE_TYPES,
            'coas' => $this->coaOptions(),
            'formula_tokens' => [
                '{{amount}}', '{{subtotal}}', '{{tax}}', '{{discount}}', '{{rounding}}',
                '{{mdr}}', '{{admin_fee}}', '{{cogs}}', '{{payroll}}', '{{bonus}}', '{{gross_pay}}', '{{deductions}}', '{{net_pay}}', '{{payable}}',
            ],
        ]);
    }

    public function index(Request $request)
    {
        if (! Schema::hasTable('finance_posting_templates')) {
            return ApiResponse::error('Tabel Jurnal Template belum tersedia. Jalankan migration Iterasi 03.', 'MISSING_TABLE', 503);
        }

        $query = DB::table('finance_posting_templates as t')
            ->leftJoin('outlets as o', 'o.id', '=', 't.outlet_id')
            ->whereNull('t.deleted_at')
            ->when($request->filled('source_type'), fn ($q) => $q->where('t.source_type', strtoupper((string) $request->input('source_type'))))
            ->when($request->filled('company_code'), fn ($q) => $q->where('t.company_code', strtoupper((string) $request->input('company_code'))))
            ->when($request->filled('marking'), fn ($q) => $q->where('t.marking', strtoupper((string) $request->input('marking'))))
            ->when(trim((string) $request->input('q', '')) !== '', function ($q) use ($request): void {
                $search = trim((string) $request->input('q'));
                $q->where(function ($inner) use ($search): void {
                    $inner->where('t.code', 'like', "%{$search}%")->orWhere('t.name', 'like', "%{$search}%");
                });
            })
            ->orderBy('t.source_type')
            ->orderBy('t.code')
            ->get(['t.*', 'o.code as outlet_code', 'o.name as outlet_name']);

        $ids = $query->pluck('id')->all();
        $lines = empty($ids) ? collect() : DB::table('finance_posting_template_lines as l')
            ->join('finance_chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.template_id', $ids)
            ->orderBy('l.sort_order')
            ->get(['l.*', 'a.code as account_code', 'a.name as account_name', 'a.account_type', 'a.normal_balance'])
            ->groupBy('template_id');

        return ApiResponse::ok([
            'items' => $query->map(function ($row) use ($lines): array {
                $item = (array) $row;
                $item['is_active'] = (bool) $row->is_active;
                $item['lines'] = ($lines[$row->id] ?? collect())->map(fn ($line) => [
                    'id' => (string) $line->id,
                    'account_id' => (string) $line->account_id,
                    'account_code' => (string) $line->account_code,
                    'account_name' => (string) $line->account_name,
                    'side' => (string) $line->side,
                    'amount_formula' => (string) $line->amount_formula,
                    'memo_template' => $line->memo_template,
                    'sort_order' => (int) $line->sort_order,
                ])->values()->all();
                return $item;
            })->all(),
        ]);
    }

    public function store(Request $request)
    {
        return $this->persist($request);
    }

    public function update(Request $request, string $id)
    {
        if (! DB::table('finance_posting_templates')->where('id', $id)->whereNull('deleted_at')->exists()) {
            return ApiResponse::error('Jurnal Template tidak ditemukan.', 'NOT_FOUND', 404);
        }
        return $this->persist($request, $id);
    }

    public function destroy(string $id)
    {
        $template = DB::table('finance_posting_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) {
            return ApiResponse::ok(['id' => $id], 'Jurnal Template sudah tidak tersedia.');
        }
        if (Schema::hasColumn('finance_posting_templates', 'is_system') && (bool) ($template->is_system ?? false)) {
            return ApiResponse::error('System Journal Template tidak dapat dihapus. Nonaktifkan atau ubah mapping COA-nya bila diperlukan.', 'SYSTEM_TEMPLATE_LOCKED', 422);
        }
        DB::table('finance_posting_templates')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return ApiResponse::ok(['id' => $id], 'Jurnal Template berhasil dinonaktifkan.');
    }

    public function preview(Request $request, string $id)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'subtotal' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'rounding' => ['nullable', 'numeric'],
            'mdr' => ['nullable', 'numeric'],
            'admin_fee' => ['nullable', 'numeric'],
            'cogs' => ['nullable', 'numeric'],
            'payroll' => ['nullable', 'numeric'],
            'payable' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $template = DB::table('finance_posting_templates')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $template) {
                return ApiResponse::error('Jurnal Template tidak ditemukan.', 'NOT_FOUND', 404);
            }
            $context = $data + [
                'company_code' => $template->company_code,
                'outlet_id' => $template->outlet_id,
                'marking' => $template->marking,
            ];
            return ApiResponse::ok($this->templateEngine->preview($id, (string) $template->source_type, $context), 'Preview template berhasil dibuat.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TEMPLATE_PREVIEW_FAILED', 422);
        }
    }

    private function persist(Request $request, ?string $id = null)
    {
        $request->merge([
            'company_code' => $this->nullableUpper($request->input('company_code')),
            'outlet_id' => $this->nullableString($request->input('outlet_id')),
            'marking' => $this->nullableUpper($request->input('marking')),
            'source_type' => strtoupper(trim((string) $request->input('source_type'))),
        ]);

        $unique = Rule::unique('finance_posting_templates', 'code')->whereNull('deleted_at');
        if ($id) {
            $unique = $unique->ignore($id, 'id');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', $unique],
            'name' => ['required', 'string', 'max:160'],
            'source_type' => ['required', Rule::in(self::SOURCE_TYPES)],
            'company_code' => ['nullable', Rule::in(FinanceScopeResolver::COMPANIES)],
            'outlet_id' => ['nullable', 'string', 'size:26', 'exists:outlets,id'],
            'marking' => ['nullable', Rule::in(FinanceScopeResolver::MARKINGS)],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'string', 'size:26', 'exists:finance_chart_of_accounts,id'],
            'lines.*.side' => ['required', Rule::in(['DEBIT', 'CREDIT'])],
            'lines.*.amount_formula' => ['required', 'string', 'max:180'],
            'lines.*.memo_template' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            if ($data['outlet_id'] ?? null) {
                $scope = $this->scopeResolver->resolve($data['company_code'] ?? null, $data['outlet_id']);
                $data['company_code'] = $scope['company_code'];
            }

            $sides = collect($data['lines'])->pluck('side')->map(fn ($side) => strtoupper((string) $side));
            if (! $sides->contains('DEBIT') || ! $sides->contains('CREDIT')) {
                throw new InvalidArgumentException('Template wajib memiliki minimal 1 baris DEBIT dan 1 baris CREDIT.');
            }

            $sampleContext = [
                'amount' => 100, 'subtotal' => 100, 'tax' => 10, 'discount' => 5, 'rounding' => 1,
                'mdr' => 2, 'admin_fee' => 1, 'cogs' => 50, 'payroll' => 100, 'bonus' => 100,
                'gross_pay' => 100, 'deductions' => 10, 'net_pay' => 90, 'payable' => 90,
            ];
            foreach ($data['lines'] as $index => $line) {
                $this->formulaEvaluator->evaluate((string) $line['amount_formula'], $sampleContext);
                $account = DB::table('finance_chart_of_accounts')->where('id', $line['account_id'])->first(['is_active', 'is_postable']);
                if (! $account || ! $account->is_active || ! $account->is_postable) {
                    throw new InvalidArgumentException('COA baris '.($index + 1).' harus aktif dan postable.');
                }
            }
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TEMPLATE_VALIDATION_FAILED', 422);
        }

        $templateId = $id ?: (string) Str::ulid();
        DB::transaction(function () use ($data, $templateId, $id, $request): void {
            $existing = $id ? DB::table('finance_posting_templates')->where('id', $id)->first() : null;
            $payload = [
                'code' => strtoupper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'source_type' => strtoupper((string) $data['source_type']),
                'company_code' => $data['company_code'] ?? null,
                'outlet_id' => $data['outlet_id'] ?? null,
                'marking' => $data['marking'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by_user_id' => $request->user()?->id,
                'updated_at' => now(),
            ];

            if ($id) {
                DB::table('finance_posting_templates')->where('id', $id)->update($payload);
                DB::table('finance_posting_template_lines')->where('template_id', $id)->delete();
            } else {
                DB::table('finance_posting_templates')->insert($payload + [
                    'id' => $templateId,
                    'created_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                    'deleted_at' => null,
                ]);
            }

            $now = now();
            DB::table('finance_posting_template_lines')->insert(collect($data['lines'])->values()->map(fn ($line, $index) => [
                'id' => (string) Str::ulid(),
                'template_id' => $templateId,
                'sort_order' => $index + 1,
                'account_id' => $line['account_id'],
                'side' => strtoupper((string) $line['side']),
                'amount_formula' => trim((string) $line['amount_formula']),
                'memo_template' => $this->nullableString($line['memo_template'] ?? null),
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        return ApiResponse::ok(['id' => $templateId], $id ? 'Jurnal Template berhasil diperbarui.' : 'Jurnal Template berhasil dibuat.', $id ? 200 : 201);
    }

    private function coaOptions(): array
    {
        if (! Schema::hasTable('finance_chart_of_accounts')) {
            return [];
        }
        return DB::table('finance_chart_of_accounts')
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : strtoupper($value);
    }
}
