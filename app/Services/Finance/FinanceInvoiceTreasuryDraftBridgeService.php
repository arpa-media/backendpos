<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ERP POS FINAL I03
 *
 * Canonical bridge for a NEW Purchasing AP Payment / AR Receipt into a
 * Finance Treasury DRAFT. The payment row remains the immutable commercial
 * event; Treasury owns the cash/bank accounting lifecycle from DRAFT onward.
 *
 * Historical payments are deliberately not backfilled automatically because
 * legacy payment outbox events may already have settled GL. Creating another
 * Treasury document for those rows could double-settle AP/AR.
 */
final class FinanceInvoiceTreasuryDraftBridgeService
{
    private const CASH_METHODS = ['CASH', 'PETTY_CASH'];

    public function __construct(
        private readonly FinanceTreasuryService $treasury,
        private readonly FinanceScopeResolver $scope,
    ) {
    }

    /** @return array<string,mixed> */
    public function createForNewPayment(string $paymentId, ?string $userId): array
    {
        $this->assertSchema();

        $row = DB::table('pur_invoice_payments as p')
            ->join('pur_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.id', $paymentId)
            ->whereNull('i.deleted_at')
            ->first([
                'p.id as payment_id', 'p.payment_number', 'p.payment_date', 'p.amount',
                'p.payment_method', 'p.reference_number', 'p.notes', 'p.treasury_transaction_id',
                'i.id as invoice_id', 'i.invoice_number', 'i.direction', 'i.outlet_id',
                'i.counterparty_name', 'i.fund_request_id', 'i.source_document_kind',
                'i.source_document_id', 'i.metadata as invoice_metadata',
            ]);

        if (! $row) {
            throw ValidationException::withMessages([
                'payment' => ['Payment/receipt tidak ditemukan untuk membuat Cash/Bank Draft.'],
            ]);
        }

        if (! empty($row->treasury_transaction_id)) {
            return $this->summaryByTreasuryId((string) $row->treasury_transaction_id);
        }

        $sourceKey = 'ERP-I03:INVOICE-PAYMENT:'.(string) $row->payment_id;
        $existingId = DB::table('finance_treasury_transactions')->where('source_key', $sourceKey)->value('id');
        if ($existingId) {
            DB::table('pur_invoice_payments')->where('id', $paymentId)->whereNull('treasury_transaction_id')->update([
                'treasury_transaction_id' => (string) $existingId,
                'updated_at' => now(),
            ]);
            return $this->summaryByTreasuryId((string) $existingId);
        }

        $direction = strtoupper(trim((string) $row->direction));
        if (! in_array($direction, ['INCOMING', 'OUTGOING'], true)) {
            throw ValidationException::withMessages(['direction' => ['Direction invoice tidak valid untuk Treasury.']]);
        }

        $method = strtoupper(trim((string) $row->payment_method));
        $accountType = in_array($method, self::CASH_METHODS, true) ? 'CASH' : 'BANK';
        $transactionType = $direction === 'INCOMING'
            ? ($accountType === 'CASH' ? 'cash_out' : 'bank_out')
            : ($accountType === 'CASH' ? 'cash_in' : 'bank_in');

        $companyCode = $this->resolveCompany($row);
        $treasuryAccount = $this->resolveTreasuryAccount($row, $companyCode, $accountType);
        $counterAccountId = $this->resolveCounterAccountId($row, $direction);
        $marking = $this->resolveMarking($row);

        $outgoing = in_array($transactionType, ['cash_out', 'bank_out'], true);
        $payload = [
            'company_code' => $companyCode,
            'marking' => $marking,
            'transaction_date' => (string) $row->payment_date,
            'amount' => round((float) $row->amount, 2),
            'from_treasury_account_id' => $outgoing ? (string) $treasuryAccount->id : null,
            'to_treasury_account_id' => $outgoing ? null : (string) $treasuryAccount->id,
            'counter_account_id' => $counterAccountId,
            'counterparty_name' => (string) $row->counterparty_name,
            'reference_number' => trim((string) ($row->reference_number ?: $row->payment_number)),
            'description' => ($direction === 'INCOMING' ? 'AP Payment ' : 'AR Receipt ')
                .(string) $row->payment_number.' · '.(string) $row->invoice_number,
            'notes' => $row->notes,
        ];

        $draft = $this->treasury->createLinkedDraft(
            $transactionType,
            $payload,
            $userId,
            $sourceKey,
            [
                'erp_pos_final_iteration' => 'I03',
                'origin' => 'PURCHASING_INVOICE_PAYMENT',
                'invoice_id' => (string) $row->invoice_id,
                'invoice_number' => (string) $row->invoice_number,
                'payment_id' => (string) $row->payment_id,
                'payment_number' => (string) $row->payment_number,
                'payment_method' => $method,
                'direction' => $direction,
                'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
                'routing' => [
                    'account_type' => $accountType,
                    'treasury_account_id' => (string) $treasuryAccount->id,
                    'treasury_account_code' => (string) $treasuryAccount->code,
                    'counter_account_id' => $counterAccountId,
                ],
            ],
        );

        DB::table('pur_invoice_payments')
            ->where('id', $paymentId)
            ->whereNull('treasury_transaction_id')
            ->update([
                'treasury_transaction_id' => (string) $draft['id'],
                'updated_at' => now(),
            ]);

        return $this->summaryByTreasuryId((string) $draft['id']);
    }

