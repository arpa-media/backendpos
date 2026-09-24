<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class HrGoLiveAuditService
{
    private const REQUIRED_TABLES = [
        'HR_squads','employees','assignments','HR_assignment_histories','HR_attendances','HR_leave_requests','HR_payroll_cutoffs','HR_payroll_slips',
        'HR_announcements','HR_announcement_targets','HR_announcement_attachments','HR_announcement_polls','HR_announcement_poll_votes',
        'HR_contracts','HR_contract_documents','HR_contract_reminders','HR_attendance_manual_logs',
        'HR_punishment_rules','HR_violations','HR_violation_recommendations','HR_warning_letters','HR_warning_letter_approvals',
        'HR_payroll_cutoff_events','HR_payroll_import_batches','HR_developments','HR_development_batches','HR_development_participants','HR_development_achievements',
        'HR_recruitments','HR_recruitment_positions','HR_career_registration_requests','HR_applications','HR_career_accounts','HR_career_profiles','HR_application_stage_histories','HR_interviews','HR_hiring_conversions',
        'HR_kpi_daily_reviews','HR_kpi_daily_entries','HR_kpi_daily_scores','HR_kpi_periods','HR_kpi_period_scores','HR_bonus_policies','HR_bonus_projections','HR_bonus_projection_lines',
        'finance_payroll_posting_inbox','access_portals','access_menus','access_role_menu_permissions','permissions',
        'HR_go_live_runs','HR_go_live_check_results',
    ];

    private const REQUIRED_PERMISSIONS = [
        'hr.announcement.view','hr.contract.view','hr.attendance.manual.create','hr.leave.manual.create','hr.punishment.view','hr.sp.approve',
        'hr.payroll.cutoff.submit','finance.payroll_posting.bpjs.import','hr.development.view','hr.recruitment.view','hr.recruitment.interview.view','hr.recruitment.hire',
        'hr.kpi.squad.view','hr.kpi.mapping.view','hr.bonus.projection.submit','finance.payroll_posting.bonus_budget','finance.payroll_posting.bonus_approve',
    ];

    private const MENU_CONTRACT = [
        'hr-user-management' => ['/user-management','user_management.view'],
        'hr-mapping-schedule' => ['/human-resource/mapping-schedule','hr.schedule.view'],
        'hr-announcement' => ['/human-resource/announcement','hr.announcement.view'],
        'hr-mapping-contract' => ['/human-resource/mapping-contract','hr.contract.view'],
        'hr-punishment' => ['/human-resource/punishment','hr.punishment.view'],
        'hr-approval-sp' => ['/human-resource/approval-sp','hr.sp.view'],
        'hr-development' => ['/human-resource/development','hr.development.view'],
        'hr-recruitment' => ['/human-resource/recruitment','hr.recruitment.view'],
        'hr-recruitment-interview' => ['/human-resource/recruitment/interview','hr.recruitment.interview.view'],
        'report-kpi-squad' => ['/report/kpi-squad','hr.kpi.squad.view'],
        'hr-mapping-kpi' => ['/human-resource/mapping-kpi','hr.kpi.mapping.view'],
        'hr-bonus-projection' => ['/human-resource/cutoff-proyeksi-bonus','hr.bonus.projection.view'],
        'hr-bonus-cutoff' => ['/human-resource/cutoff-bonus','hr.bonus.projection.view'],
    ];

    private array $checks = [];

    public function __construct(private readonly HrCrossModuleReconciliationService $reconciliation) {}

    public function run(array $options = []): array
    {
        $strict = (bool) ($options['strict'] ?? false);
        $includeData = (bool) ($options['include_data_checks'] ?? true);
        $persist = (bool) ($options['persist'] ?? true);
        $actorId = $options['actor_user_id'] ?? null;
        $this->checks = [];
        $started = now();
        $runId = null;
        $runNumber = 'HR-GL-'.$started->format('Ymd-His').'-'.strtoupper(Str::random(4));
        $dbTimezone = $this->databaseTimezone();

        if ($persist && Schema::hasTable('HR_go_live_runs')) {
            $runId = (string) Str::ulid();
            DB::table('HR_go_live_runs')->insert([
                'id'=>$runId,'run_number'=>$runNumber,'status'=>'running','strict_mode'=>$strict,'include_data_checks'=>$includeData,
                'environment'=>app()->environment(),'app_timezone'=>(string)config('app.timezone'),'database_timezone'=>$dbTimezone,
                'started_by_user_id'=>$actorId,'started_at'=>$started,'created_at'=>$started,'updated_at'=>$started,
            ]);
        }

        $this->guarded('schema.required_tables','SCHEMA','critical','Required HR schema Iterasi 01-20',fn()=>$this->checkRequiredTables());
        $this->guarded('schema.mysql_identifiers','SCHEMA','critical','MySQL FK/index identifier length',fn()=>$this->checkMysqlIdentifiers());
        $this->guarded('schema.unique_indexes','IDEMPOTENCY','critical','Critical unique/index contract',fn()=>$this->checkCriticalIndexes());
        $this->guarded('access.menu_contract','ACCESS','critical','Access Matrix menu/path/permission contract',fn()=>$this->checkMenuContract());
        $this->guarded('access.permissions','ACCESS','critical','Required Spatie permissions registered',fn()=>$this->checkPermissions());
        $this->guarded('access.matrix_orphans','ACCESS','critical','Access Matrix foreign/orphan consistency',fn()=>$this->checkAccessOrphans());
        $this->guarded('access.role_safety','ACCESS','critical','Stakeholder/Observer operational write safety',fn()=>$this->checkRoleSafety());
        $this->guarded('access.role_coverage','ACCESS','warning','Admin/Manager/Squad/Warehouse Access Matrix coverage',fn()=>$this->checkRoleCoverage());
        $this->guarded('access.career_isolation','ACCESS','critical','Career applicant authentication isolation',fn()=>$this->checkCareerIsolation());
        $this->guarded('access.legacy_users_hidden','ACCESS','warning','Legacy Users hidden and User Management active',fn()=>$this->checkUsersNavigation());
        $this->guarded('routes.hr_contract','ROUTES','critical','HR admin/self-service/career route contract',fn()=>$this->checkRoutes());
        $this->guarded('scheduler.registration','SCHEDULER','critical','Announcement/Contract/Punishment scheduler registration',fn()=>$this->checkSchedulerSource());
        $this->guarded('timezone.jakarta','TIMEZONE','warning','Application/DB timezone contract',fn()=>$this->checkTimezone($dbTimezone));
        $this->guarded('frontend.backoffice_contract','FRONTEND','critical','Backoffice HR route/menu source contract',fn()=>$this->checkFrontendContract());
        $this->guarded('frontend.capacitor_shared_layer','FRONTEND','warning','Capacitor/shared API compatibility contract',fn()=>$this->checkCapacitorContract());

        if ($includeData) {
            $this->guarded('data.cross_module_reconciliation','RECONCILIATION','critical','Cross-module data reconciliation',function(){
                $report = $this->reconciliation->run(10);
                $critical = (int)($report['counts']['critical'] ?? 0);
                $warning = (int)($report['counts']['warning'] ?? 0);
                $this->push('data.cross_module_reconciliation','RECONCILIATION','critical',$critical===0,$critical===0?'Tidak ada blocker kritis lintas modul.':'Ditemukan blocker kritis lintas modul.',$critical,0,[
                    'warning_count'=>$warning,
                    'findings'=>collect($report['issues'])->where('count','>',0)->values()->all(),
                ], $critical===0 && $warning>0 ? 'WARN' : null);
            });
        } else {
            $this->push('data.cross_module_reconciliation','RECONCILIATION','warning',true,'Data checks dilewati (--no-data).',0,0,[],'SKIP');
        }

        $counts = [
            'pass'=>collect($this->checks)->where('status','PASS')->count(),
            'warn'=>collect($this->checks)->where('status','WARN')->count(),
            'fail'=>collect($this->checks)->where('status','FAIL')->count(),
            'skip'=>collect($this->checks)->where('status','SKIP')->count(),
        ];
        $overall = $counts['fail'] > 0 ? 'FAILED' : (($strict && $counts['warn'] > 0) ? 'FAILED' : ($counts['warn'] > 0 ? 'PASSED_WITH_WARNINGS' : 'PASSED'));
        $finished = now();
        $summary = [
            'run_number'=>$runNumber,'overall_status'=>$overall,'strict'=>$strict,'include_data_checks'=>$includeData,'counts'=>$counts,
            'duration_ms'=>$started->diffInMilliseconds($finished),
            'go_live_ready'=>$overall === 'PASSED',
        ];

        if ($runId && Schema::hasTable('HR_go_live_check_results')) {
            foreach ($this->checks as $check) {
                DB::table('HR_go_live_check_results')->updateOrInsert(['go_live_run_id'=>$runId,'check_code'=>$check['check_code']], [
                    'id'=>(string)Str::ulid(),'category'=>$check['category'],'severity'=>$check['severity'],'status'=>strtolower($check['status']),
                    'message'=>$check['message'],'actual_value'=>$this->stringValue($check['actual']),'expected_value'=>$this->stringValue($check['expected']),
                    'evidence'=>json_encode($check['evidence'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created_at'=>$finished,
                ]);
            }
            DB::table('HR_go_live_runs')->where('id',$runId)->update([
                'status'=>strtolower($overall),'completed_at'=>$finished,'summary'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>$finished,
            ]);
        }

        return ['run_id'=>$runId,'summary'=>$summary,'checks'=>$this->checks];
    }

    private function checkRequiredTables(): void
    {
        $missing = array_values(array_filter(self::REQUIRED_TABLES, fn($t)=>!Schema::hasTable($t)));
        $this->push('schema.required_tables','SCHEMA','critical',count($missing)===0,'Seluruh tabel wajib tersedia.',count(self::REQUIRED_TABLES)-count($missing),count(self::REQUIRED_TABLES),['missing'=>$missing]);
    }

    private function checkMysqlIdentifiers(): void
    {
        $database = DB::getDatabaseName();
        $longIndexes = collect(DB::select("SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND CHAR_LENGTH(INDEX_NAME) > 64",[$database]));
        $longConstraints = collect(DB::select("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND CHAR_LENGTH(CONSTRAINT_NAME) > 64",[$database]));
        $ok = $longIndexes->isEmpty() && $longConstraints->isEmpty();
        $this->push('schema.mysql_identifiers','SCHEMA','critical',$ok,'Tidak ada nama index/FK melebihi batas MySQL 64 karakter.',$longIndexes->count()+$longConstraints->count(),0,['indexes'=>$longIndexes,'constraints'=>$longConstraints]);
    }

    private function checkCriticalIndexes(): void
    {
        $required = [
            ['HR_violations','hr_violation_fp_uq'],['HR_violation_recommendations','hr_violation_rec_fp_uq'],['HR_hiring_conversions','hr_hire_app_uq'],['HR_hiring_conversions','hr_hire_key_uq'],
            ['finance_payroll_posting_inbox','fin_pay_hr_cutoff_uq'],['finance_payroll_posting_inbox','fin_pay_hr_bonus_uq'],['HR_bonus_projections','hr_bonus_projection_period_uq'],
        ];
        $missing=[];
        foreach($required as[$table,$index]){
            if(!Schema::hasTable($table)){ $missing[]="$table.$index (table missing)"; continue; }
            $found=collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn($r)=>(string)($r->Key_name??'')===$index);
            if(!$found)$missing[]="$table.$index";
        }
        $this->push('schema.unique_indexes','IDEMPOTENCY','critical',count($missing)===0,'Critical idempotency/bridge indexes tersedia.',count($required)-count($missing),count($required),['missing'=>$missing]);
    }

    private function checkMenuContract(): void
    {
        if(!Schema::hasTable('access_menus')){ $this->push('access.menu_contract','ACCESS','critical',false,'access_menus tidak tersedia.',0,count(self::MENU_CONTRACT));return; }
        $errors=[];
        foreach(self::MENU_CONTRACT as$code=>[$path,$permission]){
            $row=DB::table('access_menus')->where('code',$code)->first();
            if(!$row){$errors[]="$code missing";continue;}
            if(!(bool)$row->is_active)$errors[]="$code inactive";
            if((string)$row->path!==$path)$errors[]="$code path=".(string)$row->path;
            if((string)$row->permission_view!==$permission)$errors[]="$code permission_view=".(string)$row->permission_view;
        }
        $this->push('access.menu_contract','ACCESS','critical',count($errors)===0,'Menu Iterasi 09-19 sesuai canonical path/permission.',count(self::MENU_CONTRACT)-count($errors),count(self::MENU_CONTRACT),['errors'=>$errors]);
    }

    private function checkPermissions(): void
    {
        if(!Schema::hasTable('permissions')){ $this->push('access.permissions','ACCESS','critical',false,'permissions table tidak tersedia.',0,count(self::REQUIRED_PERMISSIONS));return; }
        $existing=DB::table('permissions')->whereIn('name',self::REQUIRED_PERMISSIONS)->pluck('name')->all();
        $missing=array_values(array_diff(self::REQUIRED_PERMISSIONS,$existing));
        $this->push('access.permissions','ACCESS','critical',count($missing)===0,'Permission kritis Iterasi 09-19 terdaftar.',count($existing),count(self::REQUIRED_PERMISSIONS),['missing'=>$missing]);
    }

    private function checkAccessOrphans(): void
    {
        if(!Schema::hasTable('access_role_menu_permissions')){ $this->push('access.matrix_orphans','ACCESS','critical',false,'access_role_menu_permissions tidak tersedia.',1,0);return; }
        $orphanMenu=DB::table('access_role_menu_permissions as p')->leftJoin('access_menus as m','m.id','=','p.menu_id')->whereNull('m.id')->count();
        $orphanRole=Schema::hasTable('access_roles')?DB::table('access_role_menu_permissions as p')->leftJoin('access_roles as r','r.id','=','p.access_role_id')->whereNull('r.id')->count():0;
        $dup=DB::query()->fromSub(DB::table('access_role_menu_permissions')->select('access_role_id','access_level_id','menu_id',DB::raw('COUNT(*) total'))->groupBy('access_role_id','access_level_id','menu_id')->havingRaw('COUNT(*) > 1'),'d')->count();
        $total=$orphanMenu+$orphanRole+$dup;
        $this->push('access.matrix_orphans','ACCESS','critical',$total===0,'Tidak ada orphan/duplicate snapshot Access Matrix.',$total,0,['orphan_menu'=>$orphanMenu,'orphan_role'=>$orphanRole,'duplicate_rows'=>$dup]);
    }

    private function checkRoleSafety(): void
    {
        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_menus') || !Schema::hasTable('access_role_menu_permissions')) {
            $this->push('access.role_safety','ACCESS','critical',false,'Tabel Access Matrix role/menu tidak lengkap.',1,0);
            return;
        }

        $operationalMenuIds = DB::table('access_menus')->whereIn('code', array_keys(self::MENU_CONTRACT))->pluck('id')->all();
        $selfMenuIds = DB::table('access_menus')->whereIn('code', ['hr-self-shift-schedule','hr-self-leave-request','hr-self-payroll-slip'])->pluck('id')->all();
        $violations = [];
        $roleEvidence = [];

        foreach (['STAKEHOLDER','OBSERVER'] as $roleCode) {
            $role = DB::table('access_roles')->whereRaw('UPPER(code) = ?', [$roleCode])->first();
            if (!$role) {
                $roleEvidence[$roleCode] = ['present'=>false,'operational_write_rows'=>0,'self_service_rows'=>0];
                continue;
            }

            $operationalWrite = empty($operationalMenuIds) ? 0 : DB::table('access_role_menu_permissions')
                ->where('access_role_id', $role->id)
                ->whereIn('menu_id', $operationalMenuIds)
                ->where(function ($q) {
                    $q->where('can_create', true)->orWhere('can_edit', true)->orWhere('can_delete', true);
                })->count();

            $selfServiceExposure = empty($selfMenuIds) ? 0 : DB::table('access_role_menu_permissions')
                ->where('access_role_id', $role->id)
                ->whereIn('menu_id', $selfMenuIds)
                ->where(function ($q) {
                    $q->where('can_view', true)->orWhere('can_create', true)->orWhere('can_edit', true)->orWhere('can_delete', true);
                })->count();

            if ($operationalWrite > 0) $violations[] = "$roleCode memiliki {$operationalWrite} row write pada menu HR operasional";
            if ($selfServiceExposure > 0) $violations[] = "$roleCode memiliki {$selfServiceExposure} row akses self-service Squad";
            $roleEvidence[$roleCode] = ['present'=>true,'operational_write_rows'=>$operationalWrite,'self_service_rows'=>$selfServiceExposure];
        }

        $this->push(
            'access.role_safety','ACCESS','critical',count($violations)===0,
            count($violations)===0 ? 'Stakeholder/Observer tidak memperoleh write HR operasional atau self-service Squad.' : 'Ditemukan exposure Access Matrix yang perlu ditutup sebelum go-live.',
            count($violations),0,['violations'=>$violations,'roles'=>$roleEvidence]
        );
    }

    private function checkRoleCoverage(): void
    {
        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_menus') || !Schema::hasTable('access_role_menu_permissions')) {
            $this->push('access.role_coverage','ACCESS','warning',false,'Tabel Access Matrix role/menu tidak lengkap.',0,1);
            return;
        }

        $menuIds = DB::table('access_menus')->whereIn('code', array_keys(self::MENU_CONTRACT))->pluck('id')->all();
        $selfMenuIds = DB::table('access_menus')->whereIn('code', ['hr-self-shift-schedule','hr-self-leave-request','hr-self-payroll-slip'])->pluck('id')->all();
        $roles = DB::table('access_roles')->whereIn('code', ['ADMIN','MANAGER','WAREHOUSE','CASHIER'])->get()->keyBy(fn($r)=>strtoupper((string)$r->code));
        $evidence = [];
        $warnings = [];

        foreach (['ADMIN','MANAGER','WAREHOUSE','CASHIER'] as $code) {
            $role = $roles->get($code);
            if (!$role) {
                $evidence[$code] = ['present'=>false];
                if (in_array($code, ['ADMIN','MANAGER'], true)) $warnings[] = "$code access_role tidak ditemukan";
                continue;
            }
            $viewCount = empty($menuIds) ? 0 : DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->whereIn('menu_id',$menuIds)->where('can_view',true)->count();
            $selfCount = empty($selfMenuIds) ? 0 : DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->whereIn('menu_id',$selfMenuIds)->where('can_view',true)->count();
            $dangerousCodes = ['hr-recruitment-interview','hr-bonus-cutoff'];
            $dangerousIds = DB::table('access_menus')->whereIn('code',$dangerousCodes)->pluck('id')->all();
            $dangerousWrite = empty($dangerousIds) ? 0 : DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->whereIn('menu_id',$dangerousIds)->where(function($q){$q->where('can_create',true)->orWhere('can_edit',true)->orWhere('can_delete',true);})->count();
            $evidence[$code] = ['present'=>true,'hr_menu_view_rows'=>$viewCount,'self_service_view_rows'=>$selfCount,'sensitive_write_rows'=>$dangerousWrite];

            if ($code==='ADMIN' && $viewCount===0) $warnings[]='ADMIN tidak memiliki view row pada menu HR canonical';
            if ($code==='MANAGER' && $viewCount===0) $warnings[]='MANAGER tidak memiliki view row pada menu HR canonical';
            if ($code==='CASHIER' && $selfCount===0) $warnings[]='CASHIER/Squad tidak memiliki self-service view row';
            if ($code==='WAREHOUSE' && $dangerousWrite>0) $warnings[]="WAREHOUSE memiliki {$dangerousWrite} write row pada Interview/Cutoff Bonus; verifikasi memang disengaja";
        }

        $spatieSquad = null;
        if (Schema::hasTable('roles')) {
            $spatieSquad = DB::table('roles')->whereRaw('LOWER(name) = ?', ['squad'])->exists();
        }
        $evidence['spatie_squad_role_present'] = $spatieSquad;
        if ($spatieSquad === false && !$roles->has('CASHIER')) $warnings[]='Role Squad tidak terdeteksi di Spatie maupun access role CASHIER';

        $this->push(
            'access.role_coverage','ACCESS','warning',count($warnings)===0,
            count($warnings)===0 ? 'Coverage role Admin/Manager/Squad/Warehouse tampak konsisten.' : 'Coverage role perlu review Access Matrix sebelum go-live.',
            count($warnings),0,['warnings'=>$warnings,'roles'=>$evidence]
        );
    }

    private function checkCareerIsolation(): void
    {
        $routeFile = base_path('routes/hr_self_service_modules/17-career.php');
        $middlewareFile = base_path('app/Http/Middleware/AuthenticateCareerAccount.php');
        $routeSource = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
        $middlewareSource = is_file($middlewareFile) ? (string) file_get_contents($middlewareFile) : '';
        $checks = [
            'career_route_file' => is_file($routeFile),
            'career_middleware_file' => is_file($middlewareFile),
            'career_uses_sanctum' => str_contains($routeSource, "auth:sanctum"),
            'career_uses_boundary_middleware' => str_contains($routeSource, 'AuthenticateCareerAccount::class'),
            'career_model_assertion' => str_contains($middlewareSource, 'CareerAccount') || str_contains($middlewareSource, 'HR_career_accounts'),
        ];
        $failed = array_keys(array_filter($checks, fn($ok)=>!$ok));
        $this->push('access.career_isolation','ACCESS','critical',count($failed)===0,'Career applicant memakai auth boundary terisolasi dari user POS/Backoffice.',count($checks)-count($failed),count($checks),['failed'=>$failed,'checks'=>$checks]);
    }

    private function checkUsersNavigation(): void
    {
        $legacy=Schema::hasTable('access_menus')?DB::table('access_menus')->where('code','hr-users')->value('is_active'):null;
        $management=Schema::hasTable('access_menus')?DB::table('access_menus')->where('code','hr-user-management')->first():null;
        $ok=($legacy===null || !(bool)$legacy) && $management && (bool)$management->is_active;
        $this->push('access.legacy_users_hidden','ACCESS','warning',$ok,'Menu dummy Users hidden dan User Management aktif.',$ok?0:1,0,['legacy_users_active'=>(bool)$legacy,'user_management'=>$management]);
    }

    private function checkRoutes(): void
    {
        $paths=collect(Route::getRoutes())->map(fn($r)=>[$r->uri(),implode(',',$r->methods())]);
        $required=['api/v1/human-resource/announcements','api/v1/human-resource/contracts','api/v1/human-resource/punishments/violations','api/v1/human-resource/payroll/cutoffs','api/v1/human-resource/developments','api/v1/human-resource/recruitments','api/v1/human-resource/recruitment-interviews','api/v1/human-resource/kpi-squad','api/v1/human-resource/kpi-mapping','api/v1/human-resource/bonus-projections','api/v1/career/login'];
        $missing=[];
        foreach($required as$needle)if(!$paths->contains(fn($row)=>str_contains($row[0],$needle)))$missing[]=$needle;
        $this->push('routes.hr_contract','ROUTES','critical',count($missing)===0,'Route HR/Career utama terdaftar.',count($required)-count($missing),count($required),['missing'=>$missing]);
    }

    private function checkSchedulerSource(): void
    {
        $root=base_path();
        $console=is_file($root.'/routes/console.php')?(string)file_get_contents($root.'/routes/console.php'):'';
        $bootstrap=is_file($root.'/bootstrap/app.php')?(string)file_get_contents($root.'/bootstrap/app.php'):'';
        $required=[
            'hr:announcement-expiry-sweep'=>str_contains($console,'hr:announcement-expiry-sweep'),
            'hr:contract-lifecycle-sweep'=>str_contains($console,'hr:contract-lifecycle-sweep'),
            'hr:punishment-sweep'=>str_contains($bootstrap,'hr:punishment-sweep'),
        ];
        $missing=array_keys(array_filter($required,fn($ok)=>!$ok));
        $this->push('scheduler.registration','SCHEDULER','critical',count($missing)===0,'Scheduler HR lifecycle terdaftar tanpa saling menimpa.',count($required)-count($missing),count($required),['missing'=>$missing]);
    }

    private function checkTimezone(?string $dbTimezone): void
    {
        $appTz=(string)config('app.timezone');
        $ok=$appTz==='Asia/Jakarta';
        $this->push('timezone.jakarta','TIMEZONE','warning',$ok,'Application timezone menggunakan Asia/Jakarta.',$appTz,'Asia/Jakarta',['database_timezone'=>$dbTimezone]);
    }

    private function checkFrontendContract(): void
    {
        $front=dirname(base_path()).'/frontend - Backoffice';
        $files=[
            $front.'/src/modules/human-resource/routes.js',
            $front.'/src/modules/human-resource/route-modules/17-recruitment-interview.js',
            $front.'/src/modules/human-resource/route-modules/19-kpi-bonus.js',
            $front.'/src/pages/human-resource/HumanResourcePunishmentPage.vue',
            $front.'/src/pages/human-resource/HumanResourceBonusCutoffPlaceholderPage.vue',
            $front.'/src/modules/career/routes.js',
        ];
        $missing=array_values(array_filter($files,fn($f)=>!is_file($f)));
        $portal=is_file($front.'/src/lib/portalConfig.js')?(string)file_get_contents($front.'/src/lib/portalConfig.js'):'';
        $markers=['manage-squad-interview','cutoff-bonus','hr-user-management'];
        $markerMissing=array_values(array_filter($markers,fn($m)=>!str_contains($portal,$m)));
        $ok=count($missing)===0 && count($markerMissing)===0;
        $this->push('frontend.backoffice_contract','FRONTEND','critical',$ok,'Frontend route/menu Iterasi 09-19 tersedia pada effective baseline.',$ok?1:0,1,['missing_files'=>$missing,'missing_markers'=>$markerMissing]);
    }

    private function checkCapacitorContract(): void
    {
        $root=dirname(base_path());
        $mobile=$root.'/frontend';
        $back=$root.'/frontend - Backoffice';
        $mobileApi=$mobile.'/src/lib/api.js';
        $backApi=$back.'/src/lib/api.js';
        $package=$mobile.'/package.json';
        $cap=is_file($package) && str_contains(strtolower((string)file_get_contents($package)),'capacitor');
        $ok=is_file($mobileApi) && is_file($backApi) && $cap;
        $this->push('frontend.capacitor_shared_layer','FRONTEND','warning',$ok,'Frontend mobile Capacitor dan shared API entrypoint tetap tersedia.',$ok?1:0,1,['mobile_api'=>$mobileApi,'backoffice_api'=>$backApi,'capacitor_dependency'=>$cap]);
    }

    private function databaseTimezone(): ?string
    {
        try { $r=DB::selectOne('SELECT @@session.time_zone AS tz'); return $r?->tz ?? null; } catch(Throwable){ return null; }
    }

    private function guarded(string $code,string $category,string $severity,string $label,callable $callback): void
    {
        try{$callback();}catch(Throwable $e){$this->push($code,$category,$severity,false,$label.' gagal: '.$e->getMessage(),1,0,['exception'=>get_class($e)]);}
    }

    private function push(string $code,string $category,string $severity,bool $passed,string $message,mixed $actual=null,mixed $expected=null,array $evidence=[],?string $forcedStatus=null): void
    {
        $status=$forcedStatus ?? ($passed?'PASS':($severity==='warning'?'WARN':'FAIL'));
        $this->checks[]=['check_code'=>$code,'category'=>$category,'severity'=>$severity,'status'=>$status,'message'=>$message,'actual'=>$actual,'expected'=>$expected,'evidence'=>$evidence];
    }

    private function stringValue(mixed $value): ?string
    {
        if($value===null)return null;if(is_scalar($value))return mb_substr((string)$value,0,255);return mb_substr(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,255);
    }
}
