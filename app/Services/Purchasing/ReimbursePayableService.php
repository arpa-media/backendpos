<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Services\Finance\FinanceGeneralPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ReimbursePayableService
{
    public function __construct(
        private readonly FinanceGeneralPostingService $generalPosting,
    ) {
    }

    /** @return array<string,mixed> */
    public function ensureFromExecution(string $reimbursePaymentId, ?User $actor = null): array
    {
        return DB::transaction(function () use ($reimbursePaymentId, $actor): array {
            $payment = DB::table('pur_reimburse_payments')
                ->where('id', $reimbursePaymentId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (! $payment) {
                throw ValidationException::withMessages(['reimburse_payment_id' => 'Reimburse Payment tidak ditemukan.']);
            }
            if ((string) $payment->status !== 'POSTED') {
                throw ValidationException::withMessages(['status' => 'Reimburse Payment harus POSTED sebelum masuk Account Payable.']);
            }
            if ((float) ($payment->actual_total_amount ?? 0) <= 0) {
                throw ValidationException::withMessages(['actual_total_amount' => 'Nominal aktual Reimburse Payment belum valid.']);
            }
            if (! $payment->general_posting_id || ! $payment->posting_template_id) {
                throw ValidationException::withMessages(['posting_template_id' => 'General Posting DRAFT / Posting Template realisasi belum tersedia.']);
            }

            $existing = DB::table('pur_reimburse_payables')
                ->where('reimburse_payment_id', $reimbursePaymentId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->syncExecutionLink($payment, $existing);
                return $this->serialize($existing);
            }

            $order = DB::table('pur_reimburse_orders')->where('id', $payment->order_id)->first();
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => 'Reimburse Order sumber tidak ditemukan.']);
            }

            $date = (string) ($payment->realization_date ?: $payment->document_date ?: now()->toDateString());
            $company = strtoupper(trim((string) ($payment->company_code ?? '')));
            if (! in_array($company, ['BKJB', 'MDMF'], true)) {
                throw ValidationException::withMessages(['company_code' => 'PT realisasi reimburse harus BKJB atau MDMF.']);
            }
            $marking = strtoupper(trim((string) ($payment->marking ?: 'UNMARKING')));
            if (! in_array($marking, ['MARKING', 'UNMARKING'], true)) {
                throw ValidationException::withMessages(['marking' => 'Marking reimburse tidak valid.']);
            }

            $id = (string) Str::ulid();
            $amount = round((float) $payment->actual_total_amount, 2);
            DB::table('pur_reimburse_payables')->insert([
                'id' => $id,
                'payable_number' => $this->nextNumber(),
                'reimburse_payment_id' => $reimbursePaymentId,
                'reimburse_order_id' => (string) $payment->order_id,
                'fund_request_id' => $payment->fund_request_id ?: null,
                'outlet_id' => $payment->outlet_id ?: null,
                'company_code' => $company,
                'marking' => $marking,
                'payee_name' => trim((string) ($order->counterparty_name ?? '')) ?: 'Pemohon Reimburse',
                'payment_destination' => trim((string) ($order->payment_destination ?? '')) ?: null,
                'document_date' => $date,
                'due_date' => $date,
                'total_amount' => $amount,
                'balance_due' => $amount,
                'status' => 'WAITING_PAYMENT',
                'general_posting_id' => (string) $payment->general_posting_id,
                'posting_template_id' => (string) $payment->posting_template_id,
                'created_by_user_id' => $actor?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('pur_reimburse_payables')->where('id', $id)->first();
            $this->syncExecutionLink($payment, $row);
            $this->appendEvent($payment, $row, 'REIMBURSE_AP_CREATED', 'Reimburse masuk Account Payable', 'WAITING_PAYMENT', $actor);

            return $this->serialize($row);
        }, 3);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginate(array $filters): array
    {
        $query = DB::table('pur_reimburse_payables as p')
            ->leftJoin('outlets as o', 'o.id', '=', 'p.outlet_id')
            ->leftJoin('pur_reimburse_payments as rp', 'rp.id', '=', 'p.reimburse_payment_id')
            ->leftJoin('pur_reimburse_orders as ro', 'ro.id', '=', 'p.reimburse_order_id')
            ->select([
                'p.*', 'o.code as outlet_code', 'o.name as outlet_name',
                'rp.payment_number as reimburse_payment_number',
                'ro.reimburse_order_number as reimburse_order_number',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('p.status', strtoupper((string) $v)))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('p.outlet_id', $v))
            ->when($filters['due_to'] ?? null, fn ($q, $v) => $q->whereDate('p.due_date', '<=', $v))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($q) use ($filters): void {
                $like = '%'.trim((string) $filters['search']).'%';
                $q->where(function ($x) use ($like): void {
                    $x->where('p.payable_number', 'like', $like)
                        ->orWhere('p.payee_name', 'like', $like)
                        ->orWhere('rp.payment_number', 'like', $like)
                        ->orWhere('ro.reimburse_order_number', 'like', $like);
                });
            })
            ->orderByRaw("CASE p.status WHEN 'WAITING_PAYMENT' THEN 0 WHEN 'PAID' THEN 1 ELSE 2 END")
            ->orderBy('p.due_date')
            ->orderByDesc('p.created_at');

        $page = $query->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100));
        $base = DB::table('pur_reimburse_payables');
        if (! empty($filters['outlet_id'])) $base->where('outlet_id', $filters['outlet_id']);

        return [
            'items' => collect($page->items())->map(fn ($row): array => $this->serialize($row))->all(),
            'summary' => [
                'waiting_count' => (int) (clone $base)->where('status', 'WAITING_PAYMENT')->count(),
                'outstanding' => round((float) (clone $base)->where('status', 'WAITING_PAYMENT')->sum('balance_due'), 2),
                'paid_count' => (int) (clone $base)->where('status', 'PAID')->count(),
                'paid_amount' => round((float) (clone $base)->where('status', 'PAID')->sum('total_amount'), 2),
            ],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        $row = DB::table('pur_reimburse_payables as p')
            ->leftJoin('outlets as o', 'o.id', '=', 'p.outlet_id')
            ->leftJoin('pur_reimburse_payments as rp', 'rp.id', '=', 'p.reimburse_payment_id')
            ->leftJoin('pur_reimburse_orders as ro', 'ro.id', '=', 'p.reimburse_order_id')
            ->where('p.id', $id)
            ->first([
                'p.*', 'o.name as outlet_name', 'o.code as outlet_code',
                'rp.payment_number as reimburse_payment_number',
                'ro.reimburse_order_number as reimburse_order_number',
            ]);
        abort_unless($row, 404);

        $result = $this->serialize($row);
        $result['recognition_journal'] = $row->recognition_journal_entry_id
            ? DB::table('finance_journal_entries')->where('id', $row->recognition_journal_entry_id)->first()
            : null;
        $result['settlement_journal'] = $row->settlement_journal_entry_id
            ? DB::table('finance_journal_entries')->where('id', $row->settlement_journal_entry_id)->first()
            : null;
        $result['general_posting'] = $row->general_posting_id
            ? $this->safeGeneralPosting((string) $row->general_posting_id)
            : null;

        return $result;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function confirmPaid(string $id, array $payload, ?User $actor = null): array
    {
        return DB::transaction(function () use ($id, $payload, $actor): array {
            $payable = DB::table('pur_reimburse_payables')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payable, 404);
            if ((string) $payable->status === 'PAID') {
                if (($payable->idempotency_key ?? null) === ($payload['idempotency_key'] ?? null)) {
                    return $this->show($id);
                }
                throw ValidationException::withMessages(['status' => 'Reimburse AP sudah PAID.']);
            }
            if ((string) $payable->status !== 'WAITING_PAYMENT') {
                throw ValidationException::withMessages(['status' => 'Hanya Reimburse AP WAITING_PAYMENT yang dapat dibayar.']);
            }

            $key = trim((string) ($payload['idempotency_key'] ?? ''));
            if ($key === '') {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key wajib tersedia.']);
            }
            $duplicate = DB::table('pur_reimburse_payables')->where('idempotency_key', $key)->where('id', '<>', $id)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key sudah digunakan.']);
            }

            $paymentDate = (string) ($payload['payment_date'] ?? now()->toDateString());
            $method = strtoupper(trim((string) ($payload['payment_method'] ?? '')));
            if (! in_array($method, ['BANK_TRANSFER', 'CASH', 'PETTY_CASH', 'GIRO', 'VIRTUAL_ACCOUNT', 'OTHER'], true)) {
                throw ValidationException::withMessages(['payment_method' => 'Metode pembayaran tidak valid.']);
            }

            $execution = DB::table('pur_reimburse_payments')->where('id', $payable->reimburse_payment_id)->lockForUpdate()->first();
            if (! $execution || (string) $execution->status !== 'POSTED') {
                throw ValidationException::withMessages(['reimburse_payment_id' => 'Reimburse Payment source tidak lagi POSTED.']);
            }
            if ((float) $payable->total_amount <= 0 || abs((float) $payable->total_amount - (float) ($execution->actual_total_amount ?? 0)) > 0.01) {
                throw ValidationException::withMessages(['total_amount' => 'Nilai Reimburse AP tidak sama dengan actual realization.']);
            }

            $general = DB::table('finance_general_postings')->where('id', $payable->general_posting_id)->lockForUpdate()->first();
            if (! $general || (string) $general->status !== 'DRAFT' || (string) $general->source_code !== 'PUR_REALIZATION') {
                throw ValidationException::withMessages(['general_posting_id' => 'General Posting realisasi harus tetap DRAFT PUR_REALIZATION.']);
            }
            if (abs((float) $general->amount - (float) $payable->total_amount) > 0.01) {
                throw ValidationException::withMessages(['general_posting_id' => 'Nominal General Posting berbeda dari Reimburse AP.']);
            }

            $preview = $this->generalPosting->show((string) $general->id)['preview'] ?? null;
            if (! is_array($preview) || empty($preview['lines'])) {
                throw ValidationException::withMessages(['posting_template_id' => 'Preview Posting Template reimburse tidak tersedia.']);
            }

            $recognitionDebitLines = [];
            $debitTotal = 0.0;
            foreach ($preview['lines'] as $line) {
                $debit = round((float) ($line['debit'] ?? 0), 2);
                if ($debit <= 0.005) continue;
                $accountId = trim((string) ($line['account_id'] ?? ''));
                if ($accountId === '') {
                    throw new InvalidArgumentException('Preview Posting Template tidak memiliki account_id pada baris debit.');
                }
                $debitAccount = DB::table('finance_chart_of_accounts')->where('id', $accountId)->where('is_active', true)->where('is_postable', true)->first();
                if (! $debitAccount || ! in_array(strtoupper((string) $debitAccount->account_type), ['ASSET', 'EXPENSE', 'OTHER_EXPENSE', 'COGS'], true)) {
                    throw ValidationException::withMessages(['posting_template_id' => 'Sisi debit Posting Template reimburse harus Asset/Expense/Other Expense/COGS.']);
                }
                $recognitionDebitLines[] = [
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => 0,
                    'description' => (string) ($line['description'] ?? ('Realisasi reimburse '.$payable->payable_number)),
                ];
                $debitTotal += $debit;
            }
            $debitTotal = round($debitTotal, 2);
            if ($recognitionDebitLines === [] || abs($debitTotal - (float) $payable->total_amount) > 0.01) {
                throw ValidationException::withMessages([
                    'posting_template_id' => 'Total sisi DEBIT Posting Template harus sama dengan nominal reimburse untuk recognition.',
                ]);
            }

            $issueMap = $this->resolveIssueMapping((string) $payable->company_code, $payable->outlet_id ? (string) $payable->outlet_id : null);
            if (! $issueMap) {
                throw ValidationException::withMessages(['mapping' => 'Finance Purchasing Issue Mapping REIMBURSE_PAYMENT belum tersedia.']);
            }
            $paymentMap = $this->resolvePaymentMapping((string) $payable->company_code, $payable->outlet_id ? (string) $payable->outlet_id : null, $method);
            if (! $paymentMap) {
                throw ValidationException::withMessages(['payment_method' => 'Finance Payment Mapping Kas/Bank belum tersedia untuk metode ini.']);
            }

            $apAccount = DB::table('finance_chart_of_accounts')->where('id', $issueMap->ap_account_id)->where('is_active', true)->where('is_postable', true)->first();
            if (! $apAccount || strtoupper((string) $apAccount->account_type) !== 'LIABILITY') {
                throw ValidationException::withMessages(['mapping' => 'AP account pada Finance Purchasing Mapping harus Liability aktif/postable.']);
            }
            $cashAccount = DB::table('finance_chart_of_accounts')->where('id', $paymentMap->cash_account_id)->where('is_active', true)->where('is_postable', true)->first();
            if (! $cashAccount || strtoupper((string) $cashAccount->account_type) !== 'ASSET') {
                throw ValidationException::withMessages(['payment_method' => 'Kas/Bank pada Payment Mapping harus Asset aktif/postable.']);
            }

            $amount = round((float) $payable->total_amount, 2);
            $recognitionLines = $recognitionDebitLines;
            $recognitionLines[] = [
                'account_id' => (string) $issueMap->ap_account_id,
                'debit' => 0,
                'credit' => $amount,
                'description' => 'Recognition AP Reimburse · '.$payable->payee_name,
            ];

            $recognitionStage = $this->generalPosting->stageSystem([
                'source_key' => 'PUR_REIMBURSE:'.$payable->id.':RECOGNITION',
                'source_code' => 'PURCH_REIMBURSE',
                'source_module' => 'REIMBURSE',
                'source_identity' => (string) $payable->id,
                'journal_date' => $paymentDate,
                'business_date' => $paymentDate,
                'company_code' => (string) $payable->company_code,
                'outlet_id' => $payable->outlet_id ? (string) $payable->outlet_id : null,
                'marking' => (string) $payable->marking,
                'reference_no' => (string) $payable->payable_number,
                'description' => 'Recognition Reimburse '.$payable->payable_number,
                'subtotal' => $amount,
                'payable' => $amount,
                'metadata' => [
                    'reimburse_payable_id' => (string) $payable->id,
                    'reimburse_payment_id' => (string) $payable->reimburse_payment_id,
                    'realization_general_posting_id' => (string) $payable->general_posting_id,
                    'posting_template_id' => (string) $payable->posting_template_id,
                    'stage' => 'RECOGNITION',
                    'unified_posting_version' => 'F02',
                ],
            ], $recognitionLines, $actor?->id, true);
            $recognitionId = (string) ($recognitionStage['journal_entry_id'] ?? '');
            if ($recognitionId === '') {
                throw ValidationException::withMessages(['general_posting' => 'General Posting AUTO recognition reimburse tidak menghasilkan journal entry.']);
            }
            $recognitionNo = (string) ($recognitionStage['journal_no'] ?? DB::table('finance_journal_entries')->where('id', $recognitionId)->value('journal_no'));

            $settlementLines = [
                [
                    'account_id' => (string) $issueMap->ap_account_id,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => 'Pelunasan AP Reimburse · '.$payable->payee_name,
                ],
                [
                    'account_id' => (string) $paymentMap->cash_account_id,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => 'Pembayaran Reimburse · '.$payable->payee_name,
                ],
            ];
            $settlementStage = $this->generalPosting->stageSystem([
                'source_key' => 'PUR_REIMBURSE:'.$payable->id.':SETTLEMENT',
                'source_code' => 'PURCH_REIMBURSE',
                'source_module' => 'REIMBURSE',
                'source_identity' => (string) $payable->id,
                'journal_date' => $paymentDate,
                'business_date' => $paymentDate,
                'company_code' => (string) $payable->company_code,
                'outlet_id' => $payable->outlet_id ? (string) $payable->outlet_id : null,
                'marking' => (string) $payable->marking,
                'reference_no' => (string) $payable->payable_number,
                'description' => 'Settlement Reimburse '.$payable->payable_number,
                'subtotal' => $amount,
                'payable' => $amount,
                'metadata' => [
                    'reimburse_payable_id' => (string) $payable->id,
                    'reimburse_payment_id' => (string) $payable->reimburse_payment_id,
                    'payment_method' => $method,
                    'stage' => 'SETTLEMENT',
                    'unified_posting_version' => 'F02',
                ],
            ], $settlementLines, $actor?->id, true);
            $settlementId = (string) ($settlementStage['journal_entry_id'] ?? '');
            if ($settlementId === '') {
                throw ValidationException::withMessages(['general_posting' => 'General Posting AUTO settlement reimburse tidak menghasilkan journal entry.']);
            }
            $settlementNo = (string) ($settlementStage['journal_no'] ?? DB::table('finance_journal_entries')->where('id', $settlementId)->value('journal_no'));

            DB::table('pur_reimburse_payables')->where('id', $id)->update([
                'status' => 'PAID',
                'balance_due' => 0,
                'payment_method' => $method,
                'payment_date' => $paymentDate,
                'payment_reference' => trim((string) ($payload['reference_number'] ?? '')) ?: null,
                'payment_notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'recognition_journal_entry_id' => $recognitionId,
                'recognition_journal_no' => $recognitionNo,
                'settlement_journal_entry_id' => $settlementId,
                'settlement_journal_no' => $settlementNo,
                'idempotency_key' => $key,
                'paid_at' => now(),
                'paid_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);
            DB::table('pur_reimburse_payments')->where('id', $payable->reimburse_payment_id)->update([
                'payable_status' => 'PAID',
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            $fresh = DB::table('pur_reimburse_payables')->where('id', $id)->first();
            $this->appendEvent($execution, $fresh, 'REIMBURSE_PAID', 'Reimburse dibayar dan jurnal recognition/settlement POSTED', 'PAID', $actor);

            return $this->show($id);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function receiptData(string $reimbursePaymentId): array
    {
        $payment = DB::table('pur_reimburse_payments as rp')
            ->leftJoin('pur_reimburse_orders as ro', 'ro.id', '=', 'rp.order_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'rp.outlet_id')
            ->where('rp.id', $reimbursePaymentId)
            ->whereNull('rp.deleted_at')
            ->first([
                'rp.*', 'ro.reimburse_order_number', 'ro.counterparty_name', 'ro.payment_destination',
                'o.name as outlet_name', 'o.code as outlet_code',
            ]);
        abort_unless($payment, 404);
        $items = DB::table('pur_reimburse_payment_items')->where('document_id', $reimbursePaymentId)->orderBy('line_no')->get();
        $payable = Schema::hasTable('pur_reimburse_payables')
            ? DB::table('pur_reimburse_payables')->where('reimburse_payment_id', $reimbursePaymentId)->first()
            : null;

        return [
            'payment' => $payment,
            'items' => $items,
            'payable' => $payable ? $this->serialize($payable) : null,
            'receipt_status' => (string) ($payment->status === 'POSTED' ? ($payment->payable_status ?? 'READY_TO_PAY') : 'DRAFT'),
        ];
    }

    private function resolveIssueMapping(string $company, ?string $outletId): ?object
    {
        return DB::table('finance_purchasing_posting_mappings')
            ->where('company_code', $company)
            ->where('source_document_kind', 'REIMBURSE_PAYMENT')
            ->where('is_active', true)
            ->where(function ($q) use ($outletId): void {
                if ($outletId) $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                else $q->whereNull('outlet_id');
            })
            ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    private function resolvePaymentMapping(string $company, ?string $outletId, string $method): ?object
    {
        return DB::table('finance_purchasing_payment_mappings')
            ->where('company_code', $company)
            ->where('payment_method', $method)
            ->where('is_active', true)
            ->where(function ($q) use ($outletId): void {
                if ($outletId) $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                else $q->whereNull('outlet_id');
            })
            ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    private function syncExecutionLink(object $payment, object $payable): void
    {
        DB::table('pur_reimburse_payments')->where('id', $payment->id)->update([
            'payable_id' => (string) $payable->id,
            'payable_status' => (string) $payable->status,
            'paid_at' => $payable->paid_at ?? null,
            'updated_at' => now(),
        ]);
    }

    private function nextNumber(): string
    {
        $period = now()->format('Ymd');
        if (Schema::hasTable('pur_document_sequences')) {
            DB::table('pur_document_sequences')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'document_type' => 'REIMBURSE_AP', 'period_key' => $period,
                'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('pur_document_sequences')->where('document_type', 'REIMBURSE_AP')->where('period_key', $period)->lockForUpdate()->first();
            $next = (int) ($row->last_number ?? 0) + 1;
            DB::table('pur_document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);
            return 'AP-RMB-'.$period.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }
        return 'AP-RMB-'.$period.'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function safeGeneralPosting(string $id): ?array
    {
        try {
            return $this->generalPosting->show($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function serialize(object $row): array
    {
        $balance = round((float) ($row->balance_due ?? 0), 2);
        return [
            ...get_object_vars($row),
            'total_amount' => round((float) $row->total_amount, 2),
            'balance_due' => $balance,
            'journal_status' => (string) $row->status === 'PAID' ? 'POSTED' : 'DEFERRED_UNTIL_PAID',
            'aging_bucket' => $balance > 0 ? 'NOT_DUE' : 'PAID',
        ];
    }

    private function appendEvent(object $payment, object $payable, string $code, string $label, string $status, ?User $actor): void
    {
        if (! Schema::hasTable('pur_document_events') || ! $payment->fund_request_id) return;
        DB::table('pur_document_events')->insert([
            'id' => (string) Str::ulid(),
            'root_request_id' => (string) $payment->fund_request_id,
            'document_type' => 'REIMBURSE_PAYABLE',
            'document_id' => (string) $payable->id,
            'event_code' => $code,
            'event_label' => $label,
            'status' => $status,
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?? $actor?->username,
            'occurred_at' => now(),
            'reference_type' => 'REIMBURSE_PAYABLE',
            'reference_id' => (string) $payable->id,
            'reference_number' => (string) $payable->payable_number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
