<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV8;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV8\WarehouseFinanceCanonicalBridgeV8Service;
use App\Services\Warehouse\FinanceV8\WarehouseFinanceReportingV8Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WarehouseFinanceReportingV8Controller extends Controller
{
    public function __construct(
        private readonly WarehouseFinanceReportingV8Service $reports,
        private readonly WarehouseFinanceCanonicalBridgeV8Service $bridge,
    ) {}

    public function status(Request $request): JsonResponse
    { return response()->json(['data'=>$this->bridge->status()+['scope'=>$this->scopeMeta($request)]]); }

    public function coa(Request $request): JsonResponse
    { return response()->json(['data'=>$this->reports->coa($this->reportWarehouseIds($request))]); }

    public function generalLedger(Request $request): JsonResponse
    { return response()->json(['data'=>$this->reports->generalLedger($this->reportWarehouseIds($request),$this->periodFilters($request))]); }

    public function generalLedgerTransactions(Request $request,string $accountId): JsonResponse
    { return response()->json(['data'=>$this->reports->generalLedgerTransactions($this->reportWarehouseIds($request),$accountId,$this->periodFilters($request))]); }

    public function balanceSheet(Request $request): JsonResponse
    {
        $filters=$request->validate(['scope'=>['nullable','in:selected,all'],'as_of'=>['nullable','date'],'compare_as_of'=>['nullable','date']]);
        return response()->json(['data'=>$this->reports->balanceSheet($this->reportWarehouseIds($request),$filters)]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'scope'=>['nullable','in:selected,all'],'date_from'=>['nullable','date'],'date_to'=>['nullable','date','after_or_equal:date_from'],
            'compare_from'=>['nullable','date'],'compare_to'=>['nullable','date','after_or_equal:compare_from'],
        ]);
        return response()->json(['data'=>$this->reports->profitLoss($this->reportWarehouseIds($request),$filters)]);
    }

    public function cashFlow(Request $request): JsonResponse
    { return response()->json(['data'=>$this->reports->cashFlow($this->reportWarehouseIds($request),$this->periodFilters($request))]); }

    public function exportCoa(Request $request): Response { return $this->csvResponse($request,'coa'); }
    public function exportGeneralLedger(Request $request): Response { return $this->csvResponse($request,'general-ledger'); }
    public function exportBalanceSheet(Request $request): Response { return $this->csvResponse($request,'balance-sheet'); }
    public function exportCashFlow(Request $request): Response { return $this->csvResponse($request,'cash-flow'); }
    public function exportProfitLoss(Request $request): Response { return $this->csvResponse($request,'profit-loss'); }

    private function csvResponse(Request $request,string $report): Response
    {
        $csv=$this->reports->csv($report,$this->reportWarehouseIds($request),$request->all());
        $name='WAREHOUSE_'.strtoupper(str_replace('-','_',$report)).'_'.now('Asia/Jakarta')->format('Ymd_His').'.csv';
        return response($csv,200,[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="'.$name.'"',
            'Cache-Control'=>'no-store, no-cache, must-revalidate',
        ]);
    }

    private function periodFilters(Request $request): array
    {
        return $request->validate([
            'scope'=>['nullable','in:selected,all'],'date_from'=>['nullable','date'],'date_to'=>['nullable','date','after_or_equal:date_from'],
            'date_basis'=>['nullable','in:BUSINESS,JOURNAL,business,journal'],'source_type'=>['nullable','string','max:80'],
            'account_query'=>['nullable','string','max:120'],'q'=>['nullable','string','max:120'],'include_zero'=>['nullable','boolean'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:20','max:100'],
        ]);
    }

    private function reportWarehouseIds(Request $request): array
    {
        $selected=trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($selected==='',422,'Pilih Warehouse terlebih dahulu.');
        if (strtolower((string)$request->query('scope','selected'))!=='all') return [$selected];

        $scope=(array)$request->attributes->get('warehouse_scope',[]);
        $canAll=(bool)($scope['can_adjust_scope'] ?? false);
        if (! $canAll && method_exists($request->user(),'hasPermissionTo')) {
            try { $canAll=$request->user()->hasPermissionTo('warehouse.finance.scope.all'); } catch (\Throwable) {}
        }
        if (! $canAll) return [$selected];

        $ids=collect($scope['warehouses'] ?? [])->pluck('id')->filter()->map(fn($id)=>(string)$id)->unique()->values()->all();
        return $ids ?: [$selected];
    }

    private function scopeMeta(Request $request): array
    {
        $scope=(array)$request->attributes->get('warehouse_scope',[]);
        $canAll=(bool)($scope['can_adjust_scope']??false);
        if (! $canAll && method_exists($request->user(),'hasPermissionTo')) {
            try { $canAll=$request->user()->hasPermissionTo('warehouse.finance.scope.all'); } catch (\Throwable) {}
        }
        return ['selected_warehouse_id'=>(string)$request->attributes->get('warehouse_scope_id',''),'can_all'=>$canAll,'warehouses'=>$scope['warehouses']??[]];
    }
}
