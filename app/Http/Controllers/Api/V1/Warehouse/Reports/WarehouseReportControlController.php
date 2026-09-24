<?php
namespace App\Http\Controllers\Api\V1\Warehouse\Reports;
use App\Http\Controllers\Controller;use App\Services\Warehouse\Reports\WarehouseReportControlService;use Illuminate\Http\Request;use Symfony\Component\HttpFoundation\StreamedResponse;
class WarehouseReportControlController extends Controller{
 public function __construct(private WarehouseReportControlService $service){}
 public function executive(Request $r){return response()->json(['data'=>$this->service->executive($r->all())]);}
 public function operations(Request $r){return response()->json(['data'=>$this->service->operations($r->all())]);}
 public function finance(Request $r){return response()->json(['data'=>$this->service->finance($r->all())]);}
 public function audit(Request $r){return response()->json(['data'=>$this->service->audit($r->all())]);}
 public function reconciliation(Request $r){return response()->json(['data'=>$this->service->reconciliation($r->all())]);}
 public function run(Request $r){$v=$r->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','warehouse_id'=>'nullable|string']);return response()->json(['data'=>$this->service->runReconciliation($v,(string)optional($r->user())->id)],201);}
 public function resolve(Request $r,string $id){$v=$r->validate(['notes'=>'required|string|max:2000']);$this->service->resolve($id,$v['notes'],(string)optional($r->user())->id);return response()->json(['message'=>'Exception berhasil diselesaikan.']);}
 public function export(Request $r):StreamedResponse{$type=$r->validate(['type'=>'required|in:executive,operations,finance,audit'])['type'];$data=$this->service->{$type}($r->all());return response()->streamDownload(function()use($data){$out=fopen('php://output','w');fputcsv($out,['section','key','value']);foreach($data as$section=>$value){if(is_array($value)){foreach($value as$k=>$v)fputcsv($out,[$section,$k,is_scalar($v)?$v:json_encode($v)]);}else fputcsv($out,['summary',$section,$value]);}fclose($out);},'warehouse-'.$type.'-'.now()->format('YmdHis').'.csv',['Content-Type'=>'text/csv']);}
}
