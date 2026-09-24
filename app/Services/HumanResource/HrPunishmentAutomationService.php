<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrPunishmentRule;
use App\Models\HumanResource\HrViolation;
use App\Models\HumanResource\HrViolationRecommendation;
use App\Models\HumanResource\HrWarningLetter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrPunishmentAutomationService
{
    public function __construct(private readonly HrSpValiditySettingService $spValidity) {}

    public function sweep(?string $from = null, ?string $to = null, int $limit = 500): array
    {
        if (!Schema::hasTable('HR_shift_schedules') || !Schema::hasTable('HR_violations')) {
            return ['schedules_scanned'=>0,'violations_created'=>0,'recommendations_created'=>0];
        }
        $today = CarbonImmutable::today('Asia/Jakarta');
        $fromDate = $from ? CarbonImmutable::parse($from)->startOfDay() : $today->subDay();
        $toDate = $to ? CarbonImmutable::parse($to)->startOfDay() : $today;
        if ($fromDate->gt($toDate)) [$fromDate, $toDate] = [$toDate, $fromDate];

        $rules = HrPunishmentRule::query()->where('is_active', true)->get()->keyBy('event_type');
        $lateRule = $rules->get('late');
        $alphaRule = $rules->get('alpha');
        $grace = max((int)($lateRule?->grace_minutes ?? 0), (int)($alphaRule?->grace_minutes ?? 60));
        $nowUtc = CarbonImmutable::now('UTC');

        $schedules = DB::table('HR_shift_schedules as s')
            ->join('employees as e','e.id','=','s.employee_id')
            ->join('users as u','u.id','=','e.user_id')
            ->whereBetween('s.work_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->where('s.schedule_type','shift')->where('u.is_active',true)
            ->orderBy('s.work_date')->limit(max(1,min($limit,3000)))
            ->get(['s.*','e.nisj','e.full_name','e.user_id']);

        $created = 0;
        $touchedEmployees = [];
        foreach ($schedules as $schedule) {
            if (!$this->scheduleClosed($schedule, $nowUtc, $grace)) continue;
            $employeeId = (string)$schedule->employee_id;
            $date = (string)$schedule->work_date;
            $attendance = DB::table('HR_attendances')->where('employee_id',$employeeId)->whereDate('business_date',$date)->first();
            $squad = $this->squadForNisj($schedule->nisj ?? null);

            if (!$attendance) {
                if (!$alphaRule || !$alphaRule->auto_create_violation || $this->hasApprovedLeave($employeeId, $date)) continue;
                $fingerprint = hash('sha256','alpha|schedule|'.(string)$schedule->id);
                $row = HrViolation::query()->firstOrCreate(['fingerprint'=>$fingerprint], [
                    'employee_id'=>$employeeId,'squad_id'=>$squad?->id,'outlet_id'=>$schedule->outlet_id,
                    'violation_type'=>'alpha','violation_date'=>$date,'title'=>'Alpha / Tidak Absen',
                    'description'=>'Tidak ditemukan attendance pada jadwal shift yang telah selesai. Draft pelanggaran dibuat otomatis dan wajib direview.',
                    'severity'=>'high','late_minutes'=>0,'source_type'=>'schedule','source_ref'=>(string)$schedule->id,
                    'status'=>'draft','metadata'=>['automation'=>'iteration-13','schedule_id'=>(string)$schedule->id],
                ]);
                if ($row->wasRecentlyCreated) $created++;
                $touchedEmployees[$employeeId] = true;
                continue;
            }

            if ($lateRule && $lateRule->auto_create_violation && !empty($attendance->checkin_at)) {
                $lateMinutes = $this->lateMinutes($schedule, $attendance);
                if ($lateMinutes > 0) {
                    $fingerprint = hash('sha256','late|attendance|'.(string)$attendance->id);
                    $row = HrViolation::query()->firstOrCreate(['fingerprint'=>$fingerprint], [
                        'employee_id'=>$employeeId,'squad_id'=>$squad?->id,'outlet_id'=>$schedule->outlet_id,
                        'violation_type'=>'late','violation_date'=>$date,'title'=>'Terlambat '.$lateMinutes.' menit',
                        'description'=>'Pelanggaran terlambat terdeteksi otomatis dari jadwal dan timestamp check-in.',
                        'severity'=>'medium','late_minutes'=>$lateMinutes,'source_type'=>'attendance','source_ref'=>(string)$attendance->id,
                        // Lateness is objective attendance evidence. It is system-approved so the 2x rule can recommend SP immediately.
                        'status'=>'approved','approved_at'=>now(),'metadata'=>['automation'=>'iteration-13','schedule_id'=>(string)$schedule->id],
                    ]);
                    if ($row->wasRecentlyCreated) $created++;
                    $touchedEmployees[$employeeId] = true;
                }
            }
        }

        $recommendations = 0;
        foreach (array_keys($touchedEmployees) as $employeeId) {
            $recommendations += $this->evaluateEmployee($employeeId);
        }
        // Also catch previously-approved alpha violations that have not yet been evaluated.
        $pendingEmployees = HrViolation::query()->where('status','approved')->whereNull('recommendation_id')
            ->whereBetween('violation_date', [$today->subDays(120)->toDateString(), $today->toDateString()])
            ->distinct()->limit(500)->pluck('employee_id');
        foreach ($pendingEmployees as $employeeId) $recommendations += $this->evaluateEmployee((string)$employeeId);

        return ['schedules_scanned'=>$schedules->count(),'violations_created'=>$created,'recommendations_created'=>$recommendations];
    }

    public function evaluateEmployee(string $employeeId): int
    {
        $openLetter = HrWarningLetter::query()->where('employee_id',$employeeId)->whereIn('status',['draft','submitted'])->exists();
        $openRecommendation = HrViolationRecommendation::query()->where('employee_id',$employeeId)->where('status','pending')->exists();
        if ($openLetter || $openRecommendation) return 0;

        $currentLevel = $this->spValidity->currentLevelForEmployee($employeeId);
        if ($currentLevel >= 3) return 0;
        $nextLevel = $currentLevel + 1;
        $now = CarbonImmutable::today('Asia/Jakarta');

        foreach (HrPunishmentRule::query()->where('is_active',true)->where('auto_recommend_sp',true)->orderBy('id')->get() as $rule) {
            $windowTo = $now->toDateString();
            $windowFrom = $now->subDays(max(0,(int)$rule->window_days - 1))->toDateString();
            $query = HrViolation::query()->where('employee_id',$employeeId)->where('violation_type',$rule->event_type)
                ->where('status','approved')->whereNull('recommendation_id')->whereBetween('violation_date',[$windowFrom,$windowTo])
                ->orderBy('violation_date')->orderBy('created_at');
            $qualifying = $query->limit(max(1,(int)$rule->threshold_count))->get();
            if ($qualifying->count() < max(1,(int)$rule->threshold_count)) continue;

            return DB::transaction(function () use ($employeeId,$rule,$windowFrom,$windowTo,$qualifying,$nextLevel): int {
                if (HrViolationRecommendation::query()->where('employee_id',$employeeId)->where('status','pending')->lockForUpdate()->exists()) return 0;
                $fresh = HrViolation::query()->whereIn('id',$qualifying->pluck('id'))->whereNull('recommendation_id')->where('status','approved')->lockForUpdate()->get();
                if ($fresh->count() < max(1,(int)$rule->threshold_count)) return 0;
                $ids = $fresh->take((int)$rule->threshold_count)->pluck('id')->map(fn($v)=>(string)$v)->values()->all();
                $first = $fresh->first();
                $squad = $this->squadForEmployee($employeeId);
                $fingerprint = hash('sha256',implode('|',['rule',(string)$rule->id,$employeeId,(string)$nextLevel,implode(',',$ids)]));
                $rec = HrViolationRecommendation::query()->firstOrCreate(['fingerprint'=>$fingerprint], [
                    'employee_id'=>$employeeId,'squad_id'=>$squad?->id,'outlet_id'=>$first?->outlet_id,'rule_id'=>$rule->id,
                    'recommended_sp_level'=>$nextLevel,'window_from'=>$windowFrom,'window_to'=>$windowTo,
                    'qualifying_count'=>count($ids),'violation_ids'=>$ids,
                    'reason'=>$rule->name.' terpenuhi ('.count($ids).' kejadian dalam '.$rule->window_days.' hari). Rekomendasi SP-'.$nextLevel.' dibuat otomatis.',
                    'status'=>'pending',
                ]);
                if ($rec->wasRecentlyCreated) {
                    HrViolation::query()->whereIn('id',$ids)->update(['recommendation_id'=>$rec->id,'updated_at'=>now()]);
                    return 1;
                }
                return 0;
            });
        }
        return 0;
    }

    private function scheduleClosed(object $schedule, CarbonImmutable $nowUtc, int $grace): bool
    {
        $timezone = trim((string)($schedule->outlet_timezone_snapshot ?? 'Asia/Jakarta')) ?: 'Asia/Jakarta';
        if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Jakarta';
        $endTime = substr((string)($schedule->end_time_snapshot ?? ''),0,5);
        if ($endTime === '') return false;
        try {
            $end = CarbonImmutable::createFromFormat('Y-m-d H:i',(string)$schedule->work_date.' '.$endTime,$timezone);
            $startTime = substr((string)($schedule->start_time_snapshot ?? ''),0,5);
            if ((bool)($schedule->is_overnight_snapshot ?? false) || ($startTime !== '' && $endTime <= $startTime)) $end = $end->addDay();
            return $end->addMinutes(max(0,$grace))->utc()->lte($nowUtc);
        } catch (\Throwable) { return false; }
    }

    private function lateMinutes(object $schedule, object $attendance): int
    {
        $timezone = trim((string)($schedule->outlet_timezone_snapshot ?? 'Asia/Jakarta')) ?: 'Asia/Jakarta';
        if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'Asia/Jakarta';
        $startTime = substr((string)($schedule->start_time_snapshot ?? ''),0,5);
        if ($startTime === '') return 0;
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i',(string)$schedule->work_date.' '.$startTime,$timezone);
            $checkin = CarbonImmutable::parse((string)$attendance->checkin_at,'UTC')->setTimezone($timezone);
            return $checkin->gt($start) ? max(0,(int)floor($start->diffInSeconds($checkin)/60)) : 0;
        } catch (\Throwable) { return 0; }
    }

    private function hasApprovedLeave(string $employeeId, string $date): bool
    {
        return Schema::hasTable('HR_leave_requests') && DB::table('HR_leave_requests')->where('employee_id',$employeeId)
            ->where('status','approved')->whereDate('start_date','<=',$date)->whereDate('end_date','>=',$date)->exists();
    }

    private function squadForNisj(mixed $nisj): ?object
    {
        $value = trim((string)($nisj ?? ''));
        if ($value === '' || !Schema::hasTable('HR_squads')) return null;
        return DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower($value)])->first();
    }

    private function squadForEmployee(string $employeeId): ?object
    {
        $nisj = DB::table('employees')->where('id',$employeeId)->value('nisj');
        return $this->squadForNisj($nisj);
    }
}
