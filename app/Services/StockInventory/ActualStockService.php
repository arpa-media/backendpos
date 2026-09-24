<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use App\Models\StockInventory\StockOpname;
use App\Models\StockInventory\StockOpnameItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActualStockService
{
    /** @var array<string,\Illuminate\Support\Collection> */
    private array $stateCache = [];

    public function __construct(private readonly ActualStockLedgerViewService $ledgerView)
    {
    }

    /**
     * I02 compatibility shim. Stock Opname is an absolute physical count and may
     * legitimately increase or decrease the authoritative quantity. Kept as a
     * no-op so legacy callers/extensions do not reintroduce the old cap rule.
     */
    public function assertOpnameDoesNotExceedLastActual(string $outletId, array $items): void
    {
        // Intentionally no-op.
    }

    public function postSubmittedOpname(StockOpname $document, ?string $userId): void
    {
        $this->forgetState((string) $document->outlet_id);
        $document->loadMissing('items');

        // Iteration 07: Stock Opname is an ABSOLUTE physical-count anchor.
        // Derive every movement delta from the canonical authoritative timeline,
        // never from stk_inventory_balances.on_hand_qty. The aggregate balance can
        // legitimately lag/recover from legacy COGS behavior, while the authoritative
        // chain (Warehouse GR + submitted Opname) is the quantity source of truth.
        $opnameEvents = $this->ledgerView
            ->authoritativeTimeline((string) $document->outlet_id)
            ->filter(fn (array $event): bool => ($event['kind'] ?? null) === 'stock_opname'
                && (string) ($event['reference_id'] ?? '') === (string) $document->id)
            ->keyBy(fn (array $event): string => (string) ($event['reference_line_id'] ?? ''));

        foreach ($document->items as $item) {
            $event = $opnameEvents->get((string) $item->id);
            if (! $event) {
                throw ValidationException::withMessages([
                    'items' => ['Canonical Stock Opname event tidak ditemukan. Submit dibatalkan agar Actual Stock tidak menulis delta yang salah.'],
                ]);
            }

            $balance = $this->lockedBalance((string) $document->outlet_id, (string) $item->sku_id);
            $storedBefore = round((float) $balance->on_hand_qty, 4);
            $before = round((float) ($event['balance_before'] ?? 0), 4);
            $after = round((float) $item->actual_qty, 4);
            $delta = round($after - $before, 4);

            // I02: a physical count is authoritative even when it is higher than
            // the pre-opname balance. Positive delta is an audited adjustment IN;
            // negative delta is an audited adjustment OUT.

            $avg = round((float) $balance->average_unit_cost, 4);
            $value = round($after * $avg, 2);
            $balance->forceFill([
                'on_hand_qty' => $after,
                'inventory_value' => $value,
                'last_movement_at' => now(),
                'lock_version' => ((int) $balance->lock_version) + 1,
            ])->save();

            $movementPayload = [
                'outlet_id' => $document->outlet_id,
                'sku_id' => $item->sku_id,
                'movement_type' => 'stock_opname_adjustment',
                'reference_type' => 'stk_stock_opname',
                'reference_id' => $document->id,
                'reference_line_id' => $item->id,
                'business_date' => $document->opname_date,
                'quantity' => $delta,
                'unit_cost' => $avg,
                'total_cost' => round($delta * $avg, 2),
                'balance_qty_after' => $after,
                'average_cost_after' => $avg,
                'inventory_value_after' => $value,
                'metadata' => [
                    'authoritative_actual' => true,
                    'source' => 'stock_opname',
                    'opname_semantics' => 'absolute_physical_count_anchor',
                    'rule' => 'delta_equals_counted_actual_minus_authoritative_before',
                    'qty_before_authoritative' => $before,
                    'qty_before_stored_balance' => $storedBefore,
                    'qty_after' => $after,
                    'quantity_delta' => $delta,
                    'stored_balance_was_mismatched' => abs($storedBefore - $before) > 0.0001,
                    'erp_v5_iteration_07' => true,
                ],
                'created_by_user_id' => $userId,
            ];

            $movement = InventoryMovement::query()
                ->where('movement_type', 'stock_opname_adjustment')
                ->where('reference_type', 'stk_stock_opname')
                ->where('reference_line_id', $item->id)
                ->lockForUpdate()
                ->first();

            if ($movement) {
                $movement->forceFill($movementPayload)->save();
            } else {
                InventoryMovement::query()->create($movementPayload);
            }
        }

        // Backdated opname must not leave today's aggregate at the historical count.
        // Replay the authoritative chronology after all event-time deltas are persisted.
        $this->applyRebuiltCurrentBalance((string) $document->outlet_id);
    }

    public function previewReset(string $outletId): array
    {
        $rows=$this->rebuildRows($outletId);
        return [
            'outlet'=>$this->outlet($outletId),'items'=>$rows,
            'summary'=>[
                'sku_count'=>count($rows),'changed_count'=>collect($rows)->where('changed',true)->count(),
                'current_qty_total'=>round((float)collect($rows)->sum('current_qty'),4),'rebuilt_qty_total'=>round((float)collect($rows)->sum('rebuilt_qty'),4),
                'submitted_opname_count'=>Schema::hasTable('stk_stock_opnames')
                    ? DB::table('stk_stock_opnames')->where('outlet_id',$outletId)->where('status','submitted')->count()
                    : 0,
                'all_opname_count'=>Schema::hasTable('stk_stock_opnames')
                    ? DB::table('stk_stock_opnames')->where('outlet_id',$outletId)->count()
                    : 0,
            ],
            'policy'=>[
                'authoritative_sources'=>['submitted_stock_opname','goods_receipt_from_warehouse_do'],
                'excluded_sources'=>['cogs_sale_consumption','cogs_sale_reversal','manual_stock'],
            ],
        ];
    }

    public function reset(string $outletId,string $mode,string $userId): array
    {
        if(!in_array($mode,['rebuild','zero','reset_opnames_zero'],true)) throw ValidationException::withMessages(['mode'=>['Mode harus rebuild, zero, atau reset_opnames_zero.']]);

        if($mode==='reset_opnames_zero'){
            return $this->resetAllOpnamesToZero($outletId,$userId);
        }

        return DB::transaction(function()use($outletId,$mode,$userId):array{
            $rows=$this->rebuildRows($outletId);
            $runId=(string)Str::ulid();$beforeTotal=0.0;$afterTotal=0.0;

            // Parent audit row MUST exist before inserting reset item rows because
            // stk_actual_stock_reset_items.reset_run_id has an immediate FK to it.
            DB::table('stk_actual_stock_reset_runs')->insert([
                'id'=>$runId,'outlet_id'=>$outletId,'mode'=>$mode,'item_count'=>count($rows),
                'qty_before_total'=>0,'qty_after_total'=>0,
                'metadata'=>json_encode(['authoritative_only'=>true,'does_not_touch_cogs'=>true,'status'=>'processing']),
                'executed_by_user_id'=>$userId,'executed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($rows as $row){
                $balance=$this->lockedBalance($outletId,$row['sku_id']);
                $before=round((float)$balance->on_hand_qty,4);$after=$mode==='zero'?0.0:round((float)$row['rebuilt_qty'],4);$avg=round((float)$balance->average_unit_cost,4);$value=round($after*$avg,2);
                $balance->forceFill(['on_hand_qty'=>$after,'inventory_value'=>$value,'last_movement_at'=>now(),'lock_version'=>((int)$balance->lock_version)+1])->save();
                DB::table('stk_actual_stock_reset_items')->insert(['id'=>(string)Str::ulid(),'reset_run_id'=>$runId,'sku_id'=>$row['sku_id'],'qty_before'=>$before,'qty_after'=>$after,'average_unit_cost'=>$avg,'inventory_value_after'=>$value,'anchor_type'=>$row['anchor_type'],'anchor_id'=>$row['anchor_id'],'anchor_at'=>$row['anchor_at'],'metadata'=>json_encode(['receipt_qty_after_anchor'=>$row['receipt_qty_after_anchor']]),'created_at'=>now(),'updated_at'=>now()]);
                $beforeTotal+=$before;$afterTotal+=$after;
            }
            DB::table('stk_actual_stock_reset_runs')->where('id',$runId)->update([
                'qty_before_total'=>round($beforeTotal,4),
                'qty_after_total'=>round($afterTotal,4),
                'metadata'=>json_encode(['authoritative_only'=>true,'does_not_touch_cogs'=>true,'status'=>'completed']),
                'updated_at'=>now(),
            ]);
            return ['run_id'=>$runId,'mode'=>$mode,'summary'=>['sku_count'=>count($rows),'qty_before_total'=>round($beforeTotal,4),'qty_after_total'=>round($afterTotal,4)]];
        },5);
    }

    public function resetAllOpnamesToZero(string $outletId,string $userId): array
    {
        return DB::transaction(function()use($outletId,$userId):array{
            $opnameIds=Schema::hasTable('stk_stock_opnames')
                ? DB::table('stk_stock_opnames')->where('outlet_id',$outletId)->pluck('id')->map(fn($id)=>(string)$id)->all()
                : [];

            $submittedCount=0;
            $opnameCount=count($opnameIds);
            $movementCount=0;

            if($opnameIds!==[]){
                $submittedCount=(int)DB::table('stk_stock_opnames')
                    ->whereIn('id',$opnameIds)
                    ->where('status','submitted')
                    ->count();

                // Keep document/item evidence for audit and COGS references, but
                // remove it from the authoritative Actual Stock source set.
                DB::table('stk_stock_opnames')
                    ->whereIn('id',$opnameIds)
                    ->update([
                        'status'=>'reset',
                        'notes'=>DB::raw("CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\\n' END, '[RESET AKTUAL] Semua Stock Opname dinonaktifkan pada ".now()->format('Y-m-d H:i:s')."')"),
                        'updated_by_user_id'=>$userId,
                        'updated_at'=>now(),
                    ]);

                if(Schema::hasTable('stk_inventory_movements')){
                    $movementQuery=DB::table('stk_inventory_movements')
                        ->where('outlet_id',$outletId)
                        ->where('movement_type','stock_opname_adjustment')
                        ->where('reference_type','stk_stock_opname')
                        ->whereIn('reference_id',$opnameIds);

                    $movementCount=(int)$movementQuery->count();
                    $movementQuery->delete();
                }
            }

            $skuIds=collect();
            if(Schema::hasTable('stk_inventory_balances')){
                $skuIds=$skuIds->merge(
                    DB::table('stk_inventory_balances')->where('outlet_id',$outletId)->pluck('sku_id')
                );
            }
            if(Schema::hasTable('stk_stock_opname_items') && $opnameIds!==[]){
                $skuIds=$skuIds->merge(
                    DB::table('stk_stock_opname_items')->whereIn('stock_opname_id',$opnameIds)->pluck('sku_id')
                );
            }
            if(Schema::hasTable('stk_inventory_movements')){
                $skuIds=$skuIds->merge(
                    DB::table('stk_inventory_movements')->where('outlet_id',$outletId)->pluck('sku_id')
                );
            }
            $skuIds=$skuIds->filter()->map(fn($id)=>(string)$id)->unique()->values();

            $runId=(string)Str::ulid();
            $beforeTotal=0.0;
            $itemCount=0;

            DB::table('stk_actual_stock_reset_runs')->insert([
                'id'=>$runId,
                'outlet_id'=>$outletId,
                'mode'=>'reset_opnames_zero',
                'item_count'=>$skuIds->count(),
                'qty_before_total'=>0,
                'qty_after_total'=>0,
                'metadata'=>json_encode([
                    'authoritative_only'=>true,
                    'does_not_touch_cogs'=>true,
                    'all_stock_opnames_disabled'=>true,
                    'submitted_opname_count'=>$submittedCount,
                    'all_opname_count'=>$opnameCount,
                    'stock_opname_adjustment_movements_removed'=>$movementCount,
                    'status'=>'processing',
                ]),
                'executed_by_user_id'=>$userId,
                'executed_at'=>now(),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            foreach($skuIds as $skuId){
                $balance=$this->lockedBalance($outletId,$skuId);
                $before=round((float)$balance->on_hand_qty,4);
                $avg=round((float)$balance->average_unit_cost,4);

                $balance->forceFill([
                    'on_hand_qty'=>0,
                    'inventory_value'=>0,
                    'last_movement_at'=>now(),
                    'lock_version'=>((int)$balance->lock_version)+1,
                ])->save();

                DB::table('stk_actual_stock_reset_items')->insert([
                    'id'=>(string)Str::ulid(),
                    'reset_run_id'=>$runId,
                    'sku_id'=>$skuId,
                    'qty_before'=>$before,
                    'qty_after'=>0,
                    'average_unit_cost'=>$avg,
                    'inventory_value_after'=>0,
                    'anchor_type'=>'all_stock_opnames_reset',
                    'anchor_id'=>null,
                    'anchor_at'=>now(),
                    'metadata'=>json_encode([
                        'all_stock_opnames_disabled'=>true,
                        'stock_opname_adjustment_movements_removed'=>$movementCount,
                    ]),
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                $beforeTotal+=$before;
                $itemCount++;
            }

            DB::table('stk_actual_stock_reset_runs')->where('id',$runId)->update([
                'item_count'=>$itemCount,
                'qty_before_total'=>round($beforeTotal,4),
                'qty_after_total'=>0,
                'metadata'=>json_encode([
                    'authoritative_only'=>true,
                    'does_not_touch_cogs'=>true,
                    'all_stock_opnames_disabled'=>true,
                    'submitted_opname_count'=>$submittedCount,
                    'all_opname_count'=>$opnameCount,
                    'stock_opname_adjustment_movements_removed'=>$movementCount,
                    'status'=>'completed',
                ]),
                'updated_at'=>now(),
            ]);

            return [
                'run_id'=>$runId,
                'mode'=>'reset_opnames_zero',
                'summary'=>[
                    'sku_count'=>$itemCount,
                    'qty_before_total'=>round($beforeTotal,4),
                    'qty_after_total'=>0,
                    'submitted_opname_reset_count'=>$submittedCount,
                    'all_opname_reset_count'=>$opnameCount,
                    'stock_opname_adjustment_movements_removed'=>$movementCount,
                ],
            ];
        },5);
    }

    private function latestHardResetAt(string $outletId): ?string
    {
        if(!Schema::hasTable('stk_actual_stock_reset_runs')) return null;

        $value=DB::table('stk_actual_stock_reset_runs')
            ->where('outlet_id',$outletId)
            ->where('mode','reset_opnames_zero')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->value('executed_at');

        return $value ? (string)$value : null;
    }

    public function lastActualCap(string $outletId,string $skuId): ?float
    {
        $state = $this->stateMap($outletId)->get($skuId);
        if ($state !== null) {
            return round((float) ($state['qty'] ?? 0), 4);
        }

        // A hard reset establishes a canonical zero even when no GR has happened
        // after the reset. It is therefore not an "opening unknown" state.
        return $this->latestHardResetAt($outletId) ? 0.0 : null;
    }

    private function rebuildRows(string $outletId): array
    {
        $state = $this->stateMap($outletId);
        $hardResetAt = $this->latestHardResetAt($outletId);

        $skuIds = collect();
        if (Schema::hasTable('stk_inventory_balances')) {
            $skuIds = $skuIds->merge(
                DB::table('stk_inventory_balances')->where('outlet_id', $outletId)->pluck('sku_id')
            );
        }
        if (Schema::hasTable('stk_par_stocks')) {
            $skuIds = $skuIds->merge(
                DB::table('stk_par_stocks')->where('outlet_id', $outletId)->where('is_active', true)->pluck('sku_id')
            );
        }
        $skuIds = $skuIds->merge($state->keys())->filter()->map(fn ($id) => (string) $id)->unique()->values();

        $skus = DB::table('stk_skus as s')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->whereIn('s.id', $skuIds)
            ->get(['s.id', 's.sku_code', 's.name', 'u.code as uom_code'])
            ->keyBy('id');

        $balances = Schema::hasTable('stk_inventory_balances')
            ? DB::table('stk_inventory_balances')->where('outlet_id', $outletId)->whereIn('sku_id', $skuIds)->get()->keyBy('sku_id')
            : collect();

        $rows = [];
        foreach ($skuIds as $skuId) {
            $currentState = $state->get((string) $skuId);
            $rebuilt = round((float) ($currentState['qty'] ?? 0), 4);
            $balance = $balances->get((string) $skuId);
            $current = round((float) ($balance?->on_hand_qty ?? 0), 4);
            $sku = $skus->get((string) $skuId);

            $anchorType = $currentState['last_event_type'] ?? ($hardResetAt ? 'hard_reset_zero' : 'opening_zero');
            $anchorId = $currentState['last_reference_id'] ?? null;
            $anchorAt = $currentState['last_event_at'] ?? $currentState['last_business_date'] ?? $hardResetAt;

            $rows[] = [
                'sku_id' => (string) $skuId,
                'sku_code' => (string) ($sku?->sku_code ?? ''),
                'sku_name' => (string) ($sku?->name ?? $skuId),
                'uom_code' => (string) ($sku?->uom_code ?? ''),
                'current_qty' => $current,
                'rebuilt_qty' => $rebuilt,
                'delta' => round($rebuilt - $current, 4),
                'changed' => abs($rebuilt - $current) > 0.0001,
                'anchor_type' => (string) $anchorType,
                'anchor_id' => $anchorId ? (string) $anchorId : null,
                'anchor_at' => $anchorAt ? (string) $anchorAt : null,
                'anchor_qty' => $rebuilt,
                'receipt_qty_after_anchor' => 0.0,
                'last_reference_type' => $currentState['last_reference_type'] ?? null,
                'last_reference_number' => $currentState['last_reference_number'] ?? null,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['sku_name'], $b['sku_name']));
        return $rows;
    }

    private function applyRebuiltCurrentBalance(string $outletId): void
    {
        $this->forgetState($outletId);
        foreach($this->rebuildRows($outletId) as $row){
            $balance=$this->lockedBalance($outletId,(string)$row['sku_id']);
            $after=round((float)$row['rebuilt_qty'],4);
            $avg=round((float)$balance->average_unit_cost,4);
            $balance->forceFill([
                'on_hand_qty'=>$after,
                'inventory_value'=>round($after*$avg,2),
                'last_movement_at'=>now(),
                'lock_version'=>((int)$balance->lock_version)+1,
            ])->save();
        }
    }

    private function stateMap(string $outletId): \Illuminate\Support\Collection
    {
        if (! array_key_exists($outletId, $this->stateCache)) {
            $this->stateCache[$outletId] = $this->ledgerView->stateMap($outletId);
        }
        return $this->stateCache[$outletId];
    }

    private function forgetState(string $outletId): void
    {
        unset($this->stateCache[$outletId]);
    }

    private function lockedBalance(string $outletId,string $skuId): InventoryBalance
    {
        $balance=InventoryBalance::query()->where('outlet_id',$outletId)->where('sku_id',$skuId)->lockForUpdate()->first();
        if($balance)return $balance;
        DB::table('stk_inventory_balances')->insertOrIgnore(['id'=>(string)Str::ulid(),'outlet_id'=>$outletId,'sku_id'=>$skuId,'on_hand_qty'=>0,'average_unit_cost'=>0,'inventory_value'=>0,'last_movement_at'=>null,'lock_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        return InventoryBalance::query()->where('outlet_id',$outletId)->where('sku_id',$skuId)->lockForUpdate()->firstOrFail();
    }

    private function outlet(string $id): ?array
    {
        $o=DB::table('outlets')->where('id',$id)->first(['id','code','name']);return $o?['id'=>(string)$o->id,'code'=>(string)$o->code,'name'=>(string)$o->name]:null;
    }
}
