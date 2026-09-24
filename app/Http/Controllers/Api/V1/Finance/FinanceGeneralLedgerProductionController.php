<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceGeneralLedgerProductionService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceGeneralLedgerProductionController extends Controller
{
    public function __construct(private readonly FinanceGeneralLedgerProductionService $service) {}

    public function tree(Request $request)
    {
        $data=$this->filters($request);
        [$ids,$corporate]=$this->accessScope($request);
        try { return ApiResponse::ok($this->service->tree($data,$ids,$corporate)); }
        catch(InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'GENERAL_LEDGER_PRODUCTION_FAILED',422); }
    }

    public function export(Request $request,string $id): StreamedResponse
    {
        $data=$this->filters($request);
        [$ids,$corporate]=$this->accessScope($request);
        try { $result=$this->service->exportData($id,$data,$ids,$corporate); }
        catch(InvalidArgumentException $e) { abort(422, $e->getMessage()); }
        $account=$result['account'] ?? [];
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)($account['code'] ?? 'ledger'));
        $from=(string)($data['date_from'] ?? 'awal'); $to=(string)($data['date_to'] ?? 'akhir');
        $filename="general-ledger-{$safe}-{$from}-{$to}.csv";

        return response()->streamDownload(function() use($result,$data): void {
            $h=fopen('php://output','wb'); fwrite($h,"\xEF\xBB\xBF");
            $account=$result['account'] ?? [];
            fputcsv($h,['GENERAL LEDGER']);
            fputcsv($h,['COA',($account['code'] ?? '').' - '.($account['name'] ?? '')]);
            fputcsv($h,['Account Type',$account['account_type'] ?? '']);
            fputcsv($h,['Periode',($data['date_from'] ?? '').' s/d '.($data['date_to'] ?? '')]);
            fputcsv($h,['Scope',$data['scope'] ?? 'ALL']);
            fputcsv($h,['Marking',$data['marking'] ?? 'ALL']);
            fputcsv($h,[]);
            fputcsv($h,['Tanggal Jurnal','Tanggal Bisnis','Jurnal','Source','Reference','PT','Outlet','Marking','Keterangan','Debit','Credit','Saldo']);
            fputcsv($h,['','','OPENING','','','','','','Saldo Awal','','',$result['opening_balance'] ?? 0]);
            foreach($result['items'] ?? [] as $r) {
                fputcsv($h,[
                    $r['journal_date'] ?? '',$r['business_date'] ?? '',$r['journal_no'] ?? '',$r['source_type'] ?? '',$r['reference_no'] ?? '',
                    $r['company_code'] ?? '',$r['outlet_name'] ?? '',$r['marking'] ?? '',$r['description'] ?? '',
                    (float)($r['debit'] ?? 0),(float)($r['credit'] ?? 0),(float)($r['running_balance'] ?? 0),
                ]);
            }
            fputcsv($h,[]); fputcsv($h,['','','CLOSING','','','','','','Saldo Akhir','','',$result['ending_balance'] ?? 0]);
            fclose($h);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, no-cache, must-revalidate']);
    }

    private function filters(Request $request): array
    {
        foreach(['include_audit'] as $key) {
            if(!$request->has($key)) continue;
            $v=$request->input($key);
            if(is_bool($v)||in_array($v,[0,1,'0','1'],true)) continue;
            $n=is_string($v)?filter_var($v,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE):null;
            if($n!==null)$request->merge([$key=>$n?1:0]);
        }
        return $request->validate([
            'scope'=>['nullable','string','max:80'],'marking'=>['nullable','in:ALL,MARKING,UNMARKING'],
            'date_basis'=>['nullable','in:JOURNAL,BUSINESS'],'date_from'=>['nullable','date_format:Y-m-d'],
            'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],'source_type'=>['nullable','string','max:40'],
            'account_query'=>['nullable','string','max:120'],'account_type'=>['nullable','string','max:40'],'include_audit'=>['nullable','boolean'],
        ]);
    }

    private function accessScope(Request $request): array
    {
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        $ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
        $canAdjust=(bool)$request->attributes->get('outlet_scope_can_adjust',false);
        return [$ids,$canAdjust && !OutletScope::isLocked($request)];
    }
}
