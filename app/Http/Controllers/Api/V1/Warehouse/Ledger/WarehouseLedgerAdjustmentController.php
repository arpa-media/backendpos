<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Ledger;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseLedgerPosting;
use App\Services\Warehouse\WarehouseLedgerPermissionService;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseLedgerAdjustmentController extends WarehouseLedgerBaseController
{
    public function store(
        Request $request,
        WarehouseLedgerService $ledger,
        WarehouseLedgerPermissionService $permissions
    ): JsonResponse {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'movement_type' => ['required', Rule::in(['adjustment_in', 'adjustment_out'])],
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'allow_negative' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_key' => ['nullable', 'string', 'max:120'],
            'lines.*.sku_id' => ['required', 'ulid', 'exists:stk_skus,id'],
            'lines.*.batch_id' => ['required', 'ulid', 'exists:wh_batches,id'],
            'lines.*.storage_id' => ['required', 'ulid', 'exists:wh_storages,id'],
            'lines.*.quantity_base' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $allowNegative = (bool) ($data['allow_negative'] ?? false);
        if ($allowNegative && ! (
            $permissions->allows($request->user(), 'warehouse.ledger.negative_override')
            || $permissions->allows($request->user(), 'warehouse.ledger.adjustment.delete')
        )) {
            throw ValidationException::withMessages([
                'allow_negative' => ['Akun tidak memiliki permission warehouse.ledger.negative_override.'],
            ]);
        }

        $direction = $data['movement_type'] === 'adjustment_in' ? 'IN' : 'OUT';
        $referenceId = 'ADJ-'.strtoupper(substr(hash('sha256', $data['idempotency_key']), 0, 26));
        $posting = $ledger->post([
            'warehouse_id' => $warehouseId,
            'idempotency_key' => $data['idempotency_key'],
            'movement_type' => $data['movement_type'],
            'reference_type' => 'wh_manual_adjustment',
            'reference_id' => $referenceId,
            'business_date' => $data['business_date'],
            'reason' => trim($data['reason']),
            'user_id' => $request->user()?->id,
            'allow_negative' => $allowNegative,
            'negative_override_authorized' => $allowNegative,
            'metadata' => [
                'source' => 'warehouse_backoffice',
                'manual_adjustment_reference' => $referenceId,
            ],
            'lines' => collect($data['lines'])->map(fn (array $line, int $index) => [
                'line_key' => trim((string) ($line['line_key'] ?? '')) ?: 'ADJ-'.($index + 1),
                'sku_id' => $line['sku_id'],
                'batch_id' => $line['batch_id'],
                'storage_id' => $line['storage_id'],
                'direction' => $direction,
                'quantity_base' => (float) $line['quantity_base'],
                'unit_cost' => $direction === 'IN' ? ($line['unit_cost'] ?? null) : null,
                'metadata' => ['notes' => trim((string) ($line['notes'] ?? '')) ?: null],
            ])->all(),
        ]);

        return ApiResponse::ok($this->serializePosting($posting), 'Stock adjustment berhasil diposting.', 201);
    }

    public function reverse(
        Request $request,
        string $id,
        WarehouseLedgerService $ledger,
        WarehouseLedgerPermissionService $permissions
    ): JsonResponse {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'allow_negative' => ['nullable', 'boolean'],
        ]);

        $posting = WarehouseLedgerPosting::query()
            ->where('warehouse_id', $warehouseId)
            ->find($id);
        if (! $posting) {
            return ApiResponse::error('Posting ledger tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $allowNegative = (bool) ($data['allow_negative'] ?? false);
        if ($allowNegative && ! (
            $permissions->allows($request->user(), 'warehouse.ledger.negative_override')
            || $permissions->allows($request->user(), 'warehouse.ledger.adjustment.delete')
        )) {
            throw ValidationException::withMessages([
                'allow_negative' => ['Akun tidak memiliki permission negative override.'],
            ]);
        }

        $reversal = $ledger->reverse(
            $posting,
            (string) $request->user()?->id,
            trim($data['reason']),
            $allowNegative,
            $data['idempotency_key']
        );

        return ApiResponse::ok($this->serializePosting($reversal), 'Posting ledger berhasil direversal.');
    }

    private function serializePosting(WarehouseLedgerPosting $posting): array
    {
        $posting->loadMissing(['entries.sku.baseUom', 'entries.batch', 'entries.storage']);

        return [
            'id' => (string) $posting->id,
            'warehouse_id' => (string) $posting->warehouse_id,
            'idempotency_key' => (string) $posting->idempotency_key,
            'movement_type' => (string) $posting->movement_type,
            'reference_type' => (string) $posting->reference_type,
            'reference_id' => (string) $posting->reference_id,
            'business_date' => $posting->business_date?->format('Y-m-d'),
            'status' => (string) $posting->status,
            'reason' => $posting->reason,
            'reversal_of_id' => $posting->reversal_of_id,
            'posted_at' => $posting->posted_at?->toIso8601String(),
            'entries' => $posting->entries->map(fn ($entry) => [
                'id' => (string) $entry->id,
                'line_key' => (string) $entry->line_key,
                'direction' => (string) $entry->direction,
                'sku_id' => (string) $entry->sku_id,
                'sku_code' => (string) ($entry->sku?->sku_code ?? ''),
                'item_name' => (string) ($entry->sku?->name ?? ''),
                'base_uom_code' => (string) ($entry->sku?->baseUom?->code ?? ''),
                'batch_id' => (string) $entry->batch_id,
                'batch_code' => (string) ($entry->batch?->batch_code ?? ''),
                'storage_id' => (string) $entry->storage_id,
                'storage_code' => (string) ($entry->storage?->code ?? ''),
                'quantity_base' => (float) $entry->quantity_base,
                'signed_quantity_base' => (float) $entry->signed_quantity_base,
                'unit_cost' => (float) $entry->unit_cost,
                'total_cost' => (float) $entry->total_cost,
                'batch_qty_after' => (float) $entry->batch_qty_after,
                'aggregate_qty_after' => (float) $entry->aggregate_qty_after,
                'average_cost_after' => (float) $entry->average_cost_after,
                'inventory_value_after' => (float) $entry->inventory_value_after,
            ])->values(),
        ];
    }
}
