<?php

namespace App\Services\Warehouse\ProductionV13;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cost reconciliation monitor. Never books valuation differences automatically. */
class ProductionCostStateI21Service
{
    public function estimatePool(string $warehouseId, string $productionId, object $production): float
    {
        $opnameDone = DB::table('wh_v7_production_material_opnames')
            ->where('warehouse_id',$warehouseId)->where('production_id',$productionId)
            ->where('status','finalized')->exists();
        $material = $opnameDone
            ? (float) $production->actual_input_value
            : (float) DB::table('wh_v7_production_material_allocations')
                ->where('warehouse_id',$warehouseId)->where('production_id',$productionId)
                ->sum('available_value');
        if (!$opnameDone && $material <= 0) {
            $material = (float) ($production->planned_input_value ?? 0);
        }
        return round(max(0, $material) + max(0, (float) ($production->labor_cost ?? 0))
            + max(0, (float) ($production->overhead_cost ?? 0)), 2);
    }

    public function sync(string $warehouseId, string $productionId): void
    {
        $production = DB::table('wh_productions')->where('warehouse_id',$warehouseId)
            ->where('id',$productionId)->first();
        if (!$production) throw ValidationException::withMessages(['production' => ['Produksi tidak ditemukan.']]);
        $opname = DB::table('wh_v7_production_material_opnames')
            ->where('production_id',$productionId)->where('warehouse_id',$warehouseId)->first();
        $finalized = (string) ($opname->status ?? '') === 'finalized';
        $posted = DB::table('wh_v3_production_results')->where('production_id',$productionId)
            ->where('status','approved')->whereNotNull('ledger_posting_id');
        $count = (clone $posted)->count();
        $booked = round((float) (clone $posted)->sum('inventory_value'),2);
        $totalEstimated = (float) DB::table('wh_production_outputs')
            ->where('production_id',$productionId)->sum('estimated_qty_base');
        $totalActual = (float) DB::table('wh_production_outputs')
            ->where('production_id',$productionId)->sum('actual_qty_base');
        $pool = $this->estimatePool($warehouseId, $productionId, $production);
        $expected = $totalEstimated > 0 ? round($pool * min($totalActual/$totalEstimated,1),2) : 0;
        $difference = round($expected-$booked,2);
        $status = !$count ? ($finalized ? 'no_output' : 'pending_output')
            : (!$finalized ? 'provisional' : (abs($difference) > .01 ? 'needs_revaluation' : 'aligned'));

        // Preserve primary key and original created_at across retries.
        $existing = DB::table('wh_v13_i21_production_cost_states')->where('production_id',$productionId)->first();
        $values = [
            'warehouse_id'=>$warehouseId,'status'=>$status,
            'posted_output_value'=>$booked,'expected_output_value'=>$expected,
            'delta_value'=>$difference,'opname_finalized_at'=>$finalized ? $opname->finalized_at : null,
            'approved_result_count'=>$count,'updated_at'=>now(),
        ];
        if ($existing) DB::table('wh_v13_i21_production_cost_states')->where('production_id',$productionId)->update($values);
        else DB::table('wh_v13_i21_production_cost_states')->insert($values + [
            'production_id'=>$productionId, 'created_at'=>now(),
        ]);
    }

    public function detail(string $warehouseId,string $productionId): array
    {
        $row = DB::table('wh_v13_i21_production_cost_states')
            ->where('warehouse_id',$warehouseId)->where('production_id',$productionId)->first();
        if (!$row) {
            $hasOpname = DB::table('wh_v7_production_material_opnames')
                ->where('production_id',$productionId)->where('status','finalized')->exists();
            return ['status'=>$hasOpname ? 'legacy_unverified' : 'pending_opname',
                'posted_output_value'=>null,'expected_output_value'=>null,'delta_value'=>null,
                'cogs_final'=>false,'message'=>'Data biaya historis atau belum direkonsiliasi.'];
        }
        $status = (string) $row->status;
        return [
            'status'=>$status,
            'posted_output_value'=>(float)$row->posted_output_value,
            'expected_output_value'=>(float)$row->expected_output_value,
            'delta_value'=>(float)$row->delta_value,
            'approved_result_count'=>(int)$row->approved_result_count,
            'cogs_final'=>false, // I21 is observational; Finance/COGS final reconciliation is separate.
            'message'=>match($status) {
                'provisional'=>'Hasil sudah menambah stok Warehouse; nilai HPP masih sementara sampai opname.',
                'needs_revaluation'=>'Opname final. Ada selisih biaya terhadap ledger; perlu adjustment resmi sebelum COGS final.',
                'aligned'=>'Opname final dan estimasi sejajar; finalitas COGS masih memerlukan rekonsiliasi Finance.',
                default=>'Nilai akhir COGS belum diverifikasi.',
            },
        ];
    }

    /** Read-only Production Stock: raw carry-forward is distinct from already-posted finished goods. */
    public function stock(string $warehouseId, array $filters = []): array
    {
        $q = DB::table('wh_v7_production_stock_balances as b')
            ->join('stk_skus as s','s.id','=','b.sku_id')
            ->leftJoin('stk_uoms as u','u.id','=','s.base_uom_id')
            ->where('b.warehouse_id',$warehouseId);
        if (!empty($filters['q'])) {
            $search = '%'.trim($filters['q']).'%';
            $q->where(fn($x)=>$x->where('s.name','like',$search)->orWhere('s.sku_code','like',$search));
        }
        $p = $q->select('b.sku_id','s.name as sku_name','s.sku_code','u.code as uom',
                'b.qty_base','b.inventory_value','b.average_unit_cost','b.last_opname_at')
            ->orderBy('s.name')->paginate(min(max((int)($filters['per_page']??30),1),100));
        return [
            'items'=>$p->getCollection()->map(fn($r)=>[
                'sku_id'=>(string)$r->sku_id,'sku_name'=>$r->sku_name,'sku_code'=>$r->sku_code,
                'uom'=>$r->uom ?: 'BASE','qty_base'=>(float)$r->qty_base,
                'inventory_value'=>(float)$r->inventory_value,
                'unit_cost'=>(float)$r->average_unit_cost,'last_opname_at'=>$r->last_opname_at,
            ])->values()->all(),
            'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'total'=>$p->total()],
            'summary'=>[
                'description'=>'Carry-forward bahan produksi yang belum dialokasikan; bukan stok hasil produksi kedua.',
                'finished_goods'=>'Hasil Approved sudah tercatat di Warehouse Ledger; tidak dihitung ulang sebagai saldo Production Stock.',
            ],
        ];
    }
}
