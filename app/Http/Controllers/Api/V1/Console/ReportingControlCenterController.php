<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Jobs\Reporting\ReportingWorkerHeartbeatJob;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingControlCenterController extends Controller
{
    public function __construct(
        private readonly ReportingMaterializationOrchestrator $orchestrator,
        private readonly UserManagementService $userManagement,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.view', 'can_view');
        return response()->json(['data'=>$this->orchestrator->dashboard()]);
    }


    public function status(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.view', 'can_view');
        return response()->json(['data'=>$this->orchestrator->liveStatus()]);
    }


    public function month(Request $request, string $month): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.view', 'can_view');
        $outletId=$request->query('outlet_id');
        try {
            return response()->json(['data'=>$this->orchestrator->monthReadiness($month,$outletId?[(string)$outletId]:null)]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

    public function prepareMonth(Request $request, string $month): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        $v=$request->validate([
            'mode'=>['nullable','in:smart,daily,monthly'],
            'outlet_id'=>['nullable','string','max:40'],
            'preset'=>['nullable','in:low,balanced'],
        ]);
        $detail=$this->orchestrator->monthReadiness($month,!empty($v['outlet_id'])?[(string)$v['outlet_id']]:null);
        $mode=(string)($v['mode']??'smart');
        $pipeline=$mode==='daily'?'daily':($mode==='monthly'?'monthly':(string)$detail['recommended_pipeline']);
        if($mode==='monthly' && !($detail['can_monthly']??false)) {
            return response()->json(['message'=>'Ringkasan bulanan final hanya tersedia setelah bulan ditutup. Untuk bulan berjalan gunakan mode Otomatis/Data Harian.'],422);
        }
        $low=($v['preset']??'balanced')==='low';
        $params=[
            'date_from'=>$detail['date_from'],'date_to'=>$detail['date_to'],
            'outlet_chunk'=>$low?3:6,'date_chunk'=>$low?7:14,
            'pipeline'=>$pipeline,'mode'=>'missing_only',
        ];
        if(!empty($v['outlet_id'])) $params['outlet_ids']=[(string)$v['outlet_id']];
        try {
            $run=$this->orchestrator->startRun($params,(string)($request->user()?->getAuthIdentifier()??''),'manual');
            return response()->json(['data'=>$run],201);
        } catch (\RuntimeException $e) {
            return response()->json(['message'=>$e->getMessage()],409);
        }
    }

    public function runEngineNow(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        $result=$this->orchestrator->tick(4);
        return response()->json(['data'=>$result,'message'=>'Satu Reporting Engine tick dijalankan. Ini tidak menyalakan scheduler permanen; service scheduler tetap harus aktif di server.']);
    }

    public function testWorker(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        ReportingWorkerHeartbeatJob::dispatch();
        return response()->json(['message'=>'Tes worker dikirim ke queue reporting. Jika worker aktif, status akan berubah setelah job diproses. Tombol ini tidak menyalakan service worker OS.']);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        $v=$request->validate($this->runRules(false));
        return response()->json(['data'=>$this->orchestrator->preview($v)]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        $v=$request->validate($this->runRules(true));
        if (($v['mode'] ?? 'missing_only') === 'force_rebuild') $this->authorizeCapability($request, 'console.control_center.force_rebuild', 'can_delete');
        try {
            $run=$this->orchestrator->startRun($v,(string)($request->user()?->getAuthIdentifier() ?? ''));
            return response()->json(['data'=>$run],201);
        } catch (\RuntimeException $e) {
            return response()->json(['message'=>$e->getMessage()],409);
        }
    }

    public function pause(Request $request,string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        return response()->json(['data'=>$this->orchestrator->requestPause($run)]);
    }

    public function resume(Request $request,string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        return response()->json(['data'=>$this->orchestrator->resume($run)]);
    }

    public function cancel(Request $request,string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        return response()->json(['data'=>$this->orchestrator->requestCancel($run)]);
    }

    public function retryFailed(Request $request,string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        try {
            return response()->json(['data'=>$this->orchestrator->retryFailed($run)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message'=>$e->getMessage()],409);
        }
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.configure', 'can_edit');
        $v=$request->validate([
            'auto_enabled'=>['required','boolean'],'rolling_days'=>['required','integer','min:1','max:730'],'outlet_chunk'=>['required','integer','min:1','max:25'],'date_chunk'=>['required','integer','min:1','max:31'],
            'window_start'=>['required','date_format:H:i'],'window_end'=>['required','date_format:H:i'],'timezone'=>['required','string','max:64'],'daily_enabled'=>['required','boolean'],'hourly_enabled'=>['required','boolean'],'monthly_enabled'=>['required','boolean'],
        ]);
        return response()->json(['data'=>$this->orchestrator->updateSettings($v)]);
    }

    private function runRules(bool $withMode): array
    {
        $rules=[
            'days'=>['nullable','integer','min:1','max:730'],'date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d'],'outlet_ids'=>['nullable','array'],'outlet_ids.*'=>['string','max:40'],
            'outlet_chunk'=>['nullable','integer','min:1','max:25'],'date_chunk'=>['nullable','integer','min:1','max:31'],'pipeline'=>['nullable','in:full,daily,hourly,monthly'],
        ];
        if($withMode) $rules['mode']=['nullable','in:missing_only,force_rebuild']; else $rules['mode']=['nullable','in:missing_only,force_rebuild'];
        return $rules;
    }

    private function authorizeCapability(Request $request,string $permission,string $matrixKey): void
    {
        abort_unless($this->hasCapability($request,$permission,$matrixKey),403,'Anda tidak memiliki akses Console / Control Center untuk aksi ini.');
    }

    private function hasCapability(Request $request,string $permission,string $matrixKey): bool
    {
        $user=$request->user(); if(!$user) return false; if($user->can($permission)) return true;
        $snapshot=$this->userManagement->currentSessionSnapshot($user); if(collect($snapshot['permissions']??[])->contains($permission)) return true;
        foreach(data_get($snapshot,'access.menus',[]) as $menu) {
            if(!is_array($menu)) continue; $path='/'.ltrim(trim((string)($menu['path']??'')),'/'); if(rtrim(strtolower($path),'/')!=='/console/control-center') continue; if(($menu[$matrixKey]??false)===true) return true;
        }
        return false;
    }
}
