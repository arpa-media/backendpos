<?php

namespace App\Services\Warehouse\ProductionV3;

use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseStorage;
use App\Services\Warehouse\Iteration07\WarehouseProductionMaterialOpnameV7Service;
use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use App\Services\Warehouse\WarehouseLedgerService;
use App\Services\Warehouse\WarehouseProductionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseProductionV3Service
{
    public function __construct(
        private readonly WarehouseProductionService $core,
        private readonly WarehouseSalesDemandV3Service $sales,
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseProductionMaterialOpnameV7Service $productionOpname,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $options = $this->core->options($warehouseId);
        $options['flow_version'] = 7;
        return $options;
    }

    public function list(string $warehouseId, array $filters = []): array
    {
        $mode = (string) ($filters['mode'] ?? 'orders');
        $query = DB::table('wh_productions as production')
            ->leftJoin('users as creator', 'creator.id', '=', 'production.created_by_user_id')
            ->leftJoin('users as order_approver', 'order_approver.id', '=', 'production.order_approved_by_user_id')
            ->where('production.warehouse_id', $warehouseId)
            ->where(function ($q): void {
                $q->where('production.flow_version','>=',3)->orWhereNotNull('production.production_request_id');
            })
            ->select([
                'production.id','production.production_number','production.production_date','production.status','production.notes',
                'production.planned_input_value','production.actual_input_value','production.actual_output_value','production.yield_percent',
                'production.production_request_id','production.order_approved_at','production.finished_at','production.created_at','production.updated_at',
                'creator.name as creator_name','creator.nisj as creator_nisj','order_approver.name as approver_name','order_approver.nisj as approver_nisj',
            ])
            ->selectSub(fn ($sub) => $sub->from('wh_production_inputs as i')->whereColumn('i.production_id','production.id')->selectRaw('COUNT(*)'), 'material_count')
            ->selectSub(fn ($sub) => $sub->from('wh_production_inputs as i')->whereColumn('i.production_id','production.id')->selectRaw('COALESCE(SUM(i.planned_qty_base),0)'), 'planned_material_qty')
            ->selectSub(fn ($sub) => $sub->from('wh_production_inputs as i')->whereColumn('i.production_id','production.id')->selectRaw('COALESCE(SUM(i.actual_qty_base),0)'), 'actual_material_qty')
            ->selectSub(fn ($sub) => $sub->from('wh_production_outputs as o')->whereColumn('o.production_id','production.id')->selectRaw('COUNT(*)'), 'output_count')
            ->selectSub(fn ($sub) => $sub->from('wh_production_outputs as o')->whereColumn('o.production_id','production.id')->selectRaw('COALESCE(SUM(o.estimated_qty_base),0)'), 'estimated_output_qty')
            ->selectSub(fn ($sub) => $sub->from('wh_production_outputs as o')->whereColumn('o.production_id','production.id')->selectRaw('COALESCE(SUM(o.actual_qty_base),0)'), 'actual_output_qty')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_production_results as r')->whereColumn('r.production_id','production.id')->where('r.status','approved')->selectRaw('COUNT(*)'), 'approved_result_count')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_production_results as r')->whereColumn('r.production_id','production.id')->where('r.status','draft')->selectRaw('COUNT(*)'), 'draft_result_count')
            ->selectSub(fn ($sub) => $sub->from('wh_v3_production_results as r')->whereColumn('r.production_id','production.id')->where('r.status','rejected')->selectRaw('COUNT(*)'), 'rejected_result_count')
            ->selectSub(fn ($sub) => $sub->from('wh_v7_production_material_opnames as m')->whereColumn('m.production_id','production.id')->select('m.status')->limit(1), 'material_opname_status')
            ->selectSub(fn ($sub) => $sub->from('wh_v7_production_material_opnames as m')->join('wh_v7_production_material_opname_items as mi','mi.opname_id','=','m.id')->whereColumn('m.production_id','production.id')->selectRaw('COALESCE(SUM(mi.remaining_qty_base),0)'), 'material_remaining_qty_base');

        if ($mode === 'ongoing') $query->where('production.status', 'on_progress');
        if ($mode === 'opname') $query->whereIn('production.status',['on_progress','completed']);
        if ($mode === 'review') $query->whereIn('production.status', ['completed','approved']);
        if (($filters['status'] ?? '') !== '') $query->where('production.status', $filters['status']);
        if (($filters['q'] ?? '') !== '') {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('production.production_number','like',$term)->orWhere('creator.name','like',$term));
        }
        if (($filters['from'] ?? '') !== '') $query->where('production.production_date','>=',$filters['from']);
        if (($filters['to'] ?? '') !== '') $query->where('production.production_date','<=',$filters['to']);

        $paginator = $query->orderByDesc('production.production_date')->orderByDesc('production.created_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn ($row) => $this->summaryRow($row));
    }

    public function detail(string $warehouseId, string $id): array
    {
        $production = DB::table('wh_productions as production')
            ->leftJoin('users as creator','creator.id','=','production.created_by_user_id')
            ->leftJoin('users as order_approver','order_approver.id','=','production.order_approved_by_user_id')
            ->leftJoin('users as finisher','finisher.id','=','production.finished_by_user_id')
            ->where('production.warehouse_id',$warehouseId)->where('production.id',$id)
            ->select('production.*','creator.name as creator_name','creator.nisj as creator_nisj','order_approver.name as order_approver_name','order_approver.nisj as order_approver_nisj','finisher.name as finisher_name','finisher.nisj as finisher_nisj')
            ->first();
        if (! $production) abort(404);

        $allocationMap = DB::table('wh_v7_production_material_allocations')->where('production_id',$id)->get()->keyBy('production_input_id');
        $materials = DB::table('wh_production_inputs as input')
            ->join('stk_skus as sku','sku.id','=','input.sku_id')->leftJoin('stk_uoms as uom','uom.id','=','input.request_uom_id')
            ->where('input.production_id',$id)->orderBy('sku.name')->get(['input.*','sku.sku_code','sku.name as item_name','uom.code as uom_code'])
            ->map(function($row)use($allocationMap){$a=$allocationMap->get((string)$row->id);return [
                'id'=>(string)$row->id,'sku_id'=>(string)$row->sku_id,'sku_code'=>(string)$row->sku_code,'item_name'=>(string)$row->item_name,'uom_id'=>(string)$row->request_uom_id,
                'uom_code'=>(string)($row->request_uom_code_snapshot ?: $row->uom_code ?: 'UNIT'),'uom_name'=>(string)($row->request_uom_name_snapshot ?: 'Unit'),'conversion_factor_snapshot'=>(float)$row->conversion_factor_snapshot,
                'base_uom_id'=>(string)$row->base_uom_id,'base_uom_code'=>(string)($row->base_uom_code_snapshot ?: 'UNIT'),'base_uom_name'=>(string)($row->base_uom_name_snapshot ?: 'Unit'),
                'planned_qty_uom'=>(float)$row->planned_qty_uom,'planned_qty_base'=>(float)$row->planned_qty_base,'actual_qty_uom'=>(float)$row->actual_qty_uom,'actual_qty_base'=>(float)$row->actual_qty_base,'shortage_qty_base'=>(float)$row->shortage_qty_base,'status'=>(string)$row->status,'notes'=>$row->notes,
                'carry_in_qty_base'=>$a?round((float)$a->carry_in_qty_base,4):0,'warehouse_issue_qty_base'=>$a?round((float)$a->warehouse_issue_qty_base,4):0,'available_qty_base'=>$a?round((float)$a->available_qty_base,4):round((float)$row->actual_qty_base,4),'remaining_qty_base'=>$a?round((float)$a->remaining_qty_base,4):0,'consumed_qty_base'=>$a?round((float)$a->consumed_qty_base,4):0,
            ];})->values()->all();

        $outputs = DB::table('wh_production_outputs as output')
            ->join('stk_skus as sku','sku.id','=','output.sku_id')
            ->leftJoin('stk_uoms as uom','uom.id','=','output.output_uom_id')
            ->where('output.production_id',$id)->orderBy('sku.name')
            ->get(['output.*','sku.sku_code','sku.name as item_name','uom.code as uom_code'])
            ->map(fn ($row) => [
                'id'=>(string)$row->id,'sku_id'=>(string)$row->sku_id,'sku_code'=>(string)$row->sku_code,'item_name'=>(string)$row->item_name,
                'uom_id'=>(string)$row->output_uom_id,'uom_code'=>(string)($row->output_uom_code_snapshot ?: $row->uom_code ?: 'UNIT'),'uom_name'=>(string)($row->output_uom_name_snapshot ?: 'Unit'),
                'base_uom_id'=>(string)$row->base_uom_id,'base_uom_code'=>(string)($row->base_uom_code_snapshot ?: 'UNIT'),'base_uom_name'=>(string)($row->base_uom_name_snapshot ?: 'Unit'),
                'conversion_factor_snapshot'=>(float)$row->conversion_factor_snapshot,'estimated_qty_uom'=>(float)$row->estimated_qty_uom,
                'estimated_qty_base'=>(float)$row->estimated_qty_base,'actual_qty_uom'=>(float)$row->actual_qty_uom,'actual_qty_base'=>(float)$row->actual_qty_base,
                'yield_variance_qty_base'=>(float)$row->yield_variance_qty_base,'actual_unit_cost'=>(float)$row->actual_unit_cost,'status'=>(string)$row->status,'notes'=>$row->notes,
            ])->values()->all();

        $request = $production->production_request_id
            ? DB::table('wh_v3_production_material_requests as request')
                ->leftJoin('users as requester','requester.id','=','request.requested_by_user_id')
                ->leftJoin('users as approver','approver.id','=','request.approved_by_user_id')
                ->where('request.id',$production->production_request_id)
                ->select('request.*','requester.name as requester_name','requester.nisj as requester_nisj','approver.name as approver_name','approver.nisj as approver_nisj')->first()
            : null;

        $results = DB::table('wh_v3_production_results as result')
            ->leftJoin('users as creator','creator.id','=','result.created_by_user_id')
            ->leftJoin('users as approver','approver.id','=','result.approved_by_user_id')
            ->leftJoin('users as rejector','rejector.id','=','result.rejected_by_user_id')
            ->where('result.production_id',$id)->orderByDesc('result.result_date')->orderByDesc('result.created_at')
            ->get(['result.*','creator.name as creator_name','approver.name as approver_name','rejector.name as rejector_name'])
            ->map(fn ($row) => $this->resultRow($row, true))->values()->all();

        return [
            'id'=>(string)$production->id,'production_number'=>(string)$production->production_number,'production_date'=>$production->production_date,
            'status'=>(string)$production->status,'flow_version'=>(int)($production->flow_version ?? 2),'notes'=>$production->notes,
            'planned_input_value'=>(float)$production->planned_input_value,'actual_input_value'=>(float)$production->actual_input_value,
            'actual_output_value'=>(float)$production->actual_output_value,'yield_percent'=>(float)$production->yield_percent,
            'created_by'=>$this->person($production->created_by_user_id,$production->creator_name,$production->creator_nisj),
            'order_approved_by'=>$this->person($production->order_approved_by_user_id,$production->order_approver_name,$production->order_approver_nisj),
            'order_approved_at'=>$production->order_approved_at,'finished_by'=>$this->person($production->finished_by_user_id,$production->finisher_name,$production->finisher_nisj),
            'finished_at'=>$production->finished_at,'materials'=>$materials,'outputs'=>$outputs,
            'material_opname'=>$this->productionOpname->opnameStatus($id),
            'production_request'=>$request ? [
                'id'=>(string)$request->id,'request_number'=>(string)$request->request_number,'status'=>(string)$request->status,
                'requested_at'=>$request->requested_at,'approved_at'=>$request->approved_at,
                'requested_by'=>$this->person($request->requested_by_user_id,$request->requester_name,$request->requester_nisj),
                'approved_by'=>$this->person($request->approved_by_user_id,$request->approver_name,$request->approver_nisj),
                'material_ledger_posting_id'=>$request->material_ledger_posting_id,
            ] : null,
            'results'=>$results,'reconciliation'=>$this->reconciliation($id),'timeline'=>$this->timeline($id),
        ];
    }

    public function saveDraft(?string $id, string $warehouseId, array $payload, string $userId): array
    {
        $data = $this->core->saveProduction($id, $warehouseId, $payload, $userId);
        $productionId = (string) ($data['id'] ?? $id);
        DB::table('wh_productions')->where('id',$productionId)->update(['flow_version'=>7,'updated_at'=>now()]);
        $this->event($productionId, $id ? 'v3_draft_updated' : 'v3_draft_created', $userId, [
            'flow_version'=>7,'barcode_required'=>false,'material_count'=>count($payload['inputs'] ?? []),'output_count'=>count($payload['outputs'] ?? []),
        ], $id ? null : 'PROD-V3-CREATED:'.$productionId);
        return $this->detail($warehouseId, $productionId);
    }

    public function approveOrder(string $warehouseId, string $id, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$id,$userId): void {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$id)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ($production->production_request_id && in_array((string)$production->status,['awaiting_material','on_progress','completed'],true)) return;
            if ((string)$production->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya Production Order draft yang dapat di-approve.']]);
            if (! DB::table('wh_production_inputs')->where('production_id',$id)->exists() || ! DB::table('wh_production_outputs')->where('production_id',$id)->exists()) {
                throw ValidationException::withMessages(['production'=>['Minimal satu bahan dan satu estimasi hasil produksi wajib tersedia.']]);
            }

            DB::table('wh_productions')->where('id',$id)->update([
                'flow_version'=>7,'status'=>'awaiting_material','submitted_by_user_id'=>$production->created_by_user_id ?: $userId,'submitted_at'=>now(),
                'order_approved_by_user_id'=>$userId,'order_approved_at'=>now(),'updated_by_user_id'=>$userId,
                'lock_version'=>DB::raw('lock_version + 1'),'updated_at'=>now(),
            ]);

            $request = $this->sales->createProductionRequestFromProduction($warehouseId, $id, $production->created_by_user_id ?: $userId);
            DB::table('wh_productions')->where('id',$id)->update(['production_request_id'=>$request->id,'updated_at'=>now()]);
            $this->event($id,'production_order_approved',$userId,['production_request_id'=>(string)$request->id,'request_number'=>$request->request_number,'next_step'=>'Sales Warehouse > Production Request'],'PROD-V3-ORDER-APPROVED:'.$id);
        }, 5);

        return $this->detail($warehouseId,$id);
    }

    public function saveResultDraft(string $warehouseId, string $productionId, ?string $resultId, array $payload, string $userId): array
    {
        $id = DB::transaction(function () use ($warehouseId,$productionId,$resultId,$payload,$userId): string {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string)$production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Hasil produksi hanya dapat dicatat saat Production Order ongoing.']]);
            if ((string) $payload['result_date'] < (string) $production->production_date) {
                throw ValidationException::withMessages(['result_date'=>['Tanggal hasil produksi tidak boleh lebih awal dari Production Date.']]);
            }

            $result = $resultId ? DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first() : null;
            if ($resultId && ! $result) abort(404);
            if ($result && (string)$result->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya Draft hasil produksi yang dapat diedit. Rejected/Approved bersifat terkunci.']]);
            $id = $result?->id ?: (string) Str::ulid();
            $now = now();
            $header = [
                'warehouse_id'=>$warehouseId,'production_id'=>$productionId,'result_date'=>$payload['result_date'],'status'=>'draft','notes'=>$payload['notes'] ?? null,
                'updated_by_user_id'=>$userId,'updated_at'=>$now,
            ];
            if ($result) DB::table('wh_v3_production_results')->where('id',$id)->update($header);
            else DB::table('wh_v3_production_results')->insert($header + [
                'id'=>$id,'result_number'=>$this->number('PRODRES'),'created_by_user_id'=>$userId,'created_at'=>$now,
            ]);

            DB::table('wh_v3_production_result_items')->where('result_id',$id)->delete();
            $positive = 0;
            foreach ($payload['items'] as $index => $line) {
                $output = DB::table('wh_production_outputs')->where('production_id',$productionId)->where('id',$line['production_output_id'])->first();
                if (! $output) throw ValidationException::withMessages(["items.{$index}.production_output_id"=>['Item bukan estimasi hasil Production Order ini.']]);
                $qtyUom = round((float)($line['qty_uom'] ?? 0),4);
                if ($qtyUom < 0) throw ValidationException::withMessages(["items.{$index}.qty_uom"=>['Qty hasil tidak boleh negatif.']]);
                if ($qtyUom <= 0) continue;
                $positive++;
                $qtyBase = round($qtyUom * (float)$output->conversion_factor_snapshot,4);
                DB::table('wh_v3_production_result_items')->insert([
                    'id'=>(string)Str::ulid(),'result_id'=>$id,'production_output_id'=>$output->id,'sku_id'=>$output->sku_id,'uom_id'=>$output->output_uom_id,
                    'uom_code_snapshot'=>$output->output_uom_code_snapshot,'uom_name_snapshot'=>$output->output_uom_name_snapshot,
                    'qty_uom'=>$qtyUom,'conversion_factor_snapshot'=>$output->conversion_factor_snapshot,'base_uom_id_snapshot'=>$output->base_uom_id,'base_uom_code_snapshot'=>$output->base_uom_code_snapshot,'base_uom_name_snapshot'=>$output->base_uom_name_snapshot,'qty_base'=>$qtyBase,'storage_id'=>$line['storage_id'] ?? null,
                    'unit_cost_snapshot'=>0,'total_cost_snapshot'=>0,'status'=>'draft','notes'=>$line['notes'] ?? null,'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
            if ($positive === 0) throw ValidationException::withMessages(['items'=>['Minimal satu hasil produksi harus memiliki Qty lebih besar dari nol.']]);
            $this->event($productionId,$result ? 'production_result_draft_updated' : 'production_result_draft_created',$userId,['result_id'=>$id,'result_date'=>$payload['result_date']]);
            return (string)$id;
        },5);
        return $this->resultDetail($warehouseId,$productionId,$id);
    }

    public function rejectResult(string $warehouseId, string $productionId, string $resultId, string $reason, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$productionId,$resultId,$reason,$userId): void {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string)$production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Reject hasil hanya dapat dilakukan saat Production Order ongoing.']]);
            $result = DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            if ((string)$result->status === 'approved') throw ValidationException::withMessages(['status'=>['Approved Production Result tidak dapat di-reject.']]);
            if ((string)$result->status === 'rejected') return;
            if ((string)$result->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya Draft Production Result yang dapat di-reject.']]);
            $this->assertResultUnposted($resultId, $result);

            DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->update(['status'=>'rejected','updated_at'=>now()]);
            DB::table('wh_v3_production_results')->where('id',$resultId)->update([
                'status'=>'rejected','rejection_reason'=>trim($reason),'rejected_by_user_id'=>$userId,'rejected_at'=>now(),
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            $this->event($productionId,'production_result_rejected',$userId,[
                'result_id'=>$resultId,'result_number'=>(string)$result->result_number,'reason'=>trim($reason),
            ]);
        },5);
        return $this->resultDetail($warehouseId,$productionId,$resultId);
    }

    public function deleteResult(string $warehouseId, string $productionId, string $resultId, string $userId): array
    {
        $deleted = DB::transaction(function () use ($warehouseId,$productionId,$resultId,$userId): array {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string)$production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Production Result hanya dapat dihapus saat Production Order ongoing.']]);
            $result = DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            if (! in_array((string)$result->status,['draft','rejected'],true)) throw ValidationException::withMessages(['status'=>['Hanya Draft/Rejected Production Result yang dapat dihapus. Approved Result immutable.']]);
            $this->assertResultUnposted($resultId, $result);
            $items = DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->lockForUpdate()->get(['id','sku_id','qty_uom','qty_base','status']);
            $snapshot = [
                'result_id'=>$resultId,'result_number'=>(string)$result->result_number,'status'=>(string)$result->status,
                'result_date'=>$result->result_date,'notes'=>$result->notes,'items'=>$items->map(fn($i)=>[
                    'id'=>(string)$i->id,'sku_id'=>(string)$i->sku_id,'qty_uom'=>(float)$i->qty_uom,'qty_base'=>(float)$i->qty_base,'status'=>(string)$i->status,
                ])->values()->all(),
            ];
            $this->event($productionId,'production_result_deleted',$userId,$snapshot);
            DB::table('wh_v3_production_results')->where('id',$resultId)->delete();
            return ['id'=>$resultId,'result_number'=>(string)$result->result_number,'status'=>(string)$result->status,'deleted'=>true];
        },5);
        return $deleted;
    }

    private function assertResultUnposted(string $resultId, object $result): void
    {
        if ($result->ledger_posting_id || $result->idempotency_key || $result->approved_at || $result->approved_by_user_id) {
            throw ValidationException::withMessages(['result'=>['Production Result sudah memiliki jejak approval/ledger dan tidak dapat diubah atau dihapus.']]);
        }
        $unsafeItem = DB::table('wh_v3_production_result_items')->where('result_id',$resultId)
            ->where(function($q):void{$q->where('status','approved')->orWhereNotNull('batch_id')->orWhere('unit_cost_snapshot','>',0)->orWhere('total_cost_snapshot','>',0);})
            ->exists();
        if ($unsafeItem) throw ValidationException::withMessages(['result'=>['Production Result memiliki batch/cost/approved item. Delete/reject diblokir untuk menjaga stock ledger.']]);
        $batchExists = DB::table('wh_batches')->where('source_reference_type','wh_v3_production_result')->where('source_reference_id',$resultId)->exists();
        if ($batchExists) throw ValidationException::withMessages(['result'=>['Batch hasil produksi sudah pernah dibuat. Delete/reject diblokir.']]);
    }

    public function approveResult(string $warehouseId, string $productionId, string $resultId, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$productionId,$resultId,$userId): void {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if ((string)$production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Production Order tidak lagi ongoing.']]);
            $this->productionOpname->requireFinalizedBeforeResult($productionId);
            $result = DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('id',$resultId)->lockForUpdate()->first();
            if (! $result) abort(404);
            if ((string)$result->status === 'approved' && $result->ledger_posting_id) return;
            if ((string)$result->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya draft hasil produksi yang dapat di-approve.']]);

            $items = DB::table('wh_v3_production_result_items')->where('result_id',$resultId)->where('qty_base','>',0)->lockForUpdate()->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items'=>['Tidak ada hasil produksi positif.']]);
            $estimatedTotal = (float) DB::table('wh_production_outputs')->where('production_id',$productionId)->sum('estimated_qty_base');
            $costPool = max((float)$production->actual_input_value + (float)($production->labor_cost ?? 0) + (float)($production->overhead_cost ?? 0), 0);
            $defaultUnitCost = $estimatedTotal > 0 ? round($costPool / $estimatedTotal, 6) : 0.0;
            $ledgerLines = []; $snapshot = []; $totalQty = 0.0; $totalValue = 0.0;

            foreach ($items as $item) {
                $storage = $this->resolveStorage($warehouseId,$item->storage_id,$userId);
                $batch = WarehouseBatch::query()->withTrashed()
                    ->where('warehouse_id',$warehouseId)->where('source_reference_type','wh_v3_production_result')
                    ->where('source_reference_id',$resultId)->where('source_reference_line_id',$item->id)->lockForUpdate()->first();
                if ($batch && $batch->trashed()) $batch->restore();
                if (! $batch) {
                    $batch = WarehouseBatch::query()->create([
                        'warehouse_id'=>$warehouseId,'sku_id'=>$item->sku_id,'storage_id'=>$storage->id,
                        'batch_code'=>$this->batchNumber($production->production_number,$result->result_number,(string)$item->id),
                        'source_type'=>'PRODUCTION_V3','source_reference_type'=>'wh_v3_production_result','source_reference_id'=>$resultId,'source_reference_line_id'=>$item->id,
                        'received_at'=>now(),'production_date'=>$result->result_date,'quantity_received_base'=>0,'actual_unit_cost'=>$defaultUnitCost,
                        'price_min'=>$defaultUnitCost,'price_avg'=>$defaultUnitCost,'price_max'=>$defaultUnitCost,'status'=>'draft','notes'=>$item->notes,
                        'metadata'=>['flow_version'=>3,'production_id'=>$productionId,'result_id'=>$resultId,'barcode_generated'=>false],
                        'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                    ]);
                }
                if ((string)$batch->storage_id !== (string)$storage->id) throw ValidationException::withMessages(['storage_id'=>['Batch hasil produksi sudah terikat ke storage lain.']]);
                $qty = round((float)$item->qty_base,4); $value = round($qty*$defaultUnitCost,2);
                $ledgerLines[] = [
                    'line_key'=>'PROD-V3-RESULT:'.$item->id,'sku_id'=>(string)$item->sku_id,'batch_id'=>(string)$batch->id,'storage_id'=>(string)$storage->id,
                    'direction'=>'IN','quantity_base'=>$qty,'unit_cost'=>$defaultUnitCost,
                    'metadata'=>['flow_version'=>3,'production_id'=>$productionId,'result_id'=>$resultId,'production_result_item_id'=>(string)$item->id,'barcode_used'=>false],
                ];
                DB::table('wh_v3_production_result_items')->where('id',$item->id)->update([
                    'storage_id'=>$storage->id,'batch_id'=>$batch->id,'unit_cost_snapshot'=>$defaultUnitCost,'total_cost_snapshot'=>$value,'status'=>'approved','updated_at'=>now(),
                ]);
                $snapshot[]=['item_id'=>(string)$item->id,'sku_id'=>(string)$item->sku_id,'qty_base'=>$qty,'storage_id'=>(string)$storage->id,'batch_id'=>(string)$batch->id,'unit_cost'=>$defaultUnitCost];
                $totalQty += $qty; $totalValue += $value;
            }

            $fingerprint = hash('sha256', json_encode($snapshot));
            $key = 'WAREHOUSE-V3-PRODUCTION-RESULT:'.$resultId;
            if ($result->idempotency_key && ($result->idempotency_key !== $key || $result->payload_fingerprint !== $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key'=>['Hasil produksi sudah terikat ke payload berbeda.']]);
            }
            $posting = $this->ledger->post([
                'warehouse_id'=>$warehouseId,'idempotency_key'=>$key,'movement_type'=>'production_in','reference_type'=>'wh_v3_production_result','reference_id'=>$resultId,
                'business_date'=>$result->result_date,'reason'=>'Production Result '.$result->result_number,
                'metadata'=>['flow_version'=>3,'production_id'=>$productionId,'result_number'=>$result->result_number,'barcode_used'=>false],
                'user_id'=>$userId,'lines'=>$ledgerLines,
            ]);

            DB::table('wh_v3_production_results')->where('id',$resultId)->update([
                'status'=>'approved','ledger_posting_id'=>$posting->id,'idempotency_key'=>$key,'payload_fingerprint'=>$fingerprint,
                'total_qty_base'=>round($totalQty,4),'inventory_value'=>round($totalValue,2),'approved_by_user_id'=>$userId,'approved_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
            foreach ($items as $item) {
                $sumBase = (float) DB::table('wh_v3_production_result_items as ri')->join('wh_v3_production_results as r','r.id','=','ri.result_id')
                    ->where('r.production_id',$productionId)->where('r.status','approved')->where('ri.production_output_id',$item->production_output_id)->sum('ri.qty_base');
                $sumUom = (float) DB::table('wh_v3_production_result_items as ri')->join('wh_v3_production_results as r','r.id','=','ri.result_id')
                    ->where('r.production_id',$productionId)->where('r.status','approved')->where('ri.production_output_id',$item->production_output_id)->sum('ri.qty_uom');
                $output = DB::table('wh_production_outputs')->where('id',$item->production_output_id)->first();
                DB::table('wh_production_outputs')->where('id',$item->production_output_id)->update([
                    'actual_qty_base'=>round($sumBase,4),'actual_qty_uom'=>round($sumUom,4),'yield_variance_qty_base'=>round($sumBase-(float)$output->estimated_qty_base,4),
                    'actual_unit_cost'=>$defaultUnitCost,'allocated_cost'=>round($sumBase*$defaultUnitCost,2),
                    'status'=>$sumBase+0.0001 >= (float)$output->estimated_qty_base ? 'produced' : 'partially_produced','updated_at'=>now(),
                ]);
            }
            $actualOutputValue = (float) DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->sum('inventory_value');
            DB::table('wh_productions')->where('id',$productionId)->update([
                'actual_output_value'=>round($actualOutputValue,2),'output_ledger_posting_id'=>$production->output_ledger_posting_id ?: $posting->id,
                'updated_by_user_id'=>$userId,'lock_version'=>DB::raw('lock_version + 1'),'updated_at'=>now(),
            ]);
            $this->event($productionId,'production_result_approved',$userId,['result_id'=>$resultId,'result_number'=>$result->result_number,'ledger_posting_id'=>(string)$posting->id,'qty_base'=>round($totalQty,4),'inventory_value'=>round($totalValue,2)],'PROD-V3-RESULT-APPROVED:'.$resultId);
        },5);
        return $this->resultDetail($warehouseId,$productionId,$resultId);
    }

    public function finish(string $warehouseId, string $productionId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($warehouseId,$productionId,$payload,$userId): void {
            $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('id',$productionId)->lockForUpdate()->first();
            if (! $production) abort(404);
            if (in_array((string)$production->status,['completed','approved'],true)) return;
            if ((string)$production->status !== 'on_progress') throw ValidationException::withMessages(['status'=>['Hanya ongoing production yang dapat di-finish.']]);
            $this->productionOpname->requireFinalizedBeforeResult($productionId);
            if (DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','draft')->exists()) {
                throw ValidationException::withMessages(['results'=>['Masih ada hasil produksi draft. Approve atau hapus draft sebelum Production Finished.']]);
            }
            if (! DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->exists()) {
                throw ValidationException::withMessages(['results'=>['Minimal satu hasil produksi approved diperlukan sebelum Production Finished.']]);
            }

            // Warehouse v4 Iteration 01: a Production Order may only become
            // Finished after both material OUT and every approved result IN
            // are durably posted to the Warehouse Ledger and projected to the
            // aggregate stock movement. This prevents a visually-completed
            // production from drifting away from actual stock.
            $this->assertStockLedgerComplete($warehouseId, $productionId, $production);

            $recon = $this->reconciliation($productionId);
            DB::table('wh_productions')->where('id',$productionId)->update([
                'status'=>'completed','production_done_by_user_id'=>$userId,'production_done_at'=>now(),'finished_by_user_id'=>$userId,'finished_at'=>now(),
                'yield_percent'=>$recon['yield_percent'],'yield_variance_value'=>round((float)$production->actual_output_value-(float)$production->actual_input_value,2),
                'notes'=>trim((string)($payload['notes'] ?? '')) !== '' ? $payload['notes'] : $production->notes,
                'updated_by_user_id'=>$userId,'lock_version'=>DB::raw('lock_version + 1'),'updated_at'=>now(),
            ]);
            $this->event($productionId,'production_finished',$userId,['reconciliation'=>$recon,'notes'=>$payload['notes'] ?? null],'PROD-V3-FINISHED:'.$productionId);
        },5);
        return $this->detail($warehouseId,$productionId);
    }

    public function resultDetail(string $warehouseId, string $productionId, string $resultId): array
    {
        $row = DB::table('wh_v3_production_results as result')
            ->leftJoin('users as creator','creator.id','=','result.created_by_user_id')->leftJoin('users as approver','approver.id','=','result.approved_by_user_id')
            ->leftJoin('users as rejector','rejector.id','=','result.rejected_by_user_id')
            ->where('result.warehouse_id',$warehouseId)->where('result.production_id',$productionId)->where('result.id',$resultId)
            ->select('result.*','creator.name as creator_name','approver.name as approver_name','rejector.name as rejector_name')->first();
        if (! $row) abort(404);
        return $this->resultRow($row,true);
    }

    private function resultRow(object $row, bool $withItems = false): array
    {
        $data = [
            'id'=>(string)$row->id,'result_number'=>(string)$row->result_number,'production_id'=>(string)$row->production_id,'result_date'=>$row->result_date,
            'status'=>(string)$row->status,'total_qty_base'=>(float)$row->total_qty_base,'inventory_value'=>(float)$row->inventory_value,'notes'=>$row->notes,
            'ledger_posting_id'=>$row->ledger_posting_id,'created_by'=>$row->creator_name ?? null,'approved_by'=>$row->approver_name ?? null,'approved_at'=>$row->approved_at,
            'rejected_by'=>$row->rejector_name ?? null,'rejected_at'=>$row->rejected_at ?? null,'rejection_reason'=>$row->rejection_reason ?? null,'created_at'=>$row->created_at,
        ];
        if ($withItems) {
            $data['items'] = DB::table('wh_v3_production_result_items as item')->join('stk_skus as sku','sku.id','=','item.sku_id')->leftJoin('stk_uoms as uom','uom.id','=','item.uom_id')
                ->leftJoin('wh_storages as storage','storage.id','=','item.storage_id')->leftJoin('wh_batches as batch','batch.id','=','item.batch_id')
                ->where('item.result_id',$row->id)->orderBy('sku.name')->get([
                    'item.*','sku.sku_code','sku.name as item_name','uom.code as uom_code','storage.code as storage_code','storage.name as storage_name','batch.batch_code',
                ])->map(fn ($item) => [
                    'id'=>(string)$item->id,'production_output_id'=>(string)$item->production_output_id,'sku_id'=>(string)$item->sku_id,'sku_code'=>(string)$item->sku_code,
                    'item_name'=>(string)$item->item_name,'uom_id'=>(string)$item->uom_id,'uom_code'=>(string)($item->uom_code_snapshot ?: $item->uom_code ?: 'UNIT'),'uom_name'=>(string)($item->uom_name_snapshot ?: 'Unit'),'qty_uom'=>(float)$item->qty_uom,
                    'conversion_factor_snapshot'=>(float)$item->conversion_factor_snapshot,'base_uom_id'=>$item->base_uom_id_snapshot,'base_uom_code'=>(string)($item->base_uom_code_snapshot ?: 'UNIT'),'base_uom_name'=>(string)($item->base_uom_name_snapshot ?: 'Unit'),'qty_base'=>(float)$item->qty_base,'storage_id'=>$item->storage_id,'storage_code'=>$item->storage_code,'storage_name'=>$item->storage_name,'batch_id'=>$item->batch_id,
                    'batch_code'=>$item->batch_code,'unit_cost_snapshot'=>(float)$item->unit_cost_snapshot,'total_cost_snapshot'=>(float)$item->total_cost_snapshot,'status'=>(string)$item->status,'notes'=>$item->notes,
                ])->values()->all();
        }
        return $data;
    }

    private function assertStockLedgerComplete(string $warehouseId, string $productionId, object $production): void
    {
        $inputPostingId = trim((string) ($production->input_ledger_posting_id ?? ''));
        $carryOnly = false;
        if ($inputPostingId === '') {
            $carryOnly = (int)($production->flow_version ?? 0) >= 7
                && DB::table('wh_v7_production_material_allocations')->where('production_id',$productionId)->exists()
                && ! $this->productionOpname->hasWarehouseIssue($productionId);
            if (! $carryOnly) throw ValidationException::withMessages(['stock_ledger'=>['Material Production belum memiliki posting stock OUT.']]);
        }
        $inputPosting = $inputPostingId !== '' ? DB::table('wh_ledger_postings')->where('id',$inputPostingId)->where('warehouse_id',$warehouseId)->where('movement_type','production_out')->where('status','posted')->first(['id']) : null;
        if ($inputPostingId !== '' && (! $inputPosting || ! DB::table('wh_ledger_entries')->where('posting_id',$inputPostingId)->exists())) throw ValidationException::withMessages(['stock_ledger'=>['Posting material OUT Production belum valid/posted.']]);

        $approvedResults = DB::table('wh_v3_production_results')
            ->where('production_id', $productionId)
            ->where('status', 'approved')
            ->get(['id', 'result_number', 'ledger_posting_id']);

        $postingIds = $inputPostingId !== '' ? [$inputPostingId] : [];
        foreach ($approvedResults as $result) {
            $postingId = trim((string) ($result->ledger_posting_id ?? ''));
            if ($postingId === '') {
                throw ValidationException::withMessages([
                    'stock_ledger' => [sprintf('Production Result %s belum memiliki posting finished-goods IN.', $result->result_number)],
                ]);
            }

            $valid = DB::table('wh_ledger_postings')
                ->where('id', $postingId)
                ->where('warehouse_id', $warehouseId)
                ->where('movement_type', 'production_in')
                ->where('reference_type', 'wh_v3_production_result')
                ->where('reference_id', (string) $result->id)
                ->where('status', 'posted')
                ->exists();

            if (! $valid || ! DB::table('wh_ledger_entries')->where('posting_id', $postingId)->exists()) {
                throw ValidationException::withMessages([
                    'stock_ledger' => [sprintf('Posting finished-goods IN untuk %s belum valid/posted.', $result->result_number)],
                ]);
            }

            $postingIds[] = $postingId;
        }

        if (DB::table('wh_ledger_entries')->whereIn('posting_id', $postingIds)->whereNull('projection_movement_id')->exists()) {
            throw ValidationException::withMessages([
                'stock_ledger' => ['Ada Warehouse Ledger entry Production yang belum diproyeksikan ke actual/current stock. Jalankan rekonsiliasi ledger sebelum finish.'],
            ]);
        }
    }

    private function reconciliation(string $productionId): array
    {
        $plannedMaterial = (float) DB::table('wh_production_inputs')->where('production_id',$productionId)->sum('planned_qty_base');
        $actualMaterial = (float) DB::table('wh_production_inputs')->where('production_id',$productionId)->sum('actual_qty_base');
        $estimatedOutput = (float) DB::table('wh_production_outputs')->where('production_id',$productionId)->sum('estimated_qty_base');
        $actualOutput = (float) DB::table('wh_production_outputs')->where('production_id',$productionId)->sum('actual_qty_base');
        $yield = $estimatedOutput > 0 ? round(($actualOutput/$estimatedOutput)*100,4) : 0.0;
        return [
            'planned_material_qty_base'=>round($plannedMaterial,4),'actual_material_qty_base'=>round($actualMaterial,4),
            'material_variance_qty_base'=>round($actualMaterial-$plannedMaterial,4),'estimated_output_qty_base'=>round($estimatedOutput,4),
            'actual_output_qty_base'=>round($actualOutput,4),'output_variance_qty_base'=>round($actualOutput-$estimatedOutput,4),
            'yield_percent'=>$yield,'estimated_waste_or_shortfall_qty_base'=>round(max($estimatedOutput-$actualOutput,0),4),
            'approved_result_count'=>DB::table('wh_v3_production_results')->where('production_id',$productionId)->where('status','approved')->count(),
        ];
    }

    private function timeline(string $productionId): array
    {
        $productionEvents = DB::table('wh_production_events')->where('production_id',$productionId)->orderBy('occurred_at')->get()
            ->map(fn ($e) => ['source'=>'production','event_type'=>(string)$e->event_type,'occurred_at'=>$e->occurred_at,'actor_user_id'=>$e->actor_user_id,'payload'=>$this->json($e->payload)]);
        $requestId = DB::table('wh_productions')->where('id',$productionId)->value('production_request_id');
        $requestEvents = $requestId ? DB::table('wh_v3_sales_demand_events')->where('document_type','production_request')->where('document_id',$requestId)->orderBy('occurred_at')->get()
            ->map(fn ($e) => ['source'=>'production_request','event_type'=>(string)$e->event_type,'occurred_at'=>$e->occurred_at,'actor_user_id'=>$e->actor_user_id,'payload'=>array_merge($this->json($e->metadata),['message'=>$e->message,'from_status'=>$e->from_status,'to_status'=>$e->to_status])]) : collect();
        return $productionEvents->concat($requestEvents)->sortBy('occurred_at')->values()->all();
    }

    private function event(string $productionId, string $type, ?string $userId, array $payload = [], ?string $key = null): void
    {
        if ($key && DB::table('wh_production_events')->where('idempotency_key',$key)->exists()) return;
        DB::table('wh_production_events')->insert([
            'id'=>(string)Str::ulid(),'production_id'=>$productionId,'event_type'=>$type,'idempotency_key'=>$key,
            'payload'=>json_encode($payload),'actor_user_id'=>$userId,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function resolveStorage(string $warehouseId, ?string $storageId, ?string $userId): WarehouseStorage
    {
        $storageId = trim((string)$storageId);
        if ($storageId !== '') {
            $storage = WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->find($storageId);
            if (! $storage) throw ValidationException::withMessages(['storage_id'=>['Storage tidak aktif atau bukan milik Warehouse terpilih.']]);
            return $storage;
        }
        $storage = WarehouseStorage::query()->withTrashed()->where('warehouse_id',$warehouseId)->where('code','UNCATEGORIZED')->first();
        if ($storage) {
            if ($storage->trashed()) $storage->restore();
            if (! $storage->is_active) $storage->forceFill(['is_active'=>true,'updated_by_user_id'=>$userId])->save();
            return $storage;
        }
        return WarehouseStorage::query()->create([
            'warehouse_id'=>$warehouseId,'code'=>'UNCATEGORIZED','name'=>'Uncategorized','storage_type'=>'other',
            'position_description'=>'Default system storage untuk hasil Production Warehouse v3 saat storage tidak dipilih.',
            'is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
        ]);
    }

    private function batchNumber(string $productionNumber, string $resultNumber, string $itemId): string
    {
        $a = preg_replace('/[^A-Z0-9]/','',strtoupper($productionNumber));
        $b = preg_replace('/[^A-Z0-9]/','',strtoupper($resultNumber));
        return Str::limit('PRD3-'.substr($a,-8).'-'.substr($b,-8).'-'.strtoupper(substr($itemId,-6)), 100, '');
    }

    private function summaryRow(object $row): array
    {
        return [
            'id'=>(string)$row->id,'production_number'=>(string)$row->production_number,'production_date'=>$row->production_date,'status'=>(string)$row->status,'notes'=>$row->notes,
            'material_count'=>(int)$row->material_count,'planned_material_qty'=>(float)$row->planned_material_qty,'actual_material_qty'=>(float)$row->actual_material_qty,
            'output_count'=>(int)$row->output_count,'estimated_output_qty'=>(float)$row->estimated_output_qty,'actual_output_qty'=>(float)$row->actual_output_qty,
            'approved_result_count'=>(int)$row->approved_result_count,'draft_result_count'=>(int)$row->draft_result_count,'rejected_result_count'=>(int)($row->rejected_result_count ?? 0),
            'material_opname_status'=>$row->material_opname_status ?: null,'material_remaining_qty_base'=>round((float)($row->material_remaining_qty_base ?? 0),4),
            'planned_input_value'=>(float)$row->planned_input_value,'actual_input_value'=>(float)$row->actual_input_value,'actual_output_value'=>(float)$row->actual_output_value,
            'yield_percent'=>(float)$row->yield_percent,'production_request_id'=>$row->production_request_id,'order_approved_at'=>$row->order_approved_at,'finished_at'=>$row->finished_at,
            'created_by'=>$row->creator_name ? ['name'=>$row->creator_name,'nisj'=>$row->creator_nisj] : null,
            'order_approved_by'=>$row->approver_name ? ['name'=>$row->approver_name,'nisj'=>$row->approver_nisj] : null,
        ];
    }

    private function person(mixed $id, mixed $name, mixed $nisj): ?array
    {
        return $id ? ['id'=>(string)$id,'name'=>$name ? (string)$name : null,'nisj'=>$nisj ? (string)$nisj : null] : null;
    }

    private function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return ['items'=>collect($paginator->items())->map($map)->values()->all(),'pagination'=>[
            'current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total(),
        ]];
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.strtoupper(substr((string)Str::ulid(),-8));
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array)$value;
        $decoded = json_decode((string)$value,true);
        return is_array($decoded) ? $decoded : [];
    }
}
