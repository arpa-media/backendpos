<?php

namespace App\Services\Warehouse\ReturnRequestV9;

use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseStorage;
use App\Services\Warehouse\FinanceV4\WarehouseGeneralPostingEngine;
use App\Services\Warehouse\Support\WarehouseSkuTransactionCatalogService;
use App\Services\Warehouse\Support\WarehouseTransactionUomService;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseReturnRequestV9Service
{
    public const OUTCOMES = ['RETURN_TO_STOCK','SPOIL'];
    public const SOURCE_TYPES = ['OUTLET','CUSTOMER','SUPPLIER','PRODUCTION','INTERNAL','OTHER'];

    public function __construct(
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseTransactionUomService $transactionUoms,
        private readonly WarehouseSkuTransactionCatalogService $skuCatalog,
        private readonly WarehouseGeneralPostingEngine $finance,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $storages = WarehouseStorage::query()
            ->where('warehouse_id',$warehouseId)
            ->where('is_active',true)
            ->orderBy('code')
            ->get(['id','code','name','storage_type','position_description'])
            ->map(fn ($row) => [
                'id'=>(string)$row->id,
                'code'=>(string)$row->code,
                'name'=>(string)$row->name,
                'storage_type'=>(string)$row->storage_type,
                'position_description'=>$row->position_description,
            ])->values()->all();

        return [
            'skus'=>$this->skuCatalog->forWarehouse($warehouseId),
            'storages'=>$storages,
            'source_types'=>self::SOURCE_TYPES,
            'outcomes'=>self::OUTCOMES,
        ];
    }

    public function valuation(string $warehouseId, string $skuId): array
    {
        $sku = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as uom','uom.id','=','sku.base_uom_id')
            ->where('sku.id',$skuId)
            ->first(['sku.id','sku.sku_code','sku.name','sku.base_uom_id','uom.code as base_uom_code','uom.name as base_uom_name']);

        if (! $sku) abort(404);

        $balance = DB::table('stk_inventory_balances')
            ->where('outlet_id',$warehouseId)
            ->where('sku_id',$skuId)
            ->first(['on_hand_qty','average_unit_cost','inventory_value','last_movement_at','updated_at']);

        return [
            'sku_id'=>(string)$sku->id,
            'sku_code'=>(string)$sku->sku_code,
            'item_name'=>(string)$sku->name,
            'base_uom'=>[
                'id'=>(string)$sku->base_uom_id,
                'code'=>(string)($sku->base_uom_code ?: 'BASE'),
                'name'=>(string)($sku->base_uom_name ?: $sku->base_uom_code ?: 'Base'),
            ],
            'on_hand_qty'=>(float)($balance->on_hand_qty ?? 0),
            'average_unit_cost'=>round((float)($balance->average_unit_cost ?? 0),6),
            'inventory_value'=>round((float)($balance->inventory_value ?? 0),2),
            'last_movement_at'=>$balance->last_movement_at ?? null,
            'valued_at'=>$balance->updated_at ?? null,
        ];
    }

    public function index(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_v9_return_requests as r')
            ->leftJoin('users as creator','creator.id','=','r.created_by_user_id')
            ->where('r.warehouse_id',$warehouseId)
            ->select('r.*','creator.name as creator_name','creator.nisj as creator_nisj')
            ->selectSub(fn ($q) => $q->from('wh_v9_return_request_items as i')
                ->whereColumn('i.return_request_id','r.id')->selectRaw('COUNT(*)'),'line_count');

        if (($filters['q'] ?? '') !== '') {
            $term='%'.trim((string)$filters['q']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('r.return_number','like',$term)
                    ->orWhere('r.source_reference','like',$term)
                    ->orWhere('r.reason','like',$term);
            });
        }
        if (($filters['status'] ?? '') !== '') $query->where('r.status',$filters['status']);
        if (($filters['source_type'] ?? '') !== '') $query->where('r.source_type',$filters['source_type']);
        if (($filters['from'] ?? '') !== '') $query->where('r.return_date','>=',$filters['from']);
        if (($filters['to'] ?? '') !== '') $query->where('r.return_date','<=',$filters['to']);

        $paginator=$query->orderByDesc('r.return_date')->orderByDesc('r.created_at')
            ->paginate((int)($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn ($row) => $this->summaryRow($row));
    }

    public function detail(string $warehouseId, string $id): array
    {
        $row = DB::table('wh_v9_return_requests as r')
            ->leftJoin('users as creator','creator.id','=','r.created_by_user_id')
            ->leftJoin('users as submitter','submitter.id','=','r.submitted_by_user_id')
            ->leftJoin('users as approver','approver.id','=','r.approved_by_user_id')
            ->leftJoin('users as executor','executor.id','=','r.executed_by_user_id')
            ->leftJoin('users as canceller','canceller.id','=','r.cancelled_by_user_id')
            ->where('r.warehouse_id',$warehouseId)->where('r.id',$id)
            ->select(
                'r.*',
                'creator.name as creator_name','creator.nisj as creator_nisj',
                'submitter.name as submitter_name','submitter.nisj as submitter_nisj',
                'approver.name as approver_name','approver.nisj as approver_nisj',
                'executor.name as executor_name','executor.nisj as executor_nisj',
                'canceller.name as canceller_name','canceller.nisj as canceller_nisj'
            )->first();

        if (! $row) abort(404);

        $items = DB::table('wh_v9_return_request_items as i')
            ->join('stk_skus as sku','sku.id','=','i.sku_id')
            ->leftJoin('wh_storages as storage','storage.id','=','i.storage_id')
            ->leftJoin('wh_batches as batch','batch.id','=','i.batch_id')
            ->where('i.return_request_id',$id)
            ->orderBy('sku.name')
            ->get([
                'i.*','sku.sku_code','sku.name as item_name',
                'storage.code as storage_code','storage.name as storage_name',
                'batch.batch_code',
            ])->map(fn ($item) => [
                'id'=>(string)$item->id,
                'sku_id'=>(string)$item->sku_id,
                'sku_code'=>(string)$item->sku_code,
                'item_name'=>(string)$item->item_name,
                'uom_id'=>(string)$item->uom_id,
                'uom_code'=>(string)$item->uom_code_snapshot,
                'uom_name'=>$item->uom_name_snapshot,
                'base_uom_id'=>(string)$item->base_uom_id,
                'base_uom_code'=>(string)$item->base_uom_code_snapshot,
                'base_uom_name'=>$item->base_uom_name_snapshot,
                'conversion_factor'=>(float)$item->conversion_factor_snapshot,
                'qty_uom'=>(float)$item->qty_uom,
                'qty_base'=>(float)$item->qty_base,
                'unit_cost'=>(float)$item->unit_cost_snapshot,
                'line_value'=>(float)$item->line_value,
                'outcome'=>(string)$item->outcome,
                'storage_id'=>$item->storage_id ? (string)$item->storage_id : null,
                'storage'=>$item->storage_id ? ['code'=>$item->storage_code,'name'=>$item->storage_name] : null,
                'batch_id'=>$item->batch_id ? (string)$item->batch_id : null,
                'batch_code'=>$item->batch_code,
                'supplier_batch_code'=>$item->supplier_batch_code,
                'production_date'=>$item->production_date,
                'expiry_date'=>$item->expiry_date,
                'status'=>(string)$item->status,
                'notes'=>$item->notes,
            ])->values()->all();

        return [
            'id'=>(string)$row->id,
            'return_number'=>(string)$row->return_number,
            'warehouse_id'=>(string)$row->warehouse_id,
            'source_type'=>(string)$row->source_type,
            'source_reference'=>$row->source_reference,
            'return_date'=>$row->return_date,
            'status'=>(string)$row->status,
            'reason'=>(string)$row->reason,
            'notes'=>$row->notes,
            'totals'=>[
                'qty_base'=>(float)$row->total_qty_base,
                'return_to_stock_qty_base'=>(float)$row->return_to_stock_qty_base,
                'spoil_qty_base'=>(float)$row->spoil_qty_base,
                'return_stock_value'=>(float)$row->return_stock_value,
                'spoil_value'=>(float)$row->spoil_value,
            ],
            'stock_ledger_posting_id'=>$row->stock_ledger_posting_id ? (string)$row->stock_ledger_posting_id : null,
            'finance_spoil_posting_id'=>$row->finance_spoil_posting_id ? (string)$row->finance_spoil_posting_id : null,
            'created_by'=>$this->person($row->creator_name,$row->creator_nisj),
            'submitted_by'=>$this->person($row->submitter_name,$row->submitter_nisj),
            'submitted_at'=>$row->submitted_at,
            'approved_by'=>$this->person($row->approver_name,$row->approver_nisj),
            'approved_at'=>$row->approved_at,
            'executed_by'=>$this->person($row->executor_name,$row->executor_nisj),
            'executed_at'=>$row->executed_at,
            'cancelled_by'=>$this->person($row->canceller_name,$row->canceller_nisj),
            'cancelled_at'=>$row->cancelled_at,
            'cancellation_reason'=>$row->cancellation_reason,
            'items'=>$items,
            'timeline'=>$this->events($id),
        ];
    }

    public function saveDraft(?string $id, string $warehouseId, array $payload, string $userId): array
    {
        $requestId = DB::transaction(function () use ($id,$warehouseId,$payload,$userId): string {
            $row = $id
                ? DB::table('wh_v9_return_requests')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first()
                : null;

            if ($id && ! $row) abort(404);
            if ($row && (string)$row->status !== 'draft') {
                throw ValidationException::withMessages(['status'=>['Return Request hanya dapat diedit saat Draft.']]);
            }

            $sourceType=strtoupper(trim((string)$payload['source_type']));
            if (! in_array($sourceType,self::SOURCE_TYPES,true)) {
                throw ValidationException::withMessages(['source_type'=>['Source Type Return Request tidak valid.']]);
            }

            $requestId=$row?->id ?: (string)Str::ulid();
            $header=[
                'warehouse_id'=>$warehouseId,
                'source_type'=>$sourceType,
                'source_reference'=>trim((string)($payload['source_reference'] ?? '')) ?: null,
                'return_date'=>$payload['return_date'],
                'status'=>'draft',
                'reason'=>trim((string)$payload['reason']),
                'notes'=>trim((string)($payload['notes'] ?? '')) ?: null,
                'updated_by_user_id'=>$userId,
                'updated_at'=>now(),
            ];

            if (! $row) {
                DB::table('wh_v9_return_requests')->insert($header + [
                    'id'=>$requestId,
                    'return_number'=>$this->number(),
                    'created_by_user_id'=>$userId,
                    'created_at'=>now(),
                ]);
                $this->event($requestId,'created',null,'draft','Return Request dibuat.',$userId);
            } else {
                DB::table('wh_v9_return_requests')->where('id',$requestId)->update($header);
                $this->event($requestId,'draft_updated','draft','draft','Draft Return Request diperbarui.',$userId);
            }

            $incomingIds=[];
            $skuSeen=[];
            $totals=['qty'=>0.0,'return_qty'=>0.0,'spoil_qty'=>0.0,'return_value'=>0.0,'spoil_value'=>0.0];

            // Delete removed draft lines before upsert. This avoids a transient
            // unique(return_request_id, sku_id) collision when a removed SKU is
            // re-used by a newly added line in the same save request.
            $payloadExistingIds=collect($payload['items'] ?? [])
                ->pluck('id')->filter(fn ($value) => trim((string)$value) !== '')
                ->map(fn ($value) => (string)$value)->values()->all();
            $stale=DB::table('wh_v9_return_request_items')->where('return_request_id',$requestId);
            if ($payloadExistingIds) $stale->whereNotIn('id',$payloadExistingIds);
            $stale->delete();

            foreach (($payload['items'] ?? []) as $index=>$line) {
                $skuId=trim((string)($line['sku_id'] ?? ''));
                if ($skuId==='') throw ValidationException::withMessages(["items.{$index}.sku_id"=>['SKU wajib dipilih.']]);
                if (isset($skuSeen[$skuId])) throw ValidationException::withMessages(["items.{$index}.sku_id"=>['SKU tidak boleh duplikat dalam satu Return Request.']]);
                $skuSeen[$skuId]=true;

                $resolved=$this->transactionUoms->resolve($skuId,(string)$line['uom_id'],false);
                $qtyUom=round((float)($line['qty_uom'] ?? 0),4);
                $qtyBase=round($qtyUom*(float)$resolved['conversion_factor'],4);
                if ($qtyUom<=0 || $qtyBase<=0) throw ValidationException::withMessages(["items.{$index}.qty_uom"=>['Qty Return wajib lebih besar dari nol.']]);

                $outcome=strtoupper(trim((string)($line['outcome'] ?? '')));
                if (! in_array($outcome,self::OUTCOMES,true)) {
                    throw ValidationException::withMessages(["items.{$index}.outcome"=>['Outcome harus RETURN_TO_STOCK atau SPOIL.']]);
                }

                // Cost is authoritative from the current Warehouse valuation. The client
                // value is accepted for backward compatibility only and is never trusted as
                // the source of truth. Once saved, unit_cost_snapshot keeps document history
                // stable even when Warehouse valuation changes later.
                $currentCost=round((float)(DB::table('stk_inventory_balances')
                    ->where('outlet_id',$warehouseId)
                    ->where('sku_id',$skuId)
                    ->value('average_unit_cost') ?? 0),6);

                $itemId=trim((string)($line['id'] ?? ''));
                $existing=$itemId!=='' ? DB::table('wh_v9_return_request_items')
                    ->where('return_request_id',$requestId)->where('id',$itemId)->first() : null;

                $unitCost=$currentCost>0
                    ? $currentCost
                    : round((float)($existing->unit_cost_snapshot ?? 0),6);

                if ($unitCost<=0) {
                    throw ValidationException::withMessages(["items.{$index}.unit_cost"=>['Valuasi Warehouse untuk SKU ini belum tersedia. Pastikan stock/valuation sudah terposting sebelum membuat Return Request.']]);
                }

                $storageId=trim((string)($line['storage_id'] ?? '')) ?: null;
                if ($outcome==='RETURN_TO_STOCK') {
                    $storage=WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->find($storageId);
                    if (! $storage) throw ValidationException::withMessages(["items.{$index}.storage_id"=>['Storage aktif wajib dipilih untuk RETURN_TO_STOCK.']]);
                } else {
                    $storageId=null;
                }

                $productionDate=trim((string)($line['production_date'] ?? '')) ?: null;
                $expiryDate=trim((string)($line['expiry_date'] ?? '')) ?: null;
                if ($productionDate && $expiryDate && $expiryDate<$productionDate) {
                    throw ValidationException::withMessages(["items.{$index}.expiry_date"=>['Expiry Date tidak boleh sebelum Production Date.']]);
                }

                $lineValue=round($qtyBase*$unitCost,2);
                $itemId=$existing?->id ?: (string)Str::ulid();

                $itemData=[
                    'return_request_id'=>$requestId,
                    'sku_id'=>$skuId,
                    'uom_id'=>$resolved['uom_id'],
                    'base_uom_id'=>$resolved['base_uom_id'],
                    'uom_code_snapshot'=>$resolved['uom_code'],
                    'uom_name_snapshot'=>$resolved['uom_name'],
                    'base_uom_code_snapshot'=>$resolved['base_uom_code'],
                    'base_uom_name_snapshot'=>$resolved['base_uom_name'],
                    'conversion_factor_snapshot'=>$resolved['conversion_factor'],
                    'qty_uom'=>$qtyUom,
                    'qty_base'=>$qtyBase,
                    'unit_cost_snapshot'=>$unitCost,
                    'line_value'=>$lineValue,
                    'outcome'=>$outcome,
                    'storage_id'=>$storageId,
                    'batch_id'=>null,
                    'supplier_batch_code'=>trim((string)($line['supplier_batch_code'] ?? '')) ?: null,
                    'production_date'=>$productionDate,
                    'expiry_date'=>$expiryDate,
                    'status'=>'pending',
                    'notes'=>trim((string)($line['notes'] ?? '')) ?: null,
                    'updated_at'=>now(),
                ];

                if ($existing) DB::table('wh_v9_return_request_items')->where('id',$itemId)->update($itemData);
                else DB::table('wh_v9_return_request_items')->insert($itemData+['id'=>$itemId,'created_at'=>now()]);

                $incomingIds[]=$itemId;
                $totals['qty']+=$qtyBase;
                if ($outcome==='RETURN_TO_STOCK') {
                    $totals['return_qty']+=$qtyBase;
                    $totals['return_value']+=$lineValue;
                } else {
                    $totals['spoil_qty']+=$qtyBase;
                    $totals['spoil_value']+=$lineValue;
                }
            }

            if (count($incomingIds)===0) throw ValidationException::withMessages(['items'=>['Minimal satu item Return Request wajib diisi.']]);

            DB::table('wh_v9_return_request_items')->where('return_request_id',$requestId)->whereNotIn('id',$incomingIds)->delete();
            DB::table('wh_v9_return_requests')->where('id',$requestId)->update([
                'total_qty_base'=>round($totals['qty'],4),
                'return_to_stock_qty_base'=>round($totals['return_qty'],4),
                'spoil_qty_base'=>round($totals['spoil_qty'],4),
                'return_stock_value'=>round($totals['return_value'],2),
                'spoil_value'=>round($totals['spoil_value'],2),
                'updated_at'=>now(),
            ]);

            return (string)$requestId;
        },5);

        return $this->detail($warehouseId,$requestId);
    }

    public function submit(string $warehouseId, string $id, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$id,$userId): void {
            $row=$this->lockHeader($warehouseId,$id);
            if ($row->status==='submitted') return;
            if ($row->status!=='draft') throw ValidationException::withMessages(['status'=>['Hanya Draft yang dapat disubmit.']]);
            if (! DB::table('wh_v9_return_request_items')->where('return_request_id',$id)->exists()) throw ValidationException::withMessages(['items'=>['Return Request tidak memiliki item.']]);

            DB::table('wh_v9_return_requests')->where('id',$id)->update([
                'status'=>'submitted','submitted_by_user_id'=>$userId,'submitted_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            $this->event($id,'submitted','draft','submitted','Return Request disubmit.',$userId);
        },5);

        return $this->detail($warehouseId,$id);
    }

    public function approve(string $warehouseId, string $id, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$id,$userId): void {
            $row=$this->lockHeader($warehouseId,$id);
            if ($row->status==='approved') return;
            if ($row->status!=='submitted') throw ValidationException::withMessages(['status'=>['Hanya Return Request Submitted yang dapat diapprove.']]);

            $spoilZeroCost=DB::table('wh_v9_return_request_items')->where('return_request_id',$id)->where('outcome','SPOIL')->where('unit_cost_snapshot','<=',0)->exists();
            if ($spoilZeroCost) throw ValidationException::withMessages(['items'=>['Item SPOIL wajib mempunyai valuasi sebelum approval.']]);

            DB::table('wh_v9_return_requests')->where('id',$id)->update([
                'status'=>'approved','approved_by_user_id'=>$userId,'approved_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            $this->event($id,'approved','submitted','approved','Return Request approved dan siap execution.',$userId);
        },5);

        return $this->detail($warehouseId,$id);
    }

    public function execute(string $warehouseId, string $id, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$id,$userId): void {
            $row=$this->lockHeader($warehouseId,$id);
            if ($row->status==='executed') return;
            if ($row->status!=='approved') throw ValidationException::withMessages(['status'=>['Hanya Return Request Approved yang dapat dieksekusi.']]);

            $items=DB::table('wh_v9_return_request_items')->where('return_request_id',$id)->lockForUpdate()->orderBy('id')->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items'=>['Return Request tidak memiliki item.']]);

            $fingerprint=hash('sha256',json_encode($items->map(fn ($i)=>[
                'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'qty_base'=>(float)$i->qty_base,
                'unit_cost'=>(float)$i->unit_cost_snapshot,'outcome'=>(string)$i->outcome,'storage_id'=>$i->storage_id,
            ])->values()->all(),JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));

            $key='WAREHOUSE-V9-RETURN-EXEC:'.$id;
            if ($row->execution_idempotency_key && ($row->execution_idempotency_key!==$key || $row->execution_payload_fingerprint!==$fingerprint)) {
                throw ValidationException::withMessages(['idempotency'=>['Return Request sudah terikat ke payload execution berbeda.']]);
            }

            $ledgerLines=[];
            $spoilValue=0.0;
            foreach ($items as $item) {
                if ((string)$item->outcome==='RETURN_TO_STOCK') {
                    $storage=WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->find($item->storage_id);
                    if (! $storage) throw ValidationException::withMessages(['storage'=>["Storage item {$item->id} tidak aktif."]]);

                    $batch=WarehouseBatch::query()->withTrashed()
                        ->where('warehouse_id',$warehouseId)
                        ->where('source_reference_type','wh_v9_return_request')
                        ->where('source_reference_id',$id)
                        ->where('source_reference_line_id',$item->id)
                        ->lockForUpdate()->first();

                    if ($batch && $batch->trashed()) $batch->restore();
                    if (! $batch) {
                        $batch=WarehouseBatch::query()->create([
                            'warehouse_id'=>$warehouseId,
                            'sku_id'=>$item->sku_id,
                            'storage_id'=>$storage->id,
                            'batch_code'=>$this->batchNumber((string)$row->return_number,(string)$item->id),
                            'supplier_batch_code'=>$item->supplier_batch_code,
                            'source_type'=>'RETURN_REQUEST_V9',
                            'source_reference_type'=>'wh_v9_return_request',
                            'source_reference_id'=>$id,
                            'source_reference_line_id'=>$item->id,
                            'received_at'=>now(),
                            'production_date'=>$item->production_date,
                            'expiry_date'=>$item->expiry_date,
                            'quantity_received_base'=>0,
                            'actual_unit_cost'=>$item->unit_cost_snapshot,
                            'price_min'=>$item->unit_cost_snapshot,
                            'price_avg'=>$item->unit_cost_snapshot,
                            'price_max'=>$item->unit_cost_snapshot,
                            'status'=>'draft',
                            'notes'=>$item->notes,
                            'metadata'=>['flow_version'=>9,'return_request_id'=>$id,'return_request_item_id'=>(string)$item->id],
                            'created_by_user_id'=>$userId,
                            'updated_by_user_id'=>$userId,
                        ]);
                    }

                    $ledgerLines[]=[
                        'line_key'=>'RETURN-ITEM:'.$item->id,
                        'sku_id'=>(string)$item->sku_id,
                        'batch_id'=>(string)$batch->id,
                        'storage_id'=>(string)$storage->id,
                        'direction'=>'IN',
                        'quantity_base'=>round((float)$item->qty_base,4),
                        'unit_cost'=>round((float)$item->unit_cost_snapshot,6),
                        'metadata'=>['flow_version'=>9,'outcome'=>'RETURN_TO_STOCK','return_request_item_id'=>(string)$item->id],
                    ];

                    DB::table('wh_v9_return_request_items')->where('id',$item->id)->update([
                        'batch_id'=>$batch->id,'status'=>'stocked','updated_at'=>now(),
                    ]);
                } else {
                    $spoilValue+=round((float)$item->line_value,2);
                    DB::table('wh_v9_return_request_items')->where('id',$item->id)->update(['status'=>'spoiled','updated_at'=>now()]);
                }
            }

            $stockPostingId=$row->stock_ledger_posting_id;
            if ($ledgerLines) {
                $posting=$this->ledger->post([
                    'warehouse_id'=>$warehouseId,
                    'idempotency_key'=>'WAREHOUSE-V9-RETURN-IN:'.$id,
                    'movement_type'=>'return_in',
                    'reference_type'=>'wh_v9_return_request',
                    'reference_id'=>$id,
                    'business_date'=>(string)$row->return_date,
                    'reason'=>'Return Request '.$row->return_number.' → Return To Stock',
                    'metadata'=>['flow_version'=>9,'return_number'=>$row->return_number,'source_type'=>$row->source_type,'source_reference'=>$row->source_reference],
                    'user_id'=>$userId,
                    'allow_negative'=>false,
                    'lines'=>$ledgerLines,
                ]);
                $stockPostingId=(string)$posting->id;
            }

            $financePostingId=$row->finance_spoil_posting_id;
            if ($spoilValue>0.009) {
                $draft=$this->finance->createFromTemplate($warehouseId,'RETURN_SPOIL_LOSS',[
                    'source_type'=>'RETURN_REQUEST',
                    'source_id'=>$id,
                    'source_key'=>'WHV9:RETURN:SPOIL:'.$id,
                    'reference_no'=>(string)$row->return_number,
                    'business_date'=>(string)$row->return_date,
                    'journal_date'=>(string)$row->return_date,
                    'currency_code'=>'IDR',
                    'amounts'=>['inventory_value'=>round($spoilValue,2)],
                    'description'=>'Spoil Return Request '.$row->return_number,
                    'metadata'=>[
                        'flow_version'=>9,
                        'return_request_id'=>$id,
                        'source_type'=>$row->source_type,
                        'source_reference'=>$row->source_reference,
                        'spoil_qty_base'=>(float)$row->spoil_qty_base,
                        'spoil_value'=>round($spoilValue,2),
                        'stock_increased'=>false,
                    ],
                ],$userId);
                $posted=$this->finance->post((string)$draft['id'],[$warehouseId],$userId);
                $financePostingId=(string)$posted['id'];
            }

            DB::table('wh_v9_return_requests')->where('id',$id)->update([
                'status'=>'executed',
                'stock_ledger_posting_id'=>$stockPostingId ?: null,
                'finance_spoil_posting_id'=>$financePostingId ?: null,
                'execution_idempotency_key'=>$key,
                'execution_payload_fingerprint'=>$fingerprint,
                'executed_by_user_id'=>$userId,
                'executed_at'=>now(),
                'updated_by_user_id'=>$userId,
                'updated_at'=>now(),
            ]);

            $this->event($id,'executed','approved','executed','Return Request executed. RETURN_TO_STOCK menambah stock; SPOIL hanya diposting ke Finance.',$userId,[
                'stock_ledger_posting_id'=>$stockPostingId,
                'finance_spoil_posting_id'=>$financePostingId,
                'spoil_value'=>round($spoilValue,2),
            ]);
        },5);

        return $this->detail($warehouseId,$id);
    }

    public function cancel(string $warehouseId, string $id, string $reason, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$id,$reason,$userId): void {
            $row=$this->lockHeader($warehouseId,$id);
            if ($row->status==='cancelled') return;
            if ($row->status==='executed' || $row->stock_ledger_posting_id || $row->finance_spoil_posting_id) {
                throw ValidationException::withMessages(['status'=>['Return Request sudah posted dan tidak dapat dicancel. Gunakan proses reversal/audit terkontrol bila koreksi diperlukan.']]);
            }
            if (! in_array((string)$row->status,['draft','submitted','approved'],true)) {
                throw ValidationException::withMessages(['status'=>['Status Return Request tidak dapat dicancel.']]);
            }

            DB::table('wh_v9_return_requests')->where('id',$id)->update([
                'status'=>'cancelled','cancelled_by_user_id'=>$userId,'cancelled_at'=>now(),
                'cancellation_reason'=>$reason,'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            DB::table('wh_v9_return_request_items')->where('return_request_id',$id)->update(['status'=>'cancelled','updated_at'=>now()]);
            $this->event($id,'cancelled',(string)$row->status,'cancelled','Return Request cancelled.',$userId,['reason'=>$reason]);
        },5);

        return $this->detail($warehouseId,$id);
    }

    public function destroyDraft(string $warehouseId, string $id): void
    {
        DB::transaction(function () use ($warehouseId,$id): void {
            $row=$this->lockHeader($warehouseId,$id);
            if ($row->status!=='draft' || $row->stock_ledger_posting_id || $row->finance_spoil_posting_id) {
                throw ValidationException::withMessages(['status'=>['Hanya Draft yang belum posted yang dapat dihapus.']]);
            }
            DB::table('wh_v9_return_requests')->where('id',$id)->delete();
        },5);
    }

    private function lockHeader(string $warehouseId, string $id): object
    {
        $row=DB::table('wh_v9_return_requests')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first();
        if (! $row) abort(404);
        return $row;
    }

    private function summaryRow(object $row): array
    {
        return [
            'id'=>(string)$row->id,'return_number'=>(string)$row->return_number,
            'return_date'=>$row->return_date,'source_type'=>(string)$row->source_type,'source_reference'=>$row->source_reference,
            'status'=>(string)$row->status,'reason'=>(string)$row->reason,'line_count'=>(int)$row->line_count,
            'total_qty_base'=>(float)$row->total_qty_base,'return_to_stock_qty_base'=>(float)$row->return_to_stock_qty_base,
            'spoil_qty_base'=>(float)$row->spoil_qty_base,'return_stock_value'=>(float)$row->return_stock_value,'spoil_value'=>(float)$row->spoil_value,
            'created_by'=>$this->person($row->creator_name,$row->creator_nisj),'created_at'=>$row->created_at,
            'executed_at'=>$row->executed_at,
        ];
    }

    private function paginated(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'items'=>collect($paginator->items())->map($mapper)->values()->all(),
            'pagination'=>[
                'current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),
                'per_page'=>$paginator->perPage(),'total'=>$paginator->total(),
                'from'=>$paginator->firstItem(),'to'=>$paginator->lastItem(),
            ],
        ];
    }

    private function events(string $requestId): array
    {
        return DB::table('wh_v9_return_request_events as e')
            ->leftJoin('users as u','u.id','=','e.actor_user_id')
            ->where('e.return_request_id',$requestId)->orderBy('e.occurred_at')
            ->get(['e.*','u.name as actor_name','u.nisj as actor_nisj'])
            ->map(fn ($e)=>[
                'id'=>(string)$e->id,'event_type'=>(string)$e->event_type,
                'from_status'=>$e->from_status,'to_status'=>$e->to_status,'message'=>$e->message,
                'metadata'=>$this->json($e->metadata),'occurred_at'=>$e->occurred_at,
                'actor'=>$this->person($e->actor_name,$e->actor_nisj),
            ])->values()->all();
    }

    private function event(string $requestId,string $type,?string $from,?string $to,string $message,?string $actor,array $metadata=[]): void
    {
        DB::table('wh_v9_return_request_events')->insert([
            'id'=>(string)Str::ulid(),'return_request_id'=>$requestId,'event_type'=>$type,
            'from_status'=>$from,'to_status'=>$to,'message'=>$message,
            'metadata'=>$metadata ? json_encode($metadata) : null,'actor_user_id'=>$actor,
            'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function number(): string
    {
        return 'WRR-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr((string)Str::ulid(),-6));
    }

    private function batchNumber(string $returnNumber,string $itemId): string
    {
        $base=preg_replace('/[^A-Z0-9]/','',strtoupper($returnNumber));
        return substr('RET-'.$base.'-'.strtoupper(substr($itemId,-6)),0,100);
    }

    private function person(?string $name,?string $nisj): ?array
    {
        return $name ? ['name'=>$name,'nisj'=>$nisj] : null;
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value)==='') return [];
        $decoded=json_decode($value,true);
        return is_array($decoded) ? $decoded : [];
    }
}
