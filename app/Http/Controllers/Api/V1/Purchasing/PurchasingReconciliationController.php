<?php
namespace App\Http\Controllers\Api\V1\Purchasing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\ReconciliationIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\ResolveReconciliationIssueRequest;
use App\Http\Requests\Api\V1\Purchasing\RunReconciliationRequest;
use App\Services\Purchasing\PurchasingLegacyReconciliationService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use App\Services\Purchasing\PurchasingReconciliationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class PurchasingReconciliationController extends Controller
{
 public function __construct(private readonly PurchasingLegacyReconciliationService $runner,private readonly PurchasingReconciliationQueryService $query,private readonly PurchasingModuleRegistry $registry,private readonly PurchasingModuleAccessService $access){}
 public function catalogs(Request $r):JsonResponse{$d=$this->query->catalogs();$m=$this->registry->find('reconciliation');$d['capabilities']=$m?$this->access->capabilities($r->user(),$m):['view'=>false,'create'=>false,'edit'=>false,'delete'=>false];return $this->ok($d);}
 public function index(ReconciliationIndexRequest $r):JsonResponse{return $this->ok($this->query->paginate($r->validated()));}
 public function show(string $id):JsonResponse{return $this->ok($this->query->show($id));}
 public function runs():JsonResponse{return $this->ok(['items'=>$this->query->runs()]);}
 public function scan(RunReconciliationRequest $r):JsonResponse{return $this->ok($this->runner->run([...$r->validated(),'mode'=>'DRY_RUN'],$r->user()),201);}
 public function apply(RunReconciliationRequest $r):JsonResponse{return $this->ok($this->runner->run([...$r->validated(),'mode'=>'APPLY'],$r->user()),201);}
 public function resolve(ResolveReconciliationIssueRequest $r,string $id):JsonResponse{return $this->ok($this->query->resolveIssue($id,$r->validated('resolution_status'),$r->validated('resolution_notes'),(string)$r->user()->id));}
 private function ok(mixed $d,int $s=200):JsonResponse{return response()->json(['success'=>true,'data'=>$d],$s);}
}
