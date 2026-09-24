<?php

namespace App\Services\HumanResource;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrKpiPreviewI05Service
{
    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function preview(Request $request, array $filters): array
    {
        $from=(string)$filters['from'];$to=(string)$filters['to'];$requestedOutlet=trim((string)($filters['outlet_id']??''));
        $start=Carbon::createFromFormat('Y-m-d',$from)->startOfDay();$end=Carbon::createFromFormat('Y-m-d',$to)->startOfDay();
        if($end->lt($start))throw ValidationException::withMessages(['to'=>['Tanggal akhir harus setelah atau sama dengan tanggal awal.']]);
        if($start->diffInDays($end)>62)throw ValidationException::withMessages(['to'=>['Preview KPI maksimal 63 hari per tampilan.']]);

        $allowed=$this->scope->allowedOutletIds($request);
        if($allowed===[])return $this->emptyResult($from,$to);
        if($requestedOutlet!==''&&!in_array($requestedOutlet,$allowed,true))throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope Human Resource user.']]);
        $outletIds=$requestedOutlet!==''?[$requestedOutlet]:$allowed;

        $outlets=DB::table('outlets')->whereIn('id',$outletIds)->orderBy('name')->get(['id','code','name']);
        $reviewRows=DB::table('HR_kpi_daily_reviews as r')
            ->leftJoin('HR_kpi_daily_entries as e','e.review_id','=','r.id')
            ->whereIn('r.outlet_id',$outletIds)->whereBetween('r.review_date',[$from,$to])
            ->groupBy('r.id','r.outlet_id','r.review_date','r.status','r.revision','r.locked_at','r.updated_at')
            ->get(['r.id','r.outlet_id','r.review_date','r.status','r.revision','r.locked_at','r.updated_at',DB::raw('COUNT(e.id) as entry_count')])
            ->keyBy(fn($r)=>(string)$r->outlet_id.'|'.(string)$r->review_date);

        $dates=[];$cursor=$start->copy();
        while($cursor->lte($end)){$dates[]=['date'=>$cursor->format('Y-m-d'),'label'=>$cursor->translatedFormat('d M'),'day'=>$cursor->translatedFormat('D')];$cursor->addDay();}

        $totalLocked=0;$totalDraft=0;$totalMissing=0;
        $outletPayload=$outlets->map(function($outlet)use($dates,$reviewRows,&$totalLocked,&$totalDraft,&$totalMissing){
            $locked=0;$draft=0;$missing=0;$days=[];
            foreach($dates as$date){
                $row=$reviewRows->get((string)$outlet->id.'|'.$date['date']);
                $status=$row?((string)$row->status==='locked'?'locked':'draft'):'missing';
                if($status==='locked'){$locked++;$totalLocked++;}elseif($status==='draft'){$draft++;$totalDraft++;}else{$missing++;$totalMissing++;}
                $days[]=[
                    'date'=>$date['date'],'status'=>$status,'review_id'=>$row?(string)$row->id:null,'revision'=>$row?(int)$row->revision:null,
                    'entry_count'=>$row?(int)$row->entry_count:0,'locked_at'=>$row?->locked_at,'updated_at'=>$row?->updated_at,
                    'href'=>'/report/kpi-squad?tab=daily&outlet_id='.rawurlencode((string)$outlet->id).'&date='.$date['date'],
                ];
            }
            return[
                'id'=>(string)$outlet->id,'code'=>(string)($outlet->code??''),'name'=>(string)$outlet->name,
                'summary'=>['locked'=>$locked,'draft'=>$draft,'missing'=>$missing,'total'=>count($dates),'locked_percent'=>count($dates)>0?round(($locked/count($dates))*100,2):0],
                'days'=>$days,
            ];
        })->values()->all();

        $totalCells=count($dates)*count($outletPayload);
        return[
            'from'=>$from,'to'=>$to,'dates'=>$dates,'outlets'=>$outletPayload,
            'summary'=>[
                'outlet_count'=>count($outletPayload),'date_count'=>count($dates),'cell_count'=>$totalCells,
                'locked'=>$totalLocked,'draft'=>$totalDraft,'missing'=>$totalMissing,
                'locked_percent'=>$totalCells>0?round(($totalLocked/$totalCells)*100,2):0,
            ],
            'rule'=>[
                'locked'=>'KPI 1 dihitung menggunakan nilai Daily KPI yang tersimpan.',
                'draft'=>'KPI 1 pada Mapping KPI efektif 0 tanpa mengubah nilai Draft Daily KPI.',
                'missing'=>'Belum ada Daily KPI; kontribusi KPI 1 efektif 0.',
            ],
        ];
    }

    private function emptyResult(string $from,string $to): array
    {
        return['from'=>$from,'to'=>$to,'dates'=>[],'outlets'=>[],'summary'=>['outlet_count'=>0,'date_count'=>0,'cell_count'=>0,'locked'=>0,'draft'=>0,'missing'=>0,'locked_percent'=>0],'rule'=>[]];
    }
}