    /** @return array<string,mixed>|null */
    public function summaryForPayment(string $paymentId): ?array
    {
        if (! Schema::hasTable('pur_invoice_payments') || ! Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id')) {
            return null;
        }
        $id = DB::table('pur_invoice_payments')->where('id', $paymentId)->value('treasury_transaction_id');
        return $id ? $this->summaryByTreasuryId((string) $id) : null;
    }

    /** @return array<string,mixed> */
    private function summaryByTreasuryId(string $id): array
    {
        $r = DB::table('finance_treasury_transactions')->where('id', $id)->first([
            'id', 'treasury_number', 'company_code', 'transaction_type', 'document_template',
            'transaction_date', 'amount', 'status', 'source_key', 'general_posting_id',
        ]);
        if (! $r) {
            throw ValidationException::withMessages([
                'treasury' => ['Link Cash/Bank Draft pada payment tidak lagi valid.'],
            ]);
        }

        return [
            'id' => (string) $r->id,
            'treasury_number' => (string) $r->treasury_number,
            'company_code' => (string) $r->company_code,
            'transaction_type' => (string) $r->transaction_type,
            'document_template' => (string) $r->document_template,
            'transaction_date' => (string) $r->transaction_date,
            'amount' => round((float) $r->amount, 2),
            'status' => (string) $r->status,
            'source_key' => (string) $r->source_key,
            'general_posting_id' => $r->general_posting_id ? (string) $r->general_posting_id : null,
        ];
    }

    private function resolveCompany(object $row): string
    {
        $outletId = trim((string) ($row->outlet_id ?? ''));
        if ($outletId !== '') {
            $company = $this->scope->companyForOutlet($outletId);
            if ($company) return $company;
        }

        if (Schema::hasTable('finance_purchasing_postings')) {
            $company = DB::table('finance_purchasing_postings')
                ->where('invoice_id', $row->invoice_id)
                ->where('event_type', 'INVOICE_ISSUED')
                ->orderByDesc('created_at')
                ->value('company_code');
            $company = strtoupper(trim((string) $company));
            if ($company !== '') return $company;
        }

        $meta = $this->decode($row->invoice_metadata ?? null);
        $company = strtoupper(trim((string) ($meta['company_code'] ?? '')));
        if ($company !== '') {
            $this->scope->resolve($company, null);
            return $company;
        }

        if (! empty($row->fund_request_id) && Schema::hasTable('pur_fund_requests') && Schema::hasColumn('pur_fund_requests', 'company_code')) {
            $company = strtoupper(trim((string) DB::table('pur_fund_requests')->where('id', $row->fund_request_id)->value('company_code')));
            if ($company !== '') {
                $this->scope->resolve($company, null);
                return $company;
            }
        }

        throw ValidationException::withMessages([
            'company_code' => ['PT Finance invoice belum dapat ditentukan. Lengkapi Mapping PT Outlet / company source sebelum mencatat payment.'],
        ]);
    }

