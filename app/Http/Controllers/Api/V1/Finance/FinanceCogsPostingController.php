<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceCogsPostingService;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceCogsPostingController extends Controller
{
    public function __construct(private readonly FinanceCogsPostingService $service){}

    public function options(Request $request){return ApiResponse::ok($this->service->options($this->allowedOutlets($request)));}

    public function sources(Request $request)
    {
        $data=$request->validate([
            'date_from'=>['required','date_format:Y-m-d'],'date_to'=>['required','date_format:Y-m-d','after_or_equal:date_from'],
            'outlet_id'=>['nullable','string','size:26'],'posting_status'=>['nullable','in:UNPOSTED,DRAFT,POSTED,NEEDS_DAILY_REBUILD'],
            'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:100'],
        ]);
        return ApiResponse::ok($this->service->sources($data,$this->allowedOutlets($request)));
    }

    public function mappings(Request $request){return ApiResponse::ok(['items'=>$this->service->mappings($this->allowedOutlets($request))]);}

    public function createMapping(Request $request)
    {
        $data=$this->mappingData($request);
        if(!empty($data['outlet_id']))$this->assertOutletAccess($request,(string)$data['outlet_id']);
        try{return ApiResponse::ok(['id'=>$this->service->saveMapping($data,$request->user()?->id)],'Mapping COGS disimpan.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_MAPPING_FAILED',422);}
    }

    public function updateMapping(Request $request,string $id)
    {
        $data=$this->mappingData($request);
        if(!empty($data['outlet_id']))$this->assertOutletAccess($request,(string)$data['outlet_id']);
        try{return ApiResponse::ok(['id'=>$this->service->saveMapping($data,$request->user()?->id,$id)],'Mapping COGS diperbarui.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_MAPPING_FAILED',422);}
    }

    public function deleteMapping(Request $request,string $id)
    {
        $mapping=collect($this->service->mappings($this->allowedOutlets($request)))->firstWhere('id',$id);
        if(! $mapping) abort(404,'Mapping COGS tidak ditemukan pada scope akses Anda.');
        if(! empty($mapping['outlet_id'])) $this->assertOutletAccess($request,(string)$mapping['outlet_id']);
        try{$this->service->deleteMapping($id);return ApiResponse::ok([],'Mapping COGS dihapus.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_MAPPING_DELETE_FAILED',422);}
    }

    public function createDraft(Request $request)
    {
        $data=$request->validate([
            'cogs_calculation_run_id'=>['required','string','size:26'],
            'marking_percent'=>['nullable','numeric','min:0','max:100'],'unmarking_percent'=>['nullable','numeric','min:0','max:100'],'note'=>['nullable','string','max:1000'],
        ]);
        $this->assertRunAccess($request,$data['cogs_calculation_run_id']);
        try{return ApiResponse::ok(['id'=>$this->service->createDraft($data['cogs_calculation_run_id'],$data,$request->user()?->id)],'Draft COGS Posting dibuat.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_DRAFT_FAILED',422);}
    }

    public function show(Request $request,string $id)
    {
        $data=$this->service->show($id);$this->assertOutletAccess($request,(string)$data['outlet_id']);return ApiResponse::ok($data);
    }

    public function refresh(Request $request,string $id)
    {
        $posting=$this->service->show($id);$this->assertOutletAccess($request,(string)$posting['outlet_id']);
        $data=$request->validate(['marking_percent'=>['nullable','numeric','min:0','max:100'],'unmarking_percent'=>['nullable','numeric','min:0','max:100']]);
        try{$this->service->refreshDraft($id,$data,$request->user()?->id);return ApiResponse::ok($this->service->show($id),'Source COGS diperbarui.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_REFRESH_FAILED',422);}
    }

    public function preview(Request $request,string $id)
    {
        $posting=$this->service->show($id);$this->assertOutletAccess($request,(string)$posting['outlet_id']);
        try{return ApiResponse::ok($this->service->preview($id));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_PREVIEW_FAILED',422);}
    }

    public function post(Request $request,string $id)
    {
        $posting=$this->service->show($id);$this->assertOutletAccess($request,(string)$posting['outlet_id']);
        try{return ApiResponse::ok($this->service->post($id,$request->user()?->id),'COGS berhasil diposting.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_FAILED',422);}
    }

    public function reopen(Request $request,string $id)
    {
        $posting=$this->service->show($id);$this->assertOutletAccess($request,(string)$posting['outlet_id']);
        $data=$request->validate(['reason'=>['required','string','min:5','max:500']]);
        try{$this->service->reopen($id,$data['reason'],$request->user()?->id);return ApiResponse::ok($this->service->show($id),'COGS Posting direversal dan kembali DRAFT.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_REOPEN_FAILED',422);}
    }

    public function destroy(Request $request,string $id)
    {
        $posting=$this->service->show($id);$this->assertOutletAccess($request,(string)$posting['outlet_id']);
        try{$this->service->destroyDraft($id);return ApiResponse::ok([],'Draft COGS Posting dihapus.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'COGS_POSTING_DELETE_FAILED',422);}
    }

    private function mappingData(Request $request):array
    {
        return $request->validate([
            'company_code'=>['required','in:BKJB,MDMF'],'outlet_id'=>['nullable','string','size:26'],
            'cogs_account_id'=>['required','string','size:26'],'inventory_account_id'=>['required','string','size:26'],'variance_account_id'=>['required','string','size:26'],
            'is_active'=>['required','boolean'],'notes'=>['nullable','string','max:1000'],
        ]);
    }

    private function allowedOutlets(Request $request):array
    {
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        return array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
    }
    private function assertOutletAccess(Request $request,string $outletId):void
    {
        if(!in_array($outletId,$this->allowedOutlets($request),true))abort(403,'Outlet berada di luar scope akses Anda.');
    }
    private function assertRunAccess(Request $request,string $runId):void
    {
        $outlet=(string)(\Illuminate\Support\Facades\DB::table('cogs_calculation_runs')->where('id',$runId)->value('outlet_id')??'');
        if($outlet==='')abort(404,'COGS Calculation tidak ditemukan.');$this->assertOutletAccess($request,$outlet);
    }
}
