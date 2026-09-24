<?php

namespace App\Services\Purchasing;

use Carbon\Carbon;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Finance\FinanceInvoiceTreasuryDraftBridgeService;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceWorkflowService
{
    public function __construct(
        private readonly InvoiceWorkflowCatalog $catalog,
        private readonly FinancePostingOutboxService $financeOutbox,
        private readonly FinancePurchasingPostingService $financePosting,
        private readonly AccountPayableRealizationGateService $paymentGate,
        private readonly FinanceInvoiceTreasuryDraftBridgeService $treasuryDraftBridge,
    ) {
    }

    /** @return array<string, mixed> */
    public function catalogs(string $direction): array
    {
        $definition = $this->catalog->invoice($direction);

        return [
            'definition' => $definition,
            'statuses' => $this->catalog->statuses(),
            'payment_methods' => $this->catalog->paymentMethods(),
            'chambers' => $this->chambers(),
            'outlets' => $this->outlets(),
            'eligible_sources' => $definition['direction'] === 'INCOMING'
                ? $this->eligibleIncomingSources()
                : [],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function paginate(string $direction, array $filters): array
    {
        $definition = $this->catalog->invoice($direction);
        $query = DB::table('pur_invoices as i')
            ->leftJoin('outlets as o', 'o.id', '=', 'i.outlet_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'i.created_by_user_id')
            ->where('i.direction', $definition['direction'])
            ->whereNull('i.deleted_at')
            ->select([
                'i.*',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.type as outlet_type',
                'creator.name as creator_name',
            ]);

        $this->applyInvoiceFilters($query, $filters);

        $paginator = $query
            ->orderByRaw("CASE i.status WHEN 'ISSUED' THEN 0 WHEN 'PARTIALLY_PAID' THEN 1 WHEN 'DRAFT' THEN 2 ELSE 3 END")
            ->orderBy('i.due_date')
            ->orderByDesc('i.created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->invoiceSummary($row))->all(),
            'summary' => $this->invoiceTotals($definition['direction'], $filters),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @return array<string, mixed> */
    public function show(string $direction, string $id): array
    {
        $definition = $this->catalog->invoice($direction);
        $invoice = DB::table('pur_invoices as i')
            ->leftJoin('outlets as o', 'o.id', '=', 'i.outlet_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'i.created_by_user_id')
            ->where('i.id', $id)
            ->where('i.direction', $definition['direction'])
            ->whereNull('i.deleted_at')
            ->select('i.*', 'o.code as outlet_code', 'o.name as outlet_name', 'o.type as outlet_type', 'creator.name as creator_name')
            ->first();
        abort_unless($invoice, 404);

        return [
            'header' => $this->invoiceSummary($invoice),
            'items' => DB::table('pur_invoice_items')->where('invoice_id', $id)->orderBy('line_no')->get()->all(),
            'payments' => DB::table('pur_invoice_payments as p')
                ->leftJoin('users as u', 'u.id', '=', 'p.posted_by_user_id')
                ->where('p.invoice_id', $id)
                ->where('p.status', 'POSTED')
                ->orderBy('p.payment_date')
                ->orderBy('p.created_at')
                ->get(['p.*', 'u.name as posted_by_name'])->all(),
            'timeline' => $this->timeline($invoice),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(string $direction, array $payload, $actor): array
    {
        $definition = $this->catalog->invoice($direction);

        $id = DB::transaction(function () use ($definition, $payload, $actor): string {
            $now = now();
            $id = (string) Str::ulid();
            $source = null;

            if ($definition['direction'] === 'INCOMING') {
                $source = $this->resolveIncomingSource(
                    (string) ($payload['source_document_kind'] ?? ''),
                    (string) ($payload['source_document_id'] ?? ''),
                    true
                );
                if (DB::table('pur_invoices')
                    ->where('direction', 'INCOMING')
                    ->where('source_document_kind', $source['kind'])
                    ->where('source_document_id', $source['id'])
                    ->whereNull('deleted_at')
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'source_document_id' => ['Dokumen eksekusi ini sudah mempunyai Invoice Masuk.'],
                    ]);
                }
            }

            $invoiceNumber = $this->nextNumber($definition);
            $header = [
                'id' => $id,
                'invoice_number' => $invoiceNumber,
                'direction' => $definition['direction'],
                'external_invoice_number' => $payload['external_invoice_number'] ?? null,
                'source_document_kind' => $source['kind'] ?? null,
                'source_document_id' => $source['id'] ?? null,
                'source_document_number' => $source['number'] ?? null,
                'fund_request_id' => $source['fund_request_id'] ?? null,
                'chamber_code' => $source['chamber_code'] ?? ($payload['chamber_code'] ?? null),
                'outlet_id' => $source['outlet_id'] ?? ($payload['outlet_id'] ?? null),
                'counterparty_name' => trim((string) ($source['counterparty_name'] ?? ($payload['counterparty_name'] ?? ''))),
                'invoice_date' => $payload['invoice_date'],
                'due_date' => $payload['due_date'],
                'status' => 'DRAFT',
                'currency' => 'IDR',
                'subtotal' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'balance_due' => 0,
                'notes' => $payload['notes'] ?? null,
                'lock_version' => 1,
                'journal_status' => 'NOT_POSTED',
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($header['counterparty_name'] === '') {
                throw ValidationException::withMessages([
                    'counterparty_name' => ['Nama supplier/customer wajib diisi.'],
                ]);
            }

            DB::table('pur_invoices')->insert($header);

            $items = $definition['direction'] === 'INCOMING'
                ? $this->sourceItems($source)
                : (array) ($payload['items'] ?? []);

            $totals = $this->replaceItems($id, $items, $definition['direction'] === 'INCOMING');
            DB::table('pur_invoices')->where('id', $id)->update([
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total_amount'],
                'balance_due' => $totals['total_amount'],
                'updated_at' => $now,
            ]);

            $this->event($id, 'INVOICE_CREATED', $definition['label'] . ' dibuat', 'DRAFT', $actor, $payload['notes'] ?? null);

            return $id;
        });

        return $this->show($definition['slug'], $id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function update(string $direction, string $id, array $payload, $actor): array
    {
        $definition = $this->catalog->invoice($direction);

        DB::transaction(function () use ($definition, $id, $payload, $actor): void {
            $invoice = DB::table('pur_invoices')
                ->where('id', $id)
                ->where('direction', $definition['direction'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            abort_unless($invoice, 404);

            if ($invoice->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Hanya invoice DRAFT yang dapat diedit.']]);
            }
            if ((int) $invoice->lock_version !== (int) $payload['lock_version']) {
                throw new ConflictHttpException('Invoice telah berubah. Muat ulang halaman sebelum menyimpan.');
            }

            $counterparty = trim((string) ($payload['counterparty_name'] ?? $invoice->counterparty_name));
            if ($counterparty === '') {
                throw ValidationException::withMessages(['counterparty_name' => ['Nama supplier/customer wajib diisi.']]);
            }

            $totals = $this->replaceItems($id, (array) ($payload['items'] ?? []), false);
            DB::table('pur_invoices')->where('id', $id)->update([
                'external_invoice_number' => $payload['external_invoice_number'] ?? null,
                'counterparty_name' => $counterparty,
                'chamber_code' => $payload['chamber_code'] ?? $invoice->chamber_code,
                'outlet_id' => $payload['outlet_id'] ?? $invoice->outlet_id,
                'invoice_date' => $payload['invoice_date'],
                'due_date' => $payload['due_date'],
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total_amount'],
                'balance_due' => $totals['total_amount'],
                'notes' => $payload['notes'] ?? null,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            $this->event($id, 'INVOICE_UPDATED', $definition['label'] . ' diperbarui', 'DRAFT', $actor, $payload['notes'] ?? null);
        });

        return $this->show($definition['slug'], $id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function issue(string $direction, string $id, array $payload, $actor): array
    {
        $definition = $this->catalog->invoice($direction);

        DB::transaction(function () use ($definition, $id, $payload, $actor): void {
            $invoice = DB::table('pur_invoices')
                ->where('id', $id)
                ->where('direction', $definition['direction'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            abort_unless($invoice, 404);

            $key = (string) $payload['idempotency_key'];
            if (DB::table('pur_invoice_events')->where('invoice_id', $id)->where('idempotency_key', $key)->exists()) {
                return;
            }
            if ($invoice->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Hanya invoice DRAFT yang dapat diterbitkan.']]);
            }
            if ((float) $invoice->total_amount <= 0) {
                throw ValidationException::withMessages(['items' => ['Total invoice harus lebih dari nol.']]);
            }

            DB::table('pur_invoices')->where('id', $id)->update([
                'status' => 'ISSUED',
                'issued_by_user_id' => $actor?->id,
                'issued_at' => now(),
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);
            $this->event($id, 'INVOICE_ISSUED', $definition['label'] . ' diterbitkan', 'ISSUED', $actor, $payload['notes'] ?? null, $key);
            $this->appendRootEvent($invoice, $definition, 'INVOICE_ISSUED', $definition['label'], 'ISSUED', $actor, $payload['notes'] ?? null);
            $this->financeOutbox->enqueue(
                'invoice-issued:' . $id,
                'INVOICE_ISSUED',
                'PURCHASING_INVOICE',
                $id,
                ['invoice_id' => $id, 'direction' => $definition['direction'], 'amount' => (float) $invoice->total_amount]
            );
        });

        $result = $this->show($definition['slug'], $id);
        if ($definition['direction'] === 'INCOMING' && $this->shouldAutoPostI05Invoice($id)) {
            $result['finance_posting'] = $this->financePosting->autoPostOutboxByEventKey('invoice-issued:' . $id, $actor?->id);
        }
        return $result;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function recordPayment(string $direction, string $id, array $payload, $actor): array
    {
        $definition = $this->catalog->invoice($direction);

        $outcome = DB::transaction(function () use ($definition, $id, $payload, $actor): array {
            $invoice = DB::table('pur_invoices')
                ->where('id', $id)
                ->where('direction', $definition['direction'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            abort_unless($invoice, 404);

            $key = (string) $payload['idempotency_key'];
            $existing = DB::table('pur_invoice_payments')->where('invoice_id', $id)->where('idempotency_key', $key)->first();
            if ($existing) {
                // Historical payments intentionally remain historical. New I03
                // payments already carry treasury_transaction_id, so retries are
                // still fully idempotent without synthesizing a second settlement.
                return [
                    'payment_id' => (string) $existing->id,
                    'created' => false,
                    'treasury_draft' => $this->treasuryDraftBridge->summaryForPayment((string) $existing->id),
                ];
            }

            if (! in_array($invoice->status, ['ISSUED', 'PARTIALLY_PAID'], true)) {
                throw ValidationException::withMessages(['status' => ['Pembayaran hanya dapat dicatat pada invoice ISSUED atau PARTIALLY PAID.']]);
            }

            // ERP-V5 Iteration 14: liability existence is not payment eligibility.
            // Incoming AP tied to an Order/Realization cannot be paid while SES/GR/SA/RP is still draft.
            if ($definition['direction'] === 'INCOMING') {
                $this->paymentGate->assertInvoiceCanBePaid($invoice);
            }

            $amount = round((float) $payload['amount'], 2);
            $balance = round((float) $invoice->balance_due, 2);
            if ($amount <= 0 || $amount > $balance + 0.009) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal pembayaran harus lebih dari nol dan tidak boleh melebihi outstanding.'],
                ]);
            }

            $paymentId = (string) Str::ulid();
            $paymentNumber = $this->nextPaymentNumber($definition);
            DB::table('pur_invoice_payments')->insert([
                'id' => $paymentId,
                'invoice_id' => $id,
                'payment_number' => $paymentNumber,
                'payment_date' => $payload['payment_date'],
                'amount' => $amount,
                'payment_method' => $payload['payment_method'],
                'reference_number' => $payload['reference_number'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'status' => 'POSTED',
                'idempotency_key' => $key,
                'treasury_transaction_id' => null,
                'posted_by_user_id' => $actor?->id,
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paid = round((float) $invoice->paid_amount + $amount, 2);
            $remaining = max(round((float) $invoice->total_amount - $paid, 2), 0);
            $status = $remaining <= 0.009 ? 'PAID' : 'PARTIALLY_PAID';
            DB::table('pur_invoices')->where('id', $id)->update([
                'paid_amount' => $paid,
                'balance_due' => $remaining,
                'status' => $status,
                'paid_at' => $status === 'PAID' ? now() : null,
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            // I03 accounting contract:
            // AP Payment / AR Receipt -> Treasury DRAFT -> General Posting on
            // Treasury approval. Do NOT enqueue INVOICE_PAYMENT_POSTED into the
            // legacy Purchasing settlement outbox, otherwise approval of the
            // Cash/Bank document would create a duplicate settlement journal.
            $treasuryDraft = $this->treasuryDraftBridge->createForNewPayment(
                $paymentId,
                $actor?->id ? (string) $actor->id : null,
            );

            $this->event(
                $id,
                'PAYMENT_POSTED',
                'Pembayaran ' . $paymentNumber,
                $status,
                $actor,
                $payload['notes'] ?? null,
                $key,
                [
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'remaining' => $remaining,
                    'settlement_source' => 'TREASURY',
                    'treasury_transaction_id' => $treasuryDraft['id'] ?? null,
                    'treasury_number' => $treasuryDraft['treasury_number'] ?? null,
                    'treasury_type' => $treasuryDraft['transaction_type'] ?? null,
                ]
            );

            return [
                'payment_id' => $paymentId,
                'created' => true,
                'treasury_draft' => $treasuryDraft,
            ];
        });

        $result = $this->show($definition['slug'], $id);
        $result['treasury_draft'] = $outcome['treasury_draft'] ?? null;
        $result['settlement_source'] = 'TREASURY';
        return $result;
    }

    private function shouldAutoPostI05Invoice(string $invoiceId): bool
    {
        $invoice = DB::table('pur_invoices')->where('id', $invoiceId)->first(['source_document_kind', 'ap_order_subtype']);
        if (! $invoice) return false;
        $subtype = strtoupper(trim((string) ($invoice->ap_order_subtype ?? '')));
        $sourceKind = strtoupper(trim((string) ($invoice->source_document_kind ?? '')));
        return in_array($subtype, ['SERVICE', 'REIMBURSE', 'ASSET'], true)
            || in_array($sourceKind, ['SERVICE_ACCEPTANCE', 'REIMBURSE_PAYMENT', 'ASSET_RECEIPT'], true);
    }

    public function deleteDraft(string $direction, string $id): void
    {
        $definition = $this->catalog->invoice($direction);
        DB::transaction(function () use ($definition, $id): void {
            $invoice = DB::table('pur_invoices')
                ->where('id', $id)
                ->where('direction', $definition['direction'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            abort_unless($invoice, 404);
            if ($invoice->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Hanya invoice DRAFT yang dapat dihapus.']]);
            }
            DB::table('pur_invoices')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @return array<string, mixed> */
    public function ledgerCatalogs(string $ledger): array
    {
        $definition = $this->catalog->ledger($ledger);
        return [
            'definition' => $definition,
            'statuses' => ['ISSUED', 'PARTIALLY_PAID', 'PAID'],
            'aging_buckets' => ['NOT_DUE', '1_30', '31_60', '61_90', 'OVER_90'],
            'payment_methods' => $this->catalog->paymentMethods(),
            'chambers' => $this->chambers(),
            'outlets' => $this->outlets(),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function ledgerPaginate(string $ledger, array $filters): array
    {
        $definition = $this->catalog->ledger($ledger);
        $filters['status'] = $filters['status'] ?? null;

        $query = DB::table('pur_invoices as i')
            ->leftJoin('outlets as o', 'o.id', '=', 'i.outlet_id')
            ->where('i.direction', $definition['direction'])
            ->whereIn('i.status', ['ISSUED', 'PARTIALLY_PAID', 'PAID'])
            ->whereNull('i.deleted_at')
            ->select('i.*', 'o.code as outlet_code', 'o.name as outlet_name', 'o.type as outlet_type');

        $this->applyInvoiceFilters($query, $filters);
        if (! empty($filters['aging_bucket'])) {
            $this->applyAgingFilter($query, (string) $filters['aging_bucket']);
        }

        $paginator = $query
            ->orderByRaw("CASE WHEN i.balance_due > 0 AND i.due_date < CURRENT_DATE THEN 0 WHEN i.balance_due > 0 THEN 1 ELSE 2 END")
            ->orderBy('i.due_date')
            ->orderByDesc('i.created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->invoiceSummary($row))->all(),
            'summary' => $this->ledgerTotals($definition['direction'], $filters),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function ledgerPayments(string $ledger, array $filters): array
    {
        $definition = $this->catalog->ledger($ledger);
        $treasuryLinked = Schema::hasTable('finance_treasury_transactions')
            && Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id');

        $query = DB::table('pur_invoice_payments as p')
            ->join('pur_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'i.outlet_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.posted_by_user_id')
            ->where('i.direction', $definition['direction'])
            ->where('p.status', 'POSTED')
            ->whereNull('i.deleted_at');

        if ($treasuryLinked) {
            $query->leftJoin('finance_treasury_transactions as t', 't.id', '=', 'p.treasury_transaction_id');
        }

        $select = [
            'p.id',
            'p.payment_number',
            'p.invoice_id',
            'p.payment_date',
            'p.amount',
            'p.payment_method',
            'p.reference_number',
            'p.notes',
            'p.created_at',
            'i.invoice_number',
            'i.external_invoice_number',
            'i.counterparty_name',
            'i.outlet_id',
            'o.code as outlet_code',
            'o.name as outlet_name',
            'u.name as posted_by_name',
        ];
        if ($treasuryLinked) {
            array_push(
                $select,
                'p.treasury_transaction_id',
                't.treasury_number',
                't.transaction_type as treasury_transaction_type',
                't.document_template as treasury_document_template',
                't.status as treasury_status',
                't.general_posting_id as treasury_general_posting_id',
            );
        }
        $query->select($select);

        if (! empty($filters['outlet_id'])) {
            $query->where('i.outlet_id', $filters['outlet_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('p.payment_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('p.payment_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['payment_method'])) {
            $query->where('p.payment_method', $filters['payment_method']);
        }
        if (! empty($filters['search'])) {
            $keyword = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($inner) use ($keyword, $treasuryLinked): void {
                $inner->where('p.payment_number', 'like', $keyword)
                    ->orWhere('p.reference_number', 'like', $keyword)
                    ->orWhere('i.invoice_number', 'like', $keyword)
                    ->orWhere('i.external_invoice_number', 'like', $keyword)
                    ->orWhere('i.counterparty_name', 'like', $keyword);
                if ($treasuryLinked) $inner->orWhere('t.treasury_number', 'like', $keyword);
            });
        }

        $paginator = $query
            ->orderByDesc('p.payment_date')
            ->orderByDesc('p.created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));

        $summaryQuery = DB::table('pur_invoice_payments as p')
            ->join('pur_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('i.direction', $definition['direction'])
            ->where('p.status', 'POSTED')
            ->whereNull('i.deleted_at');
        if ($treasuryLinked) {
            $summaryQuery->leftJoin('finance_treasury_transactions as t', 't.id', '=', 'p.treasury_transaction_id');
        }

        if (! empty($filters['outlet_id'])) $summaryQuery->where('i.outlet_id', $filters['outlet_id']);
        if (! empty($filters['date_from'])) $summaryQuery->whereDate('p.payment_date', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $summaryQuery->whereDate('p.payment_date', '<=', $filters['date_to']);
        if (! empty($filters['payment_method'])) $summaryQuery->where('p.payment_method', $filters['payment_method']);
        if (! empty($filters['search'])) {
            $keyword = '%' . trim((string) $filters['search']) . '%';
            $summaryQuery->where(function ($inner) use ($keyword, $treasuryLinked): void {
                $inner->where('p.payment_number', 'like', $keyword)
                    ->orWhere('p.reference_number', 'like', $keyword)
                    ->orWhere('i.invoice_number', 'like', $keyword)
                    ->orWhere('i.external_invoice_number', 'like', $keyword)
                    ->orWhere('i.counterparty_name', 'like', $keyword);
                if ($treasuryLinked) $inner->orWhere('t.treasury_number', 'like', $keyword);
            });
        }

        return [
            'items' => collect($paginator->items())->map(function ($row) use ($treasuryLinked): array {
                $treasuryId = $treasuryLinked && property_exists($row, 'treasury_transaction_id') && $row->treasury_transaction_id
                    ? (string) $row->treasury_transaction_id
                    : null;
                return [
                    'id' => (string) $row->id,
                    'payment_number' => (string) $row->payment_number,
                    'invoice_id' => (string) $row->invoice_id,
                    'invoice_number' => (string) $row->invoice_number,
                    'external_invoice_number' => $row->external_invoice_number,
                    'counterparty_name' => (string) $row->counterparty_name,
                    'outlet_id' => $row->outlet_id,
                    'outlet_code' => $row->outlet_code,
                    'outlet_name' => $row->outlet_name,
                    'payment_date' => $row->payment_date,
                    'amount' => round((float) $row->amount, 2),
                    'payment_method' => (string) $row->payment_method,
                    'reference_number' => $row->reference_number,
                    'notes' => $row->notes,
                    'journal_status' => $treasuryId ? ($row->treasury_status ?? null) : null,
                    'journal_reference' => $treasuryId ? ($row->treasury_number ?? null) : null,
                    'treasury_transaction_id' => $treasuryId,
                    'treasury_number' => $treasuryId ? (string) ($row->treasury_number ?? '') : null,
                    'treasury_transaction_type' => $treasuryId ? (string) ($row->treasury_transaction_type ?? '') : null,
                    'treasury_document_template' => $treasuryId ? (string) ($row->treasury_document_template ?? '') : null,
                    'treasury_status' => $treasuryId ? (string) ($row->treasury_status ?? '') : null,
                    'treasury_general_posting_id' => $treasuryId && ! empty($row->treasury_general_posting_id) ? (string) $row->treasury_general_posting_id : null,
                    'settlement_source' => $treasuryId ? 'TREASURY' : 'LEGACY_OR_UNLINKED',
                    'posted_by_name' => $row->posted_by_name,
                    'created_at' => $row->created_at,
                ];
            })->all(),
            'summary' => [
                'payment_count' => (int) (clone $summaryQuery)->count(),
                'total_amount' => round((float) (clone $summaryQuery)->sum('p.amount'), 2),
                'treasury_linked_count' => $treasuryLinked ? (int) (clone $summaryQuery)->whereNotNull('p.treasury_transaction_id')->count() : 0,
                'treasury_draft_count' => $treasuryLinked ? (int) (clone $summaryQuery)->where('t.status', 'DRAFT')->count() : 0,
            ],
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @return array<string, mixed> */
    public function ledgerShow(string $ledger, string $id): array
    {
        $definition = $this->catalog->ledger($ledger);
        return $this->show(strtolower($definition['direction']), $id);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function ledgerPayment(string $ledger, string $id, array $payload, $actor): array
    {
        $definition = $this->catalog->ledger($ledger);
        return $this->recordPayment(strtolower($definition['direction']), $id, $payload, $actor);
    }

    /** @param mixed $query @param array<string, mixed> $filters */
    private function applyInvoiceFilters($query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('i.status', $filters['status']);
        }
        if (! empty($filters['chamber_code'])) {
            $query->where('i.chamber_code', $filters['chamber_code']);
        }
        if (! empty($filters['outlet_id'])) {
            $query->where('i.outlet_id', $filters['outlet_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('i.invoice_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('i.invoice_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['due_from'])) {
            $query->whereDate('i.due_date', '>=', $filters['due_from']);
        }
        if (! empty($filters['due_to'])) {
            $query->whereDate('i.due_date', '<=', $filters['due_to']);
        }
        if (! empty($filters['search'])) {
            $keyword = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($inner) use ($keyword): void {
                $inner->where('i.invoice_number', 'like', $keyword)
                    ->orWhere('i.external_invoice_number', 'like', $keyword)
                    ->orWhere('i.source_document_number', 'like', $keyword)
                    ->orWhere('i.counterparty_name', 'like', $keyword);
            });
        }
    }

    /** @param mixed $query */
    private function applyAgingFilter($query, string $bucket): void
    {
        match ($bucket) {
            'NOT_DUE' => $query->where('i.balance_due', '>', 0)->whereDate('i.due_date', '>=', today()),
            '1_30' => $query->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 1 AND 30'),
            '31_60' => $query->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 31 AND 60'),
            '61_90' => $query->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 61 AND 90'),
            'OVER_90' => $query->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) > 90'),
            default => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function eligibleIncomingSources(): array
    {
        $result = [];
        foreach ($this->catalog->incomingSources() as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasTable($source['order_table'])) {
                continue;
            }
            $query = DB::table($source['table'] . ' as x')
                ->leftJoin($source['order_table'] . ' as o', 'o.id', '=', 'x.order_id')
                ->leftJoin('outlets as ot', 'ot.id', '=', 'x.outlet_id')
                ->where('x.status', 'POSTED')
                ->whereNull('x.deleted_at')
                ->whereNotExists(function ($query) use ($source): void {
                    $query->selectRaw('1')
                        ->from('pur_invoices as i')
                        ->whereColumn('i.source_document_id', 'x.id')
                        ->where('i.source_document_kind', $source['kind'])
                        ->where('i.direction', 'INCOMING')
                        ->whereNull('i.deleted_at');
                })
                ->orderByDesc('x.document_date')
                ->limit(100);

            // Iterasi 07: an execution whose Order already owns canonical AP must never
            // be offered again as a source for a second Incoming Invoice.
            if (Schema::hasTable('pur_order_ap_lifecycles')) {
                $query->whereNotExists(function ($ap) use ($source): void {
                    $ap->selectRaw('1')->from('pur_order_ap_lifecycles as al')
                        ->whereColumn('al.order_id', 'x.order_id')
                        ->where('al.order_kind', $source['order_kind']);
                });
            }

            $rows = $query->get([
                    'x.id',
                    'x.' . $source['number'] . ' as number',
                    'x.fund_request_id',
                    'x.outlet_id',
                    'x.chamber_code',
                    'x.document_date',
                    'x.total_amount',
                    'o.' . $source['order_number'] . ' as order_number',
                    'o.counterparty_name',
                    'ot.name as outlet_name',
                ]);

            foreach ($rows as $row) {
                $result[] = [
                    'kind' => $source['kind'],
                    'kind_label' => $source['label'],
                    'id' => (string) $row->id,
                    'number' => (string) $row->number,
                    'order_number' => $row->order_number,
                    'fund_request_id' => $row->fund_request_id,
                    'outlet_id' => $row->outlet_id,
                    'outlet_name' => $row->outlet_name,
                    'chamber_code' => $row->chamber_code,
                    'document_date' => $row->document_date,
                    'counterparty_name' => $row->counterparty_name,
                    'total_amount' => round((float) $row->total_amount, 2),
                ];
            }
        }

        return collect($result)->sortByDesc('document_date')->values()->all();
    }

    /** @return array<string, mixed> */
    private function resolveIncomingSource(string $kind, string $id, bool $lock): array
    {
        $definition = $this->catalog->incomingSources()[strtoupper($kind)] ?? null;
        if (! $definition || $id === '') {
            throw ValidationException::withMessages([
                'source_document_id' => ['Pilih Goods Receipt, Service Acceptance, atau Reimburse Payment yang sudah POSTED.'],
            ]);
        }

        $query = DB::table($definition['table'] . ' as x')
            ->leftJoin($definition['order_table'] . ' as o', 'o.id', '=', 'x.order_id')
            ->where('x.id', $id)
            ->where('x.status', 'POSTED')
            ->whereNull('x.deleted_at')
            ->select(
                'x.*',
                'o.' . $definition['order_number'] . ' as order_number',
                'o.counterparty_name'
            );
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (! $row) {
            throw ValidationException::withMessages(['source_document_id' => ['Dokumen sumber tidak tersedia atau belum POSTED.']]);
        }
        if (Schema::hasTable('pur_order_ap_lifecycles')
            && DB::table('pur_order_ap_lifecycles')->where('order_kind', $definition['order_kind'])->where('order_id', $row->order_id)->exists()) {
            throw ValidationException::withMessages([
                'source_document_id' => ['Order sumber sudah mempunyai AP canonical dari final approval. Realization tidak boleh membuat Incoming Invoice kedua.'],
            ]);
        }

        return [
            ...$definition,
            'id' => (string) $row->id,
            'number' => (string) $row->{$definition['number']},
            'fund_request_id' => $row->fund_request_id,
            'outlet_id' => $row->outlet_id,
            'chamber_code' => $row->chamber_code,
            'counterparty_name' => $row->counterparty_name ?: 'Supplier / Penerima',
        ];
    }

    /** @param array<string, mixed> $source @return array<int, array<string, mixed>> */
    private function sourceItems(array $source): array
    {
        return DB::table($source['items'])
            ->where('document_id', $source['id'])
            ->where('executed_qty', '>', 0)
            ->orderBy('line_no')
            ->get()
            ->map(fn ($item): array => [
                'source_item_kind' => $source['kind'] . '_ITEM',
                'source_item_id' => (string) $item->id,
                'sku_id' => $item->sku_id,
                'item_name' => (string) $item->item_name,
                'uom_text' => $item->uom_text,
                'qty' => (float) $item->executed_qty,
                'unit_price' => (float) $item->unit_price,
                'tax_mode' => (float) $item->tax_amount > 0 ? 'TAX' : 'NO_TAX',
                'tax_percent' => 0,
                'tax_amount_override' => (float) $item->tax_amount,
                'notes' => $item->notes,
            ])->all();
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, float> */
    private function replaceItems(string $invoiceId, array $items, bool $sourceCopy): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu rincian invoice wajib tersedia.']]);
        }

        DB::table('pur_invoice_items')->where('invoice_id', $invoiceId)->delete();
        $subtotalTotal = 0.0;
        $taxTotal = 0.0;
        $lineNo = 0;

        foreach ($items as $index => $item) {
            $lineNo++;
            $name = trim((string) ($item['item_name'] ?? ''));
            $qty = round((float) ($item['qty'] ?? 0), 4);
            $price = round((float) ($item['unit_price'] ?? 0), 2);
            $taxMode = strtoupper((string) ($item['tax_mode'] ?? 'NO_TAX')) === 'TAX' ? 'TAX' : 'NO_TAX';
            $taxPercent = $taxMode === 'TAX' ? max(round((float) ($item['tax_percent'] ?? 0), 4), 0) : 0;

            if ($name === '') {
                throw ValidationException::withMessages(["items.{$index}.item_name" => ['Nama item wajib diisi.']]);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.qty" => ['Qty harus lebih dari nol.']]);
            }
            if ($price < 0) {
                throw ValidationException::withMessages(["items.{$index}.unit_price" => ['Harga tidak boleh negatif.']]);
            }

            $subtotal = round($qty * $price, 2);
            $tax = array_key_exists('tax_amount_override', $item)
                ? max(round((float) $item['tax_amount_override'], 2), 0)
                : round($subtotal * ($taxPercent / 100), 2);
            $total = round($subtotal + $tax, 2);

            DB::table('pur_invoice_items')->insert([
                'id' => (string) Str::ulid(),
                'invoice_id' => $invoiceId,
                'line_no' => $lineNo,
                'source_item_kind' => $item['source_item_kind'] ?? null,
                'source_item_id' => $item['source_item_id'] ?? null,
                'sku_id' => $item['sku_id'] ?? null,
                'item_name' => $name,
                'uom_text' => $item['uom_text'] ?? null,
                'qty' => $qty,
                'unit_price' => $price,
                'tax_mode' => $taxMode,
                'tax_percent' => $taxPercent,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'line_total' => $total,
                'notes' => $item['notes'] ?? null,
                'metadata' => json_encode(['source_copy' => $sourceCopy]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $subtotalTotal += $subtotal;
            $taxTotal += $tax;
        }

        return [
            'subtotal' => round($subtotalTotal, 2),
            'tax_amount' => round($taxTotal, 2),
            'total_amount' => round($subtotalTotal + $taxTotal, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceSummary($row): array
    {
        $balance = round((float) $row->balance_due, 2);
        $days = $balance > 0 ? now()->startOfDay()->diffInDays(Carbon::parse($row->due_date)->startOfDay(), false) : 0;
        $overdueDays = $balance > 0 && $days < 0 ? abs($days) : 0;
        $aging = $this->agingBucket($balance, $row->due_date);

        return [
            ...get_object_vars($row),
            'subtotal' => round((float) $row->subtotal, 2),
            'tax_amount' => round((float) $row->tax_amount, 2),
            'total_amount' => round((float) $row->total_amount, 2),
            'paid_amount' => round((float) $row->paid_amount, 2),
            'balance_due' => $balance,
            'overdue_days' => $overdueDays,
            'aging_bucket' => $aging,
            'payment_eligibility' => $this->paymentGate->eligibilityForInvoice($row),
        ];
    }

    private function agingBucket(float $balance, string $dueDate): string
    {
        if ($balance <= 0.009) {
            return 'PAID';
        }
        $overdue = Carbon::parse($dueDate)->startOfDay()->diffInDays(now()->startOfDay(), false);
        if ($overdue <= 0) {
            return 'NOT_DUE';
        }
        return match (true) {
            $overdue <= 30 => '1_30',
            $overdue <= 60 => '31_60',
            $overdue <= 90 => '61_90',
            default => 'OVER_90',
        };
    }

    /** @param array<string, mixed> $filters @return array<string, float|int> */
    private function invoiceTotals(string $direction, array $filters): array
    {
        $query = DB::table('pur_invoices as i')->where('i.direction', $direction)->whereNull('i.deleted_at');
        $this->applyInvoiceFilters($query, $filters);
        $row = $query->selectRaw('COUNT(*) as document_count, COALESCE(SUM(total_amount),0) as total_amount, COALESCE(SUM(paid_amount),0) as paid_amount, COALESCE(SUM(balance_due),0) as balance_due')->first();
        return [
            'document_count' => (int) ($row->document_count ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0), 2),
            'paid_amount' => round((float) ($row->paid_amount ?? 0), 2),
            'balance_due' => round((float) ($row->balance_due ?? 0), 2),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, float|int> */
    private function ledgerTotals(string $direction, array $filters): array
    {
        $base = DB::table('pur_invoices as i')
            ->where('i.direction', $direction)
            ->whereIn('i.status', ['ISSUED', 'PARTIALLY_PAID', 'PAID'])
            ->whereNull('i.deleted_at');
        $this->applyInvoiceFilters($base, $filters);

        $sum = fn ($query): float => round((float) $query->sum('i.balance_due'), 2);
        return [
            'invoice_count' => (int) (clone $base)->count(),
            'outstanding' => $sum(clone $base),
            'not_due' => $sum((clone $base)->where('i.balance_due', '>', 0)->whereDate('i.due_date', '>=', today())),
            'overdue_1_30' => $sum((clone $base)->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 1 AND 30')),
            'overdue_31_60' => $sum((clone $base)->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 31 AND 60')),
            'overdue_61_90' => $sum((clone $base)->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) BETWEEN 61 AND 90')),
            'overdue_over_90' => $sum((clone $base)->where('i.balance_due', '>', 0)->whereRaw('DATEDIFF(CURRENT_DATE, i.due_date) > 90')),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline($invoice): array
    {
        $events = collect();
        if ($invoice->fund_request_id && Schema::hasTable('pur_document_events')) {
            $events = DB::table('pur_document_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
                ->where('e.root_request_id', $invoice->fund_request_id)
                ->get([
                    'e.id', 'e.event_code', 'e.event_label', 'e.status', 'e.notes', 'e.occurred_at',
                    'e.reference_type', 'e.reference_id', 'e.reference_number',
                    DB::raw('COALESCE(e.actor_name_snapshot, u.name) as actor_name'),
                ]);
        }

        $invoiceEvents = DB::table('pur_invoice_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.invoice_id', $invoice->id)
            ->get([
                'e.id', 'e.event_code', 'e.event_label', 'e.status', 'e.notes', 'e.occurred_at',
                DB::raw("'PURCHASING_INVOICE' as reference_type"),
                DB::raw('e.invoice_id as reference_id'),
                DB::raw("'{$invoice->invoice_number}' as reference_number"),
                'u.name as actor_name',
            ]);

        return $events->concat($invoiceEvents)
            ->sortBy(fn ($event) => (string) $event->occurred_at)
            ->values()->all();
    }

    /** @param array<string, mixed> $definition */
    private function appendRootEvent($invoice, array $definition, string $code, string $label, string $status, $actor, ?string $notes): void
    {
        if (! $invoice->fund_request_id || ! Schema::hasTable('pur_document_events')) {
            return;
        }
        DB::table('pur_document_events')->insert([
            'id' => (string) Str::ulid(),
            'root_request_id' => $invoice->fund_request_id,
            'document_type' => 'INVOICE_' . $definition['direction'],
            'document_id' => $invoice->id,
            'event_code' => $code,
            'event_label' => $label,
            'status' => $status,
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?? $actor?->username,
            'occurred_at' => now(),
            'notes' => $notes,
            'reference_type' => 'INVOICE_' . $definition['direction'],
            'reference_id' => $invoice->id,
            'reference_number' => $invoice->invoice_number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function event(string $invoiceId, string $code, string $label, ?string $status, $actor, ?string $notes = null, ?string $idempotencyKey = null, array $metadata = []): void
    {
        DB::table('pur_invoice_events')->insert([
            'id' => (string) Str::ulid(),
            'invoice_id' => $invoiceId,
            'event_code' => $code,
            'event_label' => $label,
            'status' => $status,
            'actor_user_id' => $actor?->id,
            'notes' => $notes,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function nextNumber(array $definition): string
    {
        return $this->sequence('INVOICE_' . $definition['direction'], $definition['number_prefix']);
    }

    /** @param array<string, mixed> $definition */
    private function nextPaymentNumber(array $definition): string
    {
        $prefix = $definition['direction'] === 'INCOMING' ? 'PAY-AP' : 'PAY-AR';
        return $this->sequence('INVOICE_PAYMENT_' . $definition['direction'], $prefix);
    }

    private function sequence(string $documentType, string $prefix): string
    {
        $period = now()->format('Ymd');
        DB::table('pur_document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'document_type' => $documentType,
            'period_key' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table('pur_document_sequences')
            ->where('document_type', $documentType)
            ->where('period_key', $period)
            ->lockForUpdate()->first();
        $next = (int) ($row?->last_number ?? 0) + 1;
        DB::table('pur_document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);
        return sprintf('%s-%s-%04d', $prefix, $period, $next);
    }

    /** @return array<int, array<string, string>> */
    private function chambers(): array
    {
        return [
            ['code' => 'EXECUTIVE', 'name' => 'Executive'],
            ['code' => 'BRAND', 'name' => 'Brand'],
            ['code' => 'OPERATIONAL', 'name' => 'Operational'],
            ['code' => 'GENERAL_AFFAIR', 'name' => 'General Affair'],
            ['code' => 'FINANCE', 'name' => 'Finance'],
            ['code' => 'HUMAN_RESOURCE', 'name' => 'Human Resource'],
            ['code' => 'OUTLET', 'name' => 'Outlet'],
            ['code' => 'WAREHOUSE', 'name' => 'Warehouse'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function outlets(): array
    {
        return DB::table('outlets')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'type'])->map(fn ($row): array => (array) $row)->all();
    }

    /** @return array<string, int|null> */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
