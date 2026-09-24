<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Services\Finance\FinanceGeneralPostingService;
use App\Services\Finance\FinanceInvoiceTreasuryDraftBridgeService;
use App\Services\Finance\FinancePurchasingPostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OrderApLifecycleService
{
    private const OPEN = 'OPEN';
    private const PARTIALLY_PAID = 'PARTIALLY_PAID';
    private const PAID = 'PAID';

    public function __construct(
        private readonly OrderWorkflowCatalog $orders,
        private readonly ExecutionWorkflowCatalog $executions,
        private readonly FinancePostingOutboxService $outbox,
        private readonly FinancePurchasingPostingService $financePosting,
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly AccountPayableRealizationGateService $paymentGate,
        private readonly FinanceInvoiceTreasuryDraftBridgeService $treasuryDraftBridge,
    ) {
    }

    /**
     * Iterasi 07 canonical recognition hook.
     * One final-approved Order owns exactly one AP sub-ledger invoice and one lifecycle row.
     * The GL auto-post is attempted after the transactional sub-ledger commit so mapping
     * configuration errors never roll back the business approval itself.
     *
     * @return array<string,mixed>
     */
    public function recognizeFromApprovedOrder(string $kind, string $orderId, ?User $actor = null): array
    {
        $definition = $this->orders->definition($kind);
        $sourceKey = 'ORDER_APPROVED:'.$definition['kind'].':'.$orderId;

        $result = DB::transaction(function () use ($definition, $orderId, $sourceKey, $actor): array {
            $modelClass = $definition['model'];
            /** @var Model|null $order */
            $order = $modelClass::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['order_id' => 'Order sumber AP tidak ditemukan.']);
            }
            if (! in_array((string) $order->status, ['APPROVED', 'PARTIALLY_EXECUTED', 'EXECUTED'], true)) {
                throw ValidationException::withMessages(['status' => 'AP hanya dapat diakui dari Order final approved.']);
            }

            $amount = round((float) ($order->total_amount ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['total_amount' => 'Nominal Order final approved harus lebih besar dari 0.']);
            }

            $existing = DB::table('pur_order_ap_lifecycles')
                ->where('order_kind', $definition['kind'])
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $this->serializeLifecycle($existing, true);
            }

            $subtype = $this->orderSubtype($definition['kind'], $order);
            $invoiceKind = $this->financeSourceKind($definition['kind'], $subtype);
            $invoiceQuery = DB::table('pur_invoices')
                ->where('direction', 'INCOMING')
                ->where('source_document_id', $orderId)
                ->whereNull('deleted_at');
            if ($invoiceKind === 'ASSET_RECEIPT') {
                // I05 compatibility: Asset AP created before I05 was mislabeled GOODS_RECEIPT.
                // Reuse it instead of creating a second invoice for the same approved Order.
                $invoiceQuery->whereIn('source_document_kind', ['ASSET_RECEIPT', 'GOODS_RECEIPT']);
            } else {
                $invoiceQuery->where('source_document_kind', $invoiceKind);
            }
            $invoice = $invoiceQuery->lockForUpdate()->first();

            if (! $invoice) {
                $invoiceId = (string) Str::ulid();
                $number = $this->nextApNumber($definition['kind'], $orderId);
                $date = $this->approvedDate($order);
                $counterparty = trim((string) ($order->counterparty_name ?? '')) ?: 'Supplier / Penerima';
                $metadata = [
                    'ap_lifecycle' => 'ORDER_APPROVED',
                    'order_kind' => $definition['kind'],
                    'order_id' => $orderId,
                    'order_subtype' => $subtype,
                    'recognition_source_key' => $sourceKey,
                    'approved_order_amount' => $amount,
                    'scope_type' => $order->scope_type ?? null,
                    'company_code' => $order->company_code ?? null,
                    'outlet_id' => $order->outlet_id ?? null,
                    'marking' => $order->marking ?? 'MARKING',
                    'erp_v5_i05_scope_contract' => true,
                ];

                DB::table('pur_invoices')->insert([
                    'id' => $invoiceId,
                    'invoice_number' => $number,
                    'direction' => 'INCOMING',
                    'external_invoice_number' => null,
                    'source_document_kind' => $invoiceKind,
                    'source_document_id' => $orderId,
                    'source_document_number' => (string) $order->{$definition['number_field']},
                    'fund_request_id' => $order->fund_request_id ?: null,
                    'chamber_code' => $order->chamber_code ?: null,
                    'outlet_id' => $order->outlet_id ?: null,
                    'counterparty_name' => $counterparty,
                    'invoice_date' => $date,
                    'due_date' => $this->dueDate($order, $date),
                    'status' => 'ISSUED',
                    'currency' => (string) ($order->currency ?: 'IDR'),
                    'subtotal' => round((float) ($order->subtotal ?? $amount), 2),
                    'tax_amount' => round((float) ($order->tax_amount ?? 0), 2),
                    'total_amount' => $amount,
                    'paid_amount' => 0,
                    'balance_due' => $amount,
                    'notes' => 'AP otomatis dari Order final approved Iterasi 07.',
                    'lock_version' => 1,
                    'journal_status' => 'NOT_POSTED',
                    'journal_reference' => null,
                    'issued_by_user_id' => $actor?->id,
                    'issued_at' => now(),
                    'paid_at' => null,
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ap_status' => self::OPEN,
                    'ap_lifecycle_source_key' => $sourceKey,
                    'ap_order_kind' => $definition['kind'],
                    'ap_order_id' => $orderId,
                    'ap_order_subtype' => $subtype,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->copyOrderItemsToInvoice($definition, $orderId, $invoiceId);
                $this->appendInvoiceEvent($invoiceId, 'ORDER_AP_RECOGNIZED', 'Hutang/AP diakui dari Order final approved', 'ISSUED', $actor, $sourceKey, [
                    'order_kind' => $definition['kind'], 'order_id' => $orderId, 'amount' => $amount,
                ]);
                $this->appendRootEvent($order, $definition['kind'], $orderId, 'ORDER_AP_RECOGNIZED', 'AP Liability / Hutang', self::OPEN, $actor, $sourceKey, $amount);
                $invoice = DB::table('pur_invoices')->where('id', $invoiceId)->first();
            }

            $lifecycleId = (string) Str::ulid();
            DB::table('pur_order_ap_lifecycles')->insert([
                'id' => $lifecycleId,
                'source_key' => $sourceKey,
                'order_kind' => $definition['kind'],
                'order_id' => $orderId,
                'order_subtype' => $subtype,
                'fund_request_id' => $order->fund_request_id ?: null,
                'outlet_id' => $order->outlet_id ?: null,
                'invoice_id' => (string) $invoice->id,
                'liability_amount' => $amount,
                'settled_amount' => round((float) ($invoice->paid_amount ?? 0), 2),
                'balance_due' => round((float) ($invoice->balance_due ?? $amount), 2),
                'status' => $this->statusFromBalance($amount, (float) ($invoice->paid_amount ?? 0)),
                'recognition_event_key' => $sourceKey,
                'recognition_posting_status' => ((string) ($invoice->journal_status ?? 'NOT_POSTED')) === 'POSTED' ? 'POSTED' : 'PENDING',
                'recognition_journal_no' => $invoice->journal_reference ?: null,
                'recognized_at' => $invoice->issued_at ?: now(),
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->outbox->enqueue($sourceKey, 'INVOICE_ISSUED', 'PURCHASING_INVOICE', (string) $invoice->id, [
                'invoice_id' => (string) $invoice->id,
                'direction' => 'INCOMING',
                'amount' => $amount,
                'order_kind' => $definition['kind'],
                'order_subtype' => $subtype,
                'order_id' => $orderId,
            ]);

            return $this->serializeLifecycle(DB::table('pur_order_ap_lifecycles')->where('id', $lifecycleId)->first(), false);
        }, 3);

        return $this->attemptRecognitionPosting($result, $actor);
    }

    /**
     * Iterasi 07 canonical settlement hook. Approved Realization settles the AP
     * created from the approved Order; it never creates a second liability.
     *
     * @return array<string,mixed>
     */
    public function settleFromRealization(string $kind, string $executionId, ?User $actor = null): array
    {
        $executionDefinition = $this->executions->definition($kind);
        $settlementKey = 'REALIZATION_APPROVED:'.$executionDefinition['kind'].':'.$executionId;

        $seed = DB::table($executionDefinition['table'])->where('id', $executionId)->whereNull('deleted_at')->first();
        if (! $seed) {
            throw ValidationException::withMessages(['realization_id' => 'Realization tidak ditemukan.']);
        }
        if (! in_array((string) $seed->status, ['POSTED', 'APPROVED'], true)) {
            throw ValidationException::withMessages(['status' => 'Settlement hanya dapat dibuat dari Realization approved.']);
        }

        // Handles orders approved before Iterasi 07 without destructive backfill.
        $this->recognizeFromApprovedOrder($executionDefinition['order_kind'], (string) $seed->order_id, $actor);

        // ERP-V5 Iteration 14: a settlement must use the realization kind expected by the AP Order.
        // Example: PURCHASE PO waits for SES, STOCK/ASSET PO waits for GR, SO waits for SA, RO waits for RP.
        $this->paymentGate->assertRealizationMaySettle($executionDefinition['slug'], $executionId);

        $result = DB::transaction(function () use ($executionDefinition, $executionId, $settlementKey, $actor): array {
            $execution = DB::table($executionDefinition['table'])->where('id', $executionId)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $execution) abort(404);

            $lifecycle = DB::table('pur_order_ap_lifecycles')
                ->where('order_kind', $executionDefinition['order_kind'])
                ->where('order_id', $execution->order_id)
                ->lockForUpdate()
                ->first();
            if (! $lifecycle) {
                throw ValidationException::withMessages(['ap' => 'AP Order belum berhasil dibentuk.']);
            }

            $existingSettlement = DB::table('pur_order_ap_settlements')->where('settlement_key', $settlementKey)->lockForUpdate()->first();
            if ($existingSettlement) {
                return $this->serializeSettlement($existingSettlement, true);
            }

            $invoice = DB::table('pur_invoices')->where('id', $lifecycle->invoice_id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'Sub-ledger AP Order tidak ditemukan.']);
            }

            $amount = $this->realizationAmount($execution);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['actual_total_amount' => 'Nominal realisasi approved harus lebih besar dari 0.']);
            }
            $balance = round((float) $invoice->balance_due, 2);
            if ($amount > $balance + 0.009) {
                throw ValidationException::withMessages([
                    'actual_total_amount' => sprintf('Nominal realisasi %.2f melebihi outstanding AP %.2f.', $amount, $balance),
                ]);
            }

            $paymentId = (string) Str::ulid();
            $paymentMethod = $this->paymentMethod($execution);
            DB::table('pur_invoice_payments')->insert([
                'id' => $paymentId,
                'invoice_id' => (string) $invoice->id,
                'payment_number' => $this->nextPaymentNumber($executionId),
                'payment_date' => $this->realizationDate($execution),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference_number' => (string) $execution->{$executionDefinition['number']},
                'notes' => 'Settlement otomatis dari Realization approved Iterasi 07.',
                'status' => 'POSTED',
                'idempotency_key' => $settlementKey,
                'treasury_transaction_id' => null,
                'posted_by_user_id' => $actor?->id,
                'posted_at' => now(),
                'metadata' => json_encode([
                    'ap_lifecycle_id' => (string) $lifecycle->id,
                    'realization_kind' => $executionDefinition['kind'],
                    'realization_id' => $executionId,
                    'settlement_source_key' => $settlementKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paid = round((float) $invoice->paid_amount + $amount, 2);
            $remaining = max(round((float) $invoice->total_amount - $paid, 2), 0);
            $apStatus = $this->statusFromBalance((float) $invoice->total_amount, $paid);
            $invoiceStatus = $apStatus === self::PAID ? 'PAID' : 'PARTIALLY_PAID';
            DB::table('pur_invoices')->where('id', $invoice->id)->update([
                'paid_amount' => $paid,
                'balance_due' => $remaining,
                'status' => $invoiceStatus,
                'ap_status' => $apStatus,
                'paid_at' => $apStatus === self::PAID ? now() : null,
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            // ERP POS FINAL I03: all NEW AP settlement payments enter the
            // same Treasury DRAFT workflow as manual AP Payment. This prevents
            // Realization from bypassing Cash/Bank and posting a second GL.
            $treasuryDraft = $this->treasuryDraftBridge->createForNewPayment(
                $paymentId,
                $actor?->id ? (string) $actor->id : null,
            );

            $settlementId = (string) Str::ulid();
            DB::table('pur_order_ap_settlements')->insert([
                'id' => $settlementId,
                'ap_lifecycle_id' => (string) $lifecycle->id,
                'settlement_key' => $settlementKey,
                'realization_kind' => $executionDefinition['kind'],
                'realization_id' => $executionId,
                'invoice_payment_id' => $paymentId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_date' => $this->realizationDate($execution),
                'posting_event_key' => 'TREASURY:'.(string) ($treasuryDraft['id'] ?? ''),
                'posting_status' => strtoupper((string) ($treasuryDraft['status'] ?? 'DRAFT')),
                'journal_no' => null,
                'posting_error' => null,
                'created_by_user_id' => $actor?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('pur_order_ap_lifecycles')->where('id', $lifecycle->id)->update([
                'settled_amount' => $paid,
                'balance_due' => $remaining,
                'status' => $apStatus,
                'last_settlement_at' => now(),
                'updated_by_user_id' => $actor?->id,
                'updated_at' => now(),
            ]);

            $this->appendInvoiceEvent((string) $invoice->id, 'REALIZATION_AP_SETTLED', 'Realisasi mengurangi hutang/AP', $invoiceStatus, $actor, $settlementKey, [
                'realization_kind' => $executionDefinition['kind'], 'realization_id' => $executionId,
                'payment_id' => $paymentId, 'amount' => $amount, 'remaining' => $remaining,
            ]);
            $this->appendRootEvent($execution, $executionDefinition['kind'], $executionId, 'REALIZATION_AP_SETTLED', 'AP Settlement / Terbayar', $apStatus, $actor, $settlementKey, $amount);

            // I03: no INVOICE_PAYMENT_POSTED outbox here. Treasury approval
            // is the only settlement GL source for this payment.

            return $this->serializeSettlement(DB::table('pur_order_ap_settlements')->where('id', $settlementId)->first(), false);
        }, 3);

        // I03 Treasury DRAFT owns the settlement posting lifecycle.
        $this->supersedeLegacyRealizationDraft($executionDefinition, $executionId);
        return $result;
    }

    public function assertSettlementAllowed(string $kind, string $executionId): void
    {
        $d = $this->executions->definition($kind);
        $execution = DB::table($d['table'])->where('id', $executionId)->whereNull('deleted_at')->first();
        if (! $execution) {
            throw ValidationException::withMessages(['realization_id' => 'Realization tidak ditemukan.']);
        }
        if (isset($execution->order_kind) && strtoupper((string) $execution->order_kind) !== $d['order_kind']) {
            return; // legacy/manual execution is outside canonical Order AP lifecycle.
        }
        $lifecycle = DB::table('pur_order_ap_lifecycles')
            ->where('order_kind', $d['order_kind'])
            ->where('order_id', $execution->order_id)
            ->first();
        if (! $lifecycle) {
            throw ValidationException::withMessages(['ap' => 'AP Order belum terbentuk. Jalankan sinkronisasi AP terlebih dahulu.']);
        }
        if (DB::table('pur_order_ap_settlements')->where('realization_kind', $d['kind'])->where('realization_id', $executionId)->exists()) {
            return;
        }
        $amount = $this->realizationAmount($execution);
        $balance = round((float) $lifecycle->balance_due, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['actual_total_amount' => 'Nominal realisasi approved harus lebih besar dari 0.']);
        }
        if ($amount > $balance + 0.009) {
            throw ValidationException::withMessages([
                'actual_total_amount' => sprintf('Nominal realisasi %.2f melebihi outstanding AP %.2f.', $amount, $balance),
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    public function lifecycleForOrder(string $kind, string $orderId): ?array
    {
        $definition = $this->orders->definition($kind);
        $row = DB::table('pur_order_ap_lifecycles')->where('order_kind', $definition['kind'])->where('order_id', $orderId)->first();
        return $row ? $this->serializeLifecycle($row, true) : null;
    }

    private function supersedeLegacyRealizationDraft(array $definition, string $executionId): void
    {
        if (! Schema::hasColumn($definition['table'], 'general_posting_id')) return;
        $execution = DB::table($definition['table'])->where('id', $executionId)->first();
        if (! $execution || empty($execution->general_posting_id)) {
            if (Schema::hasColumn($definition['table'], 'realization_status')) {
                DB::table($definition['table'])->where('id', $executionId)->update(['realization_status' => 'AP_SETTLED', 'updated_at' => now()]);
            }
            return;
        }
        $posting = DB::table('finance_general_postings')->where('id', $execution->general_posting_id)->first();
        if ($posting && (string) $posting->status === 'DRAFT' && (string) $posting->source_code === 'PUR_REALIZATION') {
            try {
                $this->generalPosting->destroyDraft((string) $posting->id);
                $update = ['general_posting_id' => null, 'realization_status' => 'AP_SETTLED', 'updated_at' => now()];
                if (Schema::hasColumn($definition['table'], 'realization_fingerprint')) $update['realization_fingerprint'] = null;
                DB::table($definition['table'])->where('id', $executionId)->update($update);
            } catch (Throwable) {
                // Never delete or mutate a posting Finance refuses to treat as an unused DRAFT.
            }
        }
    }

    private function attemptRecognitionPosting(array $result, ?User $actor): array
    {
        if (! isset($result['recognition_event_key'], $result['id'])) return $result;
        try {
            $posting = $this->financePosting->autoPostOutboxByEventKey((string) $result['recognition_event_key'], $actor?->id);
            $status = strtoupper((string) ($posting['status'] ?? 'PENDING'));
            DB::table('pur_order_ap_lifecycles')->where('id', $result['id'])->update([
                'recognition_posting_status' => $status,
                'recognition_journal_no' => $posting['journal_no'] ?? null,
                'recognition_error' => $status === 'POSTED' ? null : ($posting['message'] ?? null),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::table('pur_order_ap_lifecycles')->where('id', $result['id'])->update([
                'recognition_posting_status' => 'FAILED', 'recognition_error' => $e->getMessage(), 'updated_at' => now(),
            ]);
        }
        $row = DB::table('pur_order_ap_lifecycles')->where('id', $result['id'])->first();
        return $this->serializeLifecycle($row, (bool) ($result['idempotent'] ?? false));
    }

    private function attemptSettlementPosting(array $result, ?User $actor): array
    {
        if (! isset($result['posting_event_key'], $result['id'])) return $result;
        try {
            $posting = $this->financePosting->autoPostOutboxByEventKey((string) $result['posting_event_key'], $actor?->id);
            $status = strtoupper((string) ($posting['status'] ?? 'PENDING'));
            DB::table('pur_order_ap_settlements')->where('id', $result['id'])->update([
                'posting_status' => $status,
                'journal_no' => $posting['journal_no'] ?? null,
                'posting_error' => $status === 'POSTED' ? null : ($posting['message'] ?? null),
                'posted_at' => $status === 'POSTED' ? now() : null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::table('pur_order_ap_settlements')->where('id', $result['id'])->update([
                'posting_status' => 'FAILED', 'posting_error' => $e->getMessage(), 'updated_at' => now(),
            ]);
        }
        $row = DB::table('pur_order_ap_settlements')->where('id', $result['id'])->first();
        return $this->serializeSettlement($row, (bool) ($result['idempotent'] ?? false));
    }

    private function copyOrderItemsToInvoice(array $definition, string $orderId, string $invoiceId): void
    {
        $itemModelClass = $definition['item_model'];
        $itemTable = (new $itemModelClass())->getTable();
        $items = DB::table($itemTable)->where($definition['item_foreign_key'], $orderId)->orderBy('line_no')->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Order approved tidak mempunyai item untuk AP.']);
        }
        foreach ($items as $item) {
            $qtyField = $definition['qty_field'];
            $qty = round((float) ($item->{$qtyField} ?? 0), 4);
            $lineTotal = round((float) ($item->line_total ?? 0), 2);
            DB::table('pur_invoice_items')->insert([
                'id' => (string) Str::ulid(),
                'invoice_id' => $invoiceId,
                'line_no' => (int) $item->line_no,
                'source_item_kind' => $definition['kind'].'_ITEM',
                'source_item_id' => (string) $item->id,
                'sku_id' => $item->sku_id ?: null,
                'item_name' => trim((string) ($item->item_name ?? '')) ?: 'Item',
                'uom_text' => $item->uom_text ?: null,
                'qty' => max($qty, 0.0001),
                'unit_price' => round((float) ($item->unit_price ?? 0), 2),
                'tax_mode' => (string) ($item->tax_mode ?? 'NO_TAX'),
                'tax_percent' => round((float) ($item->tax_percent ?? 0), 4),
                'subtotal' => round((float) ($item->subtotal ?? ($qty * (float) ($item->unit_price ?? 0))), 2),
                'tax_amount' => round((float) ($item->tax_amount ?? 0), 2),
                'line_total' => $lineTotal,
                'notes' => $item->notes ?? null,
                'metadata' => json_encode(['order_kind' => $definition['kind'], 'order_item_id' => (string) $item->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function appendInvoiceEvent(string $invoiceId, string $code, string $label, string $status, ?User $actor, string $key, array $metadata): void
    {
        if (! Schema::hasTable('pur_invoice_events')) return;
        DB::table('pur_invoice_events')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'invoice_id' => $invoiceId, 'event_code' => $code, 'event_label' => $label,
            'status' => $status, 'actor_user_id' => $actor?->id, 'notes' => null, 'idempotency_key' => $key,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function appendRootEvent(object $source, string $kind, string $id, string $code, string $label, string $status, ?User $actor, string $key, float $amount): void
    {
        if (! Schema::hasTable('pur_document_events')) return;
        $fundRequestId = $source->fund_request_id ?? null;
        if (! $fundRequestId) return;
        $exists = DB::table('pur_document_events')->where('root_request_id', $fundRequestId)->where('event_code', $code)->where('reference_type', $kind)->where('reference_id', $id)->exists();
        if ($exists) return;
        DB::table('pur_document_events')->insert([
            'id' => (string) Str::ulid(), 'root_request_id' => $fundRequestId, 'document_type' => $kind,
            'document_id' => $id, 'event_code' => $code, 'event_label' => $label, 'status' => $status,
            'actor_user_id' => $actor?->id, 'actor_name_snapshot' => $actor?->name ?? $actor?->username,
            'occurred_at' => now(), 'reference_type' => $kind, 'reference_id' => $id,
            'reference_number' => $key, 'metadata' => json_encode(['amount' => $amount]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function orderSubtype(string $kind, object $order): string
    {
        if ($kind === OrderWorkflowCatalog::PURCHASE_ORDER) return strtoupper((string) ($order->order_type ?: 'PURCHASE'));
        if ($kind === OrderWorkflowCatalog::SERVICE_ORDER) return 'SERVICE';
        return 'REIMBURSE';
    }

    private function financeSourceKind(string $kind, string $subtype): string
    {
        if ($kind === OrderWorkflowCatalog::REIMBURSE_ORDER) return 'REIMBURSE_PAYMENT';
        if ($kind === OrderWorkflowCatalog::SERVICE_ORDER) return 'SERVICE_ACCEPTANCE';
        if ($subtype === 'ASSET') return 'ASSET_RECEIPT';
        if ($subtype === 'STOCK') return 'GOODS_RECEIPT';
        return 'SERVICE_ACCEPTANCE';
    }

    private function approvedDate(object $order): string
    {
        foreach (['finance_approved_2_at', 'approved_at', 'order_date'] as $field) {
            if (! empty($order->{$field})) return substr((string) $order->{$field}, 0, 10);
        }
        return now('Asia/Jakarta')->toDateString();
    }

    private function dueDate(object $order, string $fallback): string
    {
        $needed = trim((string) ($order->needed_date ?? ''));
        return $needed !== '' ? substr($needed, 0, 10) : $fallback;
    }

    private function realizationAmount(object $execution): float
    {
        foreach (['actual_total_amount', 'total_amount'] as $field) {
            if (isset($execution->{$field}) && (float) $execution->{$field} > 0) return round((float) $execution->{$field}, 2);
        }
        return 0.0;
    }

    private function realizationDate(object $execution): string
    {
        foreach (['realization_date', 'document_date', 'approved_at', 'posted_at'] as $field) {
            if (! empty($execution->{$field})) return substr((string) $execution->{$field}, 0, 10);
        }
        return now('Asia/Jakarta')->toDateString();
    }

    private function paymentMethod(object $execution): string
    {
        $method = strtoupper(trim((string) ($execution->payment_method ?? 'OTHER')));
        return in_array($method, ['CASH', 'PETTY_CASH', 'BANK_TRANSFER', 'GIRO', 'VIRTUAL_ACCOUNT', 'OTHER'], true) ? $method : 'OTHER';
    }

    private function statusFromBalance(float $total, float $paid): string
    {
        if ($paid <= 0.009) return self::OPEN;
        if ($paid + 0.009 >= $total) return self::PAID;
        return self::PARTIALLY_PAID;
    }

    private function nextApNumber(string $kind, string $orderId): string
    {
        $prefix = match ($kind) {
            OrderWorkflowCatalog::PURCHASE_ORDER => 'AP-PO',
            OrderWorkflowCatalog::SERVICE_ORDER => 'AP-SO',
            default => 'AP-RO',
        };
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr($orderId, -8));
    }

    private function nextPaymentNumber(string $executionId): string
    {
        return 'APP-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr($executionId, -8));
    }

    /** @return array<string,mixed> */
    private function serializeLifecycle(object $row, bool $idempotent): array
    {
        return [
            'id' => (string) $row->id, 'source_key' => (string) $row->source_key,
            'order_kind' => (string) $row->order_kind, 'order_id' => (string) $row->order_id,
            'order_subtype' => (string) $row->order_subtype, 'invoice_id' => (string) $row->invoice_id,
            'liability_amount' => round((float) $row->liability_amount, 2), 'settled_amount' => round((float) $row->settled_amount, 2),
            'balance_due' => round((float) $row->balance_due, 2), 'status' => (string) $row->status,
            'recognition_event_key' => (string) $row->recognition_event_key,
            'recognition_posting_status' => (string) $row->recognition_posting_status,
            'recognition_journal_no' => $row->recognition_journal_no, 'recognition_error' => $row->recognition_error,
            'idempotent' => $idempotent,
        ];
    }

    /** @return array<string,mixed> */
    private function serializeSettlement(object $row, bool $idempotent): array
    {
        return [
            'id' => (string) $row->id, 'ap_lifecycle_id' => (string) $row->ap_lifecycle_id,
            'settlement_key' => (string) $row->settlement_key, 'realization_kind' => (string) $row->realization_kind,
            'realization_id' => (string) $row->realization_id, 'invoice_payment_id' => (string) $row->invoice_payment_id,
            'amount' => round((float) $row->amount, 2), 'payment_method' => (string) $row->payment_method,
            'payment_date' => (string) $row->payment_date, 'posting_event_key' => (string) $row->posting_event_key,
            'posting_status' => (string) $row->posting_status, 'journal_no' => $row->journal_no,
            'posting_error' => $row->posting_error, 'idempotent' => $idempotent,
        ];
    }
}
