<?php
namespace App\Http\Controllers\Api\V1\Purchasing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\{RealizationDecisionRequest,UpdateRealizationOrderRequest};
use App\Services\Purchasing\{PurchasingRealizationPostingService,RealizationOrderService};
use Illuminate\Http\{JsonResponse,Request};
class RealizationOrderController extends Controller {
 public function __construct(private readonly RealizationOrderService $service,private readonly PurchasingRealizationPostingService $posting){}
 private function ok($d):JsonResponse{return response()->json(['success'=>true,'data'=>$d]);}
 public function index(Request $r):JsonResponse{return $this->ok($this->service->overview($r->user(),$r->only(['status','type','q'])));}
 public function show(Request $r,string $kind,string $id):JsonResponse{return $this->ok($this->service->detail($kind,$id,$r->user()));}
 public function update(UpdateRealizationOrderRequest $r,string $kind,string $id):JsonResponse{return $this->ok($this->service->update($kind,$id,$r->validated(),$r->user()));}
 public function review(RealizationDecisionRequest $r,string $kind,string $id):JsonResponse{$d=$r->validated();return $this->ok($this->service->review($kind,$id,$d['idempotency_key'],$d['notes']??null,$r->user()));}
 public function submitEvidence(RealizationDecisionRequest $r,string $kind,string $id):JsonResponse{$d=$r->validated();return $this->ok($this->service->submitEvidence($kind,$id,$d['idempotency_key'],$d['notes']??null,$r->user()));}
 public function submit(RealizationDecisionRequest $r,string $kind,string $id):JsonResponse{$d=$r->validated();return $this->ok($this->service->submit($kind,$id,$d['idempotency_key'],$d['notes']??null,$r->user()));}
 public function approve(RealizationDecisionRequest $r,string $kind,string $id):JsonResponse{$d=$r->validated();$this->service->approve($kind,$id,$d['idempotency_key'],$d['notes']??null,$r->user());$finance=$this->posting->finalizeAfterApproval($kind,$id,$r->user());$result=$this->service->detail($kind,$id,$r->user());$result['finance_posting']=$finance;return $this->ok($result);}
 public function reject(RealizationDecisionRequest $r,string $kind,string $id):JsonResponse{$d=$r->validated();return $this->ok($this->service->reject($kind,$id,$d['idempotency_key'],trim((string)($d['notes']??''))?:'Ditolak',$r->user()));}
 public function upload(Request $r,string $kind,string $id):JsonResponse{$r->validate(['file'=>['required','file','max:10240']]);return $this->ok($this->service->upload($kind,$id,$r->file('file'),$r->user()));}
 public function deleteAttachment(Request $r,string $kind,string $id,string $attachmentId):JsonResponse{$this->service->deleteAttachment($kind,$id,$attachmentId,$r->user());return $this->ok(['deleted'=>true]);}
 public function sync(Request $r):JsonResponse{return $this->ok($this->service->syncApproved($r->user()));}
}
