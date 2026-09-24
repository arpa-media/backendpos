<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\HrSquadUserWiringService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrHiringConversionService
{
    public function __construct(
        private readonly HrContractService $contracts,
        private readonly UserManagementService $users,
        private readonly HrSquadUserWiringService $wiring,
    ) {}

    public function convert(string $applicationId, string $hireType, array $payload, User $actor): array
    {
        $hireType = strtolower($hireType);
        if (! in_array($hireType, ['spt','pkwt'], true)) throw ValidationException::withMessages(['hire_type' => ['Hanya SPT atau PKWT yang dapat dikonversi.']]);
        $key = hash('sha256', 'career-hire-v1|'.$applicationId.'|'.$hireType);

        return DB::transaction(function () use ($applicationId, $hireType, $payload, $actor, $key): array {
            $application = DB::table('HR_applications')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application) abort(404, 'Application tidak ditemukan.');
            $existing = DB::table('HR_hiring_conversions')->where('application_id', $applicationId)->lockForUpdate()->first();
            if ($existing && (string) $existing->status === 'completed') return $this->payload($existing);
            if ($existing && (string) $existing->hire_type !== $hireType) throw ValidationException::withMessages(['hire_type' => ['Application sudah memiliki conversion dengan jenis penerimaan berbeda.']]);

            $position = DB::table('HR_recruitment_positions as p')->join('outlets as o','o.id','=','p.destination_outlet_id')->where('p.id',$application->recruitment_position_id)->first(['p.*','o.name as destination_name']);
            if (! $position) throw ValidationException::withMessages(['position' => ['Posisi recruitment tidak ditemukan.']]);
            $account = DB::table('HR_career_accounts')->where('id', $application->career_account_id)->first();
            $profile = $account ? DB::table('HR_career_profiles')->where('career_account_id', $account->id)->first() : null;
            if (! $account) throw ValidationException::withMessages(['career_account' => ['Career Account tidak ditemukan.']]);

            $conversionId = $existing?->id ?: (string) Str::ulid();
            if (! $existing) {
                DB::table('HR_hiring_conversions')->insert([
                    'id'=>$conversionId,'application_id'=>$applicationId,'conversion_key'=>$key,'hire_type'=>$hireType,'status'=>'processing',
                    'converted_by_user_id'=>$actor->id,'payload_snapshot'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $nisj = $existing?->assigned_nisj ?: ($hireType === 'spt' ? $this->nextOfficialNisj() : $this->nextTemporaryNisj((string) $account->full_name));
            $user = null; $squad = null; $employee = null;
            if ($hireType === 'pkwt') {
                [$user, $squad, $employee] = $this->createTemporaryOperationalIdentity($nisj, $account, $profile, $position, $actor);
            } else {
                [$squad, $employee] = $this->createOfficialSquadIdentity($nisj, $account, $profile, $position, $payload['contract_start_date'] ?? now()->toDateString());
            }

            $this->syncSquadProfile((int) $squad->id, $nisj, $account, $profile, $position, $hireType, $payload);
            if ($employee?->assignment_id) {
                $assignment = Assignment::query()->find($employee->assignment_id);
                if ($assignment) {
                    $assignment->start_date = $payload['contract_start_date'] ?? now()->toDateString();
                    $assignment->status = 'active';
                    $assignment->is_primary = true;
                    $assignment->save();
                }
            }
            $contractResult = $this->contracts->create([
                'squad_id' => (int) $squad->id,
                'contract_type' => strtoupper($hireType),
                'tmt_date' => $payload['contract_start_date'] ?? now()->toDateString(),
                'first_sk_date' => null,
                'start_date' => $payload['contract_start_date'] ?? now()->toDateString(),
                'end_date' => $payload['contract_end_date'] ?? null,
                'outlet_id' => (string) $position->destination_outlet_id,
                'assignment_label' => (string) $position->destination_name,
                'division_name' => $payload['division_name'] ?? null,
                'position_name' => (string) $position->position_name,
                'notes' => 'Draft otomatis dari Recruitment application '.$applicationId.'. '.trim((string) ($payload['contract_notes'] ?? '')),
                'source' => 'recruitment',
                'status' => 'draft',
            ], $actor);
            $contractId = (string) ($contractResult['contract']['id'] ?? '');
            if ($contractId === '') throw new \RuntimeException('Draft Contract recruitment gagal dibuat.');

            DB::table('HR_hiring_conversions')->where('id',$conversionId)->update([
                'status'=>'completed','assigned_nisj'=>$nisj,'operational_user_id'=>$user?->id,'squad_id'=>$squad->id,'employee_id'=>$employee?->id,
                'contract_id'=>$contractId,'converted_by_user_id'=>$actor->id,'converted_at'=>now(),'error_message'=>null,'updated_at'=>now(),
            ]);
            $row = DB::table('HR_hiring_conversions')->where('id',$conversionId)->first();
            return $this->payload($row);
        });
    }

    private function createOfficialSquadIdentity(string $nisj, object $account, ?object $profile, object $position, string $startDate): array
    {
        $squad = DB::table('HR_squads')->where('nisj',$nisj)->whereNull('deleted_at')->first();
        if (! $squad) {
            $id = DB::table('HR_squads')->insertGetId([
                'full_name'=>$account->full_name,'nickname'=>$profile?->nickname,'nik'=>$account->nik,'address'=>$profile?->address,'birth_place'=>$profile?->birth_place,
                'birth_date'=>$profile?->birth_date,'gender'=>$profile?->gender,'religion'=>$profile?->religion,'education'=>$profile?->education,'marital_status'=>$profile?->marital_status,
                'children_count'=>(int)($profile?->children_count??0),'whatsapp'=>$profile?->whatsapp ?: $account->phone,'email'=>$this->availableSquadEmail($account->email),
                'status'=>'active','nisj'=>$nisj,'employee_type'=>'SPT','contract_type'=>'SPT','contract_start_date'=>$startDate,
                'assignment'=>(string)$position->destination_outlet_id,'position_name'=>$position->position_name,'role_name'=>'SQUAD','access_role'=>'SQUAD_DEFAULT','leave_quota'=>3,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $squad = DB::table('HR_squads')->where('id',$id)->first();
        }
        $employee = Employee::query()->where('nisj',$nisj)->first();
        if (! $employee) {
            $employee = Employee::query()->create(['user_id'=>null,'assignment_id'=>null,'nisj'=>$nisj,'full_name'=>$account->full_name,'nickname'=>$profile?->nickname ?: $account->full_name,'employment_status'=>'SPT']);
            $assignment = Assignment::query()->create(['employee_id'=>$employee->id,'outlet_id'=>$position->destination_outlet_id,'role_title'=>$position->position_name,'start_date'=>$startDate,'is_primary'=>true,'status'=>'active']);
            $employee->assignment_id=$assignment->id; $employee->save();
        }
        return [$squad,$employee];
    }

    private function createTemporaryOperationalIdentity(string $nisj, object $account, ?object $profile, object $position, User $actor): array
    {
        $existing = User::query()->where('nisj',$nisj)->first();
        if (! $existing) {
            $roleId = DB::table('access_roles')->where('code','SQUAD_DEFAULT')->value('id') ?: DB::table('access_roles')->where('code','CASHIER')->value('id');
            $levelId = DB::table('access_levels')->where('code','DEFAULT')->value('id');
            if (! $roleId) throw ValidationException::withMessages(['access_role'=>['Access Role SQUAD_DEFAULT/CASHIER belum tersedia.']]);
            $email = $this->availableUserEmail($account->email, $nisj);
            $created = $this->users->createUser($actor, [
                'name'=>$account->full_name,'email'=>$email,'username'=>$nisj,'nisj'=>$nisj,'assignment_role_title'=>$position->position_name,
                'outlet_id'=>(string)$position->destination_outlet_id,'access_role_id'=>(string)$roleId,'access_level_id'=>$levelId ? (string)$levelId : null,
                'password'=>trim((string)$account->phone),'password_confirmation'=>trim((string)$account->phone),'is_active'=>true,
            ]);
            $existing = $created['user'];
        }
        $squad = $this->wiring->ensureForUser($existing,true) ?: DB::table('HR_squads')->where('nisj',$nisj)->whereNull('deleted_at')->first();
        if (! $squad) throw new \RuntimeException('Data Squad temporary gagal dimaterialisasi.');
        $employee = Employee::query()->where('user_id',$existing->id)->first() ?: Employee::query()->where('nisj',$nisj)->first();
        return [$existing,$squad,$employee];
    }

    private function syncSquadProfile(int $squadId, string $nisj, object $account, ?object $profile, object $position, string $hireType, array $payload): void
    {
        DB::table('HR_squads')->where('id',$squadId)->update([
            'full_name'=>$account->full_name,'nickname'=>$profile?->nickname,'nik'=>$account->nik,'address'=>$profile?->address,'birth_place'=>$profile?->birth_place,
            'birth_date'=>$profile?->birth_date,'gender'=>$profile?->gender,'religion'=>$profile?->religion,'education'=>$profile?->education,'marital_status'=>$profile?->marital_status,
            'children_count'=>(int)($profile?->children_count??0),'whatsapp'=>$profile?->whatsapp ?: $account->phone,
            'status'=>'active','nisj'=>$nisj,'employee_type'=>strtoupper($hireType),'contract_type'=>strtoupper($hireType),
            'contract_start_date'=>$payload['contract_start_date']??now()->toDateString(),'contract_end_date'=>$payload['contract_end_date']??null,
            'assignment'=>(string)$position->destination_outlet_id,'division_name'=>$payload['division_name']??null,'position_name'=>$position->position_name,
            'updated_at'=>now(),
        ]);
    }

    private function nextOfficialNisj(): string { return $this->nextSequence('OFFICIAL_NISJ', null); }
    private function nextTemporaryNisj(string $name): string
    {
        $n = $this->nextSequence('TEMP_ACCOUNT', '');
        $first = Str::lower(Str::ascii(Str::before(trim($name).' ', ' ')));
        $first = preg_replace('/[^a-z0-9]+/','',$first) ?: 'applicant';
        return substr('trainee'.$n.'_'.$first,0,32);
    }

    private function nextSequence(string $code, ?string $prefix): string
    {
        $row = DB::table('HR_identity_sequences')->where('code',$code)->lockForUpdate()->first();
        if (! $row) throw new \RuntimeException('Identity sequence '.$code.' belum tersedia.');
        $next=(int)$row->last_number+1;
        DB::table('HR_identity_sequences')->where('code',$code)->update(['last_number'=>$next,'updated_at'=>now()]);
        $number=str_pad((string)$next,(int)$row->width,'0',STR_PAD_LEFT);
        return ($prefix ?? (string)($row->prefix??'')).$number;
    }

    private function availableUserEmail(?string $email,string $nisj): string
    {
        $email=strtolower(trim((string)$email));
        if($email!==''&&!DB::table('users')->whereRaw('LOWER(email)=?',[$email])->exists())return$email;
        return strtolower($nisj).'@career-provision.local';
    }
    private function availableSquadEmail(?string $email): ?string
    {
        $email=strtolower(trim((string)$email));
        if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))return null;
        return DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('LOWER(email)=?',[$email])->exists()?null:$email;
    }
    private function payload(object $r): array { return ['id'=>(string)$r->id,'application_id'=>(string)$r->application_id,'hire_type'=>(string)$r->hire_type,'status'=>(string)$r->status,'assigned_nisj'=>$r->assigned_nisj,'operational_user_id'=>$r->operational_user_id,'squad_id'=>$r->squad_id,'employee_id'=>$r->employee_id,'contract_id'=>$r->contract_id,'converted_at'=>$r->converted_at]; }
}
