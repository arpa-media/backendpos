<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class HrKpiSquadService
{
    private const RULE_VERSION = 'KPI_APRIL_2026_NORMALIZED_V1';

    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrPunishmentService $punishment,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    public function references(Request $request): array
    {
        return [
            'outlets' => $this->scope->options($request),
            'criteria' => $this->criteria(),
            'settings' => $this->settings(),
            'rule_version' => self::RULE_VERSION,
        ];
    }

    public function criteria(): array
    {
        return DB::table('HR_kpi_grooming_criteria')
            ->orderBy('division_name')->orderBy('sort_order')->orderBy('name')
            ->get()->map(fn ($row) => $this->criterionPayload($row))->values()->all();
    }

    public function saveCriterion(array $data, ?User $actor, ?string $id = null): array
    {
        $division = strtoupper(trim((string) $data['division_name']));
        $code = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', strtoupper(trim((string) $data['code']))) ?: '');
        if ($division === '' || $code === '') {
            throw ValidationException::withMessages(['criterion' => ['Division dan code criterion wajib diisi.']]);
        }

        $duplicate = DB::table('HR_kpi_grooming_criteria')
            ->where('division_name', $division)->where('code', $code)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($duplicate) throw ValidationException::withMessages(['code' => ['Code criterion sudah digunakan pada division tersebut.']]);

        $now = now();
        if ($id) {
            $row = DB::table('HR_kpi_grooming_criteria')->where('id', $id)->first();
            if (!$row) abort(404);
            DB::table('HR_kpi_grooming_criteria')->where('id', $id)->update([
                'division_name'=>$division,'code'=>$code,'name'=>trim((string)$data['name']),
                'max_score'=>(float)$data['max_score'],'sort_order'=>(int)($data['sort_order'] ?? 10),
                'is_active'=>(bool)($data['is_active'] ?? true),'description'=>trim((string)($data['description'] ?? '')) ?: null,
                'updated_by_user_id'=>$actor?->id,'updated_at'=>$now,
            ]);
        } else {
            $id = (string) Str::ulid();
            DB::table('HR_kpi_grooming_criteria')->insert([
                'id'=>$id,'division_name'=>$division,'code'=>$code,'name'=>trim((string)$data['name']),
                'max_score'=>(float)$data['max_score'],'sort_order'=>(int)($data['sort_order'] ?? 10),
                'is_active'=>(bool)($data['is_active'] ?? true),'description'=>trim((string)($data['description'] ?? '')) ?: null,
                'created_by_user_id'=>$actor?->id,'updated_by_user_id'=>$actor?->id,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        return $this->criterionPayload(DB::table('HR_kpi_grooming_criteria')->where('id',$id)->first());
    }

    public function daily(Request $request, string $outletId, string $date): array
    {
        $this->assertOutlet($request, $outletId);
        $review = DB::table('HR_kpi_daily_reviews')->where('outlet_id',$outletId)->whereDate('review_date',$date)->first();
        $outlet = DB::table('outlets')->where('id',$outletId)->first(['id','code','name','timezone']);
        if (!$outlet) abort(404);

        if ($review) return $this->reviewPayload($review, $outlet);

        $universe = $this->employeeUniverse($outletId,$date);
        $snapshot = $this->buildCriteriaSnapshot($universe->pluck('division_name')->filter()->unique()->all());
        return [
            'id'=>null,'exists'=>false,'outlet_id'=>$outletId,'outlet_code'=>(string)($outlet->code ?? ''),'outlet_name'=>(string)$outlet->name,
            'review_date'=>$date,'status'=>'draft','revision'=>1,'rule_version'=>self::RULE_VERSION,'notes'=>null,
            'locked_at'=>null,'locked_by_name'=>null,'reopened_at'=>null,'reopened_by_name'=>null,'reopen_reason'=>null,
            'criteria'=>$this->criteriaUnion($snapshot),'entries'=>$this->defaultEntries($universe,$snapshot),'audits'=>[],
            'settings'=>$this->settings(),
        ];
    }

    public function saveDaily(Request $request, string $outletId, string $date, array $data, ?User $actor, string $eventType = 'save'): array
    {
        $this->assertOutlet($request,$outletId);
        return DB::transaction(function () use ($request,$outletId,$date,$data,$actor,$eventType) {
            return $this->persistDaily($request,$outletId,$date,$data,$actor,$eventType);
        });
    }

    private function persistDaily(Request $request, string $outletId, string $date, array $data, ?User $actor, string $eventType): array
    {
        $review = DB::table('HR_kpi_daily_reviews')->where('outlet_id',$outletId)->whereDate('review_date',$date)->lockForUpdate()->first();
        if ($review && $review->status === 'locked') throw ValidationException::withMessages(['review'=>['KPI harian sudah locked. Re-open lebih dahulu.']]);

        $universe = $this->employeeUniverse($outletId,$date);
        if ($universe->isEmpty()) throw ValidationException::withMessages(['date'=>['Tidak ada Squad aktif pada outlet/tanggal tersebut.']]);

        $submitted = collect($data['entries'] ?? [])->keyBy(fn($row)=>(string)($row['employee_id'] ?? ''));
        if (!$review) {
            $id = (string)Str::ulid();
            $snapshot = $this->buildCriteriaSnapshot($universe->pluck('division_name')->filter()->unique()->all());
            DB::table('HR_kpi_daily_reviews')->insert([
                'id'=>$id,'outlet_id'=>$outletId,'review_date'=>$date,'status'=>'draft','revision'=>1,
                'rule_version'=>self::RULE_VERSION,'criteria_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),
                'notes'=>trim((string)($data['notes'] ?? '')) ?: null,'created_by_user_id'=>$actor?->id,'updated_by_user_id'=>$actor?->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $review = DB::table('HR_kpi_daily_reviews')->where('id',$id)->lockForUpdate()->first();
        } else {
            $snapshot = $this->decodeSnapshot($review->criteria_snapshot);
            $missingDivisions = $universe->pluck('division_name')->filter()->unique()->reject(fn($d)=>array_key_exists((string)$d,$snapshot))->values()->all();
            if ($missingDivisions !== []) $snapshot = array_merge($snapshot,$this->buildCriteriaSnapshot($missingDivisions));
            DB::table('HR_kpi_daily_reviews')->where('id',$review->id)->update([
                'criteria_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'notes'=>trim((string)($data['notes'] ?? $review->notes ?? '')) ?: null,
                'updated_by_user_id'=>$actor?->id,'updated_at'=>now(),
            ]);
        }

        $existingEntries = DB::table('HR_kpi_daily_entries')->where('review_id',$review->id)->get()->keyBy('employee_id');
        $existingScores = DB::table('HR_kpi_daily_scores as s')->join('HR_kpi_daily_entries as e','e.id','=','s.entry_id')
            ->where('e.review_id',$review->id)->get(['s.*','e.employee_id'])->groupBy('employee_id');

        $changed = [];
        foreach ($universe as $employee) {
            $division = strtoupper(trim((string)$employee->division_name));
            $criteria = collect($snapshot[$division] ?? []);
            $incoming = $submitted->get((string)$employee->employee_id, []);
            $incomingScores = collect($incoming['scores'] ?? [])->keyBy(fn($s)=>strtoupper(trim((string)($s['criterion_code'] ?? ''))));
            $oldEntry = $existingEntries->get((string)$employee->employee_id);
            $oldScoreMap = collect($existingScores->get((string)$employee->employee_id, collect()))->keyBy('criterion_code_snapshot');

            $scoreRows = [];
            $raw = 0.0; $max = 0.0;
            foreach ($criteria as $criterion) {
                $code = (string)$criterion['code'];
                $old = $oldScoreMap->get($code);
                $requested = $incomingScores->get($code);
                $score = $requested !== null && array_key_exists('score',$requested)
                    ? (float)$requested['score']
                    : ($old ? (float)$old->score : (float)$criterion['max_score']);
                $criterionMax = (float)$criterion['max_score'];
                if ($score < 0 || $score > $criterionMax) {
                    throw ValidationException::withMessages(["entries.{$employee->employee_id}.{$code}"=>["Score {$code} harus 0 s/d {$criterionMax}."]]);
                }
                $raw += $score; $max += $criterionMax;
                $scoreRows[] = ['criterion'=>$criterion,'score'=>$score,'note'=>trim((string)($requested['note'] ?? $old?->note ?? '')) ?: null];
            }
            $normalized = $max > 0 ? round(($raw/$max)*100,4) : 0;
            $entryId = (string)($oldEntry->id ?? Str::ulid());
            $entryData = [
                'review_id'=>$review->id,'employee_id'=>$employee->employee_id,'squad_id'=>$employee->squad_id,
                'nisj_snapshot'=>$employee->nisj,'name_snapshot'=>$employee->full_name,'division_snapshot'=>$division,
                'raw_score'=>$raw,'max_score'=>$max,'normalized_percent'=>$normalized,
                'note'=>trim((string)($incoming['note'] ?? $oldEntry?->note ?? '')) ?: null,
                'updated_by_user_id'=>$actor?->id,'updated_at'=>now(),
            ];
            if ($oldEntry) DB::table('HR_kpi_daily_entries')->where('id',$entryId)->update($entryData);
            else DB::table('HR_kpi_daily_entries')->insert(array_merge(['id'=>$entryId,'created_at'=>now()],$entryData));

            foreach ($scoreRows as $scoreRow) {
                $criterion = $scoreRow['criterion']; $code=(string)$criterion['code']; $old=$oldScoreMap->get($code);
                $payload = [
                    'entry_id'=>$entryId,'criterion_id'=>$criterion['id'] ?: null,'criterion_code_snapshot'=>$code,
                    'criterion_name_snapshot'=>$criterion['name'],'max_score_snapshot'=>$criterion['max_score'],
                    'score'=>$scoreRow['score'],'note'=>$scoreRow['note'],'updated_at'=>now(),
                ];
                if ($old) DB::table('HR_kpi_daily_scores')->where('id',$old->id)->update($payload);
                else DB::table('HR_kpi_daily_scores')->insert(array_merge(['id'=>(string)Str::ulid(),'created_at'=>now()],$payload));
            }
            $changed[]=['employee_id'=>(string)$employee->employee_id,'nisj'=>(string)$employee->nisj,'raw_score'=>$raw,'max_score'=>$max];
        }

        DB::table('HR_kpi_daily_reviews')->where('id',$review->id)->update(['updated_by_user_id'=>$actor?->id,'updated_at'=>now()]);
        $this->audit((string)$review->id,$eventType,$actor,[
            'outlet_id'=>$outletId,'review_date'=>$date,'entry_count'=>count($changed),'entries'=>$changed,
        ], trim((string)($data['audit_note'] ?? '')) ?: null);

        $fresh = DB::table('HR_kpi_daily_reviews')->where('id',$review->id)->first();
        $outlet = DB::table('outlets')->where('id',$outletId)->first(['id','code','name','timezone']);
        return $this->reviewPayload($fresh,$outlet);
    }

    public function lockDaily(Request $request, string $id, ?User $actor): array
    {
        return DB::transaction(function () use ($request,$id,$actor) {
            $review = DB::table('HR_kpi_daily_reviews')->where('id',$id)->lockForUpdate()->first();
            if (!$review) abort(404);
            $this->assertOutlet($request,(string)$review->outlet_id);
            if ($review->status === 'locked') return $this->reviewPayload($review,DB::table('outlets')->where('id',$review->outlet_id)->first());
            $entries = DB::table('HR_kpi_daily_entries')->where('review_id',$id)->get();
            if ($entries->isEmpty()) throw ValidationException::withMessages(['review'=>['Simpan draft KPI terlebih dahulu sebelum lock.']]);
            $invalid = $entries->filter(fn($e)=>(float)$e->max_score <= 0);
            if ($invalid->isNotEmpty()) throw ValidationException::withMessages(['criteria'=>['Ada Squad tanpa master criterion grooming. Lengkapi master criterion sebelum lock.']]);
            DB::table('HR_kpi_daily_reviews')->where('id',$id)->update([
                'status'=>'locked','locked_by_user_id'=>$actor?->id,'locked_at'=>now(),'updated_by_user_id'=>$actor?->id,'updated_at'=>now(),
            ]);
            $this->audit($id,'lock',$actor,['revision'=>(int)$review->revision]);
            $fresh=DB::table('HR_kpi_daily_reviews')->where('id',$id)->first();
            return $this->reviewPayload($fresh,DB::table('outlets')->where('id',$review->outlet_id)->first());
        });
    }

    public function reopenDaily(Request $request, string $id, string $reason, ?User $actor): array
    {
        return DB::transaction(function () use ($request,$id,$reason,$actor) {
            $review=DB::table('HR_kpi_daily_reviews')->where('id',$id)->lockForUpdate()->first();
            if(!$review) abort(404);
            $this->assertOutlet($request,(string)$review->outlet_id);
            if($review->status!=='locked') throw ValidationException::withMessages(['review'=>['Hanya review locked yang dapat di-reopen.']]);
            DB::table('HR_kpi_daily_reviews')->where('id',$id)->update([
                'status'=>'draft','revision'=>(int)$review->revision+1,'reopened_by_user_id'=>$actor?->id,'reopened_at'=>now(),
                'reopen_reason'=>trim($reason),'updated_by_user_id'=>$actor?->id,'updated_at'=>now(),
            ]);
            $this->audit($id,'reopen',$actor,['from_revision'=>(int)$review->revision,'to_revision'=>(int)$review->revision+1],$reason);
            $fresh=DB::table('HR_kpi_daily_reviews')->where('id',$id)->first();
            return $this->reviewPayload($fresh,DB::table('outlets')->where('id',$review->outlet_id)->first());
        });
    }

    public function period(Request $request, string $from, string $to, ?string $outletId, bool $includeDraft = false, ?string $search = null): array
    {
        $allowed=$this->scope->allowedOutletIds($request);
        if($outletId){$this->assertOutlet($request,$outletId);$allowed=[$outletId];}
        if($allowed===[]) return ['items'=>[],'summary'=>$this->periodSummary([]),'settings'=>$this->settings()];

        $rows=DB::table('HR_kpi_daily_entries as e')
            ->join('HR_kpi_daily_reviews as r','r.id','=','e.review_id')
            ->join('outlets as o','o.id','=','r.outlet_id')
            ->whereBetween('r.review_date',[$from,$to])->whereIn('r.outlet_id',$allowed)
            ->when(!$includeDraft,fn($q)=>$q->where('r.status','locked'))
            ->when($search,function($q,$v){$needle='%'.trim((string)$v).'%';$q->where(function($x)use($needle){$x->where('e.name_snapshot','like',$needle)->orWhere('e.nisj_snapshot','like',$needle)->orWhere('e.division_snapshot','like',$needle);});})
            ->get(['e.*','r.review_date','r.status as review_status','r.outlet_id','o.code as outlet_code','o.name as outlet_name']);

        $targetDays=(int)data_get($this->settings(),'GROOMING_TARGET_DAYS.days',25);
        $weight=(float)data_get($this->settings(),'GROOMING_COMPONENT_WEIGHT.weight',40);
        $items=$rows->groupBy(fn($r)=>(string)$r->outlet_id.'|'.(string)$r->employee_id)->map(function($group)use($targetDays,$weight){
            $first=$group->first();$raw=(float)$group->sum('raw_score');$reviewedMax=(float)$group->sum('max_score');
            $maxDaily=(float)$group->max('max_score');$workbookMax=$maxDaily*$targetDays;
            return [
                'employee_id'=>(string)$first->employee_id,'nisj'=>(string)($first->nisj_snapshot ?? ''),'full_name'=>(string)$first->name_snapshot,
                'division'=>(string)$first->division_snapshot,'outlet_id'=>(string)$first->outlet_id,'outlet_code'=>(string)($first->outlet_code ?? ''),'outlet_name'=>(string)$first->outlet_name,
                'days_reviewed'=>$group->pluck('review_date')->unique()->count(),'locked_days'=>$group->where('review_status','locked')->pluck('review_date')->unique()->count(),
                'raw_score'=>round($raw,2),'reviewed_max_score'=>round($reviewedMax,2),'quality_percent'=>$reviewedMax>0?round($raw/$reviewedMax*100,2):0,
                'max_score_per_day'=>round($maxDaily,2),'target_days'=>$targetDays,'workbook_max_score'=>round($workbookMax,2),
                'grooming_component_40'=>$workbookMax>0?round(min($weight,$raw/$workbookMax*$weight),4):0,
            ];
        })->sortBy(fn($r)=>$r['outlet_name'].'|'.$r['full_name'],SORT_NATURAL|SORT_FLAG_CASE)->values()->all();
        return ['items'=>$items,'summary'=>$this->periodSummary($items),'settings'=>$this->settings(),'from'=>$from,'to'=>$to,'include_draft'=>$includeDraft];
    }

    public function reportViolation(Request $request, array $data, ?User $actor): array
    {
        $outletId=(string)$data['outlet_id'];$date=(string)$data['review_date'];$employeeId=(string)$data['employee_id'];
        $this->assertOutlet($request,$outletId);
        $employee=$this->employeeUniverse($outletId,$date)->firstWhere('employee_id',$employeeId);
        if(!$employee) throw ValidationException::withMessages(['employee_id'=>['Squad tidak berada pada outlet/tanggal KPI tersebut.']]);
        $criterion=strtoupper(trim((string)($data['criterion_code'] ?? 'GENERAL')));
        $sourceRef=substr('kpi:'.$date.':'.$outletId.':'.$employeeId.':'.$criterion,0,100);
        return $this->punishment->reportViolation($request,[
            'employee_id'=>$employeeId,'outlet_id'=>$outletId,'violation_type'=>'grooming','violation_date'=>$date,
            'title'=>trim((string)$data['title']),'description'=>trim((string)($data['description'] ?? '')) ?: null,
            'severity'=>$data['severity'] ?? 'medium','source_type'=>'kpi-grooming','source_ref'=>$sourceRef,
            'metadata'=>['criterion_code'=>$criterion,'kpi_rule_version'=>self::RULE_VERSION,'reported_from'=>'report/kpi-squad'],
        ],$actor);
    }

    public function exportDaily(Request $request, string $outletId, string $date): Response
    {
        $daily=$this->daily($request,$outletId,$date);$criteria=collect($daily['criteria']);
        $headers=['DATE','OUTLET_CODE','OUTLET_NAME','NISJ','FULL_NAME','DIVISION','STATUS'];
        foreach($criteria as$c)$headers[]=$c['code'];
        $headers=array_merge($headers,['NOTE','TOTAL_SCORE','MAX_SCORE','GROOMING_PERCENT']);$rows=[$headers];
        foreach($daily['entries'] as$entry){$row=[$date,$daily['outlet_code'],$daily['outlet_name'],$entry['nisj'],$entry['full_name'],$entry['division'],$daily['status']];$map=collect($entry['scores'])->keyBy('criterion_code');foreach($criteria as$c)$row[]=$map->has($c['code'])?$map[$c['code']]['score']:'';$row[]= $entry['note'] ?? '';$row[]=$entry['raw_score'];$row[]=$entry['max_score'];$row[]=$entry['normalized_percent'];$rows[]=$row;}
        return $this->xlsx->download('HR_KPI_SQUAD_DAILY_'.$date.'_'.($daily['outlet_code'] ?: 'OUTLET').'.xlsx','DAILY',$rows);
    }

    public function exportPeriod(Request $request, string $from, string $to, ?string $outletId, bool $includeDraft): Response
    {
        $period=$this->period($request,$from,$to,$outletId,$includeDraft,null);
        $summary=[['FROM','TO','OUTLET_CODE','NISJ','FULL_NAME','DIVISION','DAYS_REVIEWED','LOCKED_DAYS','RAW_SCORE','REVIEWED_MAX','QUALITY_%','MAX/DAY','TARGET_DAYS','WORKBOOK_MAX','GROOMING_40']];
        foreach($period['items'] as$r)$summary[]=[$from,$to,$r['outlet_code'],$r['nisj'],$r['full_name'],$r['division'],$r['days_reviewed'],$r['locked_days'],$r['raw_score'],$r['reviewed_max_score'],$r['quality_percent'],$r['max_score_per_day'],$r['target_days'],$r['workbook_max_score'],$r['grooming_component_40']];

        $allowed=$this->scope->allowedOutletIds($request);if($outletId)$allowed=[$outletId];
        $flatRows=DB::table('HR_kpi_daily_entries as e')->join('HR_kpi_daily_reviews as r','r.id','=','e.review_id')->join('outlets as o','o.id','=','r.outlet_id')
            ->whereBetween('r.review_date',[$from,$to])->whereIn('r.outlet_id',$allowed)->when(!$includeDraft,fn($q)=>$q->where('r.status','locked'))->orderBy('r.review_date')->orderBy('o.name')->orderBy('e.name_snapshot')
            ->get(['e.*','r.id as review_id','r.review_date','r.status','o.code as outlet_code','o.name as outlet_name']);
        $entryIds=$flatRows->pluck('id')->all();$scores=DB::table('HR_kpi_daily_scores')->whereIn('entry_id',$entryIds)->orderBy('criterion_code_snapshot')->get()->groupBy('entry_id');
        $criterionCodes=$scores->flatten(1)->pluck('criterion_code_snapshot')->unique()->sort()->values();
        $dailyHeader=['DATE','OUTLET_CODE','OUTLET_NAME','NISJ','FULL_NAME','DIVISION','STATUS'];foreach($criterionCodes as$c)$dailyHeader[]=$c;$dailyHeader=array_merge($dailyHeader,['NOTE','TOTAL_SCORE','MAX_SCORE','GROOMING_PERCENT']);$dailyRows=[$dailyHeader];
        foreach($flatRows as$r){$row=[$r->review_date,$r->outlet_code,$r->outlet_name,$r->nisj_snapshot,$r->name_snapshot,$r->division_snapshot,$r->status];$map=collect($scores->get($r->id,collect()))->keyBy('criterion_code_snapshot');foreach($criterionCodes as$c)$row[]=$map->has($c)?$map[$c]->score:'';$row[]=$r->note;$row[]=$r->raw_score;$row[]=$r->max_score;$row[]=$r->normalized_percent;$dailyRows[]=$row;}
        return $this->xlsx->downloadWorkbook('HR_KPI_SQUAD_PERIOD_'.$from.'_TO_'.$to.'.xlsx',[['name'=>'SUMMARY','rows'=>$summary],['name'=>'DAILY','rows'=>$dailyRows]]);
    }

    public function importWorkbook(Request $request, UploadedFile $file, string $mode, ?User $actor, ?string $requestedOutletId = null, ?string $requestedDate = null): array
    {
        $sheets=$this->xlsx->readWorksheets($file);$dailySheet=collect($sheets)->first(fn($s)=>strtoupper(trim((string)$s['name']))==='DAILY') ?? ($sheets[0] ?? null);
        if(!$dailySheet || count($dailySheet['rows'])<2) throw ValidationException::withMessages(['file'=>['Worksheet DAILY tidak ditemukan atau tidak memiliki data.']]);
        $rows=$dailySheet['rows'];$headers=array_map(fn($v)=>strtoupper(trim((string)$v)),$rows[0]);$index=array_flip($headers);
        foreach(['DATE','OUTLET_CODE','NISJ'] as$required)if(!array_key_exists($required,$index))throw ValidationException::withMessages(['file'=>["Kolom {$required} wajib ada."]]);
        $outlets=collect($this->scope->options($request))->keyBy(fn($o)=>strtoupper((string)$o['code']));
        $groups=[];$errors=[];
        for($i=1;$i<count($rows);$i++){
            $row=$rows[$i];$line=$i+1;$date=trim((string)($row[$index['DATE']] ?? ''));$outletCode=strtoupper(trim((string)($row[$index['OUTLET_CODE']] ?? '')));$nisj=trim((string)($row[$index['NISJ']] ?? ''));
            if($date===''&&$outletCode===''&&$nisj==='')continue;
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){$errors[]="Baris {$line}: DATE harus YYYY-MM-DD.";continue;}
            $outlet=$outlets->get($outletCode);if(!$outlet){$errors[]="Baris {$line}: OUTLET_CODE {$outletCode} di luar scope/tidak ditemukan.";continue;}
            if($nisj===''){$errors[]="Baris {$line}: NISJ wajib diisi.";continue;}
            if($mode==='daily' && (($requestedOutletId && (string)$outlet['id']!==$requestedOutletId)||($requestedDate && $date!==$requestedDate))){$errors[]="Baris {$line}: file DAILY tidak sesuai outlet/tanggal yang dipilih.";continue;}
            $key=(string)$outlet['id'].'|'.$date;$groups[$key]??=['outlet_id'=>(string)$outlet['id'],'date'=>$date,'rows'=>[]];$groups[$key]['rows'][]=['line'=>$line,'nisj'=>$nisj,'row'=>$row,'headers'=>$headers];
        }
        if($errors!==[])throw ValidationException::withMessages(['file'=>array_slice($errors,0,100)]);
        if($groups===[])throw ValidationException::withMessages(['file'=>['Tidak ada baris data untuk diimport.']]);
        if($mode==='daily'&&count($groups)!==1)throw ValidationException::withMessages(['file'=>['Mode daily hanya boleh berisi satu outlet dan satu tanggal.']]);

        $plans=[];
        foreach($groups as$group){
            $daily=$this->daily($request,$group['outlet_id'],$group['date']);if($daily['status']==='locked'){$errors[]="{$group['date']} {$daily['outlet_code']}: review locked.";continue;}
            $entries=collect($daily['entries'])->keyBy(fn($e)=>strtolower(trim((string)$e['nisj'])));$seen=[];$planned=$daily['entries'];$plannedMap=[];foreach($planned as$k=>$e)$plannedMap[strtolower(trim((string)$e['nisj']))]=$k;
            foreach($group['rows'] as$importRow){$key=strtolower($importRow['nisj']);if(isset($seen[$key])){$errors[]="Baris {$importRow['line']}: NISJ {$importRow['nisj']} duplikat pada outlet/tanggal sama.";continue;}$seen[$key]=true;$entry=$entries->get($key);if(!$entry){$errors[]="Baris {$importRow['line']}: NISJ {$importRow['nisj']} bukan Squad aktif di outlet/tanggal tersebut.";continue;}$pos=$plannedMap[$key];$scoreMap=collect($planned[$pos]['scores'])->keyBy('criterion_code');foreach($importRow['headers'] as$col=>$header){if(!$scoreMap->has($header))continue;$raw=trim((string)($importRow['row'][$col] ?? ''));if($raw==='')continue;if(!is_numeric($raw)){$errors[]="Baris {$importRow['line']}: {$header} harus angka.";continue 2;}$max=(float)$scoreMap[$header]['max_score'];$value=(float)$raw;if($value<0||$value>$max){$errors[]="Baris {$importRow['line']}: {$header} harus 0 s/d {$max}.";continue 2;}foreach($planned[$pos]['scores'] as&$s)if($s['criterion_code']===$header)$s['score']=$value;unset($s);}if(isset($index['NOTE']))$planned[$pos]['note']=trim((string)($importRow['row'][$index['NOTE']] ?? ''));}
            $plans[]=['outlet_id'=>$group['outlet_id'],'date'=>$group['date'],'entries'=>$planned,'notes'=>$daily['notes'] ?? null];
        }
        if($errors!==[])throw ValidationException::withMessages(['file'=>array_slice($errors,0,100)]);

        DB::transaction(function()use($request,$plans,$actor,$mode){foreach($plans as$plan)$this->persistDaily($request,$plan['outlet_id'],$plan['date'],['entries'=>$plan['entries'],'notes'=>$plan['notes'],'audit_note'=>'Import XLSX '.strtoupper($mode)],$actor,'import_'.$mode);});
        return ['mode'=>$mode,'review_count'=>count($plans),'row_count'=>array_sum(array_map(fn($g)=>count($g['rows']),$groups)),'message'=>'Import KPI berhasil tanpa partial update.'];
    }

    private function reviewPayload(object $review, object $outlet): array
    {
        $snapshot=$this->decodeSnapshot($review->criteria_snapshot);$entries=DB::table('HR_kpi_daily_entries')->where('review_id',$review->id)->orderBy('name_snapshot')->get();
        $scores=DB::table('HR_kpi_daily_scores as s')->join('HR_kpi_daily_entries as e','e.id','=','s.entry_id')->where('e.review_id',$review->id)->orderBy('s.criterion_code_snapshot')->get(['s.*','e.employee_id'])->groupBy('employee_id');
        $payloadEntries=$entries->map(function($e)use($scores){return[
            'id'=>(string)$e->id,'employee_id'=>(string)$e->employee_id,'squad_id'=>$e->squad_id? (int)$e->squad_id:null,'nisj'=>(string)($e->nisj_snapshot ?? ''),'full_name'=>(string)$e->name_snapshot,
            'division'=>(string)$e->division_snapshot,'raw_score'=>(float)$e->raw_score,'max_score'=>(float)$e->max_score,'normalized_percent'=>(float)$e->normalized_percent,'note'=>$e->note,
            'scores'=>collect($scores->get((string)$e->employee_id,collect()))->map(fn($s)=>['id'=>(string)$s->id,'criterion_id'=>$s->criterion_id? (string)$s->criterion_id:null,'criterion_code'=>(string)$s->criterion_code_snapshot,'criterion_name'=>(string)$s->criterion_name_snapshot,'max_score'=>(float)$s->max_score_snapshot,'score'=>(float)$s->score,'note'=>$s->note])->values()->all(),
        ];})->values()->all();
        $audits=DB::table('HR_kpi_daily_audits as a')->leftJoin('users as u','u.id','=','a.actor_user_id')->where('a.review_id',$review->id)->orderByDesc('a.created_at')->limit(30)->get(['a.*','u.name as actor_name'])->map(fn($a)=>['id'=>(string)$a->id,'event_type'=>(string)$a->event_type,'actor_name'=>(string)($a->actor_name ?? '-'),'note'=>$a->note,'created_at'=>(string)$a->created_at])->values()->all();
        $lockName=$review->locked_by_user_id?DB::table('users')->where('id',$review->locked_by_user_id)->value('name'):null;$reopenName=$review->reopened_by_user_id?DB::table('users')->where('id',$review->reopened_by_user_id)->value('name'):null;
        return ['id'=>(string)$review->id,'exists'=>true,'outlet_id'=>(string)$review->outlet_id,'outlet_code'=>(string)($outlet->code ?? ''),'outlet_name'=>(string)($outlet->name ?? '-'),'review_date'=>(string)$review->review_date,'status'=>(string)$review->status,'revision'=>(int)$review->revision,'rule_version'=>(string)$review->rule_version,'notes'=>$review->notes,'locked_at'=>$review->locked_at,'locked_by_name'=>$lockName,'reopened_at'=>$review->reopened_at,'reopened_by_name'=>$reopenName,'reopen_reason'=>$review->reopen_reason,'criteria'=>$this->criteriaUnion($snapshot),'entries'=>$payloadEntries,'audits'=>$audits,'settings'=>$this->settings()];
    }

    private function defaultEntries(Collection $universe, array $snapshot): array
    {
        return $universe->map(function($e)use($snapshot){$division=strtoupper(trim((string)$e->division_name));$criteria=collect($snapshot[$division]??[]);$scores=$criteria->map(fn($c)=>['criterion_id'=>$c['id'],'criterion_code'=>$c['code'],'criterion_name'=>$c['name'],'max_score'=>(float)$c['max_score'],'score'=>(float)$c['max_score'],'note'=>null])->values()->all();$max=(float)$criteria->sum('max_score');return['id'=>null,'employee_id'=>(string)$e->employee_id,'squad_id'=>$e->squad_id?(int)$e->squad_id:null,'nisj'=>(string)($e->nisj??''),'full_name'=>(string)$e->full_name,'division'=>$division,'raw_score'=>$max,'max_score'=>$max,'normalized_percent'=>$max>0?100:0,'note'=>null,'scores'=>$scores];})->values()->all();
    }

    private function employeeUniverse(string $outletId, string $date): Collection
    {
        return DB::table('assignments as a')->join('employees as e','e.id','=','a.employee_id')->join('users as u','u.id','=','e.user_id')
            ->leftJoin('HR_squads as s',function($join){$join->on(DB::raw('LOWER(TRIM(s.nisj))'),'=',DB::raw('LOWER(TRIM(e.nisj))'))->whereNull('s.deleted_at');})
            ->where('a.outlet_id',$outletId)->where(function($q)use($date){$q->whereNull('a.start_date')->orWhereDate('a.start_date','<=',$date);})
            ->where(function($q)use($date){$q->whereNull('a.end_date')->orWhereDate('a.end_date','>=',$date);})
            ->where(function($q){$q->whereNull('a.status')->orWhereRaw("LOWER(a.status) NOT IN ('inactive','cancelled','terminated')");})
            ->where('u.is_active',true)->whereNotNull('s.id')->whereRaw("LOWER(COALESCE(s.status,'active'))='active'")
            ->where(function($q){$q->whereNull('s.role_name')->orWhereRaw("LOWER(s.role_name) NOT IN ('stakeholder','observer')");})
            ->select(['e.id as employee_id','e.nisj','e.full_name','s.id as squad_id','s.division_name','a.is_primary','a.created_at'])
            ->orderByDesc('a.is_primary')->orderByDesc('a.created_at')->get()->unique('employee_id')->map(function($r){$r->division_name=strtoupper(trim((string)($r->division_name ?? '')));return$r;})->sortBy('full_name',SORT_NATURAL|SORT_FLAG_CASE)->values();
    }

    private function buildCriteriaSnapshot(array $divisions): array
    {
        if($divisions===[])return[];$rows=DB::table('HR_kpi_grooming_criteria')->where('is_active',true)->whereIn('division_name',array_map(fn($d)=>strtoupper(trim((string)$d)),$divisions))->orderBy('division_name')->orderBy('sort_order')->get();
        return $rows->groupBy('division_name')->map(fn($group)=>$group->map(fn($c)=>$this->criterionPayload($c))->values()->all())->all();
    }

    private function criteriaUnion(array $snapshot): array
    {
        return collect($snapshot)->flatten(1)->unique('code')->sortBy('sort_order')->values()->all();
    }

    private function criterionPayload(object $row): array
    {
        return ['id'=>(string)$row->id,'division_name'=>(string)$row->division_name,'code'=>(string)$row->code,'name'=>(string)$row->name,'max_score'=>(float)$row->max_score,'sort_order'=>(int)$row->sort_order,'is_active'=>(bool)$row->is_active,'description'=>$row->description];
    }

    private function decodeSnapshot(mixed $json): array
    {
        if(is_array($json))return$json;$decoded=json_decode((string)$json,true);return is_array($decoded)?$decoded:[];
    }

    private function settings(): array
    {
        return DB::table('HR_kpi_settings')->get()->mapWithKeys(function($row){$v=json_decode((string)$row->value_json,true);return[(string)$row->code=>is_array($v)?$v:[]];})->all();
    }

    private function audit(string $reviewId, string $event, ?User $actor, array $snapshot = [], ?string $note = null): void
    {
        DB::table('HR_kpi_daily_audits')->insert(['id'=>(string)Str::ulid(),'review_id'=>$reviewId,'event_type'=>$event,'actor_user_id'=>$actor?->id,'payload_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),'note'=>$note,'created_at'=>now()]);
    }

    private function assertOutlet(Request $request, string $outletId): void
    {
        if(!$this->scope->isOutletAllowed($request,$outletId))throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope user.']]);
    }

    private function periodSummary(array $items): array
    {
        $c=collect($items);return['employees'=>$c->count(),'outlets'=>$c->pluck('outlet_id')->unique()->count(),'avg_quality_percent'=>$c->isEmpty()?0:round((float)$c->avg('quality_percent'),2),'avg_grooming_component_40'=>$c->isEmpty()?0:round((float)$c->avg('grooming_component_40'),4)];
    }
}
