<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ProductionCogs\I07;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ProductionCogs\I07\WarehouseProductionCogsReconciliationI07Service;
use App\Services\Warehouse\ProductionV3\WarehouseProductionV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseProductionCogsI07Controller extends Controller
{
    public function __construct(private readonly WarehouseProductionCogsReconciliationI07Service $cogs, private readonly WarehouseProductionV3Service $production) {}
    public function index(Request $r): JsonResponse { $p=$r->validate(['from'=>'nullable|date','to'=>'nullable|date','q'=>'nullable|string|max:120','finance_status'=>'nullable|in:UNRECONCILED,NOT_POSTED,POSTED,ADJUSTMENT_REQUIRED','per_page'=>'nullable|integer|in:10,25,50,100','page'=>'nullable|integer|min:1']); return ApiResponse::ok($this->cogs->index($this->wh($r),$p)); }
    public function show(Request $r,string $id): JsonResponse { return ApiResponse::ok($this->cogs->detail($this->wh($r),$id)); }
    public function reconcile(Request $r,string $id): JsonResponse { return ApiResponse::ok($this->cogs->reconcile($this->wh($r),$id,(string)$r->user()->id),'Production COGS berhasil direconcile.'); }
    public function post(Request $r,string $id): JsonResponse { return ApiResponse::ok($this->cogs->post($this->wh($r),$id,(string)$r->user()->id),'Finance Production sudah sinkron dengan reconciliation.'); }
    public function reconcilePost(Request $r,string $id): JsonResponse { return ApiResponse::ok($this->cogs->reconcileAndPost($this->wh($r),$id,(string)$r->user()->id),'COGS reconciliation dan Finance posting selesai.'); }
    public function backfill(Request $r): JsonResponse { $p=$r->validate(['from'=>'nullable|date','to'=>'nullable|date']); return ApiResponse::ok($this->cogs->backfill($this->wh($r),$p,(string)$r->user()->id),'Backfill COGS selesai.'); }
    public function finishAndReconcile(Request $r,string $id): JsonResponse { $p=$r->validate(['notes'=>'nullable|string|max:2000']); $detail=$this->production->finish($this->wh($r),$id,$p,(string)$r->user()->id); $recon=$this->cogs->reconcileAndPost($this->wh($r),$id,(string)$r->user()->id); $detail['cogs_reconciliation']=$recon; return ApiResponse::ok($detail,'Production Finished, COGS reconciled, dan Finance posted.'); }
    private function wh(Request $r):string{$id=trim((string)$r->attributes->get('warehouse_scope_id',''));abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');return$id;}
}
