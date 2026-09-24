<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseStockRequestLiabilityOwnershipService
{
    public const POLICY_KEY = 'WAREHOUSE_STOCK_REQUEST_ORDER_AP';
    public const ROLE_MIRROR = 'MIRROR';
    public const STATUS_COVERED = 'COVERED';
    public const STATUS_OWNER_PENDING = 'OWNER_PENDING';
    public const STATUS_LEGACY_CONFLICT = 'LEGACY_POSTED_CONFLICT';

    /**
     * Warehouse Stock Request has one liability owner: Order AP lifecycle.
     * The Warehouse Incoming Invoice remains a commercial/audit document only.
     * It must never create a second AP liability or accept a second AP payment.
     *
     * @return array<string,mixed>
     */
    public function coverIfWarehouseStockRequestMirror(string $invoiceId, ?string $userId = null): array
    {
        if (! $this->ready()) {
            return ['is_mirror' => false, 'coverage_status' => 'UNAVAILABLE'];
        }

        return DB::transaction(function () use ($invoiceId, $userId): array {
            $ctx = $this->resolveContext($invoiceId, true);
            if (! $ctx) {
                return ['is_mirror' => false, 'coverage_status' => 'NOT_APPLICABLE'];
            }

            $existingPosting = $this->mirrorFinancePosting($invoiceId);
            $legacyConflict = $existingPosting && strtoupper((string) $existingPosting->status) === 'POSTED';
            $coverageStatus = $legacyConflict
                ? self::STATUS_LEGACY_CONFLICT
                : ($ctx['lifecycle'] ? self::STATUS_COVERED : self::STATUS_OWNER_PENDING);

            $canonicalGeneralPostingId = $ctx['lifecycle']
                ? $this->canonicalGeneralPostingId((string) $ctx['lifecycle']->recognition_event_key)
                : null;
            $outbox = DB::table('pur_finance_posting_outbox')
                ->where('event_key', 'invoice-issued:' . $invoiceId)
                ->lockForUpdate()
                ->first();

            $ownershipId = (string) (DB::table('pur_invoice_liability_ownerships')->where('invoice_id', $invoiceId)->value('id') ?: Str::ulid());
            $payload = [
                'liability_role' => self::ROLE_MIRROR,
                'policy_key' => self::POLICY_KEY,
                'warehouse_outgoing_invoice_id' => (string) $ctx['warehouse_invoice']->id,
                'stock_request_id' => (string) $ctx['warehouse_invoice']->source_id,
                'order_kind' => $ctx['order'] ? 'PURCHASE_ORDER' : null,
                'order_id' => $ctx['order']?->id,
                'ap_lifecycle_id' => $ctx['lifecycle']?->id,
                'canonical_ap_invoice_id' => $ctx['lifecycle']?->invoice_id,
                'canonical_recognition_event_key' => $ctx['lifecycle']?->recognition_event_key,
                'canonical_recognition_journal_no' => $ctx['lifecycle']?->recognition_journal_no,
                'canonical_general_posting_id' => $canonicalGeneralPostingId,
                'covered_outbox_id' => $outbox?->id,
                'coverage_status' => $coverageStatus,
                'metadata' => json_encode([
                    'version' => 'ERP-V5-I06',
                    'source_document_kind' => (string) $ctx['invoice']->source_document_kind,
                    'warehouse_source_number' => (string) ($ctx['warehouse_invoice']->source_number ?? ''),
                    'canonical_owner' => 'ORDER_AP_LIFECYCLE',
                    'legacy_posted_conflict' => $legacyConflict,
                    'updated_by_user_id' => $userId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];

            DB::table('pur_invoice_liability_ownerships')->updateOrInsert(
                ['invoice_id' => $invoiceId],
                $payload + ['id' => $ownershipId, 'created_at' => now()]
            );

            if (! $legacyConflict) {
                $meta = $this->decodeJson($ctx['invoice']->metadata ?? null);
                $meta['liability_role'] = self::ROLE_MIRROR;
                $meta['liability_policy'] = self::POLICY_KEY;
                $meta['canonical_ap_invoice_id'] = $ctx['lifecycle']?->invoice_id;
                $meta['canonical_ap_lifecycle_id'] = $ctx['lifecycle']?->id;
                $meta['canonical_recognition_event_key'] = $ctx['lifecycle']?->recognition_event_key;
                $meta['canonical_recognition_journal_no'] = $ctx['lifecycle']?->recognition_journal_no;
                $meta['finance_posting_policy'] = 'MIRROR_NO_LIABILITY';
                $meta['i06_covered_at'] = now()->toIso8601String();

                // MIRROR status deliberately excludes this commercial invoice from Account Payable aging.
                // Warehouse Finance V3 is patched in I06 to still treat MIRROR as proof that Purchasing
                // received/issued the commercial document.
                DB::table('pur_invoices')->where('id', $invoiceId)->update([
                    'status' => 'MIRROR',
                    'journal_status' => $ctx['lifecycle']?->recognition_journal_no ? 'COVERED' : 'OWNER_PENDING',
                    'journal_reference' => $ctx['lifecycle']?->recognition_journal_no,
                    'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

                if ($outbox && in_array(strtoupper((string) $outbox->status), ['PENDING', 'FAILED'], true)) {
                    $outboxUpdate = [
                        'status' => 'COVERED',
                        'last_error' => null,
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('pur_finance_posting_outbox', 'general_posting_id')) {
                        $outboxUpdate['general_posting_id'] = $canonicalGeneralPostingId;
                    }
                    DB::table('pur_finance_posting_outbox')->where('id', $outbox->id)->update($outboxUpdate);
                }

                if ($existingPosting && strtoupper((string) $existingPosting->status) === 'DRAFT') {
                    $postingUpdate = [
                        'status' => 'CANCELLED',
                        'updated_by_user_id' => $userId,
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('finance_purchasing_postings', 'reopen_reason')) {
                        $postingUpdate['reopen_reason'] = 'ERP-V5 I06: Warehouse Stock Request invoice is a commercial mirror; Order AP lifecycle owns liability.';
                    }
                    DB::table('finance_purchasing_postings')->where('id', $existingPosting->id)->update($postingUpdate);
                }
            }

            return [
                'is_mirror' => true,
                'liability_role' => self::ROLE_MIRROR,
                'coverage_status' => $coverageStatus,
                'invoice_id' => $invoiceId,
                'warehouse_outgoing_invoice_id' => (string) $ctx['warehouse_invoice']->id,
                'stock_request_id' => (string) $ctx['warehouse_invoice']->source_id,
                'order_id' => $ctx['order']?->id,
                'ap_lifecycle_id' => $ctx['lifecycle']?->id,
                'canonical_ap_invoice_id' => $ctx['lifecycle']?->invoice_id,
                'canonical_recognition_event_key' => $ctx['lifecycle']?->recognition_event_key,
                'canonical_recognition_journal_no' => $ctx['lifecycle']?->recognition_journal_no,
                'canonical_general_posting_id' => $canonicalGeneralPostingId,
                'legacy_posted_conflict' => $legacyConflict,
            ];
        }, 5);
    }

    public function assertInvoiceCanBePaid(string $invoiceId): void
    {
        if (! $this->isMirror($invoiceId)) return;

        $ownership = $this->ownership($invoiceId);
        $canonical = $ownership?->canonical_ap_invoice_id
            ? DB::table('pur_invoices')->where('id', $ownership->canonical_ap_invoice_id)->value('invoice_number')
            : null;

        throw ValidationException::withMessages([
            'invoice_id' => [
                'Invoice Warehouse Stock Request adalah mirror komersial dan bukan hutang baru. '
                . 'Pembayaran harus mengikuti Realization/AP canonical'
                . ($canonical ? ' ' . $canonical : '') . '.',
            ],
        ]);
    }

    public function isMirror(string $invoiceId): bool
    {
        if (! $this->ready()) return false;
        if (DB::table('pur_invoice_liability_ownerships')->where('invoice_id', $invoiceId)->where('liability_role', self::ROLE_MIRROR)->exists()) {
            return true;
        }
        return $this->resolveContext($invoiceId, false) !== null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function decorateInvoicePayload(array $payload): array
    {
        if (! $this->ready()) return $payload;

        if (isset($payload['header']) && is_array($payload['header']) && ! empty($payload['header']['id'])) {
            $payload['header'] = $this->decorateRow($payload['header']);
            return $payload;
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            $ids = collect($payload['items'])->pluck('id')->filter()->map(fn ($v) => (string) $v)->all();
            $owners = $ids === [] ? collect() : DB::table('pur_invoice_liability_ownerships')->whereIn('invoice_id', $ids)->get()->keyBy('invoice_id');
            $payload['items'] = collect($payload['items'])->map(function ($row) use ($owners) {
                $data = is_array($row) ? $row : (array) $row;
                $owner = $owners->get((string) ($data['id'] ?? ''));
                return $owner ? $this->decorateRow($data, $owner) : $data;
            })->all();
        }
        return $payload;
    }

    /** @return object|null */
    public function ownership(string $invoiceId): ?object
    {
        if (! Schema::hasTable('pur_invoice_liability_ownerships')) return null;
        return DB::table('pur_invoice_liability_ownerships')->where('invoice_id', $invoiceId)->first();
    }

    /** @return array<string,mixed>|null */
    private function resolveContext(string $invoiceId, bool $lock): ?array
    {
        $invoiceQuery = DB::table('pur_invoices')->where('id', $invoiceId)->whereNull('deleted_at');
        if ($lock) $invoiceQuery->lockForUpdate();
        $invoice = $invoiceQuery->first();
        if (! $invoice || strtoupper((string) $invoice->direction) !== 'INCOMING' || strtoupper((string) $invoice->source_document_kind) !== 'WAREHOUSE_OUTGOING_INVOICE') {
            return null;
        }

        $warehouseInvoice = DB::table('wh_v3_outgoing_invoices')
            ->where('id', $invoice->source_document_id)
            ->first();
        if (! $warehouseInvoice
            || strtolower((string) $warehouseInvoice->source_type) !== 'stock_request'
            || strtolower((string) $warehouseInvoice->destination_type) !== 'outlet'
            || empty($warehouseInvoice->source_id)) {
            return null;
        }

        $lifecycle = DB::table('pur_order_ap_lifecycles as al')
            ->join('pur_purchase_orders as po', 'po.id', '=', 'al.order_id')
            ->where('al.order_kind', 'PURCHASE_ORDER')
            ->where('po.stock_request_id', $warehouseInvoice->source_id)
            ->orderByDesc('al.created_at')
            ->first([
                'al.id', 'al.order_kind', 'al.order_id', 'al.invoice_id', 'al.source_key',
                'al.recognition_event_key', 'al.recognition_posting_status', 'al.recognition_journal_no',
                'al.status', 'al.balance_due', 'po.po_number', 'po.stock_request_id',
            ]);

        $order = $lifecycle
            ? DB::table('pur_purchase_orders')->where('id', $lifecycle->order_id)->first(['id', 'po_number', 'stock_request_id', 'status'])
            : DB::table('pur_purchase_orders')
                ->where('stock_request_id', $warehouseInvoice->source_id)
                ->orderByDesc('approved_at')
                ->orderByDesc('created_at')
                ->first(['id', 'po_number', 'stock_request_id', 'status']);

        return [
            'invoice' => $invoice,
            'warehouse_invoice' => $warehouseInvoice,
            'order' => $order,
            'lifecycle' => $lifecycle,
        ];
    }

    private function canonicalGeneralPostingId(string $recognitionEventKey): ?string
    {
        if (Schema::hasColumn('pur_finance_posting_outbox', 'general_posting_id')) {
            $id = DB::table('pur_finance_posting_outbox')->where('event_key', $recognitionEventKey)->value('general_posting_id');
            if ($id) return (string) $id;
        }

        if (! Schema::hasTable('finance_purchasing_postings')
            || ! Schema::hasTable('finance_purchasing_posting_journals')
            || ! Schema::hasTable('finance_general_posting_journals')) return null;

        $row = DB::table('finance_purchasing_postings as p')
            ->join('finance_purchasing_posting_journals as pj', function ($join): void {
                $join->on('pj.purchasing_posting_id', '=', 'p.id')
                    ->on('pj.posting_version', '=', 'p.posting_version');
            })
            ->join('finance_general_posting_journals as gj', 'gj.journal_entry_id', '=', 'pj.journal_entry_id')
            ->where('p.event_key', $recognitionEventKey)
            ->where('p.status', 'POSTED')
            ->first(['gj.general_posting_id']);

        return $row?->general_posting_id ? (string) $row->general_posting_id : null;
    }

    private function mirrorFinancePosting(string $invoiceId): ?object
    {
        if (! Schema::hasTable('finance_purchasing_postings')) return null;
        return DB::table('finance_purchasing_postings')
            ->where('invoice_id', $invoiceId)
            ->where('event_type', 'INVOICE_ISSUED')
            ->orderByDesc('created_at')
            ->first();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decorateRow(array $row, ?object $owner = null): array
    {
        $owner ??= ! empty($row['id']) ? $this->ownership((string) $row['id']) : null;
        if (! $owner) return $row;

        $canonicalNumber = $owner->canonical_ap_invoice_id
            ? DB::table('pur_invoices')->where('id', $owner->canonical_ap_invoice_id)->value('invoice_number')
            : null;
        $row['liability_role'] = (string) $owner->liability_role;
        $row['liability_policy'] = (string) $owner->policy_key;
        $row['liability_coverage_status'] = (string) $owner->coverage_status;
        $row['canonical_ap_invoice_id'] = $owner->canonical_ap_invoice_id;
        $row['canonical_ap_invoice_number'] = $canonicalNumber;
        $row['canonical_recognition_journal_no'] = $owner->canonical_recognition_journal_no;
        $row['canonical_general_posting_id'] = $owner->canonical_general_posting_id;
        $row['payment_eligibility'] = [
            'eligible' => false,
            'code' => 'LIABILITY_MIRROR',
            'message' => 'Mirror invoice Warehouse. Liability dan pembayaran dimiliki Order AP canonical' . ($canonicalNumber ? ' ' . $canonicalNumber : '') . '.',
        ];
        return $row;
    }

    private function ready(): bool
    {
        return Schema::hasTable('pur_invoice_liability_ownerships')
            && Schema::hasTable('pur_invoices')
            && Schema::hasTable('wh_v3_outgoing_invoices')
            && Schema::hasTable('pur_purchase_orders')
            && Schema::hasTable('pur_order_ap_lifecycles')
            && Schema::hasTable('pur_finance_posting_outbox');
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
