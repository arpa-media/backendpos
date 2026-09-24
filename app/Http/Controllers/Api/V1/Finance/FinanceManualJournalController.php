<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceJournalService;
use App\Services\Finance\FinanceManualJournalUnifiedPostingService;
use App\Services\Finance\FinancePostingTemplateEngine;
use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FinanceManualJournalController extends Controller
{
    public function __construct(
        private readonly FinanceJournalService $journalService,
        private readonly FinanceManualJournalUnifiedPostingService $unifiedManual,
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceScopeResolver $scopeResolver,
    ) {
    }

    public function options()
    {
        return ApiResponse::ok([
            'companies' => $this->scopeResolver->companies(),
            'outlets' => array_values(array_filter($this->scopeResolver->outletMappings(true), fn (array $row) => $row['is_mapped'])),
            'markings' => FinanceScopeResolver::MARKINGS,
            'statuses' => ['DRAFT', 'POSTED', 'REVERSED'],
            'coas' => $this->coaOptions(),
            'templates' => Schema::hasTable('finance_posting_templates')
                ? DB::table('finance_posting_templates')->whereNull('deleted_at')->where('is_active', true)
                    ->where(function ($q): void {
                        $q->where('source_type', 'MANUAL_JOURNAL');
                        if (Schema::hasColumn('finance_posting_templates', 'manual_selectable')) {
                            $q->orWhere('manual_selectable', true);
                        }
                    })
                    ->orderByRaw("CASE WHEN source_type = 'MANUAL_JOURNAL' THEN 0 ELSE 1 END")
                    ->orderBy('source_type')->orderBy('code')
                    ->get(['id', 'code', 'name', 'source_type', 'company_code', 'outlet_id', 'marking'])
                    ->map(fn ($row) => (array) $row)->all()
                : [],
        ]);
    }

    public function index(Request $request)
    {
        $this->guardTables();
        $perPage = max(10, min(100, (int) $request->input('per_page', 20)));
        $query = DB::table('finance_journal_entries as j')
            ->leftJoin('outlets as o', 'o.id', '=', 'j.outlet_id')
            ->where('j.source_type', 'MANUAL')
            ->when($request->filled('company_code'), fn ($q) => $q->where('j.company_code', strtoupper((string) $request->input('company_code'))))
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('j.outlet_id', (string) $request->input('outlet_id')))
            ->when($request->filled('marking'), fn ($q) => $q->where('j.marking', strtoupper((string) $request->input('marking'))))
            ->when($request->filled('status'), fn ($q) => $q->where('j.status', strtoupper((string) $request->input('status'))))
            ->when($request->filled('date_from'), fn ($q) => $q->where('j.journal_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('j.journal_date', '<=', $request->input('date_to')))
            ->when(trim((string) $request->input('q', '')) !== '', function ($q) use ($request): void {
                $search = trim((string) $request->input('q'));
                $q->where(function ($inner) use ($search): void {
                    $inner->where('j.journal_no', 'like', "%{$search}%")
                        ->orWhere('j.reference_no', 'like', "%{$search}%")
                        ->orWhere('j.description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('j.journal_date')
            ->orderByDesc('j.created_at')
            ->select(['j.*', 'o.name as outlet_name', 'o.code as outlet_code']);

        $paginator = $query->paginate($perPage);
        $ids = collect($paginator->items())->pluck('id')->all();
        $lines = $this->linesForEntries($ids);
        $reversalNos = $this->reversalNos(collect($paginator->items())->pluck('reversal_journal_id')->filter()->all());

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($row) => $this->shapeEntry($row, $lines[$row->id] ?? [], $reversalNos[(string) ($row->reversal_journal_id ?? '')] ?? null))->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $this->guardTables();
        $row = DB::table('finance_journal_entries as j')
            ->leftJoin('outlets as o', 'o.id', '=', 'j.outlet_id')
            ->where('j.id', $id)
            ->first(['j.*', 'o.name as outlet_name', 'o.code as outlet_code']);
        if (! $row) {
            return ApiResponse::error('Jurnal tidak ditemukan.', 'NOT_FOUND', 404);
        }
        return ApiResponse::ok($this->shapeEntry($row, $this->linesForEntries([$id])[$id] ?? [], null));
    }

    public function store(Request $request)
    {
        $payload = $this->validatedPayload($request);
        try {
            $id = $this->journalService->createManual($payload, $request->user()?->id);
            return ApiResponse::ok(['id' => $id], 'Draft Manual Journal berhasil dibuat.', 201);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'JOURNAL_VALIDATION_FAILED', 422);
        }
    }

    public function update(Request $request, string $id)
    {
        $payload = $this->validatedPayload($request);
        try {
            $id = $this->journalService->updateManual($id, $payload, $request->user()?->id);
            return ApiResponse::ok(['id' => $id], 'Draft Manual Journal berhasil diperbarui.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'JOURNAL_UPDATE_FAILED', 422);
        }
    }

    public function post(Request $request, string $id)
    {
        try {
            $result = $this->unifiedManual->postDraft($id, $request->user()?->id);
            return ApiResponse::ok(
                ['id' => $id] + $result,
                'Manual Journal berhasil diposting melalui General Posting. Draft manual dipindahkan ke audit layer General Posting.'
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'JOURNAL_POST_FAILED', 422);
        }
    }

    public function reverse(Request $request, string $id)
    {
        $data = $request->validate([
            'reversal_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $result = $this->unifiedManual->reversePosted(
                $id,
                trim((string) ($data['reason'] ?? '')) ?: 'Reversal Manual Journal melalui General Posting',
                $request->user()?->id
            );
            return ApiResponse::ok(
                ['id' => $id] + $result,
                'Manual Journal berhasil di-Unpost melalui General Posting. Reversal memakai tanggal jurnal asal sebagai canonical reset period.'
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'JOURNAL_REVERSAL_FAILED', 422);
        }
    }

    public function destroy(string $id)
    {
        try {
            $this->journalService->deleteDraft($id);
            return ApiResponse::ok(['id' => $id], 'Draft Manual Journal berhasil dihapus.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'JOURNAL_DELETE_FAILED', 422);
        }
    }

    public function previewTemplate(Request $request)
    {
        $data = $request->validate([
            'template_id' => ['required', 'string', 'size:26'],
            'company_code' => ['nullable', Rule::in(FinanceScopeResolver::COMPANIES)],
            'outlet_id' => ['nullable', 'string', 'size:26', 'exists:outlets,id'],
            'marking' => ['nullable', Rule::in(FinanceScopeResolver::MARKINGS)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'subtotal' => ['nullable', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'rounding' => ['nullable', 'numeric'],
            'mdr' => ['nullable', 'numeric'],
            'admin_fee' => ['nullable', 'numeric'],
            'cogs' => ['nullable', 'numeric'],
            'payroll' => ['nullable', 'numeric'],
            'bonus' => ['nullable', 'numeric'],
            'gross_pay' => ['nullable', 'numeric'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
            'net_pay' => ['nullable', 'numeric'],
            'payable' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_no' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $template = DB::table('finance_posting_templates')
                ->where('id', $data['template_id'])
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();
            if (! $template) {
                throw new InvalidArgumentException('Jurnal Template tidak ditemukan atau sudah tidak aktif.');
            }

            $manualSelectable = strtoupper((string) ($template->source_type ?? '')) === 'MANUAL_JOURNAL'
                || (Schema::hasColumn('finance_posting_templates', 'manual_selectable') && (bool) ($template->manual_selectable ?? false));
            if (! $manualSelectable) {
                throw new InvalidArgumentException('Template ini tidak diizinkan untuk Manual Journal.');
            }

            // I06: a selected template is allowed to provide its own scope. The old
            // validation required PT/outlet before the template was even resolved,
            // which made valid scoped templates fail Preview & Terapkan.
            $outletId = trim((string) ($data['outlet_id'] ?? '')) ?: (trim((string) ($template->outlet_id ?? '')) ?: null);
            $companyCode = strtoupper(trim((string) ($data['company_code'] ?? '')))
                ?: (strtoupper(trim((string) ($template->company_code ?? ''))) ?: null);
            $marking = strtoupper(trim((string) ($data['marking'] ?? '')))
                ?: (strtoupper(trim((string) ($template->marking ?? ''))) ?: 'MARKING');

            if (! $companyCode && ! $outletId) {
                throw new InvalidArgumentException('Pilih PT/outlet atau gunakan template yang sudah memiliki scope PT/outlet.');
            }

            $scope = $this->scopeResolver->resolve($companyCode, $outletId);
            $amount = (float) $data['amount'];
            $data['gross_pay'] = (float) ($data['gross_pay'] ?? $amount);
            $data['deductions'] = (float) ($data['deductions'] ?? 0);
            $data['net_pay'] = (float) ($data['net_pay'] ?? max(0, $data['gross_pay'] - $data['deductions']));
            $data['payable'] = (float) ($data['payable'] ?? $data['net_pay']);
            $data['payroll'] = (float) ($data['payroll'] ?? $data['gross_pay']);
            $data['bonus'] = (float) ($data['bonus'] ?? $amount);
            $data['cogs'] = (float) ($data['cogs'] ?? $amount);
            $data['marking'] = $marking;

            $preview = $this->templateEngine->preview($data['template_id'], 'MANUAL_JOURNAL', $data + $scope);
            $preview['scope'] = [
                'company_code' => $scope['company_code'] ?? $companyCode,
                'outlet_id' => $scope['outlet_id'] ?? $outletId,
                'marking' => $marking,
            ];
            return ApiResponse::ok($preview, 'Preview template jurnal berhasil dibuat.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'TEMPLATE_PREVIEW_FAILED', 422);
        }
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'journal_date' => ['required', 'date'],
            'business_date' => ['nullable', 'date'],
            'company_code' => ['required_without:outlet_id', 'nullable', Rule::in(FinanceScopeResolver::COMPANIES)],
            'outlet_id' => ['nullable', 'string', 'size:26', 'exists:outlets,id'],
            'marking' => ['required', Rule::in(FinanceScopeResolver::MARKINGS)],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'string', 'size:26', 'exists:finance_chart_of_accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);
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

    private function linesForEntries(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return DB::table('finance_journal_entry_lines')
            ->whereIn('journal_entry_id', $ids)
            ->orderBy('line_no')
            ->get()
            ->groupBy('journal_entry_id')
            ->map(fn ($rows) => $rows->map(fn ($line) => [
                'id' => (string) $line->id,
                'account_id' => (string) $line->account_id,
                'account_code' => (string) $line->account_code,
                'account_name' => (string) $line->account_name,
                'account_type' => (string) $line->account_type,
                'normal_balance' => (string) $line->normal_balance,
                'description' => $line->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ])->values()->all())
            ->all();
    }

    private function reversalNos(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        return DB::table('finance_journal_entries')->whereIn('id', $ids)->pluck('journal_no', 'id')->map(fn ($value) => (string) $value)->all();
    }

    private function shapeEntry(object $row, array $lines, ?string $reversalNo): array
    {
        return [
            'id' => (string) $row->id,
            'journal_no' => (string) $row->journal_no,
            'journal_date' => (string) $row->journal_date,
            'business_date' => (string) $row->business_date,
            'company_code' => (string) $row->company_code,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'outlet_name' => $row->outlet_name ?? null,
            'outlet_code' => $row->outlet_code ?? null,
            'marking' => (string) $row->marking,
            'source_type' => (string) $row->source_type,
            'reference_no' => $row->reference_no,
            'description' => $row->description,
            'status' => (string) $row->status,
            'total_debit' => (float) $row->total_debit,
            'total_credit' => (float) $row->total_credit,
            'posted_at' => $row->posted_at,
            'reversed_at' => $row->reversed_at,
            'reversal_journal_id' => $row->reversal_journal_id ? (string) $row->reversal_journal_id : null,
            'reversal_journal_no' => $reversalNo,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'lines' => $lines,
        ];
    }

    private function guardTables(): void
    {
        foreach (['finance_journal_entries', 'finance_journal_entry_lines', 'finance_chart_of_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException("Tabel {$table} belum tersedia. Jalankan migration Finance Iterasi 03.");
            }
        }
    }
}
