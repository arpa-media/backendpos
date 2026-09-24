<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrCareerPortalService
{
    public function __construct(private readonly HrCareerDocumentService $documents) {}

    public function dashboard(HrCareerAccount $account): array
    {
        $profile = $this->profile($account);
        return [
            'account' => [
                'id' => (string) $account->id, 'nik' => (string) $account->nik, 'full_name' => (string) $account->full_name,
                'phone' => (string) $account->phone, 'email' => $account->email, 'must_change_password' => (bool) $account->must_change_password,
                'profile_complete' => $account->profile_completed_at !== null,
                'application_blocked' => $account->application_blocked_at !== null,
                'application_block_reason' => $account->application_block_reason,
            ],
            'profile' => $profile,
            'applications' => $this->applications($account),
            'metrics' => [
                'applications' => DB::table('HR_applications')->where('career_account_id', $account->id)->count(),
                'interviews' => DB::table('HR_interviews as i')->join('HR_applications as a', 'a.id', '=', 'i.application_id')->where('a.career_account_id', $account->id)->count(),
            ],
        ];
    }

    public function profile(HrCareerAccount $account): array
    {
        $p = DB::table('HR_career_profiles')->where('career_account_id', $account->id)->first();
        $cv = $this->documents->currentCv($account);
        return [
            'account_id' => (string) $account->id,
            'nik' => (string) $account->nik,
            'full_name' => (string) $account->full_name,
            'phone' => (string) $account->phone,
            'email' => $account->email,
            'nickname' => $p?->nickname, 'address' => $p?->address, 'city' => $p?->city, 'province' => $p?->province,
            'birth_place' => $p?->birth_place, 'birth_date' => $p?->birth_date, 'gender' => $p?->gender,
            'religion' => $p?->religion, 'education' => $p?->education, 'school_name' => $p?->school_name,
            'major' => $p?->major, 'marital_status' => $p?->marital_status, 'children_count' => (int) ($p?->children_count ?? 0),
            'whatsapp' => $p?->whatsapp ?: $account->phone,
            'emergency_contact_name' => $p?->emergency_contact_name, 'emergency_contact_phone' => $p?->emergency_contact_phone,
            'work_experience' => $p?->work_experience, 'skills' => $p?->skills,
            'cv' => $cv ? $this->documents->metadata($cv) : null,
            'profile_complete' => $account->profile_completed_at !== null,
        ];
    }

    public function saveProfile(HrCareerAccount $account, array $data): array
    {
        return DB::transaction(function () use ($account, $data): array {
            $account->forceFill([
                'full_name' => trim((string) $data['full_name']),
                'phone' => trim((string) $data['phone']),
                'email' => ! empty($data['email']) ? strtolower(trim((string) $data['email'])) : null,
            ])->save();
            $payload = [
                'nickname' => $this->nullString($data['nickname'] ?? null), 'address' => $this->nullString($data['address'] ?? null),
                'city' => $this->nullString($data['city'] ?? null), 'province' => $this->nullString($data['province'] ?? null),
                'birth_place' => $this->nullString($data['birth_place'] ?? null), 'birth_date' => $data['birth_date'] ?? null,
                'gender' => $this->nullString($data['gender'] ?? null), 'religion' => $this->nullString($data['religion'] ?? null),
                'education' => $this->nullString($data['education'] ?? null), 'school_name' => $this->nullString($data['school_name'] ?? null),
                'major' => $this->nullString($data['major'] ?? null), 'marital_status' => $this->nullString($data['marital_status'] ?? null),
                'children_count' => max(0, (int) ($data['children_count'] ?? 0)), 'whatsapp' => $this->nullString($data['whatsapp'] ?? $data['phone'] ?? null),
                'emergency_contact_name' => $this->nullString($data['emergency_contact_name'] ?? null),
                'emergency_contact_phone' => $this->nullString($data['emergency_contact_phone'] ?? null),
                'work_experience' => $this->nullString($data['work_experience'] ?? null), 'skills' => $this->nullString($data['skills'] ?? null),
                'updated_at' => now(),
            ];
            $exists = DB::table('HR_career_profiles')->where('career_account_id', $account->id)->exists();
            if ($exists) DB::table('HR_career_profiles')->where('career_account_id', $account->id)->update($payload);
            else DB::table('HR_career_profiles')->insert(array_merge(['id' => (string) Str::ulid(), 'career_account_id' => $account->id, 'created_at' => now()], $payload));
            $this->refreshCompletion($account);
            return $this->profile($account->fresh());
        });
    }

    public function refreshCompletion(HrCareerAccount $account): bool
    {
        $p = DB::table('HR_career_profiles')->where('career_account_id', $account->id)->first();
        $cv = $this->documents->currentCv($account);
        $complete = $p && $cv
            && trim((string) $account->full_name) !== '' && trim((string) $account->nik) !== '' && trim((string) $account->phone) !== ''
            && trim((string) $p->address) !== '' && trim((string) $p->birth_place) !== '' && ! empty($p->birth_date)
            && trim((string) $p->gender) !== '' && trim((string) $p->education) !== '' && trim((string) ($p->whatsapp ?: $account->phone)) !== '';
        $account->forceFill(['profile_completed_at' => $complete ? ($account->profile_completed_at ?: now()) : null])->save();
        return (bool) $complete;
    }

    public function recruitments(HrCareerAccount $account): array
    {
        if (! $account->profile_completed_at) return [];
        $now = now();
        $rows = DB::table('HR_recruitments as r')
            ->whereNull('r.deleted_at')->where('r.status', 'published')
            ->where(fn ($q) => $q->whereNull('r.active_from')->orWhere('r.active_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('r.active_until')->orWhere('r.active_until', '>=', $now))
            ->orderBy('r.active_until')->get(['r.*']);
        return $rows->map(function ($r) use ($account) {
            $positions = DB::table('HR_recruitment_positions as p')->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
                ->where('p.recruitment_id', $r->id)->whereNull('p.deleted_at')->where('p.is_active', true)
                ->orderBy('p.sort_order')->get(['p.*','o.name as destination_name','o.code as destination_code'])
                ->map(function ($p) {
                    $p->qualifications = DB::table('HR_recruitment_qualifications')->where('recruitment_position_id', $p->id)->orderBy('sort_order')->get(['requirement_type','label'])->map(fn ($q) => (array) $q)->all();
                    return (array) $p;
                })->all();
            $existing = DB::table('HR_applications')->where('career_account_id', $account->id)->where('recruitment_id', $r->id)->first();
            return [
                'id' => (string) $r->id, 'code' => (string) $r->code, 'title' => (string) $r->title, 'description' => $r->description,
                'active_from' => $r->active_from, 'active_until' => $r->active_until, 'positions' => $positions,
                'applied' => $existing !== null, 'application_stage' => $existing?->stage,
            ];
        })->values()->all();
    }

    public function apply(HrCareerAccount $account, string $recruitmentId, string $positionId): array
    {
        if ($account->must_change_password) throw ValidationException::withMessages(['password' => ['Ganti password awal terlebih dahulu sebelum melamar.']]);
        if (! $account->profile_completed_at) throw ValidationException::withMessages(['profile' => ['Lengkapi Data Pribadi dan upload CV sebelum melamar.']]);
        if ($account->application_blocked_at) throw ValidationException::withMessages(['application' => ['Akun diblokir dari lamaran baru karena hasil recruitment sebelumnya: '.($account->application_block_reason ?: 'rejected_all').'.']]);

        return DB::transaction(function () use ($account, $recruitmentId, $positionId): array {
            $rec = DB::table('HR_recruitments')->where('id', $recruitmentId)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $rec || (string) $rec->status !== 'published') throw ValidationException::withMessages(['recruitment' => ['Recruitment tidak aktif.']]);
            $now = now();
            if (($rec->active_from && $now->lt($rec->active_from)) || ($rec->active_until && $now->gt($rec->active_until))) throw ValidationException::withMessages(['recruitment' => ['Periode recruitment tidak aktif.']]);
            $position = DB::table('HR_recruitment_positions')->where('id', $positionId)->where('recruitment_id', $rec->id)->whereNull('deleted_at')->where('is_active', true)->first();
            if (! $position) throw ValidationException::withMessages(['position_id' => ['Posisi tidak tersedia.']]);
            $existing = DB::table('HR_applications')->where('career_account_id', $account->id)->where('recruitment_id', $rec->id)->first();
            if ($existing) throw ValidationException::withMessages(['recruitment' => ['Anda sudah melamar pada recruitment card ini.']]);
            $id = (string) Str::ulid();
            $application = [
                'id' => $id, 'recruitment_id' => $rec->id, 'recruitment_position_id' => $position->id,
                'registration_request_id' => $account->registration_request_id, 'career_account_id' => $account->id,
                'nik' => $account->nik, 'applicant_name' => $account->full_name, 'email' => $account->email, 'phone' => $account->phone,
                'stage' => 'applied', 'applied_at' => now(), 'stage_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
            if (Schema::hasColumn('HR_applications', 'workflow_stage')) {
                $application['workflow_stage'] = 'applied';
                $application['workflow_outcome'] = null;
                $application['workflow_updated_at'] = now();
            }
            DB::table('HR_applications')->insert($application);
            $this->stageHistory($id, null, 'applied', 'career', null, $account->id, 'Applicant mengirim lamaran.', ['position_id' => $position->id]);
            return $this->applicationDetail($account, $id);
        });
    }

    public function applications(HrCareerAccount $account): array
    {
        return DB::table('HR_applications as a')->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->where('a.career_account_id', $account->id)->orderByDesc('a.applied_at')
            ->get(['a.id','a.stage','a.applied_at','a.stage_changed_at','a.notes','r.code as recruitment_code','r.title as recruitment_title','p.position_name','p.employment_type_target','o.name as destination_name'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function applicationDetail(HrCareerAccount $account, string $id): array
    {
        $a = DB::table('HR_applications as a')->join('HR_recruitments as r','r.id','=','a.recruitment_id')->join('HR_recruitment_positions as p','p.id','=','a.recruitment_position_id')->join('outlets as o','o.id','=','p.destination_outlet_id')
            ->where('a.id',$id)->where('a.career_account_id',$account->id)->first(['a.*','r.code as recruitment_code','r.title as recruitment_title','p.position_name','o.name as destination_name']);
        abort_unless($a, 404, 'Lamaran tidak ditemukan.');
        $history = DB::table('HR_application_stage_histories')->where('application_id',$id)->orderBy('changed_at')->get(['from_stage','to_stage','source','note','changed_at'])->map(fn($r)=>(array)$r)->all();
        $interviews = DB::table('HR_interviews')->where('application_id',$id)->orderBy('sequence_no')->get(['sequence_no','scheduled_at','conducted_at','interviewer_name_snapshot','recommendation','result','recorded_at'])->map(fn($r)=>(array)$r)->all();
        return ['application'=>(array)$a,'history'=>$history,'interviews'=>$interviews];
    }

    public function stageHistory(string $applicationId, ?string $from, string $to, string $source, ?string $userId, ?string $careerId, ?string $note, array $metadata = []): void
    {
        DB::table('HR_application_stage_histories')->insert([
            'id'=>(string)Str::ulid(),'application_id'=>$applicationId,'from_stage'=>$from,'to_stage'=>$to,'source'=>$source,
            'actor_user_id'=>$userId,'actor_career_account_id'=>$careerId,'note'=>$note,'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null,
            'changed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function nullString(mixed $value): ?string { $v = trim((string) $value); return $v === '' ? null : $v; }
}
