<?php

namespace App\Services\Warehouse;

use App\Models\Warehouse\WarehouseKeeperTask;
use App\Models\Warehouse\WarehouseProductionTask;
use App\Models\Warehouse\WarehouseSignedScanToken;
use App\Models\Warehouse\WarehouseStockTransferTask;
use App\Models\Warehouse\WarehouseTaskAssignment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarehouseMobileScannerService
{
    public const WORKFLOWS = [
        'checker_prepare' => 'Checker Prepare',
        'checker_keeper' => 'Checker Keeper',
        'checker_production' => 'Checker Production',
        'checker_transfer' => 'Checker Transfer',
    ];

    public function __construct(
        private readonly WarehouseFulfillmentService $fulfillmentService,
        private readonly WarehouseProcurementService $procurementService,
        private readonly WarehouseProductionService $productionService,
        private readonly WarehouseStockTransferService $transferService,
    ) {
    }

    public function tasks(string $warehouseId, string $userId): array
    {
        $rows = collect();

        WarehouseTaskAssignment::query()
            ->where('warehouse_id', $warehouseId)
            ->where('assigned_to_user_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['item.sku.baseUom', 'fulfillment.request'])
            ->orderByDesc('assigned_at')
            ->get()
            ->each(function (WarehouseTaskAssignment $task) use ($rows): void {
                $item = $task->item;
                $rows->push($this->taskRow(
                    'checker_prepare', (string) $task->id, (string) $task->status,
                    (string) ($task->fulfillment?->request?->request_number ?? '-'),
                    $item?->sku?->sku_code, $item?->sku?->name,
                    (float) ($item?->requested_qty_base ?? 0),
                    (float) ($item?->scanned_qty_base ?? 0),
                    (string) ($item?->sku?->baseUom?->code ?? 'BASE')
                ));
            });

        WarehouseKeeperTask::query()
            ->where('warehouse_id', $warehouseId)
            ->where('assigned_to_user_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['item.sku.baseUom', 'stockIn'])
            ->orderByDesc('assigned_at')
            ->get()
            ->each(function (WarehouseKeeperTask $task) use ($rows): void {
                $item = $task->item;
                $rows->push($this->taskRow(
                    'checker_keeper', (string) $task->id, (string) $task->status,
                    (string) ($task->stockIn?->stock_in_number ?? '-'),
                    $item?->sku?->sku_code, $item?->sku?->name,
                    (float) ($item?->accepted_qty_base ?? 0),
                    (float) (($item?->stored_label_count ?? 0) * ($item?->package_qty_base ?? 0)),
                    (string) ($item?->sku?->baseUom?->code ?? 'BASE')
                ));
            });

        WarehouseProductionTask::query()
            ->where('warehouse_id', $warehouseId)
            ->where('assigned_to_user_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['input.sku.baseUom', 'production'])
            ->orderByDesc('assigned_at')
            ->get()
            ->each(function (WarehouseProductionTask $task) use ($rows): void {
                $item = $task->input;
                $rows->push($this->taskRow(
                    'checker_production', (string) $task->id, (string) $task->status,
                    (string) ($task->production?->production_number ?? '-'),
                    $item?->sku?->sku_code, $item?->sku?->name,
                    (float) ($item?->planned_qty_base ?? 0),
                    (float) ($item?->actual_qty_base ?? 0),
                    (string) ($item?->sku?->baseUom?->code ?? 'BASE')
                ));
            });

        WarehouseStockTransferTask::query()
            ->where('warehouse_id', $warehouseId)
            ->where('assigned_to_user_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['item.sku.baseUom', 'transfer'])
            ->orderByDesc('assigned_at')
            ->get()
            ->each(function (WarehouseStockTransferTask $task) use ($rows): void {
                $item = $task->item;
                $rows->push($this->taskRow(
                    'checker_transfer', (string) $task->id, (string) $task->status,
                    (string) ($task->transfer?->transfer_number ?? '-'),
                    $item?->sku?->sku_code, $item?->sku?->name,
                    (float) ($item?->requested_qty_base ?? 0),
                    (float) ($item?->scanned_qty_base ?? 0),
                    (string) ($item?->sku?->baseUom?->code ?? 'BASE')
                ));
            });

        return [
            'workflows' => collect(self::WORKFLOWS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'tasks' => $rows->sortBy([['workflow_label', 'asc'], ['document_number', 'asc'], ['item_name', 'asc']])->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function issueTokens(
        string $warehouseId,
        string $userId,
        string $workflow,
        string $targetId,
        int $count,
        string $requestId,
    ): array {
        $this->assertWorkflow($workflow);
        $this->assertTaskAccess($workflow, $targetId, $warehouseId, $userId);
        $count = min(20, max(1, $count));
        $expiresAt = now()->addHours(8);
        $tokens = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            $nonce = Str::random(48);
            $payload = [
                'id' => $id,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'workflow' => $workflow,
                'target_id' => $targetId,
                'nonce' => $nonce,
                'expires_at' => $expiresAt->timestamp,
            ];
            $opaque = Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_SLASHES));
            WarehouseSignedScanToken::query()->create([
                'id' => $id,
                'warehouse_id' => $warehouseId,
                'user_id' => $userId,
                'workflow' => $workflow,
                'target_id' => $targetId,
                'nonce' => $nonce,
                'signature_hash' => hash('sha256', $opaque),
                'status' => 'issued',
                'request_id' => $requestId,
                'issued_at' => now(),
                'expires_at' => $expiresAt,
                'metadata' => ['source' => 'warehouse_mobile_scanner', 'sequence' => $i + 1],
            ]);
            $tokens[] = ['token' => $opaque, 'expires_at' => $expiresAt->toIso8601String()];
        }

        return [
            'workflow' => $workflow,
            'target_id' => $targetId,
            'count' => count($tokens),
            'expires_at' => $expiresAt->toIso8601String(),
            'tokens' => $tokens,
        ];
    }

    public function replayScan(
        string $warehouseId,
        string $userId,
        string $opaqueToken,
        string $barcode,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $payload = $this->decodeToken($opaqueToken);
        foreach (['id', 'warehouse_id', 'user_id', 'workflow', 'target_id', 'nonce', 'expires_at'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw ValidationException::withMessages(['signed_token' => 'Signed scan token tidak lengkap.']);
            }
        }
        if (! hash_equals((string) $payload['warehouse_id'], $warehouseId) || ! hash_equals((string) $payload['user_id'], $userId)) {
            throw ValidationException::withMessages(['signed_token' => 'Signed scan token tidak sesuai user atau Warehouse aktif.']);
        }
        if ((int) $payload['expires_at'] < now()->timestamp) {
            throw ValidationException::withMessages(['signed_token' => 'Signed scan token sudah kedaluwarsa.']);
        }

        $workflow = (string) $payload['workflow'];
        $targetId = (string) $payload['target_id'];
        $this->assertWorkflow($workflow);
        $scanPayloadHash = hash('sha256', json_encode([
            'workflow' => $workflow,
            'target_id' => $targetId,
            'barcode' => strtoupper(trim($barcode)),
            'idempotency_key' => $idempotencyKey,
        ], JSON_UNESCAPED_SLASHES));

        $tokenState = DB::transaction(function () use ($payload, $opaqueToken, $warehouseId, $userId, $requestId, $scanPayloadHash): string {
            /** @var WarehouseSignedScanToken|null $token */
            $token = WarehouseSignedScanToken::query()->whereKey((string) $payload['id'])->lockForUpdate()->first();
            if (! $token || ! hash_equals((string) $token->signature_hash, hash('sha256', $opaqueToken))) {
                throw ValidationException::withMessages(['signed_token' => 'Signed scan token tidak valid.']);
            }
            if ((string) $token->warehouse_id !== $warehouseId || (string) $token->user_id !== $userId) {
                throw ValidationException::withMessages(['signed_token' => 'Signed scan token berada di scope berbeda.']);
            }
            if ((string) $token->status === 'consumed' && hash_equals((string) $token->payload_hash, $scanPayloadHash)) {
                return 'already_consumed';
            }
            if ((string) $token->status === 'processing' && hash_equals((string) $token->payload_hash, $scanPayloadHash)) {
                return 'processing_retry';
            }
            if ((string) $token->status !== 'issued') {
                throw ValidationException::withMessages(['signed_token' => 'Signed scan token sudah digunakan atau tidak aktif.']);
            }
            if ($token->expires_at->isPast()) {
                $token->update(['status' => 'expired']);
                throw ValidationException::withMessages(['signed_token' => 'Signed scan token sudah kedaluwarsa.']);
            }
            $token->update([
                'status' => 'processing',
                'processing_at' => now(),
                'payload_hash' => $scanPayloadHash,
                'request_id' => $requestId,
                'consumed_by_user_id' => $userId,
            ]);
            return 'processing';
        }, 3);

        if ($tokenState === 'already_consumed') {
            return [
                'workflow' => $workflow, 'target_id' => $targetId, 'result' => null,
                'token_status' => 'already_consumed', 'idempotent_replay' => true,
                'synced_at' => now()->toIso8601String(),
            ];
        }

        try {
            // A retry may arrive after the workflow scan succeeded but before the client
            // received the response. Existing workflow idempotency keys make this safe.
            $this->assertTaskAccess($workflow, $targetId, $warehouseId, $userId);
            $result = match ($workflow) {
                'checker_prepare' => $this->fulfillmentService->scan($targetId, $warehouseId, [
                    'barcode' => $barcode,
                    'idempotency_key' => $idempotencyKey,
                ], $userId, false),
                'checker_keeper' => $this->procurementService->scanKeeperTask($targetId, $warehouseId, $userId, [
                    'barcode' => $barcode,
                    'idempotency_key' => $idempotencyKey,
                ], false),
                'checker_production' => $this->productionService->scanTask($targetId, $warehouseId, [
                    'barcode' => $barcode,
                    'idempotency_key' => $idempotencyKey,
                ], $userId, false),
                'checker_transfer' => $this->transferService->scanTask($targetId, $warehouseId, [
                    'barcode' => $barcode,
                    'idempotency_key' => $idempotencyKey,
                ], $userId, false),
            };
            WarehouseSignedScanToken::query()->whereKey((string) $payload['id'])->update([
                'status' => 'consumed',
                'consumed_at' => now(),
                'metadata' => ['result' => 'accepted', 'barcode_hash' => hash('sha256', strtoupper(trim($barcode)))],
            ]);
            return [
                'workflow' => $workflow,
                'target_id' => $targetId,
                'result' => $result,
                'token_status' => 'consumed',
                'synced_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            WarehouseSignedScanToken::query()->whereKey((string) $payload['id'])->update([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => [
                    'result' => 'rejected',
                    'barcode_hash' => hash('sha256', strtoupper(trim($barcode))),
                    'error_class' => $exception::class,
                    'error_code' => (string) $exception->getCode(),
                ],
            ]);
            throw $exception;
        }
    }

    public function cleanupExpired(): int
    {
        return WarehouseSignedScanToken::query()
            ->whereIn('status', ['issued', 'processing'])
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    private function decodeToken(string $opaqueToken): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($opaqueToken), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            throw ValidationException::withMessages(['signed_token' => 'Signed scan token tidak dapat diverifikasi.']);
        }
    }

    private function assertWorkflow(string $workflow): void
    {
        if (! array_key_exists($workflow, self::WORKFLOWS)) {
            throw ValidationException::withMessages(['workflow' => 'Workflow mobile scanner tidak didukung.']);
        }
    }

    private function assertTaskAccess(string $workflow, string $targetId, string $warehouseId, string $userId): void
    {
        $model = match ($workflow) {
            'checker_prepare' => WarehouseTaskAssignment::class,
            'checker_keeper' => WarehouseKeeperTask::class,
            'checker_production' => WarehouseProductionTask::class,
            'checker_transfer' => WarehouseStockTransferTask::class,
        };
        $exists = $model::query()
            ->whereKey($targetId)
            ->where('warehouse_id', $warehouseId)
            ->where('assigned_to_user_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['target_id' => 'Task tidak aktif, tidak berada pada Warehouse ini, atau bukan assignment user.']);
        }
    }

    private function taskRow(
        string $workflow,
        string $taskId,
        string $status,
        string $documentNumber,
        ?string $skuCode,
        ?string $itemName,
        float $targetQty,
        float $scannedQty,
        string $baseUom,
    ): array {
        return [
            'workflow' => $workflow,
            'workflow_label' => self::WORKFLOWS[$workflow],
            'task_id' => $taskId,
            'status' => $status,
            'document_number' => $documentNumber,
            'sku_code' => (string) ($skuCode ?? '-'),
            'item_name' => (string) ($itemName ?? '-'),
            'target_qty_base' => round($targetQty, 4),
            'scanned_qty_base' => round($scannedQty, 4),
            'remaining_qty_base' => round(max(0, $targetQty - $scannedQty), 4),
            'base_uom' => $baseUom,
        ];
    }
}
