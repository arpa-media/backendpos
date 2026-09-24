<?php

namespace App\Services\Warehouse\LogisticsV3;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use App\Models\User;
use App\Services\Cogs\WarehouseV3ValuationBridgeService;
use App\Services\Purchasing\WarehouseOutletInvoiceBridgeService;
use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use App\Services\Warehouse\Billing\WarehouseSourcePriceSnapshotI06Service;
use App\Services\Warehouse\Iteration07\WarehouseDispatchStockV7Service;
use App\Services\Warehouse\Pricing\WarehouseSalesPriceResolverI06;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseLogisticsV3Service
{
    public function __construct(
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseBillingUomService $billingUom,
        private readonly WarehouseSourcePriceSnapshotI06Service $sourcePriceSnapshots,
        private readonly WarehouseSalesPriceResolverI06 $strictSalesPricing,
        private readonly WarehouseOutletInvoiceBridgeService $purchasingInvoiceBridge,
        private readonly WarehouseV3ValuationBridgeService $cogsValuationBridge,
        private readonly WarehouseDispatchStockV7Service $dispatchStock,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('employee.assignment', function (Builder $query) use ($warehouseId): void {
                $query->where('outlet_id', $warehouseId)
                    ->where(fn (Builder $status) => $status->whereNull('status')->orWhereIn('status', ['active','ACTIVE']));
            })
            ->with(['employee.assignment:id,employee_id,outlet_id,role_title'])
            ->orderBy('name')
            ->get(['id','name','nisj'])
            ->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'nisj' => (string) $user->nisj,
                'role_title' => (string) ($user->employee?->assignment?->role_title ?? ''),
            ])->values()->all();

        return ['senders' => $users];
    }

    public function listPrepare(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_v3_logistics_prepare_requests as p')
            ->leftJoin('wh_v3_delivery_orders as d', 'd.prepare_request_id', '=', 'p.id')
            ->leftJoin('stk_requests as source_request', function ($join): void {
                $join->on('source_request.id', '=', 'p.source_id')
                    ->where('p.source_type', '=', 'stock_request');
            })
            ->where('p.warehouse_id', $warehouseId)
            ->select('p.*', 'd.id as delivery_order_id', 'd.delivery_number', 'd.status as delivery_status', 'source_request.request_date as source_request_date')
            ->selectSub(fn ($q) => $q->from('wh_v3_logistics_prepare_items as i')->whereColumn('i.prepare_request_id', 'p.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn ($q) => $q->from('wh_v3_logistics_prepare_items as i')->whereColumn('i.prepare_request_id', 'p.id')->selectRaw('COALESCE(SUM(i.approved_qty_base),0)'), 'approved_qty_base');
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('p.prepare_number', 'like', $term)->orWhere('p.source_number', 'like', $term));
        }
        if (! empty($filters['status'])) $query->where('p.status', $filters['status']);
        if (! empty($filters['source_type'])) $query->where('p.source_type', $filters['source_type']);
        if (! empty($filters['from'])) {
            $fromDate = (string) $filters['from'];
            $fromUtc = Carbon::parse($fromDate, 'Asia/Jakarta')->startOfDay()->utc();
            $query->where(function ($date) use ($fromDate, $fromUtc): void {
                $date->where(function ($stockRequest) use ($fromDate): void {
                    $stockRequest->where('p.source_type', 'stock_request')
                        ->where('source_request.request_date', '>=', $fromDate);
                })->orWhere(function ($otherSource) use ($fromUtc): void {
                    $otherSource->where('p.source_type', '!=', 'stock_request')
                        ->where('p.created_at', '>=', $fromUtc);
                });
            });
        }
        if (! empty($filters['to'])) {
            $toDate = (string) $filters['to'];
            $untilUtc = Carbon::parse($toDate, 'Asia/Jakarta')->startOfDay()->addDay()->utc();
            $query->where(function ($date) use ($toDate, $untilUtc): void {
                $date->where(function ($stockRequest) use ($toDate): void {
                    $stockRequest->where('p.source_type', 'stock_request')
                        ->where('source_request.request_date', '<=', $toDate);
                })->orWhere(function ($otherSource) use ($untilUtc): void {
                    $otherSource->where('p.source_type', '!=', 'stock_request')
                        ->where('p.created_at', '<', $untilUtc);
                });
            });
        }
        $useDefaultView = empty($filters['q']) && empty($filters['status']) && empty($filters['source_type']) && empty($filters['from']) && empty($filters['to']);
        if ($useDefaultView) {
            $today = now('Asia/Jakarta')->startOfDay();
            $completedFrom = $today->copy()->utc();
            $completedUntil = $today->copy()->addDay()->utc();
            $query->where(function ($status) use ($completedFrom, $completedUntil): void {
                $status->whereIn('p.status', ['queued','preparing'])
                    ->orWhere(function ($completed) use ($completedFrom, $completedUntil): void {
                        $completed->where('p.status', 'completed')
                            ->where('p.updated_at', '>=', $completedFrom)
                            ->where('p.updated_at', '<', $completedUntil);
                    });
            });
        }
        $paginator = $query
            ->orderByRaw("CASE WHEN p.status='queued' THEN 0 WHEN p.status='preparing' THEN 1 WHEN p.status='completed' THEN 2 ELSE 3 END")
            ->orderByRaw('COALESCE(source_request.request_date, DATE(p.created_at)) ASC')
            ->orderBy('p.created_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn ($r) => [
            'id'=>(string)$r->id,'prepare_number'=>(string)$r->prepare_number,'source_type'=>(string)$r->source_type,
            'source_id'=>(string)$r->source_id,'source_number'=>$r->source_number,'destination_type'=>$r->destination_type,
            'destination_id'=>$r->destination_id,'destination'=>$this->destination($r->destination_type,$r->destination_id),
            'status'=>(string)$r->status,'request_date'=>$r->source_request_date ?: Carbon::parse($r->created_at)->timezone('Asia/Jakarta')->toDateString(),'requested_delivery_date'=>$r->requested_delivery_date,
            'line_count'=>(int)$r->line_count,'approved_qty_base'=>round((float)$r->approved_qty_base,4),
            'delivery_order_id'=>$r->delivery_order_id,'delivery_number'=>$r->delivery_number,'delivery_status'=>$r->delivery_status,
            'approved_at'=>$r->approved_at,'created_at'=>$r->created_at,
        ]);
    }

    public function prepareDetail(string $warehouseId, string $id): array
    {
        $p = DB::table('wh_v3_logistics_prepare_requests')->where('warehouse_id',$warehouseId)->where('id',$id)->first();
        if (! $p) abort(404);
        $do = DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$id)->first();
        $items = DB::table('wh_v3_logistics_prepare_items as i')
            ->join('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.uom_id')
            ->where('i.prepare_request_id',$id)->orderBy('s.name')
            ->get(['i.*','s.sku_code','s.name as item_name','u.code as uom_code'])
            ->map(function ($i) use ($warehouseId): array {
                $available = (float) (DB::table('stk_inventory_balances')->where('outlet_id',$warehouseId)->where('sku_id',$i->sku_id)->value('on_hand_qty') ?? 0);
                return [
                    'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,
                    'uom_code'=>(string)($i->uom_code ?: 'UNIT'),'requested_qty_uom'=>round((float)$i->requested_qty_uom,4),
                    'requested_qty_base'=>round((float)$i->requested_qty_base,4),'approved_qty_uom'=>round((float)$i->approved_qty_uom,4),
                    'approved_qty_base'=>round((float)$i->approved_qty_base,4),'sent_qty_uom'=>round($this->baseToUom((float)$i->ready_qty_base,(float)$i->approved_qty_base,(float)$i->approved_qty_uom),4),
                    'sent_qty_base'=>round((float)$i->ready_qty_base,4),'warehouse_on_hand_qty'=>round($available,4),'status'=>(string)$i->status,'notes'=>$i->notes,
                ];
            })->values()->all();
        return [
            'id'=>(string)$p->id,'prepare_number'=>(string)$p->prepare_number,'source_type'=>(string)$p->source_type,'source_id'=>(string)$p->source_id,
            'source_number'=>$p->source_number,'destination_type'=>$p->destination_type,'destination_id'=>$p->destination_id,
            'destination'=>$this->destination($p->destination_type,$p->destination_id),'status'=>(string)$p->status,'requested_delivery_date'=>$p->requested_delivery_date,
            'metadata'=>$this->json($p->metadata),'items'=>$items,'delivery_order'=>$do ? ['id'=>(string)$do->id,'delivery_number'=>(string)$do->delivery_number,'status'=>(string)$do->status] : null,
            'timeline'=>$this->events('prepare_request',(string)$p->id),
        ];
    }

    public function generateDeliveryOrder(string $warehouseId, string $prepareId, array $payload, string $userId): array
    {
        $deliveryId = DB::transaction(function () use ($warehouseId,$prepareId,$payload,$userId): string {
            $prepare = DB::table('wh_v3_logistics_prepare_requests')->where('warehouse_id',$warehouseId)->where('id',$prepareId)->lockForUpdate()->first();
            if (! $prepare) abort(404);
            $existing = DB::table('wh_v3_delivery_orders')->where('prepare_request_id',$prepareId)->lockForUpdate()->first();
            if ($existing) return (string) $existing->id;
            if (! in_array((string)$prepare->status,['queued','preparing'],true)) {
                throw ValidationException::withMessages(['status'=>['Checker Prepare sudah diproses atau tidak lagi dapat dibuatkan Delivery Order.']]);
            }
            $this->assertWarehouseUser((string)$payload['sender_user_id'],$warehouseId);
            $inputMap = collect($payload['items'] ?? [])->keyBy(fn ($row)=>(string)($row['item_id']??''));
            $prepareItems = DB::table('wh_v3_logistics_prepare_items')->where('prepare_request_id',$prepareId)->lockForUpdate()->get();
            if ($prepareItems->isEmpty()) throw ValidationException::withMessages(['items'=>['Checker Prepare tidak memiliki item.']]);

            $destination = $this->destination($prepare->destination_type,$prepare->destination_id);
            $isSalesCustomer = false;
            if (
                (string) $prepare->source_type === 'sales_order'
                && Schema::hasColumn('wh_v3_sales_orders', 'sales_channel')
            ) {
                $salesCustomer = DB::table('wh_v3_sales_orders')
                    ->where('id', $prepare->source_id)
                    ->where('sales_channel', 'customer_manual')
                    ->first([
                        'destination_label_snapshot', 'destination_recipient_snapshot', 'destination_phone_snapshot',
                        'destination_address_snapshot', 'destination_city_snapshot', 'destination_province_snapshot',
                        'destination_postal_code_snapshot',
                    ]);
                if ($salesCustomer) {
                    $isSalesCustomer = true;
                    $cityLine = trim(implode(' ', array_filter([
                        trim((string) $salesCustomer->destination_city_snapshot),
                        trim((string) $salesCustomer->destination_province_snapshot),
                        trim((string) $salesCustomer->destination_postal_code_snapshot),
                    ])));
                    $addressLines = array_filter([
                        trim((string) $salesCustomer->destination_address_snapshot),
                        $cityLine,
                    ]);
                    $destination['name'] = trim((string) $salesCustomer->destination_label_snapshot)
                        ?: trim((string) $salesCustomer->destination_recipient_snapshot)
                        ?: ($destination['name'] ?? 'Customer');
                    $destination['address'] = implode(', ', $addressLines) ?: ($destination['address'] ?? null);
                    $destination['recipient_name'] = $salesCustomer->destination_recipient_snapshot;
                    $destination['phone'] = $salesCustomer->destination_phone_snapshot;
                }
            }
            $deliveryId = (string) Str::ulid();
            DB::table('wh_v3_delivery_orders')->insert([
                'id'=>$deliveryId,'delivery_number'=>$this->number('DO'),'prepare_request_id'=>$prepareId,'warehouse_id'=>$warehouseId,
                'source_type'=>$prepare->source_type,'source_id'=>$prepare->source_id,'source_number'=>$prepare->source_number,
                'destination_type'=>$prepare->destination_type,'destination_id'=>$prepare->destination_id,
                'destination_code_snapshot'=>$destination['code']??null,'destination_name_snapshot'=>$destination['name']??null,'destination_address_snapshot'=>$destination['address']??null,
                'status'=>'dispatched','estimated_delivery_date'=>$payload['estimated_delivery_date'],'estimated_delivery_time'=>$payload['estimated_delivery_time'],
                'sender_user_id'=>$payload['sender_user_id'],'prepared_by_user_id'=>$userId,'prepared_at'=>now(),'dispatched_at'=>now(),'notes'=>$payload['notes']??null,
                'metadata'=>json_encode([
                    'flow_version'=>3,'barcode_required'=>false,'receiver_on_do'=>false,
                    'sales_customer'=>$isSalesCustomer,
                    'destination_recipient_name'=>$destination['recipient_name']??null,
                    'destination_phone'=>$destination['phone']??null,
                ]),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $positive = 0;
            $dispatchBySku = [];
            foreach ($prepareItems as $item) {
                $input = $inputMap->get((string)$item->id);
                if (! $input) throw ValidationException::withMessages(['items'=>['Seluruh item Checker Prepare wajib memiliki Qty Dikirim.']]);
                $approvedUom = round((float)$item->approved_qty_uom,4);
                $approvedBase = round((float)$item->approved_qty_base,4);
                $sentUom = round((float)($input['sent_qty_uom'] ?? 0),4);
                if ($sentUom < 0 || $sentUom > $approvedUom + 0.0001) {
                    throw ValidationException::withMessages(['items'=>["Qty dikirim item {$item->id} harus antara 0 dan Approved Qty."]]);
                }
                $factor = $approvedUom > 0 ? $approvedBase / $approvedUom : 1.0;
                $sentBase = round($sentUom*$factor,4);
                if ($sentBase > 0) { $positive++; $dispatchBySku[(string)$item->sku_id] = round(($dispatchBySku[(string)$item->sku_id] ?? 0) + $sentBase, 4); }
                DB::table('wh_v3_delivery_order_items')->insert([
                    'id'=>(string)Str::ulid(),'delivery_order_id'=>$deliveryId,'prepare_item_id'=>$item->id,'source_item_id'=>$item->source_item_id,
                    'sku_id'=>$item->sku_id,'uom_id'=>$item->uom_id,'requested_qty_uom'=>$item->requested_qty_uom,'requested_qty_base'=>$item->requested_qty_base,
                    'approved_qty_uom'=>$approvedUom,'approved_qty_base'=>$approvedBase,'sent_qty_uom'=>$sentUom,'sent_qty_base'=>$sentBase,
                    'metadata'=>json_encode(['notes'=>$input['notes']??null,'barcode_used'=>false]),'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('wh_v3_logistics_prepare_items')->where('id',$item->id)->update([
                    'ready_qty_base'=>$sentBase,'status'=>$sentBase>0?'dispatched':'not_dispatched','notes'=>$input['notes']??$item->notes,'updated_at'=>now(),
                ]);
            }
            if ($positive === 0) throw ValidationException::withMessages(['items'=>['Minimal satu item harus memiliki Qty Dikirim lebih besar dari nol.']]);
            foreach ($dispatchBySku as $skuId => $qtyBase) $this->assertAvailableForDispatch($warehouseId, $skuId, $qtyBase);

            // Iteration 07: bootstrap GR audit row before stock posting so legacy valuation/reset consumers keep the same reference contract.
            $this->bootstrapGoodsReceipt($deliveryId,$userId);
            // Transfer v4 already owns WAREHOUSE-V4-TRANSFER-OUT:{deliveryId}; do not double post it here.
            $dispatchPosting = (string)$prepare->source_type === 'transfer_stock'
                ? null
                : $this->dispatchStock->ensurePosted($warehouseId,$deliveryId,$userId);

            DB::table('wh_v3_logistics_prepare_requests')->where('id',$prepareId)->update(['status'=>'dispatched','updated_at'=>now()]);
            $message = (string)$prepare->source_type === 'transfer_stock'
                ? 'Delivery Order Transfer digenerate. transfer_out tetap ditangani flow Transfer v4.'
                : 'Delivery Order digenerate. Actual Stock Warehouse langsung berkurang pada DO Dispatch.';
            $meta=['delivery_order_id'=>$deliveryId]; if($dispatchPosting) $meta['dispatch_ledger_posting_id']=(string)$dispatchPosting->id;
            $this->event('prepare_request',$prepareId,'delivery_order_generated',(string)$prepare->status,'dispatched',$message,$userId,$meta);
            $this->event('delivery_order',$deliveryId,'dispatched',null,'dispatched',$message,$userId,['prepare_request_id'=>$prepareId]+$meta);
            if ($prepare->source_type === 'stock_request') $this->updateStockRequestStatus((string)$prepare->source_id,'on-delivery','warehouse_v3_delivery_order_generated','Delivery Order sudah digenerate dan sedang dikirim.',$userId,['delivery_order_id'=>$deliveryId]);
            return $deliveryId;
        },5);

        return $this->deliveryOrderDetail($warehouseId,$deliveryId);
    }

    public function listDeliveryOrders(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_v3_delivery_orders as d')
            ->leftJoin('users as sender', 'sender.id', '=', 'd.sender_user_id')
            ->where('d.warehouse_id', $warehouseId)
            ->select('d.*', 'sender.name as sender_name', 'sender.nisj as sender_nisj')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_delivery_order_items as i')
                ->whereColumn('i.delivery_order_id', 'd.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_delivery_order_items as i')
                ->whereColumn('i.delivery_order_id', 'd.id')->selectRaw('COALESCE(SUM(i.sent_qty_base),0)'), 'sent_qty_base');

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('d.delivery_number', 'like', $term)
                ->orWhere('d.source_number', 'like', $term)
                ->orWhere('d.destination_name_snapshot', 'like', $term));
        }
        if (! empty($filters['status'])) {
            $query->where('d.status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $fromUtc = Carbon::parse((string) $filters['from'], 'Asia/Jakarta')->startOfDay()->utc();
            $query->whereRaw('COALESCE(d.dispatched_at,d.created_at) >= ?', [$fromUtc->format('Y-m-d H:i:s')]);
        }
        if (! empty($filters['to'])) {
            $untilUtc = Carbon::parse((string) $filters['to'], 'Asia/Jakarta')->startOfDay()->addDay()->utc();
            $query->whereRaw('COALESCE(d.dispatched_at,d.created_at) < ?', [$untilUtc->format('Y-m-d H:i:s')]);
        }

        $paginator = $query
            ->orderByDesc(DB::raw('COALESCE(d.dispatched_at,d.created_at)'))
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn ($row) => [
            'id' => (string) $row->id,
            'delivery_number' => (string) $row->delivery_number,
            'source_type' => (string) $row->source_type,
            'source_number' => $row->source_number,
            'destination_type' => (string) $row->destination_type,
            'destination' => ['code' => $row->destination_code_snapshot, 'name' => $row->destination_name_snapshot],
            'status' => (string) $row->status,
            'estimated_delivery_date' => $row->estimated_delivery_date,
            'estimated_delivery_time' => $row->estimated_delivery_time,
            'sender' => $row->sender_name ? ['name' => $row->sender_name, 'nisj' => $row->sender_nisj] : null,
            'line_count' => (int) $row->line_count,
            'sent_qty_base' => round((float) $row->sent_qty_base, 4),
            'dispatched_at' => $row->dispatched_at,
        ]);
    }

    public function deliveryOrderDetail(string $warehouseId,string $id):array
    {
        $d=DB::table('wh_v3_delivery_orders as d')->leftJoin('users as sender','sender.id','=','d.sender_user_id')->leftJoin('users as prep','prep.id','=','d.prepared_by_user_id')
            ->where('d.warehouse_id',$warehouseId)->where('d.id',$id)->first(['d.*','sender.name as sender_name','sender.nisj as sender_nisj','prep.name as prepared_name','prep.nisj as prepared_nisj']);
        if(!$d)abort(404);
        $items=$this->deliveryItems($id);
        $gr=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$id)->first();
        return [
            'id'=>(string)$d->id,'delivery_number'=>(string)$d->delivery_number,'source_type'=>(string)$d->source_type,'source_id'=>(string)$d->source_id,'source_number'=>$d->source_number,
            'warehouse'=>$this->outlet((string)$d->warehouse_id),'destination_type'=>(string)$d->destination_type,'destination_id'=>(string)$d->destination_id,
            'destination'=>['code'=>$d->destination_code_snapshot,'name'=>$d->destination_name_snapshot,'address'=>$d->destination_address_snapshot],'status'=>(string)$d->status,
            'estimated_delivery_date'=>$d->estimated_delivery_date,'estimated_delivery_time'=>$d->estimated_delivery_time,'dispatched_at'=>$d->dispatched_at,'stock_dispatched_at'=>$d->stock_dispatched_at ?? null,'dispatch_ledger_posting_id'=>$d->dispatch_ledger_posting_id ?? null,'notes'=>$d->notes,
            'prepared_by'=>$d->prepared_name?['name'=>$d->prepared_name,'nisj'=>$d->prepared_nisj]:null,'prepared_at'=>$d->prepared_at,
            'sender'=>$d->sender_name?['name'=>$d->sender_name,'nisj'=>$d->sender_nisj]:null,
            'items'=>$items,'goods_receipt'=>$gr?['id'=>(string)$gr->id,'goods_receipt_number'=>(string)$gr->goods_receipt_number,'status'=>(string)$gr->status]:null,
            'timeline'=>$this->events('delivery_order',$id),
        ];
    }

    public function listGoodsReceipts(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->leftJoin('users as receiver', 'receiver.id', '=', 'g.receiver_user_id')
            ->where('g.warehouse_id', $warehouseId)
            ->select('g.*', 'd.delivery_number', 'd.source_number', 'd.destination_code_snapshot', 'd.destination_name_snapshot', 'receiver.name as receiver_name')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_goods_receipt_items as i')
                ->whereColumn('i.goods_receipt_id', 'g.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_goods_receipt_items as i')
                ->whereColumn('i.goods_receipt_id', 'g.id')->selectRaw('COALESCE(SUM(i.received_qty_base),0)'), 'received_qty_base')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_goods_receipt_items as i')
                ->whereColumn('i.goods_receipt_id', 'g.id')->selectRaw('COALESCE(SUM(i.not_received_qty_base),0)'), 'not_received_qty_base');

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('g.goods_receipt_number', 'like', $term)
                ->orWhere('d.delivery_number', 'like', $term)
                ->orWhere('d.destination_name_snapshot', 'like', $term));
        }
        if (! empty($filters['status'])) {
            $query->where('g.status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $fromDate = (string) $filters['from'];
            $fromUtc = Carbon::parse($fromDate, 'Asia/Jakarta')->startOfDay()->utc();
            $query->where(function ($date) use ($fromDate, $fromUtc): void {
                $date->where(fn ($q) => $q->whereNotNull('g.receipt_date')->where('g.receipt_date', '>=', $fromDate))
                    ->orWhere(fn ($q) => $q->whereNull('g.receipt_date')->where('g.created_at', '>=', $fromUtc));
            });
        }
        if (! empty($filters['to'])) {
            $toDate = (string) $filters['to'];
            $untilUtc = Carbon::parse($toDate, 'Asia/Jakarta')->startOfDay()->addDay()->utc();
            $query->where(function ($date) use ($toDate, $untilUtc): void {
                $date->where(fn ($q) => $q->whereNotNull('g.receipt_date')->where('g.receipt_date', '<=', $toDate))
                    ->orWhere(fn ($q) => $q->whereNull('g.receipt_date')->where('g.created_at', '<', $untilUtc));
            });
        }

        $paginator = $query
            ->orderByRaw("CASE WHEN g.status='submitted' THEN 0 WHEN g.status='draft' THEN 1 ELSE 2 END")
            ->orderByDesc(DB::raw('COALESCE(g.receipt_date,DATE(g.created_at))'))
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn ($row) => [
            'id' => (string) $row->id,
            'goods_receipt_number' => (string) $row->goods_receipt_number,
            'delivery_order_id' => (string) $row->delivery_order_id,
            'delivery_number' => (string) $row->delivery_number,
            'source_number' => $row->source_number,
            'destination' => ['code' => $row->destination_code_snapshot, 'name' => $row->destination_name_snapshot],
            'status' => (string) $row->status,
            'line_count' => (int) $row->line_count,
            'received_qty_base' => round((float) $row->received_qty_base, 4),
            'not_received_qty_base' => round((float) $row->not_received_qty_base, 4),
            'receiver_name' => $row->receiver_name,
            'receipt_date' => $row->receipt_date,
            'received_at' => $row->received_at,
            'completed_at' => $row->completed_at,
        ]);
    }

    public function goodsReceiptDetail(string $warehouseId,string $id):array
    {
        $g=DB::table('wh_v3_goods_receipts as g')->join('wh_v3_delivery_orders as d','d.id','=','g.delivery_order_id')
            ->leftJoin('users as completed','completed.id','=','g.completed_by_user_id')->where('g.warehouse_id',$warehouseId)->where('g.id',$id)
            ->first(['g.*','d.delivery_number','d.source_type','d.source_id','d.source_number','d.destination_code_snapshot','d.destination_name_snapshot','d.destination_address_snapshot','d.estimated_delivery_date','d.estimated_delivery_time','completed.name as completed_name']);
        if(!$g)abort(404);
        $items=$this->goodsReceiptItems($id);
        $invoice=$g->outgoing_invoice_id?DB::table('wh_v3_outgoing_invoices')->where('id',$g->outgoing_invoice_id)->first():null;
        return [
            'id'=>(string)$g->id,'goods_receipt_number'=>(string)$g->goods_receipt_number,'delivery_order_id'=>(string)$g->delivery_order_id,'delivery_number'=>(string)$g->delivery_number,
            'source_type'=>(string)$g->source_type,'source_id'=>(string)$g->source_id,'source_number'=>$g->source_number,'destination_type'=>(string)$g->destination_type,'destination_id'=>(string)$g->destination_id,
            'destination'=>['code'=>$g->destination_code_snapshot,'name'=>$g->destination_name_snapshot,'address'=>$g->destination_address_snapshot],
            'warehouse'=>$this->outlet((string)$g->warehouse_id),'status'=>(string)$g->status,'receipt_date'=>$g->receipt_date,'received_at'=>$g->received_at,'sender'=>['name'=>$g->sender_name_snapshot,'signed_at'=>$g->sender_signed_at],
            'receiver'=>$g->receiver_name_snapshot?['name'=>$g->receiver_name_snapshot,'signed_at'=>$g->receiver_signed_at]:null,'ledger_posting_id'=>$g->ledger_posting_id,
            'completed_at'=>$g->completed_at,'completed_by'=>$g->completed_name,'notes'=>$g->notes,'items'=>$items,
            'outgoing_invoice'=>$invoice?['id'=>(string)$invoice->id,'invoice_number'=>(string)$invoice->invoice_number,'status'=>(string)$invoice->status]:null,
            'timeline'=>$this->events('goods_receipt',$id),
        ];
    }

    public function outletDeliveries(string $outletId,array $filters):array
    {
        $q=DB::table('wh_v3_delivery_orders as d')->join('outlets as w','w.id','=','d.warehouse_id')->leftJoin('wh_v3_goods_receipts as g','g.delivery_order_id','=','d.id')
            ->where('d.destination_type','outlet')->where('d.destination_id',$outletId)->whereIn('d.status',['dispatched','receiving','received','completed'])
            ->select('d.*','w.name as warehouse_name','w.code as warehouse_code','g.id as goods_receipt_id','g.goods_receipt_number','g.status as goods_receipt_status','g.received_at')
            ->selectSub(fn($s)=>$s->from('wh_v3_delivery_order_items as i')->whereColumn('i.delivery_order_id','d.id')->selectRaw('COUNT(*)'),'line_count');
        if(!empty($filters['q'])){$term='%'.trim((string)$filters['q']).'%';$q->where(fn($x)=>$x->where('d.delivery_number','like',$term)->orWhere('d.source_number','like',$term));}
        if(!empty($filters['status']))$q->where('g.status',$filters['status']);
        $p=$q->orderByDesc('d.dispatched_at')->paginate((int)($filters['per_page']??30));
        return $this->paginated($p,fn($r)=>[
            'id'=>(string)$r->id,'delivery_number'=>(string)$r->delivery_number,'source_number'=>$r->source_number,'warehouse'=>['code'=>$r->warehouse_code,'name'=>$r->warehouse_name],
            'estimated_delivery_date'=>$r->estimated_delivery_date,'estimated_delivery_time'=>$r->estimated_delivery_time,'status'=>(string)$r->status,
            'goods_receipt_id'=>$r->goods_receipt_id,'goods_receipt_number'=>$r->goods_receipt_number,'goods_receipt_status'=>$r->goods_receipt_status,'received_at'=>$r->received_at,'line_count'=>(int)$r->line_count,
        ]);
    }

    public function outletDeliveryDetail(string $outletId,string $deliveryId):array
    {
        $d=DB::table('wh_v3_delivery_orders as d')->join('outlets as w','w.id','=','d.warehouse_id')->leftJoin('users as sender','sender.id','=','d.sender_user_id')
            ->where('d.destination_type','outlet')->where('d.destination_id',$outletId)->where('d.id',$deliveryId)
            ->first(['d.*','w.name as warehouse_name','w.code as warehouse_code','sender.name as sender_name','sender.nisj as sender_nisj']);
        if(!$d)abort(404);
        $g=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->first();
        return [
            'id'=>(string)$d->id,'delivery_number'=>(string)$d->delivery_number,'source_number'=>$d->source_number,'warehouse'=>['code'=>$d->warehouse_code,'name'=>$d->warehouse_name],
            'estimated_delivery_date'=>$d->estimated_delivery_date,'estimated_delivery_time'=>$d->estimated_delivery_time,'sender'=>['name'=>$d->sender_name,'nisj'=>$d->sender_nisj],
            'status'=>(string)$d->status,'minimum_receipt_date'=>$this->minimumReceiptDate($d),'maximum_receipt_date'=>now('Asia/Jakarta')->toDateString(),'items'=>$this->deliveryItems($deliveryId),'goods_receipt'=>$g?[
                'id'=>(string)$g->id,'goods_receipt_number'=>(string)$g->goods_receipt_number,'status'=>(string)$g->status,'receipt_date'=>$g->receipt_date,'received_at'=>$g->received_at,
                'receiver_name'=>$g->receiver_name_snapshot,'items'=>$this->goodsReceiptItems((string)$g->id),
            ]:null,
        ];
    }

    public function receiveAtOutlet(string $outletId,string $deliveryId,array $payload,string $userId):array
    {
        DB::transaction(function()use($outletId,$deliveryId,$payload,$userId):void{
            $d=DB::table('wh_v3_delivery_orders')->where('destination_type','outlet')->where('destination_id',$outletId)->where('id',$deliveryId)->lockForUpdate()->first();
            if(!$d)abort(404);
            $receiptDate = (string) ($payload['receipt_date'] ?? now('Asia/Jakarta')->toDateString());
            $minimumReceiptDate = $this->minimumReceiptDate($d);
            $maximumReceiptDate = now('Asia/Jakarta')->toDateString();
            if ($receiptDate < $minimumReceiptDate) {
                throw ValidationException::withMessages(['receipt_date'=>[sprintf('Tanggal terima minimal %s, tidak boleh sebelum tanggal request.', $minimumReceiptDate)]]);
            }
            if ($receiptDate > $maximumReceiptDate) {
                throw ValidationException::withMessages(['receipt_date'=>['Tanggal terima tidak boleh melebihi hari ini.']]);
            }
            if(!in_array((string)$d->status,['dispatched','receiving'],true)){
                $g=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->first();
                if($g && in_array((string)$g->status,['submitted','completed'],true)) return;
                throw ValidationException::withMessages(['status'=>['Delivery Order tidak dapat diterima pada status saat ini.']]);
            }
            $g=DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->lockForUpdate()->first();
            if(!$g) throw ValidationException::withMessages(['goods_receipt'=>['Draft Goods Receipt tidak ditemukan.']]);
            if(in_array((string)$g->status,['submitted','completed'],true)) return;
            $byId=collect($payload['items']??[])->keyBy(fn($r)=>(string)($r['item_id']??''));
            $items=DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$g->id)->lockForUpdate()->get();
            foreach($items as $item){
                $input=$byId->get((string)$item->id);if(!$input)throw ValidationException::withMessages(['items'=>['Seluruh item wajib diisi Actual Qty diterima.']]);
                $receivedUom=round((float)($input['received_qty_uom']??0),4);$sentUom=round((float)$item->sent_qty_uom,4);
                $notUom=array_key_exists('not_received_qty_uom',$input)?round((float)$input['not_received_qty_uom'],4):round($sentUom-$receivedUom,4);
                if($receivedUom<0||$notUom<0||$receivedUom>$sentUom+0.0001||$notUom>$sentUom+0.0001)throw ValidationException::withMessages(['items'=>["Qty diterima/tidak diterima tidak valid untuk item {$item->id}."]]);
                if(abs(($receivedUom+$notUom)-$sentUom)>0.0001)throw ValidationException::withMessages(['items'=>["Qty Diterima + Qty Tidak Diterima harus sama dengan Qty Dikirim untuk item {$item->id}."]]);
                $factor=$sentUom>0?(float)$item->sent_qty_base/$sentUom:1.0;$receivedBase=round($receivedUom*$factor,4);
                $notBase=round($notUom*$factor,4);
                DB::table('wh_v3_goods_receipt_items')->where('id',$item->id)->update([
                    'received_qty_uom'=>$receivedUom,'received_qty_base'=>$receivedBase,'not_received_qty_uom'=>$notUom,'not_received_qty_base'=>$notBase,
                    'notes'=>$input['notes']??null,'updated_at'=>now(),
                ]);
            }
            $receiver=DB::table('users')->where('id',$userId)->first(['name','nisj']);
            DB::table('wh_v3_goods_receipts')->where('id',$g->id)->update([
                'status'=>'submitted','receipt_date'=>$receiptDate,'received_at'=>now(),'receiver_user_id'=>$userId,
                'receiver_name_snapshot'=>$receiver?->name ?: 'Receiver','receiver_signed_at'=>now(),'notes'=>$payload['notes']??null,'updated_at'=>now(),
            ]);
            DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->update(['status'=>'receiving','updated_at'=>now()]);
            $this->event('goods_receipt',(string)$g->id,'outlet_received','draft','submitted','Outlet menginput actual received/not received tanpa scan barcode.',$userId,['delivery_order_id'=>$deliveryId]);
            if($d->source_type==='stock_request')$this->updateStockRequestStatus((string)$d->source_id,'goods-receipt','warehouse_v3_outlet_received','Outlet sudah menerima Delivery Order. Goods Receipt menunggu Complete Warehouse.',$userId,['goods_receipt_id'=>(string)$g->id]);
        },5);
        return $this->outletDeliveryDetail($outletId,$deliveryId);
    }

    public function completeGoodsReceipt(string $warehouseId,string $id,string $userId,?string $notes=null):array
    {
        DB::transaction(function()use($warehouseId,$id,$userId,$notes):void{
            $g=DB::table('wh_v3_goods_receipts')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first();
            if(!$g)abort(404);
            if($g->status==='completed' && $g->ledger_posting_id && $g->outgoing_invoice_id){
                $completedDelivery = DB::table('wh_v3_delivery_orders')->where('id',$g->delivery_order_id)->first(['source_type','destination_type']);
                if ($completedDelivery && ((string)$completedDelivery->source_type === 'stock_request' || (string)$completedDelivery->destination_type === 'outlet')) {
                    $this->purchasingInvoiceBridge->syncFromWarehouseOutgoingInvoice((string)$g->outgoing_invoice_id,$userId);
                }
                return;
            }
            if($g->status!=='submitted')throw ValidationException::withMessages(['status'=>['Goods Receipt harus sudah diterima oleh tujuan sebelum Complete.']]);
            if(!$g->receiver_user_id||!$g->receiver_signed_at)throw ValidationException::withMessages(['receiver'=>['Tanda tangan Receiver belum tersedia.']]);
            $d=DB::table('wh_v3_delivery_orders')->where('id',$g->delivery_order_id)->lockForUpdate()->first();
            if(!$d)throw ValidationException::withMessages(['delivery_order'=>['Delivery Order tidak ditemukan.']]);
            $items=DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$id)->lockForUpdate()->get();
            foreach($items as $item){
                $sent=round((float)$item->sent_qty_base,4); $received=round((float)$item->received_qty_base,4); $notReceived=round((float)$item->not_received_qty_base,4);
                if(abs(($received+$notReceived)-$sent)>0.0001)throw ValidationException::withMessages(['items'=>["Qty received + not received tidak balance dengan Qty dikirim pada item {$item->id}."]]);
            }
            // New DOs were already posted OUT at Dispatch. For pre-I07 in-flight non-transfer DOs, this safely backfills one time.
            $posting = (string)$d->source_type === 'transfer_stock'
                ? DB::table('wh_ledger_postings')->where('id',$g->ledger_posting_id)->first()
                : $this->dispatchStock->ensurePosted($warehouseId,(string)$d->id,$userId);
            if(!$posting) throw ValidationException::withMessages(['stock_ledger'=>['Posting stock OUT Delivery Order belum tersedia.']]);
            DB::table('wh_v3_goods_receipts')->where('id',$id)->update(['ledger_posting_id'=>$posting->id,'updated_at'=>now()]);
            $this->postDestinationInventory($g,$items,(string)$posting->id,$userId);
            $invoice=$this->ensureDraftOutgoingInvoice($g,$d,$items,(string)$posting->id,$userId);
            // Hanya Stock Request/outlet yang menjadi Incoming Invoice di Purchasing outlet.
            // Sales Customer eksternal tetap murni Outgoing Invoice Warehouse.
            if ((string)$d->source_type === 'stock_request' || (string)$d->destination_type === 'outlet') {
                $this->purchasingInvoiceBridge->syncFromWarehouseOutgoingInvoice((string)$invoice->id,$userId);
            }
            DB::table('wh_v3_goods_receipts')->where('id',$id)->update([
                'status'=>'completed','outgoing_invoice_id'=>$invoice->id,'completed_by_user_id'=>$userId,'completed_at'=>now(),'notes'=>$notes??$g->notes,'updated_at'=>now(),
            ]);
            // ERP V5 Iteration 15: capture Warehouse V3 inventory valuation into
            // canonical COGS snapshots after destination movement + invoice exist.
            // This bridge is valuation-only and never mutates Actual Stock quantity.
            $this->cogsValuationBridge->captureCompletedReceipt($id,$userId);
            DB::table('wh_v3_delivery_orders')->where('id',$d->id)->update(['status'=>'completed','updated_at'=>now()]);
            DB::table('wh_v3_logistics_prepare_requests')->where('id',$d->prepare_request_id)->update(['status'=>'completed','updated_at'=>now()]);
            DB::table('wh_v3_logistics_prepare_items')->where('prepare_request_id',$d->prepare_request_id)->update(['status'=>'completed','updated_at'=>now()]);
            $this->event('goods_receipt',$id,'completed','submitted','completed','Goods Receipt Complete. Stock origin diposting OUT dan draft Outgoing Invoice dibuat.',$userId,['ledger_posting_id'=>$posting->id,'outgoing_invoice_id'=>$invoice->id]);
            if($d->source_type==='stock_request')$this->updateStockRequestStatus((string)$d->source_id,'completed','warehouse_v3_goods_receipt_completed','Goods Receipt Complete. Stock Request selesai.',$userId,['ledger_posting_id'=>$posting->id,'outgoing_invoice_id'=>$invoice->id]);
        },5);
        return $this->goodsReceiptDetail($warehouseId,$id);
    }

    private function bootstrapGoodsReceipt(string $deliveryId,string $userId):void
    {
        if(DB::table('wh_v3_goods_receipts')->where('delivery_order_id',$deliveryId)->exists())return;
        $d=DB::table('wh_v3_delivery_orders')->where('id',$deliveryId)->first();if(!$d)return;
        $sender=DB::table('users')->where('id',$d->sender_user_id)->first(['name']);
        $grId=(string)Str::ulid();
        DB::table('wh_v3_goods_receipts')->insert([
            'id'=>$grId,'goods_receipt_number'=>$this->number('GR'),'delivery_order_id'=>$deliveryId,'warehouse_id'=>$d->warehouse_id,
            'destination_type'=>$d->destination_type,'destination_id'=>$d->destination_id,'status'=>'draft','sender_user_id'=>$d->sender_user_id,
            'sender_name_snapshot'=>$sender?->name ?: 'Sender','sender_signed_at'=>$d->dispatched_at ?: now(),'idempotency_key'=>'WAREHOUSE-V3-GR:'.$deliveryId,
            'metadata'=>json_encode(['flow_version'=>3,'barcode_required'=>false,'price_visible'=>false]),'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach(DB::table('wh_v3_delivery_order_items')->where('delivery_order_id',$deliveryId)->get() as $item){
            DB::table('wh_v3_goods_receipt_items')->insert([
                'id'=>(string)Str::ulid(),'goods_receipt_id'=>$grId,'delivery_order_item_id'=>$item->id,'sku_id'=>$item->sku_id,'uom_id'=>$item->uom_id,
                'requested_qty_uom'=>$item->requested_qty_uom,'requested_qty_base'=>$item->requested_qty_base,'sent_qty_uom'=>$item->sent_qty_uom,'sent_qty_base'=>$item->sent_qty_base,
                'received_qty_uom'=>0,'received_qty_base'=>0,'not_received_qty_uom'=>0,'not_received_qty_base'=>0,
                'metadata'=>json_encode(['flow_version'=>3]),'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $this->event('goods_receipt',$grId,'draft_created',null,'draft','Draft Goods Receipt otomatis dibuat dari Delivery Order.',$userId,['delivery_order_id'=>$deliveryId]);
    }

    private function postDestinationInventory(object $g,$items,string $postingId,string $userId):void
    {
        if($g->destination_type!=='outlet')return;
        foreach($items as $item){
            $qty=round((float)$item->received_qty_base,4);if($qty<=0)continue;
            if(InventoryMovement::query()->where('movement_type','goods_receipt')->where('reference_type','wh_v3_goods_receipt')->where('reference_line_id',$item->id)->exists())continue;
            $costRow=DB::table('wh_ledger_entries')->where('posting_id',$postingId)->where('line_key','like','DOITEM-'.$item->delivery_order_item_id.'-%')->selectRaw('COALESCE(SUM(total_cost),0) total_cost,COALESCE(SUM(quantity_base),0) qty')->first();
            $unitCost=(float)($costRow?->qty??0)>0?round((float)$costRow->total_cost/(float)$costRow->qty,4):0.0;
            $balance=InventoryBalance::query()->where('outlet_id',$g->destination_id)->where('sku_id',$item->sku_id)->lockForUpdate()->first();
            if(!$balance){DB::table('stk_inventory_balances')->insertOrIgnore(['id'=>(string)Str::ulid(),'outlet_id'=>$g->destination_id,'sku_id'=>$item->sku_id,'on_hand_qty'=>0,'average_unit_cost'=>0,'inventory_value'=>0,'last_movement_at'=>null,'lock_version'=>1,'created_at'=>now(),'updated_at'=>now()]);$balance=InventoryBalance::query()->where('outlet_id',$g->destination_id)->where('sku_id',$item->sku_id)->lockForUpdate()->firstOrFail();}
            $oldQty=round((float)$balance->on_hand_qty,4);$oldValue=round((float)$balance->inventory_value,2);$value=round($qty*$unitCost,2);$newQty=round($oldQty+$qty,4);$newValue=round($oldValue+$value,2);$avg=$newQty>0?round($newValue/$newQty,4):0;
            $balance->forceFill(['on_hand_qty'=>$newQty,'average_unit_cost'=>$avg,'inventory_value'=>$newValue,'last_movement_at'=>now(),'lock_version'=>((int)$balance->lock_version)+1])->save();
            InventoryMovement::query()->create(['outlet_id'=>$g->destination_id,'sku_id'=>$item->sku_id,'movement_type'=>'goods_receipt','reference_type'=>'wh_v3_goods_receipt','reference_id'=>$g->id,'reference_line_id'=>$item->id,'business_date'=>now('Asia/Jakarta')->toDateString(),'quantity'=>$qty,'unit_cost'=>$unitCost,'total_cost'=>$value,'balance_qty_after'=>$newQty,'average_cost_after'=>$avg,'inventory_value_after'=>$newValue,'metadata'=>['warehouse_logistics_v3'=>true,'warehouse_ledger_posting_id'=>$postingId,'not_received_qty_base'=>(float)$item->not_received_qty_base],'created_by_user_id'=>$userId]);
        }
        DB::table('wh_v3_goods_receipts')->where('id',$g->id)->update(['outlet_inventory_posted_at'=>now(),'updated_at'=>now()]);
    }

    private function ensureDraftOutgoingInvoice(object $g,object $d,$items,string $postingId,string $userId):object
    {
        $existing=DB::table('wh_v3_outgoing_invoices')->where('goods_receipt_id',$g->id)->lockForUpdate()->first();if($existing)return $existing;
        $id=(string)Str::ulid();$subtotal=0.0;$stockQty=0.0;$stockValue=0.0;
        $invoiceDate = now('Asia/Jakarta')->toDateString();
        $dueDate = null;
        $invoiceCurrency = 'IDR';
        $salesOrder = null;
        if ((string)$d->source_type === 'sales_order' && Schema::hasTable('wh_v3_sales_orders')) {
            $salesOrder = DB::table('wh_v3_sales_orders')->where('id',$d->source_id)->first(['sales_channel','currency_code']);
            if ($salesOrder?->currency_code) $invoiceCurrency = strtoupper((string)$salesOrder->currency_code);
        }
        if ((string)$d->destination_type === 'customer' && Schema::hasTable('wh_customers')) {
            $customer = DB::table('wh_customers')->where('id',$d->destination_id)->first(['credit_term_days','currency_code']);
            $creditDays = (int) ($customer?->credit_term_days ?? 0);
            $dueDate = now('Asia/Jakarta')->addDays(max(0,$creditDays))->toDateString();
            if (! $salesOrder && $customer?->currency_code) $invoiceCurrency = strtoupper((string)$customer->currency_code);
        }
        DB::table('wh_v3_outgoing_invoices')->insert([
            'id'=>$id,'invoice_number'=>$this->number('OUT-INV'),'warehouse_id'=>$g->warehouse_id,'goods_receipt_id'=>$g->id,'source_type'=>$d->source_type,'source_id'=>$d->source_id,'source_number'=>$d->source_number,
            'destination_type'=>$d->destination_type,'destination_id'=>$d->destination_id,'destination_code_snapshot'=>$d->destination_code_snapshot,'destination_name_snapshot'=>$d->destination_name_snapshot,
            'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,'currency_code'=>$invoiceCurrency,'status'=>'draft','idempotency_key'=>'WAREHOUSE-V3-OUTGOING-INVOICE:'.$g->id,
            'metadata'=>json_encode(['flow_version'=>3,'auto_generated'=>true,'goods_receipt_completed'=>true,'billing_integrity_version'=>3,'pricing_contract'=>'SOURCE_DOCUMENT_SNAPSHOT_I06']),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $deliveryItemSource = DB::table('wh_v3_delivery_order_items')->where('delivery_order_id',$d->id)->get(['id','source_item_id'])->keyBy(fn($row)=>(string)$row->id);

        foreach($items as $item){
            $received=round((float)$item->received_qty_base,4);$sent=round((float)$item->sent_qty_base,4);
            $deliverySource=$deliveryItemSource->get((string)$item->delivery_order_item_id);
            $sourceItemId=trim((string)($deliverySource?->source_item_id??''));
            if($sourceItemId==='')throw ValidationException::withMessages(['warehouse_price'=>['Delivery Order item tidak memiliki source item untuk mewarisi snapshot harga.']]);

            if(in_array((string)$d->source_type,['sales_order','stock_request'],true)){
                $sourcePrice=$this->sourcePriceSnapshots->resolve((string)$d->source_type,$sourceItemId);
                $pricing=$sourcePrice['pricing'];
                $rawBilling=$this->billingUom->billBaseQuantity($received,$pricing);
                $discountPercent=round((float)$sourcePrice['discount_percent'],4);
                $gross=round((float)$rawBilling['line_total'],2);
                $discountAmount=round($gross*($discountPercent/100),2);
                $billing=['billing_qty'=>$rawBilling['billing_qty'],'unit_price'=>$rawBilling['unit_price'],'line_total'=>round($gross-$discountAmount,2)];
                $snapshotOrigin=(string)$sourcePrice['origin'];
                $priceBusinessDate=$sourcePrice['business_date'];
            }else{
                // Non-sales source types retain strict current-date resolution. Sales Order and
                // Stock Request are explicitly forbidden from this path to preserve audit snapshots.
                $pricing=$this->strictSalesPricing->resolve((string)$g->warehouse_id,(string)$d->destination_type,(string)$d->destination_id,(string)$item->sku_id,$invoiceDate,true);
                $billing=$this->billingUom->billBaseQuantity($received,$pricing);
                $discountPercent=0.0;$discountAmount=0.0;$snapshotOrigin='strict_master_non_sales_source';$priceBusinessDate=$invoiceDate;
            }

            $costRow=DB::table('wh_ledger_entries')->where('posting_id',$postingId)->where('line_key','like','DOITEM-'.$item->delivery_order_item_id.'-%')->selectRaw('COALESCE(SUM(total_cost),0) total_cost,COALESCE(SUM(quantity_base),0) qty')->first();
            $costTotal=round((float)($costRow?->total_cost??0),2);$unitCost=(float)($costRow?->qty??0)>0?round($costTotal/(float)$costRow->qty,6):0.0;
            DB::table('wh_v3_outgoing_invoice_items')->insert([
                'id'=>(string)Str::ulid(),'outgoing_invoice_id'=>$id,'goods_receipt_item_id'=>$item->id,'sku_id'=>$item->sku_id,
                'billing_uom_id'=>$pricing['price_uom_id'],'billing_uom_code_snapshot'=>$pricing['price_uom_code'],'billing_conversion_factor_snapshot'=>$pricing['conversion_factor'],'billing_qty'=>$billing['billing_qty'],
                'billed_qty_base'=>$received,'stock_movement_qty_base'=>$sent,'unit_price'=>$billing['unit_price'],'unit_price_basis'=>$pricing['unit_price_basis'],'line_total'=>$billing['line_total'],'inventory_unit_cost'=>$unitCost,'inventory_cost_total'=>$costTotal,
                'source_snapshot'=>json_encode([
                    'delivery_order_item_id'=>$item->delivery_order_item_id,'source_item_id'=>$sourceItemId,'source_type'=>(string)$d->source_type,'received_qty_base'=>$received,'not_received_qty_base'=>(float)$item->not_received_qty_base,
                    'billing'=>['version'=>5,'pricing_source'=>$pricing['source'],'snapshot_origin'=>$snapshotOrigin,'price_business_date'=>$priceBusinessDate,'price_policy_id'=>$pricing['policy_id'],'price_uom_id'=>$pricing['price_uom_id'],'price_uom_code'=>$pricing['price_uom_code'],'conversion_factor_to_base'=>$pricing['conversion_factor'],'billing_qty'=>$billing['billing_qty'],'unit_price'=>$billing['unit_price'],'unit_price_basis'=>$pricing['unit_price_basis'],'discount_percent'=>$discountPercent,'discount_amount'=>$discountAmount,'line_total'=>$billing['line_total'],'price_uom_review_required'=>$pricing['price_uom_review_required']],
                ]),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $subtotal+=$billing['line_total'];$stockQty+=$sent;$stockValue+=$costTotal;
        }
        DB::table('wh_v3_outgoing_invoices')->where('id',$id)->update(['total_stock_movement_qty'=>round($stockQty,4),'stock_valuation_total'=>round($stockValue,2),'subtotal'=>round($subtotal,2),'grand_total'=>round($subtotal,2),'updated_at'=>now()]);
        return DB::table('wh_v3_outgoing_invoices')->where('id',$id)->first();
    }


    private function minimumReceiptDate(object $delivery): string
    {
        if ((string) ($delivery->source_type ?? '') === 'stock_request' && ! empty($delivery->source_id) && Schema::hasTable('stk_requests')) {
            $requestDate = DB::table('stk_requests')->where('id', (string) $delivery->source_id)->value('request_date');
            if ($requestDate) return (string) $requestDate;
        }

        if (! empty($delivery->dispatched_at)) return substr((string) $delivery->dispatched_at, 0, 10);
        if (! empty($delivery->created_at)) return substr((string) $delivery->created_at, 0, 10);
        return now('Asia/Jakarta')->toDateString();
    }

    private function assertAvailableForDispatch(string $warehouseId,string $skuId,float $quantity):void
    {
        $row=DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->where('sku_id',$skuId)->selectRaw('COALESCE(SUM(on_hand_qty - reserved_qty - quarantine_qty),0) qty')->first();
        $available=(float)($row?->qty??0);
        if(round($available,4)+0.0001<round($quantity,4))throw ValidationException::withMessages(['stock'=>[sprintf('Stock tersedia SKU %s hanya %.4f base unit, sedangkan Qty Dikirim membutuhkan %.4f.',$skuId,$available,$quantity)]]);
    }

    private function allocateBatchLines(string $warehouseId,string $skuId,float $quantity,string $prefix):array
    {
        $remaining=round($quantity,4);$lines=[];
        $balances=DB::table('wh_batch_balances as b')->join('wh_batches as x','x.id','=','b.batch_id')->where('b.warehouse_id',$warehouseId)->where('b.sku_id',$skuId)->whereNull('x.deleted_at')
            ->whereRaw('(b.on_hand_qty - b.reserved_qty - b.quarantine_qty) > 0')->orderByRaw('CASE WHEN x.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('x.expiry_date')->orderBy('x.received_at')->orderBy('x.created_at')
            ->lockForUpdate()->get(['b.batch_id','b.storage_id','b.on_hand_qty','b.reserved_qty','b.quarantine_qty','b.average_unit_cost']);
        foreach($balances as $b){if($remaining<=0.0001)break;$available=round((float)$b->on_hand_qty-(float)$b->reserved_qty-(float)$b->quarantine_qty,4);if($available<=0)continue;$take=min($remaining,$available);$lines[]=['line_key'=>$prefix.'-'.$b->batch_id.'-'.$b->storage_id,'sku_id'=>$skuId,'batch_id'=>(string)$b->batch_id,'storage_id'=>(string)$b->storage_id,'quantity_base'=>round($take,4),'unit_cost'=>(float)$b->average_unit_cost,'metadata'=>['allocation'=>'FEFO/FIFO','barcode_used'=>false]];$remaining=round($remaining-$take,4);}
        if($remaining>0.0001)throw ValidationException::withMessages(['stock'=>[sprintf('Stock Warehouse tidak mencukupi untuk SKU %s. Kekurangan %.4f base unit.',$skuId,$remaining)]]);
        return $lines;
    }

    private function deliveryItems(string $deliveryId):array
    {
        return DB::table('wh_v3_delivery_order_items as i')->join('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.uom_id')->where('i.delivery_order_id',$deliveryId)->orderBy('s.name')
            ->get(['i.*','s.sku_code','s.name as item_name','u.code as uom_code'])->map(fn($i)=>[
                'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,'uom_code'=>(string)($i->uom_code?:'UNIT'),
                'requested_qty_uom'=>round((float)$i->requested_qty_uom,4),'approved_qty_uom'=>round((float)$i->approved_qty_uom,4),'sent_qty_uom'=>round((float)$i->sent_qty_uom,4),
                'requested_qty_base'=>round((float)$i->requested_qty_base,4),'approved_qty_base'=>round((float)$i->approved_qty_base,4),'sent_qty_base'=>round((float)$i->sent_qty_base,4),
            ])->values()->all();
    }

    private function goodsReceiptItems(string $grId):array
    {
        return DB::table('wh_v3_goods_receipt_items as i')->join('stk_skus as s','s.id','=','i.sku_id')->leftJoin('stk_uoms as u','u.id','=','i.uom_id')->where('i.goods_receipt_id',$grId)->orderBy('s.name')
            ->get(['i.*','s.sku_code','s.name as item_name','u.code as uom_code'])->map(fn($i)=>[
                'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,'uom_code'=>(string)($i->uom_code?:'UNIT'),
                'requested_qty_uom'=>round((float)$i->requested_qty_uom,4),'sent_qty_uom'=>round((float)$i->sent_qty_uom,4),'received_qty_uom'=>round((float)$i->received_qty_uom,4),'not_received_qty_uom'=>round((float)$i->not_received_qty_uom,4),
                'requested_qty_base'=>round((float)$i->requested_qty_base,4),'sent_qty_base'=>round((float)$i->sent_qty_base,4),'received_qty_base'=>round((float)$i->received_qty_base,4),'not_received_qty_base'=>round((float)$i->not_received_qty_base,4),'notes'=>$i->notes,
            ])->values()->all();
    }

    private function destination(?string $type,?string $id):array
    {
        if(!$id)return ['id'=>null,'code'=>null,'name'=>'-','address'=>null];
        if($type==='customer' && Schema::hasTable('wh_customers')){$r=DB::table('wh_customers')->where('id',$id)->first(['id','code','name']);return $r?['id'=>(string)$r->id,'code'=>$r->code,'name'=>$r->name,'address'=>null]:['id'=>$id,'code'=>null,'name'=>'Customer','address'=>null];}
        $r=DB::table('outlets')->where('id',$id)->first();return $r?['id'=>(string)$r->id,'code'=>$r->code??null,'name'=>$r->name??'Outlet','address'=>$r->address??null]:['id'=>$id,'code'=>null,'name'=>ucfirst((string)$type),'address'=>null];
    }

    private function outlet(string $id):array
    {
        $r=DB::table('outlets')->where('id',$id)->first();return ['id'=>$id,'code'=>$r->code??null,'name'=>$r->name??'Warehouse','address'=>$r->address??null];
    }

    private function assertWarehouseUser(string $userId,string $warehouseId):void
    {
        $valid=User::query()->whereKey($userId)->where('is_active',true)->whereHas('employee.assignment',fn(Builder $q)=>$q->where('outlet_id',$warehouseId)->where(fn(Builder $s)=>$s->whereNull('status')->orWhereIn('status',['active','ACTIVE'])))->exists();
        if(!$valid)throw ValidationException::withMessages(['sender_user_id'=>['Sender harus merupakan user aktif pada Warehouse terpilih.']]);
    }

    private function updateStockRequestStatus(string $id,string $status,string $eventCode,string $message,string $userId,array $metadata=[]):void
    {
        if(!Schema::hasTable('stk_requests'))return;DB::table('stk_requests')->where('id',$id)->update(['status'=>$status,'updated_by_user_id'=>$userId,'lock_version'=>DB::raw('lock_version + 1'),'updated_at'=>now()]);
        if(Schema::hasTable('stk_request_items'))DB::table('stk_request_items')->where('stock_request_id',$id)->update(['status'=>$status,'fulfillment_status'=>'logistics_v3_'.$status,'updated_at'=>now()]);
        if(Schema::hasTable('stk_request_timelines'))DB::table('stk_request_timelines')->insert(['id'=>(string)Str::ulid(),'stock_request_id'=>$id,'event_code'=>$eventCode,'status'=>$status,'message'=>$message,'metadata'=>$metadata?json_encode($metadata):null,'actor_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function event(string $type,string $id,string $event,?string $from,?string $to,string $message,?string $actor,array $metadata=[]):void
    {
        DB::table('wh_v3_logistics_events')->insert(['id'=>(string)Str::ulid(),'document_type'=>$type,'document_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>$metadata?json_encode($metadata):null,'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function events(string $type,string $id):array
    {
        return DB::table('wh_v3_logistics_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where('e.document_type',$type)->where('e.document_id',$id)->orderBy('e.occurred_at')
            ->get(['e.*','u.name as actor_name','u.nisj as actor_nisj'])->map(fn($r)=>['id'=>(string)$r->id,'event_type'=>(string)$r->event_type,'from_status'=>$r->from_status,'to_status'=>$r->to_status,'message'=>$r->message,'metadata'=>$this->json($r->metadata),'actor'=>$r->actor_name?['name'=>$r->actor_name,'nisj'=>$r->actor_nisj]:null,'created_at'=>$r->occurred_at])->values()->all();
    }

    private function baseToUom(float $base,float $approvedBase,float $approvedUom):float{return $approvedBase>0?$base*($approvedUom/$approvedBase):0.0;}
    private function number(string $prefix):string{return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));}
    private function json(mixed $v):mixed{if(is_array($v)||$v===null)return $v;$x=json_decode((string)$v,true);return json_last_error()===JSON_ERROR_NONE?$x:$v;}
    private function paginated(LengthAwarePaginator $p,callable $map):array{return ['items'=>collect($p->items())->map($map)->values()->all(),'pagination'=>['current_page'=>$p->currentPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'last_page'=>$p->lastPage()]];}
}
