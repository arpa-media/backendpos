<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePayrollPostingService;
use App\Services\HumanResource\HrPayrollCutoffWorkflowService;
use App\Services\HumanResource\HrKpiBonusService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class FinancePayrollPostingController extends Controller
{
    public function __construct(private readonly FinancePayrollPostingService $service, private readonly HrPayrollCutoffWorkflowService $hrWorkflow, private readonly HrKpiBonusService $bonusWorkflow) {}

    public function options(Request $request){[$ids,$corporate]=$this->accessScope($request);return ApiResponse::ok($this->service->options($ids,$corporate));}
    public function index(Request $request){[$ids,$corporate]=$this->accessScope($request);$data=$request->validate(['q'=>['nullable','string','max:120'],'request_type'=>['nullable',Rule::in(['PAYROLL','BONUS'])],'status'=>['nullable','string','max:30'],'company_code'=>['nullable','string','max:16'],'outlet_id'=>['nullable','string','size:26'],'date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:100']]);return ApiResponse::ok($this->service->paginate($data,$ids,$corporate));}
    public function show(Request $request,string $id){try{$row=$this->service->show($id);$this->assertRowScope($request,$row);return ApiResponse::ok($row);}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_POSTING_NOT_FOUND',404);}}
    public function store(Request $request){return $this->persist($request);}
    public function update(Request $request,string $id){return $this->persist($request,$id);}
    public function submit(Request $request,string $id){return $this->action(function()use($request,$id):void{$this->assertRecordScope($request,$id);$this->service->submit($id,$request->user()?->id);},'Pengajuan berhasil disubmit.');}
    public function approve(Request $request,string $id){return $this->action(function()use($request,$id):void{DB::transaction(function()use($request,$id):void{$this->assertRecordScope($request,$id);$row=$this->service->show($id);if(!empty($row['hr_cutoff_id']))$this->hrWorkflow->markFinanceProcessing($id,$request->user()?->id);$this->service->approve($id,$request->user()?->id);if(!empty($row['hr_cutoff_id']))$this->hrWorkflow->markFinanceApproved($id,$request->user()?->id);if(!empty($row['hr_bonus_projection_id']))$this->bonusWorkflow->finalizeFromFinance($id,$request->user());});},'Pengajuan Payroll/Bonus berhasil di-approve.');}
    public function postAccrual(Request $request,string $id){try{$this->assertRecordScope($request,$id);return ApiResponse::ok($this->service->postAccrual($id,$request->user()?->id),'Accrual Payroll/Bonus berhasil diposting.');}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_ACCRUAL_FAILED',422);}}
    public function pay(Request $request,string $id){$data=$request->validate(['payment_date'=>['required','date_format:Y-m-d'],'amount'=>['required','numeric','min:0.01'],'payment_account_id'=>['required','string','size:26','exists:finance_chart_of_accounts,id'],'payer_name'=>['nullable','string','max:160'],'reference_no'=>['nullable','string','max:120'],'notes'=>['nullable','string','max:2000'],'idempotency_key'=>['nullable','string','max:191']]);try{$this->assertRecordScope($request,$id);return ApiResponse::ok($this->service->pay($id,$data,$request->user()?->id),'Pembayaran Payroll/Bonus berhasil diposting.');}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_PAYMENT_FAILED',422);}}

    public function cancelApproval(Request $request,string $id)
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        try{
            $this->assertRecordScope($request,$id);
            $row=$this->service->show($id);
            $this->assertHrBonusNotReversed($row);
            $result=DB::transaction(function()use($request,$id,$data,$row):array{
                $result=$this->service->cancelApproval($id,$data['reason'],$request->user()?->id);
                if(!empty($row['hr_cutoff_id']))$this->hrWorkflow->markFinanceApprovalCancelled($id,$request->user()?->id,$data['reason']);
                return $result;
            });
            return ApiResponse::ok($result,'Approval Finance dibatalkan. Pengajuan kembali SUBMITTED.');
        }catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_APPROVAL_CANCEL_FAILED',422);}
    }

    public function reverseAccrual(Request $request,string $id)
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        try{
            $this->assertRecordScope($request,$id);
            $row=$this->service->show($id);
            $this->assertHrBonusNotReversed($row);
            return ApiResponse::ok($this->service->reverseAccrual($id,$data['reason'],$request->user()?->id),'Accrual direversal. Status kembali APPROVED.');
        }catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_ACCRUAL_REVERSE_FAILED',422);}
    }

    public function reversePayment(Request $request,string $id,string $paymentId)
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        try{
            $this->assertRecordScope($request,$id);
            $row=$this->service->show($id);
            $this->assertHrBonusNotReversed($row);
            return ApiResponse::ok($this->service->reversePayment($id,$paymentId,$data['reason'],$request->user()?->id),'Payment direversal dan outstanding dihitung ulang.');
        }catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_PAYMENT_REVERSE_FAILED',422);}
    }
    public function destroy(Request $request,string $id){try{$this->assertRecordScope($request,$id);$this->service->deleteDraft($id);return ApiResponse::ok(['id'=>$id],'Draft Payroll/Bonus dihapus.');}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_DELETE_FAILED',422);}}

    private function persist(Request $request,?string $id=null)
    {
        $data=$request->validate([
            'request_type'=>['required',Rule::in(['PAYROLL','BONUS'])],'payroll_batch_id'=>['nullable','string','max:120'],'reference_no'=>['nullable','string','max:120'],'description'=>['nullable','string','max:3000'],
            'company_code'=>['required_without:outlet_id','nullable','string','max:16'],'outlet_id'=>['nullable','string','size:26','exists:outlets,id'],'marking'=>['required',Rule::in(['MARKING','UNMARKING'])],
            'period_from'=>['required','date_format:Y-m-d'],'period_to'=>['required','date_format:Y-m-d','after_or_equal:period_from'],'business_date'=>['required','date_format:Y-m-d'],
            'gross_pay'=>['required','numeric','min:0.01'],'deductions'=>['nullable','numeric','min:0'],'employee_count'=>['nullable','integer','min:0'],
        ]);
        try{$this->assertPayloadScope($request,$data['outlet_id']??null);$rowId=$this->service->save($data,$request->user()?->id,$id);return ApiResponse::ok(['id'=>$rowId],$id?'Draft Payroll/Bonus diperbarui.':'Draft Payroll/Bonus dibuat.',$id?200:201);}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_VALIDATION_FAILED',422);}
    }
    private function assertHrBonusNotReversed(array $row):void
    {
        if(!empty($row['hr_bonus_projection_id']))throw new InvalidArgumentException('Reversal Iterasi 25 hanya untuk Payroll/Cutoff Gaji. Workflow Bonus HR tetap mengikuti lifecycle Bonus.');
    }
    private function assertRecordScope(Request $request,string $id):void{$row=$this->service->show($id);$this->assertRowScope($request,$row);}
    private function assertRowScope(Request $request,array $row):void{$this->assertPayloadScope($request,$row['outlet_id']??null);}
    private function assertPayloadScope(Request $request,?string $outletId):void{[$ids,$corporate]=$this->accessScope($request);if($outletId!==null&&$outletId!==''&&!in_array($outletId,$ids,true))throw new InvalidArgumentException('Outlet di luar scope akses Finance user.');if(($outletId===null||$outletId==='')&&!$corporate)throw new InvalidArgumentException('User tidak memiliki akses posting scope PT/corporate.');}
    private function action(callable $callback,string $message){try{$callback();return ApiResponse::ok([], $message);}catch(ValidationException $e){throw $e;}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PAYROLL_ACTION_FAILED',422);}catch(Throwable $e){report($e);return ApiResponse::error('Gagal memproses Payroll/Bonus Posting.','PAYROLL_ACTION_ERROR',500);}}
    private function accessScope(Request $request):array{$scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);$ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));$canAdjust=(bool)$request->attributes->get('outlet_scope_can_adjust',false);return[$ids,$canAdjust&&!OutletScope::isLocked($request)];}
}
