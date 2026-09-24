<?php

namespace App\Services\Warehouse\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehousePackageBarcodeService
{
    public function split(string $warehouseId, string $unitId, float $splitQty, ?string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $unitId, $splitQty, $reason, $userId): array {
            $unit = $this->lockUnit($warehouseId, $unitId);
            $remaining = $this->remaining($unit);
            if (! in_array($unit->status, ['available', 'quarantine'], true)) {
                throw ValidationException::withMessages(['stock_unit_id' => ['Hanya barcode available atau quarantine yang dapat di-split.']]);
            }
            if ($splitQty <= 0 || $splitQty >= $remaining - 0.00001) {
                throw ValidationException::withMessages(['split_qty_base' => ['Qty split harus lebih dari 0 dan lebih kecil dari sisa barcode.']]);
            }

            $newParentQty = round($remaining - $splitQty, 4);
            DB::table('wh_stock_units')->where('id', $unit->id)->update([
                'qty_base' => $newParentQty,
                'remaining_qty_base' => $newParentQty,
                'package_state' => 'opened',
                'opened_at' => $unit->opened_at ?: now(),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            $childId = (string) Str::ulid();
            $childBarcode = $this->nextBarcode($warehouseId, 'SPLIT');
            DB::table('wh_stock_units')->insert([
                'id' => $childId,
                'barcode' => $childBarcode,
                'warehouse_id' => $unit->warehouse_id,
                'batch_id' => $unit->batch_id,
                'sku_id' => $unit->sku_id,
                'storage_id' => $unit->storage_id,
                'qty_base' => $splitQty,
                'original_qty_base' => $splitQty,
                'remaining_qty_base' => $splitQty,
                'package_uom_id' => $unit->package_uom_id,
                'package_uom_code' => $unit->package_uom_code,
                'package_conversion_factor' => $unit->package_conversion_factor,
                'parent_stock_unit_id' => $unit->id,
                'root_stock_unit_id' => $unit->root_stock_unit_id ?: $unit->id,
                'package_state' => 'repacked',
                'status' => $unit->status,
                'print_count' => 0,
                'activated_at' => $unit->activated_at,
                'lock_version' => 1,
                'metadata' => json_encode(['generated_from' => 'package_split', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->event($warehouseId, $unit->id, $childId, 'split_out', $remaining, -$splitQty, $newParentQty, $reason, $userId);
            $this->event($warehouseId, $childId, $unit->id, 'split_in', 0, $splitQty, $splitQty, $reason, $userId);

            return ['parent' => $this->find($unit->id), 'child' => $this->find($childId)];
        });
    }

    public function relabel(string $warehouseId, string $unitId, ?string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $unitId, $reason, $userId): array {
            $unit = $this->lockUnit($warehouseId, $unitId);
            $remaining = $this->remaining($unit);
            if ($remaining <= 0 || in_array($unit->status, ['consumed', 'cancelled', 'damaged'], true)) {
                throw ValidationException::withMessages(['stock_unit_id' => ['Barcode tanpa saldo aktif tidak dapat direlabel.']]);
            }

            $childId = (string) Str::ulid();
            $childBarcode = $this->nextBarcode($warehouseId, 'RELABEL');
            DB::table('wh_stock_units')->insert([
                'id' => $childId, 'barcode' => $childBarcode, 'warehouse_id' => $unit->warehouse_id,
                'batch_id' => $unit->batch_id, 'sku_id' => $unit->sku_id, 'storage_id' => $unit->storage_id,
                'qty_base' => $remaining, 'original_qty_base' => $remaining, 'remaining_qty_base' => $remaining,
                'package_uom_id' => $unit->package_uom_id, 'package_uom_code' => $unit->package_uom_code,
                'package_conversion_factor' => $unit->package_conversion_factor,
                'parent_stock_unit_id' => $unit->id, 'root_stock_unit_id' => $unit->root_stock_unit_id ?: $unit->id,
                'package_state' => 'relabeled', 'status' => $unit->status, 'print_count' => 0,
                'activated_at' => $unit->activated_at, 'lock_version' => 1,
                'metadata' => json_encode(['generated_from' => 'package_relabel', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                'created_by_user_id' => $userId, 'updated_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('wh_stock_units')->where('id', $unit->id)->update([
                'qty_base' => 0, 'remaining_qty_base' => 0, 'status' => 'cancelled', 'package_state' => 'relabeled_source',
                'depleted_at' => now(), 'lock_version' => DB::raw('lock_version + 1'), 'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            $this->event($warehouseId, $unit->id, $childId, 'relabel_out', $remaining, -$remaining, 0, $reason, $userId);
            $this->event($warehouseId, $childId, $unit->id, 'relabel_in', 0, $remaining, $remaining, $reason, $userId);
            return ['source' => $this->find($unit->id), 'replacement' => $this->find($childId)];
        });
    }

    public function consume(string $warehouseId, string $barcode, float $qty, string $contextType, string $contextId, ?string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $barcode, $qty, $contextType, $contextId, $reason, $userId): array {
            $unit = DB::table('wh_stock_units')->where('warehouse_id', $warehouseId)->where('barcode', trim($barcode))->lockForUpdate()->first();
            if (! $unit) throw ValidationException::withMessages(['barcode' => ['Barcode tidak ditemukan pada warehouse aktif.']]);
            if (! in_array($unit->status, ['available', 'reserved', 'quarantine'], true)) throw ValidationException::withMessages(['barcode' => ["Status barcode {$unit->status} tidak dapat dikonsumsi."]]);
            $remaining = $this->remaining($unit);
            if ($qty <= 0 || $qty > $remaining + 0.00001) throw ValidationException::withMessages(['qty_base' => [sprintf('Qty maksimal yang dapat diproses adalah %.4f.', $remaining)]]);
            $after = max(0, round($remaining - $qty, 4));
            DB::table('wh_stock_units')->where('id', $unit->id)->update([
                'qty_base' => $after, 'remaining_qty_base' => $after,
                'package_state' => $after > 0 ? 'opened' : 'depleted',
                'status' => $after > 0 ? $unit->status : 'consumed',
                'opened_at' => $after > 0 ? ($unit->opened_at ?: now()) : $unit->opened_at,
                'depleted_at' => $after <= 0 ? now() : null,
                'lock_version' => DB::raw('lock_version + 1'), 'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            $this->event($warehouseId, $unit->id, null, 'consume', $remaining, -$qty, $after, $reason, $userId, $contextType, $contextId);
            return $this->find($unit->id);
        });
    }

    public function countOpname(string $warehouseId, string $sessionKey, string $barcode, float $countedQty, ?string $notes, ?string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $sessionKey, $barcode, $countedQty, $notes, $userId): array {
            $unit = DB::table('wh_stock_units')->where('warehouse_id', $warehouseId)->where('barcode', trim($barcode))->lockForUpdate()->first();
            if (! $unit) throw ValidationException::withMessages(['barcode' => ['Barcode tidak ditemukan pada warehouse aktif.']]);
            if ($countedQty < 0) throw ValidationException::withMessages(['counted_qty_base' => ['Quantity hasil hitung tidak boleh negatif.']]);
            $system = $this->remaining($unit); $variance = round($countedQty - $system, 4);
            DB::table('wh_package_opname_counts')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'session_key' => $sessionKey, 'stock_unit_id' => $unit->id],
                ['id' => (string) (DB::table('wh_package_opname_counts')->where('warehouse_id',$warehouseId)->where('session_key',$sessionKey)->where('stock_unit_id',$unit->id)->value('id') ?: Str::ulid()), 'system_qty_base' => $system, 'counted_qty_base' => $countedQty, 'variance_qty_base' => $variance, 'status' => 'counted', 'notes' => $notes, 'counted_by_user_id' => $userId, 'counted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
            return ['unit' => $this->find($unit->id), 'session_key' => $sessionKey, 'system_qty_base' => $system, 'counted_qty_base' => $countedQty, 'variance_qty_base' => $variance];
        });
    }

    private function lockUnit(string $warehouseId, string $unitId): object
    {
        $unit = DB::table('wh_stock_units')->where('warehouse_id', $warehouseId)->where('id', $unitId)->lockForUpdate()->first();
        if (! $unit) throw ValidationException::withMessages(['stock_unit_id' => ['Barcode tidak ditemukan pada warehouse aktif.']]);
        return $unit;
    }
    private function remaining(object $unit): float { return (float) ($unit->remaining_qty_base ?? $unit->qty_base ?? 0); }
    private function find(string $id): array { return (array) DB::table('wh_stock_units')->where('id', $id)->first(); }
    private function nextBarcode(string $warehouseId, string $type): string { $code=(string)(DB::table('outlets')->where('id',$warehouseId)->value('code')?:'WH'); return 'WH-'.preg_replace('/[^A-Z0-9]/','',strtoupper($code)).'-'.$type.'-'.strtoupper(substr((string)Str::ulid(),-10)); }
    private function event(string $warehouseId, string $unitId, ?string $relatedId, string $type, float $before, float $change, float $after, ?string $reason, ?string $userId, ?string $referenceType=null, ?string $referenceId=null): void
    {
        DB::table('wh_stock_unit_events')->insert(['id'=>(string)Str::ulid(),'warehouse_id'=>$warehouseId,'stock_unit_id'=>$unitId,'related_stock_unit_id'=>$relatedId,'event_type'=>$type,'qty_before_base'=>$before,'qty_change_base'=>$change,'qty_after_base'=>$after,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'reason'=>$reason,'metadata'=>null,'created_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
    }
}
