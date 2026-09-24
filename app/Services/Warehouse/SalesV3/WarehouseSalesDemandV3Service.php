<?php

namespace App\Services\Warehouse\SalesV3;

use App\Services\Warehouse\Iteration07\WarehouseProductionMaterialOpnameV7Service;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseSalesDemandV3Service
{
    public function __construct(
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseProductionMaterialOpnameV7Service $productionOpname,
    ) {
    }

    public function stockRequestSummary(string $warehouseId): array
    {
        $base = $this->stockRequestBase($warehouseId);

        return [
            'pending' => (clone $base)->where(fn ($q) => $q->whereNull('review.status')->orWhere('review.status', 'pending'))->count(),
            'approved' => (clone $base)->where('review.status', 'approved')->count(),
            'rejected' => (clone $base)->where('review.status', 'rejected')->count(),
            'logistics_queued' => DB::table('wh_v3_logistics_prepare_requests')
                ->where('warehouse_id', $warehouseId)
                ->where('source_type', 'stock_request')
                ->whereIn('status', ['queued','preparing'])
                ->count(),
        ];
    }

    public function listStockRequests(string $warehouseId, array $filters): array
    {
        $query = $this->stockRequestBase($warehouseId)
            ->leftJoin('outlets as origin', 'origin.id', '=', 'request.outlet_id')
            ->leftJoin('outlets as destination', 'destination.id', '=', 'request.destination_warehouse_id')
            ->select([
                'request.id','request.request_number','request.request_date','request.needed_date','request.status as source_status',
                'request.request_approval_status','request.submitted_at','request.updated_at','origin.id as outlet_id','origin.code as outlet_code','origin.name as outlet_name',
                'review.id as review_id','review.status as review_status','review.approved_at','review.rejected_at','review.rejection_reason','review.logistics_prepare_request_id',
            ])
            ->selectSub(function ($sub): void {
                $sub->from('stk_request_items as item')->whereColumn('item.stock_request_id', 'request.id')->selectRaw('COUNT(*)');
            }, 'line_count')
            ->selectSub(function ($sub): void {
                $sub->from('stk_request_items as item')->whereColumn('item.stock_request_id', 'request.id')
                    ->selectRaw('COALESCE(SUM(COALESCE(item.requested_qty_base,item.requested_qty,0)),0)');
            }, 'requested_qty_base')
            ->selectSub(function ($sub): void {
                $sub->from('wh_v3_stock_request_review_items as ri')
                    ->join('wh_v3_stock_request_reviews as r', 'r.id', '=', 'ri.review_id')
                    ->whereColumn('r.stock_request_id', 'request.id')
                    ->selectRaw('COALESCE(SUM(ri.approved_qty_base),0)');
            }, 'approved_qty_base');

        if (($filters['q'] ?? '') !== '') {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('request.request_number', 'like', $term)
                    ->orWhere('origin.name', 'like', $term)
                    ->orWhere('origin.code', 'like', $term);
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $status = (string) $filters['status'];
            if ($status === 'pending') $query->where(fn ($q) => $q->whereNull('review.status')->orWhere('review.status', 'pending'));
            else $query->where('review.status', $status);
        }
        if (($filters['from'] ?? '') !== '') $query->where('request.request_date', '>=', $filters['from']);
        if (($filters['to'] ?? '') !== '') $query->where('request.request_date', '<=', $filters['to']);

        $useDefaultView = empty($filters['q']) && empty($filters['status']) && empty($filters['from']) && empty($filters['to']);
        if ($useDefaultView) {
            $today = now('Asia/Jakarta')->startOfDay();
            $approvedFrom = $today->copy()->utc();
            $approvedUntil = $today->copy()->addDay()->utc();
            $query->where(function ($q) use ($approvedFrom, $approvedUntil): void {
                $q->where(function ($pending): void {
                        $pending->whereNull('review.status')->orWhere('review.status', 'pending');
                    })
                    ->orWhere(function ($approved) use ($approvedFrom, $approvedUntil): void {
                        $approved->where('review.status', 'approved')
                            ->where('review.approved_at', '>=', $approvedFrom)
                            ->where('review.approved_at', '<', $approvedUntil);
                    });
            });
        }

        $paginator = $query
            ->orderByRaw("CASE WHEN review.status IS NULL OR review.status='pending' THEN 0 WHEN review.status='approved' THEN 1 WHEN review.status='rejected' THEN 2 ELSE 3 END")
            ->orderBy('request.request_date')
            ->orderBy('request.created_at')
            ->paginate((int) ($filters['per_page'] ?? 50));
        return $this->paginated($paginator, fn ($row) => $this->stockRequestSummaryRow($row));
    }

    public function stockRequestDetail(string $warehouseId, string $requestId): array
    {
        $request = $this->stockRequestBase($warehouseId)
            ->leftJoin('outlets as origin', 'origin.id', '=', 'request.outlet_id')
            ->leftJoin('outlets as destination', 'destination.id', '=', 'request.destination_warehouse_id')
            ->leftJoin('users as submitted_user', 'submitted_user.id', '=', 'request.submitted_by_user_id')
            ->leftJoin('users as approved_user', 'approved_user.id', '=', 'review.approved_by_user_id')
            ->leftJoin('users as rejected_user', 'rejected_user.id', '=', 'review.rejected_by_user_id')
            ->where('request.id', $requestId)
            ->select([
                'request.*','origin.code as outlet_code','origin.name as outlet_name','destination.code as warehouse_code','destination.name as warehouse_name',
                'review.id as review_id','review.status as review_status','review.approval_notes','review.approved_at','review.rejection_reason','review.rejected_at','review.logistics_prepare_request_id',
                'submitted_user.name as submitted_by_name','submitted_user.nisj as submitted_by_nisj',
                'approved_user.name as approved_by_name','approved_user.nisj as approved_by_nisj',
                'rejected_user.name as rejected_by_name','rejected_user.nisj as rejected_by_nisj',
            ])->first();
        if (! $request) abort(404);

        $items = DB::table('stk_request_items as item')
            ->join('stk_skus as sku', 'sku.id', '=', 'item.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'item.request_uom_id')
            ->leftJoin('stk_uoms as base_uom', 'base_uom.id', '=', 'item.base_uom_id_snapshot')
            ->leftJoin('wh_v3_stock_request_review_items as ri', 'ri.stock_request_item_id', '=', 'item.id')
            ->where('item.stock_request_id', $requestId)
            ->orderBy('sku.name')
            ->get([
                'item.id','item.sku_id','sku.sku_code','sku.name as item_name','item.request_uom_id',
                'uom.code as uom_code','uom.name as uom_name','item.request_uom_code_snapshot','item.request_uom_name_snapshot',
                'item.requested_qty_uom','item.requested_qty_base','item.requested_qty','item.conversion_factor_snapshot',
                'item.base_uom_id_snapshot','item.base_uom_code_snapshot','base_uom.name as base_uom_name',
                'ri.approved_qty_uom','ri.approved_qty_base','ri.notes as approval_item_notes',
            ])->map(function ($row) use ($warehouseId): array {
                $requestedUom = (float) ($row->requested_qty_uom ?: $row->requested_qty ?: 0);
                $factor = (float) ($row->conversion_factor_snapshot ?: 1);
                $requestedBase = (float) ($row->requested_qty_base ?: ((float) $row->requested_qty ?: $requestedUom * $factor));
                return [
                    'id' => (string) $row->id,
                    'sku_id' => (string) $row->sku_id,
                    'sku_code' => (string) $row->sku_code,
                    'item_name' => (string) $row->item_name,
                    'uom_id' => $row->request_uom_id ? (string) $row->request_uom_id : null,
                    'uom_code' => (string) ($row->request_uom_code_snapshot ?: $row->uom_code ?: 'UNIT'),
                    'uom_name' => (string) ($row->request_uom_name_snapshot ?: $row->uom_name ?: 'Unit'),
                    'conversion_factor' => round($factor, 8),
                    'base_uom_id' => $row->base_uom_id_snapshot ? (string) $row->base_uom_id_snapshot : null,
                    'base_uom_code' => (string) ($row->base_uom_code_snapshot ?: 'UNIT'),
                    'base_uom_name' => (string) ($row->base_uom_name ?: 'Unit'),
                    'requested_qty_uom' => round($requestedUom, 4),
                    'requested_qty_base' => round($requestedBase, 4),
                    'approved_qty_uom' => $row->approved_qty_uom === null ? round($requestedUom, 4) : round((float) $row->approved_qty_uom, 4),
                    'approved_qty_base' => $row->approved_qty_base === null ? round($requestedBase, 4) : round((float) $row->approved_qty_base, 4),
                    'warehouse_on_hand_qty' => round((float) (DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->where('sku_id', $row->sku_id)->value('on_hand_qty') ?? 0), 4),
                    'notes' => $row->approval_item_notes,
                ];
            })->values()->all();

        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'request_date' => $request->request_date,
            'needed_date' => $request->needed_date,
            'source_status' => (string) $request->status,
            'approval_status' => (string) ($request->review_status ?: 'pending'),
            'outlet' => ['id'=>(string)$request->outlet_id,'code'=>(string)$request->outlet_code,'name'=>(string)$request->outlet_name],
            'warehouse' => ['id'=>(string)$request->destination_warehouse_id,'code'=>(string)$request->warehouse_code,'name'=>(string)$request->warehouse_name],
            'notes' => $request->notes,
            'submitted_at' => $request->submitted_at,
            'submitted_by' => $request->submitted_by_name ? ['name'=>$request->submitted_by_name,'nisj'=>$request->submitted_by_nisj] : null,
            'approved_at' => $request->approved_at,
            'approved_by' => $request->approved_by_name ? ['name'=>$request->approved_by_name,'nisj'=>$request->approved_by_nisj] : null,
            'approval_notes' => $request->approval_notes,
            'rejection_reason' => $request->rejection_reason,
            'rejected_at' => $request->rejected_at,
            'rejected_by' => $request->rejected_by_name ? ['name'=>$request->rejected_by_name,'nisj'=>$request->rejected_by_nisj] : null,
            'logistics_prepare_request_id' => $request->logistics_prepare_request_id,
            'items' => $items,
            'timeline' => $this->stockRequestTimeline($requestId),
        ];
    }

    public function approveStockRequest(string $warehouseId, string $requestId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($warehouseId, $requestId, $payload, $userId): void {
            $request = DB::table('stk_requests')->where('id', $requestId)->where('destination_warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $request) abort(404);
            if (! in_array((string) $request->request_approval_status, ['approved1','approved'], true)
                && ! in_array((string) $request->status, ['requested','review','prepare','ready'], true)) {
                throw ValidationException::withMessages(['status' => ['Stock Request belum melewati approval outlet dan belum dapat diproses Warehouse.']]);
            }

            $review = DB::table('wh_v3_stock_request_reviews')->where('stock_request_id', $requestId)->lockForUpdate()->first();
            if ($review && $review->status === 'approved') return;
            $reviewId = $review?->id ?: (string) Str::ulid();
            if (! $review) {
                DB::table('wh_v3_stock_request_reviews')->insert([
                    'id'=>$reviewId,'stock_request_id'=>$requestId,'warehouse_id'=>$warehouseId,'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $inputs = collect($payload['items'] ?? [])->keyBy(fn ($row) => (string) ($row['item_id'] ?? ''));
            $requestItems = DB::table('stk_request_items')->where('stock_request_id', $requestId)->lockForUpdate()->get();
            if ($requestItems->isEmpty()) throw ValidationException::withMessages(['items' => ['Stock Request tidak memiliki item.']]);
            $approvedAny = false;

            foreach ($requestItems as $item) {
                $input = $inputs->get((string) $item->id);
                if (! $input) throw ValidationException::withMessages(['items' => ['Seluruh item wajib memiliki Approved Qty.']]);
                $requestedUom = round((float) ($item->requested_qty_uom ?: $item->requested_qty ?: 0), 4);
                $factor = round((float) ($item->conversion_factor_snapshot ?: 1), 8);
                $requestedBase = round((float) ($item->requested_qty_base ?: ((float) $item->requested_qty ?: $requestedUom * $factor)), 4);
                $approvedUom = round((float) ($input['approved_qty_uom'] ?? 0), 4);
                if ($approvedUom < 0 || $approvedUom > $requestedUom + 0.0001) {
                    throw ValidationException::withMessages(['items' => ["Approved Qty untuk item {$item->id} harus antara 0 dan Requested Qty."]]);
                }
                $approvedBase = round($approvedUom * $factor, 4);
                if ($approvedBase > 0) $approvedAny = true;

                $existing = DB::table('wh_v3_stock_request_review_items')->where('stock_request_item_id', $item->id)->first();
                DB::table('wh_v3_stock_request_review_items')->updateOrInsert(
                    ['stock_request_item_id'=>$item->id],
                    [
                        'id'=>$existing?->id ?: (string)Str::ulid(),'review_id'=>$reviewId,
                        'requested_qty_uom'=>$requestedUom,'requested_qty_base'=>$requestedBase,
                        'approved_qty_uom'=>$approvedUom,'approved_qty_base'=>$approvedBase,
                        'notes'=>$input['notes'] ?? null,'created_at'=>$existing?->created_at ?: now(),'updated_at'=>now(),
                    ]
                );
                DB::table('stk_request_items')->where('id', $item->id)->update([
                    'approved_qty'=>$approvedBase,'status'=>'prepare','fulfillment_status'=>'logistics_v3_queued',
                    'approved_by_user_id'=>$userId,'approved_at'=>now(),'approval_notes'=>$input['notes'] ?? null,
                ]);

                // Keep the internal auto-generated PO quantity aligned with the Warehouse decision,
                // but never expose its price in Sales Warehouse v3.
                $poItems = DB::table('pur_purchase_order_items')->where('stock_request_item_id', $item->id)->get(['id','purchase_order_id','unit_price']);
                foreach ($poItems as $poItem) {
                    DB::table('pur_purchase_order_items')->where('id', $poItem->id)->update([
                        'approved_qty' => $approvedBase,
                        'line_total' => round($approvedBase * (float) $poItem->unit_price, 2),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (! $approvedAny) throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Approved Qty lebih besar dari nol.']]);

            $poIds = DB::table('pur_purchase_orders')->where('stock_request_id', $requestId)->pluck('id');
            foreach ($poIds as $poId) {
                DB::table('pur_purchase_orders')->where('id', $poId)->update([
                    'total_amount' => round((float) DB::table('pur_purchase_order_items')->where('purchase_order_id', $poId)->sum('line_total'), 2),
                    'updated_at' => now(),
                ]);
            }

            $prepare = $this->createStockRequestPrepareQueue($request, $reviewId, $warehouseId, $userId);
            DB::table('wh_v3_stock_request_reviews')->where('id', $reviewId)->update([
                'status'=>'approved','logistics_prepare_request_id'=>$prepare->id,'approval_notes'=>$payload['notes'] ?? null,
                'approved_by_user_id'=>$userId,'approved_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('stk_requests')->where('id', $requestId)->update([
                'status'=>'prepare','accepted_by_user_id'=>$userId,'accepted_at'=>now(),'updated_by_user_id'=>$userId,
                'lock_version'=>DB::raw('lock_version + 1'),'updated_at'=>now(),
            ]);
            DB::table('stk_request_timelines')->insert([
                'id'=>(string)Str::ulid(),'stock_request_id'=>$requestId,'event_code'=>'warehouse_v3_approved_to_logistics','status'=>'prepare',
                'message'=>'Warehouse menyetujui Qty Stock Request. Request langsung masuk Logistic → Checker Prepare tanpa Stock Request Fulfillment dan tanpa barcode.',
                'metadata'=>json_encode(['logistics_prepare_request_id'=>$prepare->id,'stock_deducted'=>false,'flow_version'=>3]),
                'actor_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event('stock_request', $requestId, 'approved_to_logistics', $review?->status ?: 'pending', 'approved', 'Approved Qty disetujui dan handoff ke Checker Prepare.', $userId, ['prepare_request_id'=>$prepare->id]);
        }, 5);

        return $this->stockRequestDetail($warehouseId, $requestId);
    }

    public function rejectStockRequest(string $warehouseId, string $requestId, string $reason, string $userId): array
    {
        DB::transaction(function () use ($warehouseId, $requestId, $reason, $userId): void {
            $request = DB::table('stk_requests')->where('id', $requestId)->where('destination_warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $request) abort(404);
            if (! in_array((string) $request->request_approval_status, ['approved1','approved'], true)
                && ! in_array((string) $request->status, ['requested','review','prepare','ready'], true)) {
                throw ValidationException::withMessages(['status' => ['Stock Request belum melewati approval outlet dan belum dapat direview Warehouse.']]);
            }

            $review = DB::table('wh_v3_stock_request_reviews')->where('stock_request_id', $requestId)->lockForUpdate()->first();
            if ($review && $review->status === 'approved') {
                throw ValidationException::withMessages(['status' => ['Stock Request sudah approved dan tidak dapat direject dari Inbox.']]);
            }
            if ($review && $review->status === 'rejected') return;

            $reviewId = $review?->id ?: (string) Str::ulid();
            if (! $review) {
                DB::table('wh_v3_stock_request_reviews')->insert([
                    'id'=>$reviewId,'stock_request_id'=>$requestId,'warehouse_id'=>$warehouseId,'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('wh_v3_stock_request_reviews')->where('id', $reviewId)->update([
                'status'=>'rejected','rejection_reason'=>trim($reason),'rejected_by_user_id'=>$userId,'rejected_at'=>now(),
                'approval_notes'=>null,'approved_by_user_id'=>null,'approved_at'=>null,'updated_at'=>now(),
            ]);

            if (Schema::hasTable('stk_request_timelines')) {
                DB::table('stk_request_timelines')->insert([
                    'id'=>(string)Str::ulid(),'stock_request_id'=>$requestId,'event_code'=>'warehouse_v3_rejected','status'=>'rejected',
                    'message'=>'Warehouse menolak Stock Request saat review Inbox. Request tidak diteruskan ke Checker Prepare.',
                    'metadata'=>json_encode(['reason'=>trim($reason),'flow_version'=>3]),'actor_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $this->event('stock_request', $requestId, 'rejected', $review?->status ?: 'pending', 'rejected', 'Stock Request ditolak Warehouse.', $userId, ['reason'=>trim($reason)]);
        }, 5);

        return $this->stockRequestDetail($warehouseId, $requestId);
    }

    public function listProductionRequests(string $warehouseId, array $filters): array
    {
        $this->syncProductionCandidates($warehouseId);
        $query = DB::table('wh_v3_production_material_requests as request')
            ->join('wh_productions as production', 'production.id', '=', 'request.production_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'request.requested_by_user_id')
            ->leftJoin('users as approver', 'approver.id', '=', 'request.approved_by_user_id')
            ->where('request.warehouse_id', $warehouseId)
            ->select([
                'request.*','production.production_number','production.production_date','production.status as production_status',
                'requester.name as requester_name','requester.nisj as requester_nisj','approver.name as approver_name','approver.nisj as approver_nisj',
            ])
            ->selectSub(function ($sub): void {
                $sub->from('wh_v3_production_material_request_items as item')->whereColumn('item.production_request_id','request.id')->selectRaw('COUNT(*)');
            }, 'line_count')
            ->selectSub(function ($sub): void {
                $sub->from('wh_v3_production_material_request_items as item')->whereColumn('item.production_request_id','request.id')->selectRaw('COALESCE(SUM(item.requested_qty_base),0)');
            }, 'requested_qty_base')
            ->selectSub(function ($sub): void {
                $sub->from('wh_v3_production_material_request_items as item')->whereColumn('item.production_request_id','request.id')->selectRaw('COALESCE(SUM(item.approved_qty_base),0)');
            }, 'approved_qty_base');

        if (($filters['q'] ?? '') !== '') {
            $term='%'.trim((string)$filters['q']).'%';
            $query->where(fn($q)=>$q->where('request.request_number','like',$term)->orWhere('production.production_number','like',$term));
        }
        if (($filters['status'] ?? '') !== '') $query->where('request.status', $filters['status']);
        if (($filters['from'] ?? '') !== '') $query->where('production.production_date','>=',$filters['from']);
        if (($filters['to'] ?? '') !== '') $query->where('production.production_date','<=',$filters['to']);

        $paginator=$query->orderByDesc('request.created_at')->paginate((int)($filters['per_page']??50));
        return $this->paginated($paginator, fn($row)=>[
            'id'=>(string)$row->id,'request_number'=>(string)$row->request_number,'production_id'=>(string)$row->production_id,
            'production_number'=>(string)$row->production_number,'production_date'=>$row->production_date,'production_status'=>(string)$row->production_status,
            'status'=>(string)$row->status,'line_count'=>(int)$row->line_count,'requested_qty_base'=>round((float)$row->requested_qty_base,4),
            'approved_qty_base'=>round((float)$row->approved_qty_base,4),'requested_at'=>$row->requested_at,'approved_at'=>$row->approved_at,
            'requested_by'=>$row->requester_name?['name'=>$row->requester_name,'nisj'=>$row->requester_nisj]:null,
            'approved_by'=>$row->approver_name?['name'=>$row->approver_name,'nisj'=>$row->approver_nisj]:null,
            'material_ledger_posting_id'=>$row->material_ledger_posting_id,
        ]);
    }

    public function productionRequestDetail(string $warehouseId, string $id): array
    {
        $this->syncProductionCandidates($warehouseId);
        $row=DB::table('wh_v3_production_material_requests as request')
            ->join('wh_productions as production','production.id','=','request.production_id')
            ->leftJoin('users as requester','requester.id','=','request.requested_by_user_id')
            ->leftJoin('users as approver','approver.id','=','request.approved_by_user_id')
            ->where('request.warehouse_id',$warehouseId)->where('request.id',$id)
            ->select('request.*','production.production_number','production.production_date','production.status as production_status','production.notes as production_notes',
                'requester.name as requester_name','requester.nisj as requester_nisj','approver.name as approver_name','approver.nisj as approver_nisj')->first();
        if(!$row) abort(404);
        $items=DB::table('wh_v3_production_material_request_items as item')
            ->join('stk_skus as sku','sku.id','=','item.sku_id')->leftJoin('stk_uoms as uom','uom.id','=','item.request_uom_id')
            ->where('item.production_request_id',$id)->orderBy('sku.name')->get(['item.*','sku.sku_code','sku.name as item_name','uom.code as uom_code','uom.name as uom_name'])
            ->map(function($item)use($warehouseId):array{
                $requestedBase=round((float)$item->requested_qty_base,4);
                $allocation=DB::table('wh_v7_production_material_allocations')->where('production_request_item_id',$item->id)->first();
                if($allocation){$prodStock=round((float)$allocation->carry_in_qty_base,4);$needWh=round((float)$allocation->warehouse_issue_qty_base,4);}
                else{$avail=$this->productionOpname->availability($warehouseId,(string)$item->sku_id,$requestedBase);$prodStock=min($requestedBase,(float)$avail['qty_base']);$needWh=max(round($requestedBase-$prodStock,4),0);}
                $stock=DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->where('sku_id',$item->sku_id)->selectRaw('COALESCE(SUM(on_hand_qty-reserved_qty-quarantine_qty),0) qty')->first();
                return [
                    'id'=>(string)$item->id,'production_input_id'=>(string)$item->production_input_id,'sku_id'=>(string)$item->sku_id,'sku_code'=>(string)$item->sku_code,'item_name'=>(string)$item->item_name,
                    'uom_id'=>$item->request_uom_id,'uom_code'=>(string)($item->request_uom_code_snapshot ?: $item->uom_code ?: 'UNIT'),'uom_name'=>(string)($item->request_uom_name_snapshot ?: $item->uom_name ?: 'Unit'),
                    'conversion_factor'=>round((float)($item->conversion_factor_snapshot ?: 1),8),'base_uom_id'=>$item->base_uom_id_snapshot,'base_uom_code'=>(string)($item->base_uom_code_snapshot ?: 'UNIT'),'base_uom_name'=>(string)($item->base_uom_name_snapshot ?: 'Unit'),
                    'requested_qty_uom'=>round((float)$item->requested_qty_uom,4),'requested_qty_base'=>$requestedBase,'approved_qty_uom'=>$item->status==='pending'?round((float)$item->requested_qty_uom,4):round((float)$item->approved_qty_uom,4),'approved_qty_base'=>$item->status==='pending'?$requestedBase:round((float)$item->approved_qty_base,4),
                    'production_stock_qty_base'=>round($prodStock,4),'warehouse_need_qty_base'=>round($needWh,4),'warehouse_on_hand_qty'=>round((float)($stock->qty ?? 0),4),'status'=>(string)$item->status,'notes'=>$item->notes,
                ];
            })->values()->all();
        $summary=DB::table('wh_v7_production_material_allocations')->where('production_id',$row->production_id)->selectRaw('COALESCE(SUM(carry_in_qty_base),0) carry_qty,COALESCE(SUM(warehouse_issue_qty_base),0) warehouse_qty')->first();
        return [
            'id'=>(string)$row->id,'request_number'=>(string)$row->request_number,'production_id'=>(string)$row->production_id,'production_number'=>(string)$row->production_number,
            'production_date'=>$row->production_date,'production_status'=>(string)$row->production_status,'status'=>(string)$row->status,'production_notes'=>$row->production_notes,
            'requested_at'=>$row->requested_at,'requested_by'=>$row->requester_name?['name'=>$row->requester_name,'nisj'=>$row->requester_nisj]:null,'approved_at'=>$row->approved_at,'approved_by'=>$row->approver_name?['name'=>$row->approver_name,'nisj'=>$row->approver_nisj]:null,
            'approval_notes'=>$row->approval_notes,'material_ledger_posting_id'=>$row->material_ledger_posting_id,'material_source_summary'=>['production_stock_qty_base'=>round((float)($summary->carry_qty??0),4),'warehouse_issue_qty_base'=>round((float)($summary->warehouse_qty??0),4)],
            'items'=>$items,'timeline'=>$this->events('production_request',(string)$row->id),
        ];
    }

    public function approveProductionRequest(string $warehouseId, string $id, array $payload, string $userId): array
    {
        DB::transaction(function()use($warehouseId,$id,$payload,$userId):void{
            $request=DB::table('wh_v3_production_material_requests')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first(); if(!$request) abort(404);
            if($request->status==='approved') return;
            if($request->status!=='pending') throw ValidationException::withMessages(['status'=>['Hanya Production Request pending yang dapat di-approve.']]);
            $production=DB::table('wh_productions')->where('id',$request->production_id)->where('warehouse_id',$warehouseId)->lockForUpdate()->first(); if(!$production) abort(404);
            if($production->input_ledger_posting_id) throw ValidationException::withMessages(['production'=>['Material Production Order sudah pernah diposting ke ledger.']]);
            $inputMap=collect($payload['items']??[])->keyBy(fn($row)=>(string)($row['item_id']??''));
            $items=DB::table('wh_v3_production_material_request_items')->where('production_request_id',$id)->lockForUpdate()->get();
            $ledgerLines=[];$approvedTotal=0.0;$carryTotal=0.0;$warehouseIssueTotal=0.0;
            foreach($items as $item){
                $input=$inputMap->get((string)$item->id); if(!$input) throw ValidationException::withMessages(['items'=>['Seluruh bahan wajib memiliki Approved Qty.']]);
                $approvedUom=round((float)($input['approved_qty_uom']??0),4);$requestedUom=round((float)$item->requested_qty_uom,4);
                if($approvedUom<0||$approvedUom>$requestedUom+0.0001) throw ValidationException::withMessages(['items'=>["Approved Qty bahan {$item->sku_id} melebihi Requested Qty."]]);
                $factor=(float)($item->conversion_factor_snapshot ?: ($requestedUom>0?(float)$item->requested_qty_base/$requestedUom:1));$approvedBase=round($approvedUom*$factor,4);$approvedTotal+=$approvedBase;
                $alloc=$this->productionOpname->reserveForMaterial($warehouseId,(string)$request->production_id,(string)$item->production_input_id,(string)$item->id,(string)$item->sku_id,$approvedBase);
                $carry=round((float)$alloc['carry_in_qty_base'],4);$issue=round((float)$alloc['warehouse_issue_qty_base'],4);$carryTotal+=$carry;$warehouseIssueTotal+=$issue;
                DB::table('wh_v3_production_material_request_items')->where('id',$item->id)->update(['approved_qty_uom'=>$approvedUom,'approved_qty_base'=>$approvedBase,'status'=>$approvedBase>0?'approved':'zero_approved','notes'=>$input['notes']??null,'updated_at'=>now()]);
                if($issue>0) $ledgerLines=array_merge($ledgerLines,$this->allocateBatchLines($warehouseId,(string)$item->sku_id,$issue,'PRODREQ-'.$item->id));
                DB::table('wh_production_inputs')->where('id',$item->production_input_id)->update(['actual_qty_uom'=>$approvedUom,'actual_qty_base'=>$approvedBase,'shortage_qty_base'=>max(round((float)$item->requested_qty_base-$approvedBase,4),0),'status'=>'completed','shortage_reason'=>$approvedBase+0.0001<(float)$item->requested_qty_base?'Adjusted by Production Request approval':null,'updated_at'=>now()]);
            }
            if($approvedTotal<=0.0001) throw ValidationException::withMessages(['items'=>['Minimal satu bahan harus memiliki Approved Qty lebih besar dari nol.']]);
            $posting=null;
            if($ledgerLines) $posting=$this->ledger->post(['warehouse_id'=>$warehouseId,'idempotency_key'=>'WAREHOUSE-V7-PRODUCTION-MATERIAL:'.$id,'movement_type'=>'production_out','reference_type'=>'wh_v3_production_material_request','reference_id'=>$id,'business_date'=>now('Asia/Jakarta')->toDateString(),'reason'=>'Net Warehouse Material Issue '.$request->request_number,'metadata'=>['production_id'=>$request->production_id,'flow_version'=>7,'production_stock_carry_qty_base'=>round($carryTotal,4),'warehouse_issue_qty_base'=>round($warehouseIssueTotal,4)],'user_id'=>$userId,'allow_negative'=>false,'lines'=>$ledgerLines]);
            $cost=$this->productionOpname->finalizeIssueCosts($warehouseId,(string)$request->production_id,$posting?(string)$posting->id:null);
            DB::table('wh_v3_production_material_requests')->where('id',$id)->update(['status'=>'approved','approved_by_user_id'=>$userId,'approved_at'=>now(),'material_ledger_posting_id'=>$posting?->id,'approval_notes'=>$payload['notes']??null,'updated_at'=>now()]);
            DB::table('wh_productions')->where('id',$request->production_id)->update(['status'=>'on_progress','flow_version'=>7,'input_ledger_posting_id'=>$posting?->id,'input_idempotency_key'=>$posting?'WAREHOUSE-V7-PRODUCTION-MATERIAL:'.$id:null,'actual_input_value'=>round((float)$cost['available_value'],2),'materials_released_by_user_id'=>$userId,'materials_released_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>DB::raw('lock_version+1'),'updated_at'=>now()]);
            $this->event('production_request',$id,'approved_material_allocated','pending','approved',sprintf('Production Request approved. %.4f base Production Stock carry-forward + %.4f base Warehouse issue.',$carryTotal,$warehouseIssueTotal),$userId,['production_id'=>$request->production_id,'ledger_posting_id'=>$posting?->id,'production_stock_carry_qty_base'=>round($carryTotal,4),'warehouse_issue_qty_base'=>round($warehouseIssueTotal,4),'flow_version'=>7]);
        },5);
        return $this->productionRequestDetail($warehouseId,$id);
    }

    public function logisticsQueue(string $warehouseId, array $filters): array
    {
        $query=DB::table('wh_v3_logistics_prepare_requests as prepare')
            ->where('prepare.warehouse_id',$warehouseId)
            ->select('prepare.*')
            ->selectSub(function($sub):void{$sub->from('wh_v3_logistics_prepare_items as item')->whereColumn('item.prepare_request_id','prepare.id')->selectRaw('COUNT(*)');},'line_count')
            ->selectSub(function($sub):void{$sub->from('wh_v3_logistics_prepare_items as item')->whereColumn('item.prepare_request_id','prepare.id')->selectRaw('COALESCE(SUM(item.approved_qty_base),0)');},'approved_qty_base');
        if(($filters['q']??'')!==''){$term='%'.trim((string)$filters['q']).'%';$query->where(fn($q)=>$q->where('prepare.prepare_number','like',$term)->orWhere('prepare.source_number','like',$term));}
        if(($filters['status']??'')!=='')$query->where('prepare.status',$filters['status']);
        if(($filters['source_type']??'')!=='')$query->where('prepare.source_type',$filters['source_type']);
        $p=$query->orderByDesc('prepare.created_at')->paginate((int)($filters['per_page']??50));
        return $this->paginated($p,fn($r)=>[
            'id'=>(string)$r->id,'prepare_number'=>(string)$r->prepare_number,'source_type'=>(string)$r->source_type,'source_id'=>(string)$r->source_id,'source_number'=>$r->source_number,
            'destination_type'=>$r->destination_type,'destination_id'=>$r->destination_id,'status'=>(string)$r->status,'requested_delivery_date'=>$r->requested_delivery_date,
            'line_count'=>(int)$r->line_count,'approved_qty_base'=>round((float)$r->approved_qty_base,4),'approved_at'=>$r->approved_at,'created_at'=>$r->created_at,
        ]);
    }

    public function logisticsQueueDetail(string $warehouseId,string $id):array
    {
        $row=DB::table('wh_v3_logistics_prepare_requests')->where('warehouse_id',$warehouseId)->where('id',$id)->first();if(!$row)abort(404);
        $items=DB::table('wh_v3_logistics_prepare_items as item')->join('stk_skus as sku','sku.id','=','item.sku_id')->leftJoin('stk_uoms as uom','uom.id','=','item.uom_id')->where('item.prepare_request_id',$id)->orderBy('sku.name')->get(['item.*','sku.sku_code','sku.name as item_name','uom.code as uom_code'])->map(fn($i)=>[
            'id'=>(string)$i->id,'sku_code'=>(string)$i->sku_code,'item_name'=>(string)$i->item_name,'uom_code'=>(string)($i->uom_code?:'UNIT'),
            'requested_qty_uom'=>round((float)$i->requested_qty_uom,4),'approved_qty_uom'=>round((float)$i->approved_qty_uom,4),'approved_qty_base'=>round((float)$i->approved_qty_base,4),'status'=>(string)$i->status,'notes'=>$i->notes,
        ])->values()->all();
        return ['id'=>(string)$row->id,'prepare_number'=>(string)$row->prepare_number,'source_type'=>(string)$row->source_type,'source_id'=>(string)$row->source_id,'source_number'=>$row->source_number,'destination_type'=>$row->destination_type,'destination_id'=>$row->destination_id,'status'=>(string)$row->status,'requested_delivery_date'=>$row->requested_delivery_date,'metadata'=>$this->json($row->metadata),'items'=>$items];
    }

    public function syncProductionCandidates(string $warehouseId): void
    {
        // Transitional bridge for the current Production baseline. Iterasi 06 can call
        // createProductionRequestFromProduction() explicitly at Production Order approval.
        $ids = DB::table('wh_productions')
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'prepare')
            ->whereNull('input_ledger_posting_id')
            ->pluck('id');

        foreach ($ids as $productionId) {
            $this->createProductionRequestFromProduction($warehouseId, (string) $productionId, null);
        }
    }

    public function createProductionRequestFromProduction(string $warehouseId, string $productionId, ?string $actorUserId = null): object
    {
        return DB::transaction(function () use ($warehouseId, $productionId, $actorUserId): object {
            $existing = DB::table('wh_v3_production_material_requests')->where('production_id', $productionId)->lockForUpdate()->first();
            if ($existing) return $existing;

            $production = DB::table('wh_productions')->where('warehouse_id', $warehouseId)->where('id', $productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ($production->input_ledger_posting_id) {
                throw ValidationException::withMessages(['production' => ['Production Order sudah melakukan material issue dan tidak dapat membuat Production Request baru.']]);
            }
            if (! in_array((string) $production->status, ['prepare','approved','awaiting_material','pending_material'], true)) {
                throw ValidationException::withMessages(['status' => ['Production Order belum berada pada status yang dapat membuat Production Request.']]);
            }

            $inputs = DB::table('wh_production_inputs')->where('production_id', $productionId)->get();
            if ($inputs->isEmpty()) {
                throw ValidationException::withMessages(['production' => ['Production Order tidak memiliki bahan produksi.']]);
            }

            $id = (string) Str::ulid();
            $number = $this->number('PRDREQ');
            $requesterId = $actorUserId ?: ($production->submitted_by_user_id ?: $production->created_by_user_id);
            DB::table('wh_v3_production_material_requests')->insert([
                'id'=>$id,'request_number'=>$number,'production_id'=>$production->id,'warehouse_id'=>$warehouseId,'status'=>'pending',
                'requested_by_user_id'=>$requesterId,'requested_at'=>$production->submitted_at ?: now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach ($inputs as $input) {
                DB::table('wh_v3_production_material_request_items')->insert([
                    'id'=>(string)Str::ulid(),'production_request_id'=>$id,'production_input_id'=>$input->id,'sku_id'=>$input->sku_id,
                    'request_uom_id'=>$input->request_uom_id,'request_uom_code_snapshot'=>$input->request_uom_code_snapshot,'request_uom_name_snapshot'=>$input->request_uom_name_snapshot,
                    'conversion_factor_snapshot'=>$input->conversion_factor_snapshot,'base_uom_id_snapshot'=>$input->base_uom_id,'base_uom_code_snapshot'=>$input->base_uom_code_snapshot,'base_uom_name_snapshot'=>$input->base_uom_name_snapshot,
                    'requested_qty_uom'=>$input->planned_qty_uom,'requested_qty_base'=>$input->planned_qty_base,
                    'approved_qty_uom'=>0,'approved_qty_base'=>0,'status'=>'pending','notes'=>$input->notes,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $this->event('production_request',$id,'created_from_production_order',null,'pending','Production Request dibuat dari Production Order untuk approval bahan.',$requesterId,[
                'production_id'=>$production->id,'production_number'=>$production->production_number,'source_status'=>$production->status,'flow_version'=>3,
            ]);
            return DB::table('wh_v3_production_material_requests')->where('id', $id)->first();
        }, 3);
    }

    private function createStockRequestPrepareQueue(object $request,string $reviewId,string $warehouseId,string $userId):object
    {
        $prepare=DB::table('wh_v3_logistics_prepare_requests')->where('source_type','stock_request')->where('source_id',$request->id)->lockForUpdate()->first();
        if($prepare)return $prepare;
        $id=(string)Str::ulid();
        DB::table('wh_v3_logistics_prepare_requests')->insert([
            'id'=>$id,'prepare_number'=>$this->number('PREP'),'warehouse_id'=>$warehouseId,'source_type'=>'stock_request','source_id'=>$request->id,'source_number'=>$request->request_number,
            'destination_type'=>'outlet','destination_id'=>$request->outlet_id,'status'=>'queued','requested_delivery_date'=>$request->needed_date,
            'metadata'=>json_encode(['review_id'=>$reviewId,'flow_version'=>3,'stock_deducted'=>false,'barcode_required'=>false]),'approved_by_user_id'=>$userId,'approved_at'=>now(),'created_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $reviewItems=DB::table('wh_v3_stock_request_review_items as ri')->join('stk_request_items as item','item.id','=','ri.stock_request_item_id')->where('ri.review_id',$reviewId)->where('ri.approved_qty_base','>',0)->get(['ri.*','item.sku_id','item.request_uom_id']);
        foreach($reviewItems as $item){DB::table('wh_v3_logistics_prepare_items')->insert(['id'=>(string)Str::ulid(),'prepare_request_id'=>$id,'source_item_id'=>$item->stock_request_item_id,'sku_id'=>$item->sku_id,'uom_id'=>$item->request_uom_id,'requested_qty_uom'=>$item->requested_qty_uom,'requested_qty_base'=>$item->requested_qty_base,'approved_qty_uom'=>$item->approved_qty_uom,'approved_qty_base'=>$item->approved_qty_base,'ready_qty_base'=>0,'status'=>'queued','notes'=>$item->notes,'metadata'=>json_encode(['flow_version'=>3]),'created_at'=>now(),'updated_at'=>now()]);}
        return DB::table('wh_v3_logistics_prepare_requests')->where('id',$id)->first();
    }

    private function allocateBatchLines(string $warehouseId,string $skuId,float $quantity,string $linePrefix):array
    {
        $remaining=round($quantity,4);$lines=[];
        $balances=DB::table('wh_batch_balances as balance')->join('wh_batches as batch','batch.id','=','balance.batch_id')
            ->where('balance.warehouse_id',$warehouseId)->where('balance.sku_id',$skuId)->whereNull('batch.deleted_at')
            ->whereRaw('(balance.on_hand_qty - balance.reserved_qty - balance.quarantine_qty) > 0')
            ->orderByRaw('CASE WHEN batch.expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('batch.expiry_date')->orderBy('batch.received_at')->orderBy('batch.created_at')
            ->lockForUpdate()->get(['balance.batch_id','balance.storage_id','balance.on_hand_qty','balance.reserved_qty','balance.quarantine_qty','balance.average_unit_cost']);
        foreach($balances as $balance){if($remaining<=0.0001)break;$available=round((float)$balance->on_hand_qty-(float)$balance->reserved_qty-(float)$balance->quarantine_qty,4);if($available<=0)continue;$take=min($remaining,$available);$lines[]=['line_key'=>$linePrefix.'-'.$balance->batch_id.'-'.$balance->storage_id,'sku_id'=>$skuId,'batch_id'=>(string)$balance->batch_id,'storage_id'=>(string)$balance->storage_id,'quantity_base'=>round($take,4),'unit_cost'=>(float)$balance->average_unit_cost,'metadata'=>['allocation'=>'FEFO/FIFO','barcode_used'=>false]];$remaining=round($remaining-$take,4);}
        if($remaining>0.0001)throw ValidationException::withMessages(['stock'=>[sprintf('Stock bahan tidak mencukupi untuk SKU %s. Kekurangan %.4f base unit.',$skuId,$remaining)]]);
        return $lines;
    }

    private function stockRequestBase(string $warehouseId)
    {
        return DB::table('stk_requests as request')
            ->leftJoin('wh_v3_stock_request_reviews as review','review.stock_request_id','=','request.id')
            ->where('request.destination_warehouse_id',$warehouseId)
            ->where('request.request_channel','warehouse_operations')
            ->whereNotIn('request.status',['draft','rejected','cancelled'])
            ->where(function($q):void{$q->whereIn('request.request_approval_status',['approved1','approved'])->orWhereIn('request.status',['requested','review','prepare','ready']);});
    }

    private function stockRequestSummaryRow(object $row):array
    {
        return ['id'=>(string)$row->id,'request_number'=>(string)$row->request_number,'request_date'=>$row->request_date,'needed_date'=>$row->needed_date,
            'source_status'=>(string)$row->source_status,'approval_status'=>(string)($row->review_status?:'pending'),'request_approval_status'=>(string)$row->request_approval_status,
            'outlet'=>['id'=>(string)$row->outlet_id,'code'=>(string)$row->outlet_code,'name'=>(string)$row->outlet_name],
            'line_count'=>(int)$row->line_count,'requested_qty_base'=>round((float)$row->requested_qty_base,4),'approved_qty_base'=>round((float)$row->approved_qty_base,4),
            'approved_at'=>$row->approved_at,'rejected_at'=>$row->rejected_at ?? null,'rejection_reason'=>$row->rejection_reason ?? null,'logistics_prepare_request_id'=>$row->logistics_prepare_request_id,'updated_at'=>$row->updated_at];
    }

    private function stockRequestTimeline(string $requestId):array
    {
        if(!Schema::hasTable('stk_request_timelines'))return[];
        return DB::table('stk_request_timelines as timeline')->leftJoin('users as actor','actor.id','=','timeline.actor_user_id')->where('timeline.stock_request_id',$requestId)->orderBy('timeline.created_at')->get(['timeline.id','timeline.event_code','timeline.status','timeline.message','timeline.metadata','timeline.created_at','actor.name as actor_name','actor.nisj as actor_nisj'])->map(fn($r)=>['id'=>(string)$r->id,'event_code'=>(string)$r->event_code,'status'=>$r->status,'message'=>(string)$r->message,'metadata'=>$this->json($r->metadata),'actor'=>$r->actor_name?['name'=>$r->actor_name,'nisj'=>$r->actor_nisj]:null,'created_at'=>$r->created_at])->all();
    }

    private function event(string $type,string $id,string $event,?string $from,?string $to,string $message,?string $actor,array $metadata=[]):void
    {
        DB::table('wh_v3_sales_demand_events')->insert(['id'=>(string)Str::ulid(),'document_type'=>$type,'document_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>$metadata?json_encode($metadata):null,'actor_user_id'=>$actor?:null,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function events(string $type,string $id):array
    {
        return DB::table('wh_v3_sales_demand_events as event')->leftJoin('users as actor','actor.id','=','event.actor_user_id')->where('event.document_type',$type)->where('event.document_id',$id)->orderBy('event.occurred_at')->get(['event.*','actor.name as actor_name','actor.nisj as actor_nisj'])->map(fn($r)=>['id'=>(string)$r->id,'event_type'=>(string)$r->event_type,'from_status'=>$r->from_status,'to_status'=>$r->to_status,'message'=>$r->message,'metadata'=>$this->json($r->metadata),'actor'=>$r->actor_name?['name'=>$r->actor_name,'nisj'=>$r->actor_nisj]:null,'created_at'=>$r->occurred_at])->all();
    }

    private function number(string $prefix):string
    {
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));
    }

    private function json(mixed $value):mixed
    {
        if(is_array($value)||$value===null)return $value; $decoded=json_decode((string)$value,true); return json_last_error()===JSON_ERROR_NONE?$decoded:$value;
    }

    private function paginated(LengthAwarePaginator $paginator, callable $mapper):array
    {
        return ['items'=>collect($paginator->items())->map($mapper)->values()->all(),'pagination'=>['current_page'=>$paginator->currentPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total(),'last_page'=>$paginator->lastPage()]];
    }
}
