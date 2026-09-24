<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AccountPayableRealizationGateService
{
    private const FINAL_STATUSES = ['POSTED', 'APPROVED'];

    public function __construct(private readonly ExecutionWorkflowCatalog $executions)
    {
    }

    /** @return array<string,mixed> */
    public function eligibilityForInvoice(object|string $invoice): array
    {
        $row = is_string($invoice)
            ? DB::table('pur_invoices')->where('id', $invoice)->whereNull('deleted_at')->first()
            : $invoice;

        if (! $row) {
            return $this->result(false, 'INVOICE_NOT_FOUND', 'Invoice tidak ditemukan.', false);
        }

        if (strtoupper((string) ($row->direction ?? '')) !== 'INCOMING') {
            return $this->result(true, 'NOT_ACCOUNT_PAYABLE', null, false);
        }

        $requirement = $this->resolveRequirement($row);
        $balance = round((float) ($row->balance_due ?? 0), 2);

        if (! $requirement['required']) {
            if ($balance <= 0.009) {
                return $this->result(false, 'SETTLED', 'Account Payable sudah lunas.', false, $requirement);
            }
            return $this->result(true, 'NO_REALIZATION_REQUIRED', null, false, $requirement);
        }

        if (! $requirement['realization']) {
            $label = (string) ($requirement['expected_label'] ?? 'Realization');
            return $this->result(
                false,
                'WAITING_REALIZATION',
                "Waiting Realization: {$label} belum tersedia.",
                true,
                $requirement
            );
        }

        $realization = $requirement['realization'];
        $status = strtoupper((string) ($realization['status'] ?? ''));
        $complete = in_array($status, self::FINAL_STATUSES, true);

        if (! $complete) {
            $number = trim((string) ($realization['number'] ?? '')) ?: (string) ($requirement['expected_label'] ?? 'Realization');
            return $this->result(
                false,
                'WAITING_REALIZATION',
                "Waiting Realization: {$number} masih {$status}. Selesaikan sampai POSTED/APPROVED sebelum pembayaran AP.",
                true,
                $requirement
            );
        }

        if ($balance <= 0.009) {
            return $this->result(false, 'SETTLED', 'Account Payable sudah lunas.', true, $requirement);
        }

        return $this->result(true, 'REALIZATION_FINAL', null, true, $requirement);
    }

    public function assertInvoiceCanBePaid(object|string $invoice): void
    {
        $eligibility = $this->eligibilityForInvoice($invoice);
        if (($eligibility['eligible'] ?? false) === true) return;

        if (($eligibility['realization_required'] ?? false) !== true) return;

        throw ValidationException::withMessages([
            'realization' => [(string) ($eligibility['reason'] ?? 'Realization belum selesai sehingga Account Payable belum dapat dibayar.')],
        ]);
    }

    public function assertRealizationMaySettle(string $kind, string $executionId): void
    {
        $definition = $this->executions->definition($kind);
        $execution = DB::table($definition['table'])->where('id', $executionId)->whereNull('deleted_at')->first();
        if (! $execution) {
            throw ValidationException::withMessages(['realization_id' => 'Realization tidak ditemukan.']);
        }

        $status = strtoupper((string) ($execution->status ?? ''));
        if (! in_array($status, self::FINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "{$definition['label']} harus POSTED/APPROVED sebelum settlement AP.",
            ]);
        }

        if (! Schema::hasTable('pur_order_ap_lifecycles')) return;

        $lifecycle = DB::table('pur_order_ap_lifecycles')
            ->where('order_kind', $definition['order_kind'])
            ->where('order_id', (string) $execution->order_id)
            ->first();
        if (! $lifecycle) return;

        $expected = $this->expectedDefinition((string) $lifecycle->order_kind, (string) $lifecycle->order_subtype);
        if (! $expected) return;

        if ($expected['kind'] !== $definition['kind']) {
            throw ValidationException::withMessages([
                'realization_id' => sprintf(
                    'AP %s/%s menunggu %s, bukan %s.',
                    (string) $lifecycle->order_kind,
                    (string) $lifecycle->order_id,
                    $expected['label'],
                    $definition['label']
                ),
            ]);
        }
    }

    /** @return array<string,mixed> */
    public function metadataSnapshot(object|string $invoice): array
    {
        $eligibility = $this->eligibilityForInvoice($invoice);
        return [
            'version' => 'ERP_V5_ITERATION_14',
            'eligible' => (bool) ($eligibility['eligible'] ?? false),
            'status' => (string) ($eligibility['status'] ?? 'UNKNOWN'),
            'reason' => $eligibility['reason'] ?? null,
            'realization_required' => (bool) ($eligibility['realization_required'] ?? false),
            'expected_realization_kind' => $eligibility['expected_realization_kind'] ?? null,
            'expected_realization_label' => $eligibility['expected_realization_label'] ?? null,
            'realization' => $eligibility['realization'] ?? null,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function resolveRequirement(object $invoice): array
    {
        $orderKind = strtoupper(trim((string) ($invoice->ap_order_kind ?? '')));
        $orderId = trim((string) ($invoice->ap_order_id ?? ''));
        $orderSubtype = strtoupper(trim((string) ($invoice->ap_order_subtype ?? '')));

        if (($orderKind === '' || $orderId === '') && Schema::hasTable('pur_order_ap_lifecycles')) {
            $life = DB::table('pur_order_ap_lifecycles')->where('invoice_id', (string) $invoice->id)->first();
            if ($life) {
                $orderKind = strtoupper((string) $life->order_kind);
                $orderId = (string) $life->order_id;
                $orderSubtype = strtoupper((string) $life->order_subtype);
            }
        }

        if ($orderKind !== '' && $orderId !== '') {
            $expected = $this->expectedDefinition($orderKind, $orderSubtype);
            if ($expected) {
                $realization = $this->realizationForOrder($expected, $orderId);
                return [
                    'required' => true,
                    'order_kind' => $orderKind,
                    'order_id' => $orderId,
                    'order_subtype' => $orderSubtype,
                    'expected_kind' => $expected['kind'],
                    'expected_label' => $expected['label'],
                    'realization' => $realization,
                ];
            }
        }

        $sourceKind = strtoupper(trim((string) ($invoice->source_document_kind ?? '')));
        $sourceId = trim((string) ($invoice->source_document_id ?? ''));

        if ($sourceId !== '' && in_array($sourceKind, ['SERVICE_ENTRY_SHEET', 'GOODS_RECEIPT', 'SERVICE_ACCEPTANCE', 'REIMBURSE_PAYMENT'], true)) {
            $definition = $this->executions->definition($sourceKind);
            $row = DB::table($definition['table'])->where('id', $sourceId)->whereNull('deleted_at')->first();
            return [
                'required' => true,
                'order_kind' => $row?->order_kind ?? $definition['order_kind'],
                'order_id' => $row?->order_id ?? null,
                'order_subtype' => null,
                'expected_kind' => $definition['kind'],
                'expected_label' => $definition['label'],
                'realization' => $row ? $this->serializeRealization($definition, $row) : null,
            ];
        }

        if ($sourceKind === 'WAREHOUSE_OUTGOING_INVOICE' && $sourceId !== '' && Schema::hasTable('wh_v3_outgoing_invoices')) {
            $source = DB::table('wh_v3_outgoing_invoices')->where('id', $sourceId)->first();
            $gr = $source && ! empty($source->goods_receipt_id) && Schema::hasTable('wh_v3_goods_receipts')
                ? DB::table('wh_v3_goods_receipts')->where('id', $source->goods_receipt_id)->first()
                : null;
            $realization = $gr ? [
                'kind' => 'WAREHOUSE_GOODS_RECEIPT',
                'label' => 'Warehouse Goods Receipt',
                'id' => (string) $gr->id,
                'number' => (string) ($gr->goods_receipt_number ?? $source->source_number ?? $invoice->source_document_number ?? ''),
                'status' => strtoupper((string) $gr->status) === 'COMPLETED' ? 'APPROVED' : strtoupper((string) $gr->status),
                'final_at' => $gr->completed_at ?? $gr->updated_at ?? null,
            ] : null;
            return [
                'required' => true,
                'order_kind' => null,
                'order_id' => null,
                'order_subtype' => 'WAREHOUSE_STOCK_REQUEST',
                'expected_kind' => 'WAREHOUSE_GOODS_RECEIPT',
                'expected_label' => 'Warehouse Goods Receipt',
                'realization' => $realization,
            ];
        }

        return [
            'required' => false,
            'order_kind' => $orderKind ?: null,
            'order_id' => $orderId ?: null,
            'order_subtype' => $orderSubtype ?: null,
            'expected_kind' => null,
            'expected_label' => null,
            'realization' => null,
        ];
    }

    /** @return array<string,string>|null */
    private function expectedDefinition(string $orderKind, string $subtype): ?array
    {
        $kind = strtoupper(str_replace('-', '_', trim($orderKind)));
        $type = strtoupper(trim($subtype));

        return match ($kind) {
            'PURCHASE_ORDER' => in_array($type, ['ASSET', 'STOCK'], true)
                ? ['kind' => 'GOODS_RECEIPT', 'label' => 'Goods Receipt']
                : ['kind' => 'SERVICE_ENTRY_SHEET', 'label' => 'Service Entry Sheet'],
            'SERVICE_ORDER' => ['kind' => 'SERVICE_ACCEPTANCE', 'label' => 'Service Acceptance'],
            'REIMBURSE_ORDER' => ['kind' => 'REIMBURSE_PAYMENT', 'label' => 'Reimburse Payment'],
            default => null,
        };
    }

    /** @param array<string,string> $expected @return array<string,mixed>|null */
    private function realizationForOrder(array $expected, string $orderId): ?array
    {
        $definition = $this->executions->definition($expected['kind']);
        if (! Schema::hasTable($definition['table'])) return null;

        $query = DB::table($definition['table'])
            ->where('order_id', $orderId)
            ->whereNull('deleted_at');
        if (Schema::hasColumn($definition['table'], 'order_kind')) {
            $query->where('order_kind', $definition['order_kind']);
        }

        $row = $query->orderByDesc('created_at')->first();
        return $row ? $this->serializeRealization($definition, $row) : null;
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function serializeRealization(array $definition, object $row): array
    {
        $finalAt = null;
        foreach (['approved_at', 'posted_at', 'completed_at', 'updated_at'] as $field) {
            if (! empty($row->{$field})) {
                $finalAt = $row->{$field};
                break;
            }
        }

        return [
            'kind' => (string) $definition['kind'],
            'label' => (string) $definition['label'],
            'id' => (string) $row->id,
            'number' => (string) ($row->{$definition['number']} ?? ''),
            'status' => strtoupper((string) ($row->status ?? '')),
            'final_at' => $finalAt,
        ];
    }

    /** @param array<string,mixed> $requirement @return array<string,mixed> */
    private function result(bool $eligible, string $status, ?string $reason, bool $required, array $requirement = []): array
    {
        return [
            'eligible' => $eligible,
            'status' => $status,
            'reason' => $reason,
            'realization_required' => $required,
            'order_kind' => $requirement['order_kind'] ?? null,
            'order_id' => $requirement['order_id'] ?? null,
            'order_subtype' => $requirement['order_subtype'] ?? null,
            'expected_realization_kind' => $requirement['expected_kind'] ?? null,
            'expected_realization_label' => $requirement['expected_label'] ?? null,
            'realization' => $requirement['realization'] ?? null,
            'contract_version' => 'ERP_V5_ITERATION_14',
        ];
    }
}
