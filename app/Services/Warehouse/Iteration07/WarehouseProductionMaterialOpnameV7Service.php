<?php

namespace App\Services\Warehouse\Iteration07;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseProductionMaterialOpnameV7Service
{
    public function availability(string $warehouseId, string $skuId, float $requiredBase = 0): array
    {
        $row = DB::table('wh_v7_production_stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->first();

        $qty = round((float) ($row->qty_base ?? 0), 4);
        $value = round((float) ($row->inventory_value ?? 0), 2);

        return [
            'qty_base' => $qty,
            'inventory_value' => $value,
            'average_unit_cost' => $qty > 0 ? round($value / $qty, 6) : 0.0,
            'warehouse_need_qty_base' => max(round($requiredBase - $qty, 4), 0),
        ];
    }

    public function reserveForMaterial(
        string $warehouseId,
        string $productionId,
        string $inputId,
        ?string $requestItemId,
        string $skuId,
        float $approvedBase
    ): array {
        $approvedBase = round(max($approvedBase, 0), 4);

        if ($existing = DB::table('wh_v7_production_material_allocations')
            ->where('production_input_id', $inputId)
            ->lockForUpdate()
            ->first()) {
            return [
                'allocation_id' => (string) $existing->id,
                'carry_in_qty_base' => (float) $existing->carry_in_qty_base,
                'carry_in_value' => (float) $existing->carry_in_value,
                'warehouse_issue_qty_base' => (float) $existing->warehouse_issue_qty_base,
            ];
        }

        $balance = $this->lockBalance($warehouseId, $skuId);
        $balanceQty = round((float) $balance->qty_base, 4);
        $balanceValue = round((float) $balance->inventory_value, 2);
        $carry = min($approvedBase, max($balanceQty, 0));
        $carryValue = $balanceQty > 0 ? round($balanceValue * ($carry / $balanceQty), 2) : 0;
        $newQty = round($balanceQty - $carry, 4);
        $newValue = round($balanceValue - $carryValue, 2);

        if (abs($newQty) < 0.0001) {
            $newQty = 0;
            $newValue = 0;
        }

        DB::table('wh_v7_production_stock_balances')->where('id', $balance->id)->update([
            'qty_base' => $newQty,
            'inventory_value' => $newValue,
            'average_unit_cost' => $newQty > 0 ? round($newValue / $newQty, 6) : 0,
            'lock_version' => (int) $balance->lock_version + 1,
            'updated_at' => now(),
        ]);

        $issue = max(round($approvedBase - $carry, 4), 0);
        $id = (string) Str::ulid();

        DB::table('wh_v7_production_material_allocations')->insert([
            'id' => $id,
            'warehouse_id' => $warehouseId,
            'production_id' => $productionId,
            'production_input_id' => $inputId,
            'production_request_item_id' => $requestItemId,
            'sku_id' => $skuId,
            'carry_in_qty_base' => $carry,
            'carry_in_value' => $carryValue,
            'warehouse_issue_qty_base' => $issue,
            'warehouse_issue_value' => 0,
            'available_qty_base' => $approvedBase,
            'available_value' => $carryValue,
            'remaining_qty_base' => 0,
            'remaining_value' => 0,
            'consumed_qty_base' => 0,
            'consumed_value' => 0,
            'status' => 'allocated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'allocation_id' => $id,
            'carry_in_qty_base' => $carry,
            'carry_in_value' => $carryValue,
            'warehouse_issue_qty_base' => $issue,
        ];
    }

    public function finalizeIssueCosts(string $warehouseId, string $productionId, ?string $postingId): array
    {
        $rows = DB::table('wh_v7_production_material_allocations')
            ->where('warehouse_id', $warehouseId)
            ->where('production_id', $productionId)
            ->lockForUpdate()
            ->get();

        $totalValue = 0;
        $totalQty = 0;

        foreach ($rows as $row) {
            $issueValue = 0;
            if ($postingId && (float) $row->warehouse_issue_qty_base > 0 && $row->production_request_item_id) {
                $cost = DB::table('wh_ledger_entries')
                    ->where('posting_id', $postingId)
                    ->where('line_key', 'like', 'PRODREQ-'.$row->production_request_item_id.'-%')
                    ->selectRaw('COALESCE(SUM(total_cost),0) total')
                    ->first();
                $issueValue = round((float) ($cost->total ?? 0), 2);
            }

            $availableValue = round((float) $row->carry_in_value + $issueValue, 2);
            $availableQty = round((float) $row->available_qty_base, 4);
            $totalValue += $availableValue;
            $totalQty += $availableQty;

            DB::table('wh_v7_production_material_allocations')->where('id', $row->id)->update([
                'warehouse_issue_value' => $issueValue,
                'available_value' => $availableValue,
                'updated_at' => now(),
            ]);

            if ($input = DB::table('wh_production_inputs')->where('id', $row->production_input_id)->first()) {
                $factor = max((float) ($input->conversion_factor_snapshot ?: 1), 0.00000001);
                DB::table('wh_production_inputs')->where('id', $input->id)->update([
                    'actual_qty_base' => $availableQty,
                    'actual_qty_uom' => round($availableQty / $factor, 4),
                    'actual_material_cost' => $availableValue,
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('wh_productions')->where('id', $productionId)->update([
            'actual_input_value' => round($totalValue, 2),
            'updated_at' => now(),
        ]);

        return [
            'available_qty_base' => round($totalQty, 4),
            'available_value' => round($totalValue, 2),
        ];
    }

    public function detail(string $warehouseId, string $productionId): array
    {
        $production = DB::table('wh_productions')
            ->where('warehouse_id', $warehouseId)
            ->where('id', $productionId)
            ->first();
        if (! $production) {
            abort(404);
        }

        if ((string) $production->status === 'on_progress') {
            $this->ensureLegacyAllocations($warehouseId, $productionId);
        }

        $opname = DB::table('wh_v7_production_material_opnames')
            ->where('production_id', $productionId)
            ->first();
        $saved = $opname
            ? DB::table('wh_v7_production_material_opname_items')->where('opname_id', $opname->id)->get()->keyBy('production_input_id')
            : collect();

        $hasInputMode = Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_uom_mode');
        $hasInputQty = Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_qty');
        $hasPurchaseSnapshot = Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_conversion_factor_snapshot');

        $items = DB::table('wh_v7_production_material_allocations as a')
            ->join('wh_production_inputs as i', 'i.id', '=', 'a.production_input_id')
            ->join('stk_skus as sku', 'sku.id', '=', 'a.sku_id')
            ->leftJoin('stk_uoms as purchase_uom', 'purchase_uom.id', '=', 'sku.purchase_uom_id')
            ->where('a.warehouse_id', $warehouseId)
            ->where('a.production_id', $productionId)
            ->orderBy('sku.name')
            ->get([
                'a.*',
                'i.request_uom_id',
                'i.request_uom_code_snapshot',
                'i.base_uom_id',
                'i.base_uom_code_snapshot',
                'i.conversion_factor_snapshot',
                'sku.sku_code',
                'sku.name as item_name',
                'sku.purchase_uom_id',
                'sku.purchase_conversion_factor',
                'purchase_uom.code as purchase_uom_code',
            ])
            ->map(function ($row) use ($saved, $hasInputMode, $hasInputQty, $hasPurchaseSnapshot) {
                $factor = max((float) ($row->conversion_factor_snapshot ?: 1), 0.00000001);
                $savedRow = $saved->get((string) $row->production_input_id);
                $remainingBase = $savedRow ? (float) $savedRow->remaining_qty_base : (float) $row->remaining_qty_base;
                $remainingUom = $savedRow ? (float) $savedRow->remaining_qty_uom : round($remainingBase / $factor, 4);
                $purchaseFactor = ($savedRow && $hasPurchaseSnapshot && (float) ($savedRow->purchase_conversion_factor_snapshot ?? 0) > 0)
                    ? (float) $savedRow->purchase_conversion_factor_snapshot
                    : max((float) ($row->purchase_conversion_factor ?: 1), 0.00000001);
                $purchaseUomId = ($savedRow && $hasPurchaseSnapshot && ! empty($savedRow->purchase_uom_id_snapshot))
                    ? (string) $savedRow->purchase_uom_id_snapshot
                    : (string) ($row->purchase_uom_id ?: $row->base_uom_id);
                $purchaseUomCode = ($savedRow && $hasPurchaseSnapshot && ! empty($savedRow->purchase_uom_code_snapshot))
                    ? (string) $savedRow->purchase_uom_code_snapshot
                    : (string) ($row->purchase_uom_code ?: $row->base_uom_code_snapshot ?: 'BASE');
                $inputMode = ($savedRow && $hasInputMode && ! empty($savedRow->remaining_input_uom_mode))
                    ? (string) $savedRow->remaining_input_uom_mode
                    : 'purchase';
                $inputQty = ($savedRow && $hasInputQty)
                    ? (float) $savedRow->remaining_input_qty
                    : ($inputMode === 'base' ? $remainingBase : round($remainingBase / $purchaseFactor, 4));

                return [
                    'allocation_id' => (string) $row->id,
                    'production_input_id' => (string) $row->production_input_id,
                    'sku_id' => (string) $row->sku_id,
                    'sku_code' => (string) $row->sku_code,
                    'item_name' => (string) $row->item_name,
                    'uom_id' => (string) $row->request_uom_id,
                    'uom_code' => (string) ($row->request_uom_code_snapshot ?: 'UNIT'),
                    'base_uom_code' => (string) ($row->base_uom_code_snapshot ?: 'BASE'),
                    'conversion_factor' => $factor,
                    'purchase_uom_id' => $purchaseUomId,
                    'purchase_uom_code' => $purchaseUomCode,
                    'purchase_conversion_factor' => $purchaseFactor,
                    'carry_in_qty_base' => (float) $row->carry_in_qty_base,
                    'warehouse_issue_qty_base' => (float) $row->warehouse_issue_qty_base,
                    'available_qty_base' => (float) $row->available_qty_base,
                    'available_value' => (float) $row->available_value,
                    'remaining_qty_uom' => round($remainingUom, 4),
                    'remaining_qty_base' => round($remainingBase, 4),
                    'remaining_input_uom_mode' => $inputMode,
                    'remaining_input_qty' => round($inputQty, 4),
                    'consumed_qty_base' => round(max((float) $row->available_qty_base - $remainingBase, 0), 4),
                    'notes' => $savedRow->notes ?? null,
                ];
            })
            ->values()
            ->all();

        $stock = DB::table('wh_v7_production_stock_balances as b')
            ->join('stk_skus as sku', 'sku.id', '=', 'b.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 'sku.base_uom_id')
            ->where('b.warehouse_id', $warehouseId)
            ->where('b.qty_base', '>', 0)
            ->orderBy('sku.name')
            ->get(['b.*', 'sku.sku_code', 'sku.name as item_name', 'u.code as base_uom_code'])
            ->map(fn ($r) => [
                'sku_id' => (string) $r->sku_id,
                'sku_code' => (string) $r->sku_code,
                'item_name' => (string) $r->item_name,
                'qty_base' => (float) $r->qty_base,
                'inventory_value' => (float) $r->inventory_value,
                'average_unit_cost' => (float) $r->average_unit_cost,
                'base_uom_code' => (string) ($r->base_uom_code ?: 'BASE'),
                'last_opname_at' => $r->last_opname_at,
            ])
            ->values()
            ->all();

        $reopenRequests = $this->reopenRequests($warehouseId, $productionId);
        $reopenEligibility = $opname && (string) $opname->status === 'finalized'
            ? $this->reopenEligibility($warehouseId, $productionId, $opname)
            : [
                'eligible' => false,
                'blockers' => $opname ? ['Opname belum berstatus finalized.'] : ['Production Opname belum tersedia.'],
            ];

        return [
            'production_id' => $productionId,
            'production_number' => (string) $production->production_number,
            'production_status' => (string) $production->status,
            'flow_version' => (int) ($production->flow_version ?? 0),
            'opname' => $opname ? [
                'id' => (string) $opname->id,
                'opname_number' => (string) $opname->opname_number,
                'status' => (string) $opname->status,
                'opname_date' => $opname->opname_date,
                'notes' => $opname->notes,
                'finalized_at' => $opname->finalized_at,
            ] : null,
            'items' => $items,
            'production_stock_balance' => $stock,
            'reopen_eligibility' => $reopenEligibility,
            'reopen_requests' => $reopenRequests,
            'pending_reopen_request' => collect($reopenRequests)->firstWhere('status', 'pending'),
        ];
    }

    public function saveDraft(string $warehouseId, string $productionId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($warehouseId, $productionId, $payload, $userId): void {
            $production = DB::table('wh_productions')
                ->where('warehouse_id', $warehouseId)
                ->where('id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $production) {
                abort(404);
            }
            if ((string) $production->status !== 'on_progress') {
                throw ValidationException::withMessages(['status' => ['Actual Bahan hanya dapat diisi pada Ongoing Production.']]);
            }

            $this->ensureLegacyAllocations($warehouseId, $productionId);
            $opname = DB::table('wh_v7_production_material_opnames')
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();

            if ($opname && (string) $opname->status === 'finalized') {
                throw ValidationException::withMessages(['status' => ['Production Stock Opname sudah finalized. Ajukan reopen terlebih dahulu bila perlu diedit.']]);
            }

            if (! $opname) {
                $id = (string) Str::ulid();
                DB::table('wh_v7_production_material_opnames')->insert([
                    'id' => $id,
                    'opname_number' => $this->number(),
                    'warehouse_id' => $warehouseId,
                    'production_id' => $productionId,
                    'status' => 'draft',
                    'opname_date' => $payload['opname_date'],
                    'notes' => $payload['notes'] ?? null,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $opname = DB::table('wh_v7_production_material_opnames')->where('id', $id)->first();
            } else {
                DB::table('wh_v7_production_material_opnames')->where('id', $opname->id)->update([
                    'opname_date' => $payload['opname_date'],
                    'notes' => $payload['notes'] ?? null,
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }

            $map = collect($payload['items'] ?? [])->keyBy(fn ($r) => (string) ($r['production_input_id'] ?? ''));
            $allocs = DB::table('wh_v7_production_material_allocations')
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->get();

            if ($allocs->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Material allocation Production belum tersedia.']]);
            }

            foreach ($allocs as $a) {
                $line = $map->get((string) $a->production_input_id);
                if (! $line) {
                    throw ValidationException::withMessages(['items' => ['Seluruh bahan wajib diisi Sisa Actual.']]);
                }

                $input = DB::table('wh_production_inputs')->where('id', $a->production_input_id)->first();
                $requestFactor = max((float) ($input->conversion_factor_snapshot ?: 1), 0.00000001);
                $existing = DB::table('wh_v7_production_material_opname_items')
                    ->where('opname_id', $opname->id)
                    ->where('production_input_id', $a->production_input_id)
                    ->first();
                $purchase = DB::table('stk_skus as sku')
                    ->leftJoin('stk_uoms as pu', 'pu.id', '=', 'sku.purchase_uom_id')
                    ->where('sku.id', $a->sku_id)
                    ->first(['sku.purchase_uom_id', 'sku.purchase_conversion_factor', 'pu.code as purchase_uom_code']);
                $hasExistingPurchaseSnapshot = $existing
                    && Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_conversion_factor_snapshot')
                    && (float) ($existing->purchase_conversion_factor_snapshot ?? 0) > 0;
                $purchaseUomId = $hasExistingPurchaseSnapshot
                    ? (string) ($existing->purchase_uom_id_snapshot ?: $input->base_uom_id)
                    : (string) ($purchase->purchase_uom_id ?? $input->base_uom_id);
                $purchaseUomCode = $hasExistingPurchaseSnapshot
                    ? (string) ($existing->purchase_uom_code_snapshot ?: $input->base_uom_code_snapshot ?: 'BASE')
                    : (string) ($purchase->purchase_uom_code ?? $input->base_uom_code_snapshot ?? 'BASE');
                $purchaseFactor = $hasExistingPurchaseSnapshot
                    ? max((float) $existing->purchase_conversion_factor_snapshot, 0.00000001)
                    : max((float) ($purchase->purchase_conversion_factor ?? 1), 0.00000001);
                $mode = strtolower(trim((string) ($line['remaining_uom_mode'] ?? 'purchase')));
                if (! in_array($mode, ['purchase', 'base'], true)) {
                    throw ValidationException::withMessages(['items' => ['Satuan Sisa Actual harus Base atau Purchase UOM.']]);
                }

                $rawInputQty = $line['remaining_qty'] ?? $line['remaining_qty_uom'] ?? null;
                if ($rawInputQty === null || $rawInputQty === '') {
                    throw ValidationException::withMessages(['items' => ['Seluruh bahan wajib diisi Sisa Actual.']]);
                }

                $inputQty = round((float) $rawInputQty, 4);
                if ($inputQty < 0) {
                    throw ValidationException::withMessages(['items' => ['Sisa Actual tidak boleh negatif.']]);
                }

                $remainingBase = $mode === 'base'
                    ? round($inputQty, 4)
                    : round($inputQty * $purchaseFactor, 4);
                $remainingUom = round($remainingBase / $requestFactor, 4);

                $available = round((float) $a->available_qty_base, 4);
                if ($remainingBase > $available + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => [sprintf('Sisa Actual SKU %s melebihi material tersedia %.4f base.', $a->sku_id, $available)],
                    ]);
                }

                $availableValue = round((float) $a->available_value, 2);
                $remainingValue = $available > 0 ? round($availableValue * ($remainingBase / $available), 2) : 0;
                $consumed = round($available - $remainingBase, 4);
                $consumedValue = round($availableValue - $remainingValue, 2);
                $data = [
                    'sku_id' => $a->sku_id,
                    'uom_id' => $input->request_uom_id,
                    'conversion_factor_snapshot' => $requestFactor,
                    'available_qty_base' => $available,
                    'remaining_qty_uom' => $remainingUom,
                    'remaining_qty_base' => $remainingBase,
                    'consumed_qty_base' => $consumed,
                    'unit_cost_snapshot' => $available > 0 ? round($availableValue / $available, 6) : 0,
                    'remaining_value' => $remainingValue,
                    'consumed_value' => $consumedValue,
                    'notes' => $line['notes'] ?? null,
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_uom_mode')) {
                    $data['remaining_input_uom_mode'] = $mode;
                }
                if (Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_qty')) {
                    $data['remaining_input_qty'] = $inputQty;
                }
                if (Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_uom_id_snapshot')) {
                    $data['purchase_uom_id_snapshot'] = $purchaseUomId ?: null;
                    $data['purchase_uom_code_snapshot'] = $purchaseUomCode;
                    $data['purchase_conversion_factor_snapshot'] = $purchaseFactor;
                }

                if ($existing) {
                    DB::table('wh_v7_production_material_opname_items')->where('id', $existing->id)->update($data);
                } else {
                    DB::table('wh_v7_production_material_opname_items')->insert($data + [
                        'id' => (string) Str::ulid(),
                        'opname_id' => $opname->id,
                        'production_input_id' => $a->production_input_id,
                        'created_at' => now(),
                    ]);
                }
            }
        }, 5);

        return $this->detail($warehouseId, $productionId);
    }

    public function finalize(string $warehouseId, string $productionId, array $payload, string $userId): array
    {
        $existing = DB::table('wh_v7_production_material_opnames')
            ->where('warehouse_id', $warehouseId)
            ->where('production_id', $productionId)
            ->first();
        if ($existing && (string) $existing->status === 'finalized') {
            return $this->detail($warehouseId, $productionId);
        }

        $this->saveDraft($warehouseId, $productionId, $payload, $userId);

        DB::transaction(function () use ($warehouseId, $productionId, $userId): void {
            $production = DB::table('wh_productions')
                ->where('warehouse_id', $warehouseId)
                ->where('id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $production) {
                abort(404);
            }

            $opname = DB::table('wh_v7_production_material_opnames')
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $opname) {
                throw ValidationException::withMessages(['opname' => ['Draft opname tidak ditemukan.']]);
            }
            if ((string) $opname->status === 'finalized') {
                return;
            }

            if (DB::table('wh_v3_production_results')
                ->where('production_id', $productionId)
                ->where('status', 'approved')
                ->exists()) {
                throw ValidationException::withMessages(['results' => ['Finalize Actual Bahan sebelum approve Actual Hasil Produksi.']]);
            }

            $allocs = DB::table('wh_v7_production_material_allocations')
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->get();
            $items = DB::table('wh_v7_production_material_opname_items')
                ->where('opname_id', $opname->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('production_input_id');

            if ($allocs->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => ['Seluruh bahan wajib mempunyai Sisa Actual.']]);
            }

            $consumedTotal = 0;
            foreach ($allocs as $a) {
                $item = $items->get((string) $a->production_input_id);
                if (! $item) {
                    continue;
                }

                $balance = $this->lockBalance($warehouseId, (string) $a->sku_id);
                $newQty = round((float) $balance->qty_base + (float) $item->remaining_qty_base, 4);
                $newValue = round((float) $balance->inventory_value + (float) $item->remaining_value, 2);

                DB::table('wh_v7_production_stock_balances')->where('id', $balance->id)->update([
                    'qty_base' => $newQty,
                    'inventory_value' => $newValue,
                    'average_unit_cost' => $newQty > 0 ? round($newValue / $newQty, 6) : 0,
                    'last_opname_at' => now(),
                    'lock_version' => (int) $balance->lock_version + 1,
                    'updated_at' => now(),
                ]);

                DB::table('wh_v7_production_material_allocations')->where('id', $a->id)->update([
                    'remaining_qty_base' => $item->remaining_qty_base,
                    'remaining_value' => $item->remaining_value,
                    'consumed_qty_base' => $item->consumed_qty_base,
                    'consumed_value' => $item->consumed_value,
                    'status' => 'opname_finalized',
                    'updated_at' => now(),
                ]);

                $input = DB::table('wh_production_inputs')->where('id', $a->production_input_id)->first();
                $factor = max((float) ($input->conversion_factor_snapshot ?: 1), 0.00000001);
                DB::table('wh_production_inputs')->where('id', $a->production_input_id)->update([
                    'actual_qty_base' => (float) $item->consumed_qty_base,
                    'actual_qty_uom' => round((float) $item->consumed_qty_base / $factor, 4),
                    'actual_material_cost' => (float) $item->consumed_value,
                    'updated_at' => now(),
                ]);

                $consumedTotal += (float) $item->consumed_value;
            }

            DB::table('wh_v7_production_material_opnames')->where('id', $opname->id)->update([
                'status' => 'finalized',
                'finalized_by_user_id' => $userId,
                'finalized_at' => now(),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            DB::table('wh_productions')->where('id', $productionId)->update([
                'actual_input_value' => round($consumedTotal, 2),
                'updated_by_user_id' => $userId,
                'lock_version' => DB::raw('lock_version+1'),
                'updated_at' => now(),
            ]);
        }, 5);

        return $this->detail($warehouseId, $productionId);
    }

    public function requestReopen(
        string $warehouseId,
        string $productionId,
        string $reason,
        string $userId
    ): array {
        $requestId = DB::transaction(function () use ($warehouseId, $productionId, $reason, $userId): string {
            $production = DB::table('wh_productions')
                ->where('warehouse_id', $warehouseId)
                ->where('id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $production) {
                abort(404);
            }

            $opname = DB::table('wh_v7_production_material_opnames')
                ->where('warehouse_id', $warehouseId)
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $opname || (string) $opname->status !== 'finalized') {
                throw ValidationException::withMessages(['status' => ['Hanya Production Opname finalized yang dapat diajukan reopen.']]);
            }
            if (! Schema::hasTable('wh_v7_production_material_opname_reopen_requests')) {
                throw ValidationException::withMessages(['migration' => ['Migration Iterasi 04 belum diterapkan.']]);
            }

            if (DB::table('wh_v7_production_material_opname_reopen_requests')
                ->where('opname_id', $opname->id)
                ->where('status', 'pending')
                ->exists()) {
                throw ValidationException::withMessages(['reopen' => ['Masih ada permintaan reopen yang menunggu approval.']]);
            }

            $eligibility = $this->reopenEligibility($warehouseId, $productionId, $opname);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['reopen' => $eligibility['blockers']]);
            }

            $id = (string) Str::ulid();
            DB::table('wh_v7_production_material_opname_reopen_requests')->insert([
                'id' => $id,
                'request_number' => $this->reopenNumber(),
                'warehouse_id' => $warehouseId,
                'production_id' => $productionId,
                'opname_id' => $opname->id,
                'status' => 'pending',
                'reason' => trim($reason),
                'requested_by_user_id' => $userId,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        }, 5);

        $detail = $this->detail($warehouseId, $productionId);
        $detail['requested_reopen_id'] = $requestId;
        return $detail;
    }

    public function approveReopen(
        string $warehouseId,
        string $productionId,
        string $requestId,
        ?string $decisionNotes,
        string $userId
    ): array {
        DB::transaction(function () use ($warehouseId, $productionId, $requestId, $decisionNotes, $userId): void {
            $production = DB::table('wh_productions')
                ->where('warehouse_id', $warehouseId)
                ->where('id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $production) {
                abort(404);
            }

            $opname = DB::table('wh_v7_production_material_opnames')
                ->where('warehouse_id', $warehouseId)
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $opname) {
                abort(404);
            }

            $request = DB::table('wh_v7_production_material_opname_reopen_requests')
                ->where('id', $requestId)
                ->where('opname_id', $opname->id)
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $request) {
                abort(404);
            }
            if ((string) $request->status === 'approved') {
                return;
            }
            if ((string) $request->status !== 'pending') {
                throw ValidationException::withMessages(['reopen' => ['Permintaan reopen sudah diputuskan.']]);
            }
            if ((string) $request->requested_by_user_id === $userId) {
                throw ValidationException::withMessages(['approval' => ['Requester tidak boleh meng-approve permintaan reopen miliknya sendiri.']]);
            }
            if ((string) $opname->status !== 'finalized') {
                throw ValidationException::withMessages(['status' => ['Production Opname tidak lagi finalized.']]);
            }

            $items = DB::table('wh_v7_production_material_opname_items')
                ->where('opname_id', $opname->id)
                ->orderBy('sku_id')
                ->lockForUpdate()
                ->get();
            foreach ($items->pluck('sku_id')->filter()->unique()->sort()->values() as $skuId) {
                // Lock the shared Production Stock pool before the current-read
                // downstream guard. reserveForMaterial() locks the same row, so
                // no new carry-in can slip between safety check and reversal.
                $this->lockBalance($warehouseId, (string) $skuId);
            }

            $eligibility = $this->reopenEligibility($warehouseId, $productionId, $opname, true);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['reopen' => $eligibility['blockers']]);
            }

            $allocs = DB::table('wh_v7_production_material_allocations')
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->get()
                ->keyBy('production_input_id');

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Detail Production Opname tidak ditemukan.']]);
            }

            $reversalLines = [];
            foreach ($items as $item) {
                $allocation = $allocs->get((string) $item->production_input_id);
                if (! $allocation) {
                    throw ValidationException::withMessages(['allocation' => ['Material allocation tidak lengkap; reopen dibatalkan.']]);
                }

                $balance = $this->lockBalance($warehouseId, (string) $item->sku_id);
                $beforeQty = round((float) $balance->qty_base, 4);
                $beforeValue = round((float) $balance->inventory_value, 2);
                $reverseQty = round((float) $item->remaining_qty_base, 4);
                $reverseValue = round((float) $item->remaining_value, 2);

                if ($beforeQty + 0.0001 < $reverseQty) {
                    throw ValidationException::withMessages([
                        'stock' => [sprintf('Production Stock SKU %s tidak cukup untuk reversal %.4f base.', $item->sku_id, $reverseQty)],
                    ]);
                }
                if ($beforeValue + 0.02 < $reverseValue) {
                    throw ValidationException::withMessages([
                        'stock' => [sprintf('Nilai Production Stock SKU %s tidak cukup untuk reversal.', $item->sku_id)],
                    ]);
                }

                $afterQty = round($beforeQty - $reverseQty, 4);
                $afterValue = round($beforeValue - $reverseValue, 2);
                if (abs($afterQty) < 0.0001) {
                    $afterQty = 0;
                }
                if (abs($afterValue) < 0.02) {
                    $afterValue = 0;
                }

                DB::table('wh_v7_production_stock_balances')->where('id', $balance->id)->update([
                    'qty_base' => $afterQty,
                    'inventory_value' => $afterValue,
                    'average_unit_cost' => $afterQty > 0 ? round($afterValue / $afterQty, 6) : 0,
                    'last_opname_at' => $this->lastFinalizedOpnameAtForSku(
                        $warehouseId,
                        (string) $item->sku_id,
                        (string) $opname->id
                    ),
                    'lock_version' => (int) $balance->lock_version + 1,
                    'updated_at' => now(),
                ]);

                DB::table('wh_v7_production_material_allocations')->where('id', $allocation->id)->update([
                    'remaining_qty_base' => 0,
                    'remaining_value' => 0,
                    'consumed_qty_base' => 0,
                    'consumed_value' => 0,
                    'status' => 'allocated',
                    'updated_at' => now(),
                ]);

                $input = DB::table('wh_production_inputs')->where('id', $allocation->production_input_id)->first();
                if ($input) {
                    $factor = max((float) ($input->conversion_factor_snapshot ?: 1), 0.00000001);
                    DB::table('wh_production_inputs')->where('id', $allocation->production_input_id)->update([
                        'actual_qty_base' => (float) $allocation->available_qty_base,
                        'actual_qty_uom' => round((float) $allocation->available_qty_base / $factor, 4),
                        'actual_material_cost' => (float) $allocation->available_value,
                        'updated_at' => now(),
                    ]);
                }

                $reversalLines[] = [
                    'sku_id' => (string) $item->sku_id,
                    'production_input_id' => (string) $item->production_input_id,
                    'qty_reversed_base' => $reverseQty,
                    'value_reversed' => $reverseValue,
                    'balance_before_qty_base' => $beforeQty,
                    'balance_before_value' => $beforeValue,
                    'balance_after_qty_base' => $afterQty,
                    'balance_after_value' => $afterValue,
                ];
            }

            $availableTotal = round((float) DB::table('wh_v7_production_material_allocations')
                ->where('production_id', $productionId)
                ->sum('available_value'), 2);

            DB::table('wh_v7_production_material_opnames')->where('id', $opname->id)->update([
                'status' => 'draft',
                'finalized_by_user_id' => null,
                'finalized_at' => null,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            DB::table('wh_productions')->where('id', $productionId)->update([
                'actual_input_value' => $availableTotal,
                'updated_by_user_id' => $userId,
                'lock_version' => DB::raw('lock_version+1'),
                'updated_at' => now(),
            ]);

            DB::table('wh_v7_production_material_opname_reopen_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'decision_notes' => $decisionNotes ?: null,
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'reversal_snapshot' => json_encode([
                    'opname_finalized_at' => $opname->finalized_at,
                    'opname_finalized_by_user_id' => $opname->finalized_by_user_id,
                    'production_actual_input_value_before' => (float) $production->actual_input_value,
                    'production_actual_input_value_after' => $availableTotal,
                    'lines' => $reversalLines,
                ], JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }, 5);

        return $this->detail($warehouseId, $productionId);
    }

    public function rejectReopen(
        string $warehouseId,
        string $productionId,
        string $requestId,
        string $decisionNotes,
        string $userId
    ): array {
        DB::transaction(function () use ($warehouseId, $productionId, $requestId, $decisionNotes, $userId): void {
            $opname = DB::table('wh_v7_production_material_opnames')
                ->where('warehouse_id', $warehouseId)
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $opname) {
                abort(404);
            }

            $request = DB::table('wh_v7_production_material_opname_reopen_requests')
                ->where('id', $requestId)
                ->where('opname_id', $opname->id)
                ->where('production_id', $productionId)
                ->lockForUpdate()
                ->first();
            if (! $request) {
                abort(404);
            }
            if ((string) $request->status === 'rejected') {
                return;
            }
            if ((string) $request->status !== 'pending') {
                throw ValidationException::withMessages(['reopen' => ['Permintaan reopen sudah diputuskan.']]);
            }

            DB::table('wh_v7_production_material_opname_reopen_requests')->where('id', $requestId)->update([
                'status' => 'rejected',
                'decision_notes' => trim($decisionNotes),
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
        }, 5);

        return $this->detail($warehouseId, $productionId);
    }

    public function opnameStatus(string $productionId): ?array
    {
        $row = DB::table('wh_v7_production_material_opnames')->where('production_id', $productionId)->first();
        if (! $row) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'opname_number' => (string) $row->opname_number,
            'status' => (string) $row->status,
            'opname_date' => $row->opname_date,
            'remaining_qty_base' => round((float) DB::table('wh_v7_production_material_opname_items')
                ->where('opname_id', $row->id)
                ->sum('remaining_qty_base'), 4),
            'finalized_at' => $row->finalized_at,
        ];
    }

    public function requireFinalizedBeforeResult(string $productionId): void
    {
        $prod = DB::table('wh_productions')->where('id', $productionId)->first(['flow_version']);
        if (! $prod || (int) ($prod->flow_version ?? 0) < 7) {
            return;
        }

        if (! DB::table('wh_v7_production_material_opnames')
            ->where('production_id', $productionId)
            ->where('status', 'finalized')
            ->exists()) {
            throw ValidationException::withMessages([
                'material_opname' => ['Isi dan Finalize Actual Bahan Produksi terlebih dahulu.'],
            ]);
        }
    }

    public function hasWarehouseIssue(string $productionId): bool
    {
        return (float) DB::table('wh_v7_production_material_allocations')
            ->where('production_id', $productionId)
            ->sum('warehouse_issue_qty_base') > 0.0001;
    }

    private function reopenEligibility(string $warehouseId, string $productionId, object $opname, bool $locking = false): array
    {
        $blockers = [];
        $productionQuery = DB::table('wh_productions')
            ->where('warehouse_id', $warehouseId)
            ->where('id', $productionId);
        $production = $locking ? $productionQuery->lockForUpdate()->first() : $productionQuery->first();

        if (! $production) {
            $blockers[] = 'Production Order tidak ditemukan.';
        } elseif ((string) $production->status !== 'on_progress') {
            $blockers[] = 'Reopen hanya diizinkan selama Production Order masih Ongoing.';
        }

        if ((string) ($opname->status ?? '') !== 'finalized') {
            $blockers[] = 'Production Opname belum finalized.';
        }

        $approvedResultQuery = DB::table('wh_v3_production_results')
            ->where('production_id', $productionId)
            ->where('status', 'approved');
        $approvedResultExists = $locking
            ? (bool) $approvedResultQuery->lockForUpdate()->first(['id'])
            : $approvedResultQuery->exists();
        if ($approvedResultExists) {
            $blockers[] = 'Actual Hasil Produksi sudah approved; reversal Actual Bahan tidak lagi aman.';
        }

        $itemQuery = DB::table('wh_v7_production_material_opname_items')
            ->where('opname_id', $opname->id);
        $items = $locking ? $itemQuery->lockForUpdate()->get() : $itemQuery->get();
        if ($items->isEmpty()) {
            $blockers[] = 'Detail material opname tidak ditemukan.';
        }

        $cutoff = $opname->finalized_at ?: $opname->updated_at ?: now();
        $skuIds = $items->pluck('sku_id')->filter()->unique()->values()->all();
        if ($skuIds) {
            $downstreamQuery = DB::table('wh_v7_production_material_allocations')
                ->where('warehouse_id', $warehouseId)
                ->where('production_id', '<>', $productionId)
                ->whereIn('sku_id', $skuIds)
                ->where('carry_in_qty_base', '>', 0.0001)
                ->where('created_at', '>=', $cutoff)
                ->orderBy('created_at')
                ->limit(5);
            $downstream = $locking
                ? $downstreamQuery->lockForUpdate()->get(['sku_id', 'production_id', 'carry_in_qty_base', 'created_at'])
                : $downstreamQuery->get(['sku_id', 'production_id', 'carry_in_qty_base', 'created_at']);
            $productionNumbers = DB::table('wh_productions')
                ->whereIn('id', $downstream->pluck('production_id')->filter()->unique()->values()->all())
                ->pluck('production_number', 'id');

            foreach ($downstream as $row) {
                $blockers[] = sprintf(
                    'Production Stock SKU %s sudah dipakai downstream oleh %s (carry-in %.4f base).',
                    $row->sku_id,
                    $productionNumbers[(string) $row->production_id] ?? (string) $row->production_id,
                    (float) $row->carry_in_qty_base
                );
            }
        }

        foreach ($items as $item) {
            $balance = $locking
                ? $this->lockBalance($warehouseId, (string) $item->sku_id)
                : DB::table('wh_v7_production_stock_balances')
                    ->where('warehouse_id', $warehouseId)
                    ->where('sku_id', $item->sku_id)
                    ->first();
            $balanceQty = round((float) ($balance->qty_base ?? 0), 4);
            $balanceValue = round((float) ($balance->inventory_value ?? 0), 2);
            $reverseQty = round((float) $item->remaining_qty_base, 4);
            $reverseValue = round((float) $item->remaining_value, 2);

            if ($balanceQty + 0.0001 < $reverseQty) {
                $blockers[] = sprintf('Saldo Production Stock SKU %s tidak cukup untuk reversal qty.', $item->sku_id);
            }
            if ($balanceValue + 0.02 < $reverseValue) {
                $blockers[] = sprintf('Nilai Production Stock SKU %s tidak cukup untuk reversal value.', $item->sku_id);
            }
        }

        return [
            'eligible' => count($blockers) === 0,
            'blockers' => array_values(array_unique($blockers)),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function reopenRequests(string $warehouseId, string $productionId): array
    {
        if (! Schema::hasTable('wh_v7_production_material_opname_reopen_requests')) {
            return [];
        }

        return DB::table('wh_v7_production_material_opname_reopen_requests as rr')
            ->leftJoin('users as requester', 'requester.id', '=', 'rr.requested_by_user_id')
            ->leftJoin('users as decider', 'decider.id', '=', 'rr.decided_by_user_id')
            ->where('rr.warehouse_id', $warehouseId)
            ->where('rr.production_id', $productionId)
            ->orderByDesc('rr.requested_at')
            ->get([
                'rr.id',
                'rr.request_number',
                'rr.status',
                'rr.reason',
                'rr.requested_by_user_id',
                'rr.requested_at',
                'rr.decision_notes',
                'rr.decided_by_user_id',
                'rr.decided_at',
                'requester.name as requested_by_name',
                'decider.name as decided_by_name',
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'request_number' => (string) $row->request_number,
                'status' => (string) $row->status,
                'reason' => (string) $row->reason,
                'requested_by_user_id' => $row->requested_by_user_id ? (string) $row->requested_by_user_id : null,
                'requested_by_name' => $row->requested_by_name ?: null,
                'requested_at' => $row->requested_at,
                'decision_notes' => $row->decision_notes,
                'decided_by_user_id' => $row->decided_by_user_id ? (string) $row->decided_by_user_id : null,
                'decided_by_name' => $row->decided_by_name ?: null,
                'decided_at' => $row->decided_at,
            ])
            ->values()
            ->all();
    }

    private function lastFinalizedOpnameAtForSku(string $warehouseId, string $skuId, string $excludeOpnameId): mixed
    {
        return DB::table('wh_v7_production_material_opname_items as oi')
            ->join('wh_v7_production_material_opnames as o', 'o.id', '=', 'oi.opname_id')
            ->where('o.warehouse_id', $warehouseId)
            ->where('o.status', 'finalized')
            ->where('o.id', '<>', $excludeOpnameId)
            ->where('oi.sku_id', $skuId)
            ->max('o.finalized_at');
    }

    private function ensureLegacyAllocations(string $warehouseId, string $productionId): void
    {
        if (! Schema::hasTable('wh_v7_production_material_allocations')
            || DB::table('wh_v7_production_material_allocations')->where('production_id', $productionId)->exists()) {
            return;
        }

        $production = DB::table('wh_productions')
            ->where('warehouse_id', $warehouseId)
            ->where('id', $productionId)
            ->first();
        if (! $production || (string) $production->status !== 'on_progress') {
            return;
        }

        foreach (DB::table('wh_production_inputs')->where('production_id', $productionId)->get() as $input) {
            $qty = round((float) $input->actual_qty_base, 4);
            if ($qty <= 0) {
                continue;
            }
            $value = (float) ($input->actual_material_cost ?? 0);
            DB::table('wh_v7_production_material_allocations')->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $warehouseId,
                'production_id' => $productionId,
                'production_input_id' => $input->id,
                'production_request_item_id' => null,
                'sku_id' => $input->sku_id,
                'carry_in_qty_base' => 0,
                'carry_in_value' => 0,
                'warehouse_issue_qty_base' => $qty,
                'warehouse_issue_value' => $value,
                'available_qty_base' => $qty,
                'available_value' => $value,
                'remaining_qty_base' => 0,
                'remaining_value' => 0,
                'consumed_qty_base' => 0,
                'consumed_value' => 0,
                'status' => 'allocated_legacy',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function lockBalance(string $warehouseId, string $skuId): object
    {
        $row = DB::table('wh_v7_production_stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lockForUpdate()
            ->first();
        if ($row) {
            return $row;
        }

        DB::table('wh_v7_production_stock_balances')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $warehouseId,
            'sku_id' => $skuId,
            'qty_base' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('wh_v7_production_stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lockForUpdate()
            ->first();
    }

    private function number(): string
    {
        return 'PSO-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function reopenNumber(): string
    {
        return 'PSO-RPN-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }
}
