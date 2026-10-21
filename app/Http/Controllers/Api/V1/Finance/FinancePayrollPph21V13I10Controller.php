<?php
namespace App\Http\Controllers\Api\V1\Finance;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePayrollPph21V13I10Service;
use App\Services\Finance\FinancePayrollPostingService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
final class FinancePayrollPph21V13I10Controller extends Controller {
    public function __construct(private readonly FinancePayrollPph21V13I10Service $tax,private readonly FinancePayrollPostingService $posting) {}
    private function scope(Request $request,string $id): void {
        $row=$this->posting->show($id);
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        $ids=array_map('strval',$scope['outlet_ids']??[]);
        $corporate=(bool)$request->attributes->get('outlet_scope_can_adjust',false) && !OutletScope::isLocked($request);
        $outletId=$row['outlet_id']??null;
        if($outletId && !in_array((string)$outletId,$ids,true)) abort(403,'Payroll di luar scope Finance user.');
        if(!$outletId && !$corporate) abort(403,'Tidak memiliki akses payroll corporate.');
    }
    public function recipients(Request $r,string $id) {$this->scope($r,$id);return ApiResponse::ok($this->tax->recipients($id));}
    public function export(Request $r,string $id) {$this->scope($r,$id);return $this->tax->export($id);}
    public function import(Request $r,string $id) {
        $this->scope($r,$id);
        $r->validate(['file'=>['required','file','mimes:xlsx','max:10240']]);
        return ApiResponse::ok($this->tax->import($id,$r->file('file'),(string)$r->user()->id),'Import PPh21 selesai.');
    }
}