    private function resolveTreasuryAccount(object $row, string $companyCode, string $accountType): object
    {
        $preferredCoaId = null;
        if (strtoupper((string) $row->direction) === 'INCOMING' && Schema::hasTable('finance_purchasing_payment_mappings')) {
            $q = DB::table('finance_purchasing_payment_mappings')
                ->where('company_code', $companyCode)
                ->where('payment_method', strtoupper((string) $row->payment_method))
                ->where('is_active', true);
            $outletId = trim((string) ($row->outlet_id ?? ''));
            if ($outletId !== '') {
                $q->where(function ($x) use ($outletId): void {
                    $x->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                })->orderByRaw('CASE WHEN outlet_id = ? THEN 0 ELSE 1 END', [$outletId]);
            } else {
                $q->whereNull('outlet_id');
            }
            $preferredCoaId = $q->value('cash_account_id');
        }

        $base = DB::table('finance_treasury_accounts')
            ->where('company_code', $companyCode)
            ->where('account_type', $accountType)
            ->where('is_active', true);

        if ($preferredCoaId) {
            $preferred = (clone $base)->where('finance_coa_id', $preferredCoaId)->first();
            if ($preferred) return $preferred;
        }

        $byDefaultCode = (clone $base)->where('code', $accountType)->first();
        if ($byDefaultCode) return $byDefaultCode;

        $fallback = (clone $base)->orderBy('code')->first();
        if ($fallback) return $fallback;

        throw ValidationException::withMessages([
            'treasury_account' => ["Rekening Treasury {$accountType} aktif untuk PT {$companyCode} belum tersedia."],
        ]);
    }

    private function resolveCounterAccountId(object $row, string $direction): string
    {
        if ($direction === 'INCOMING' && Schema::hasTable('finance_purchasing_postings') && Schema::hasTable('finance_purchasing_posting_mappings')) {
            $id = DB::table('finance_purchasing_postings as p')
                ->join('finance_purchasing_posting_mappings as m', 'm.id', '=', 'p.posting_mapping_id')
                ->where('p.invoice_id', $row->invoice_id)
                ->where('p.event_type', 'INVOICE_ISSUED')
                ->orderByDesc('p.created_at')
                ->value('m.ap_account_id');
            if ($id && $this->postableCoaExists((string) $id)) return (string) $id;
        }

        $code = $direction === 'INCOMING' ? '2-20100' : '1-10100';
        $id = DB::table('finance_chart_of_accounts')
            ->where('code', $code)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->value('id');
        if ($id) return (string) $id;

        throw ValidationException::withMessages([
            'counter_account_id' => ["COA {$code} untuk ".($direction === 'INCOMING' ? 'Account Payable' : 'Account Receivable').' belum tersedia/aktif.'],
        ]);
    }

    private function resolveMarking(object $row): string
    {
        if (Schema::hasTable('finance_purchasing_postings')) {
            $marking = strtoupper(trim((string) DB::table('finance_purchasing_postings')
                ->where('invoice_id', $row->invoice_id)
                ->where('event_type', 'INVOICE_ISSUED')
                ->orderByDesc('created_at')
                ->value('marking')));
            if (in_array($marking, FinanceScopeResolver::MARKINGS, true)) return $marking;
        }

        $meta = $this->decode($row->invoice_metadata ?? null);
        $marking = strtoupper(trim((string) ($meta['marking'] ?? 'MARKING')));
        return in_array($marking, FinanceScopeResolver::MARKINGS, true) ? $marking : 'MARKING';
    }

    private function postableCoaExists(string $id): bool
    {
        return DB::table('finance_chart_of_accounts')->where('id', $id)->where('is_active', true)->where('is_postable', true)->exists();
    }

    private function assertSchema(): void
    {
        foreach (['pur_invoice_payments', 'pur_invoices', 'finance_treasury_accounts', 'finance_treasury_transactions', 'finance_treasury_events', 'finance_chart_of_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['schema' => ["Table {$table} belum tersedia. Jalankan php artisan migrate."]]);
            }
        }
        if (! Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id')) {
            throw ValidationException::withMessages(['schema' => ['Link AP/AR → Treasury I03 belum tersedia. Jalankan php artisan migrate.']]);
        }
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
