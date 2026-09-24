<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['HR_kpi_daily_reviews','HR_kpi_daily_entries','HR_kpi_daily_scores','HR_shift_schedules','HR_contracts','HR_warning_letters','finance_payroll_posting_inbox'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("HR Iterasi 19 membutuhkan tabel {$table}.");
        }

        $this->createKpiPeriods();
        $this->createBonusPolicies();
        $this->createBonusProjections();
        $this->extendFinanceInbox();
        $this->seedPolicies();
        $this->seedAccessMatrix();
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design: KPI/bonus period snapshots are payroll audit evidence.
    }

    private function createKpiPeriods(): void
    {
        if (! Schema::hasTable('HR_kpi_periods')) {
            Schema::create('HR_kpi_periods', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('outlet_id');
                $t->date('period_from');
                $t->date('period_to');
                $t->string('status', 20)->default('draft'); // draft|locked
                $t->unsignedInteger('revision')->default(1);
                $t->string('rule_version', 80)->default('KPI_COMPOSITE_V1');
                $t->json('rule_snapshot');
                $t->char('source_hash', 64)->nullable();
                $t->foreignUlid('calculated_by_user_id')->nullable();
                $t->timestamp('calculated_at')->nullable();
                $t->foreignUlid('locked_by_user_id')->nullable();
                $t->timestamp('locked_at')->nullable();
                $t->timestamps();
                $t->unique(['outlet_id','period_from','period_to'], 'hr_kpi_period_scope_uq');
                $t->index(['period_to','status'], 'hr_kpi_period_status_idx');
                $t->foreign('outlet_id', 'hr_kpi_period_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
                $t->foreign('calculated_by_user_id', 'hr_kpi_period_calc_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('locked_by_user_id', 'hr_kpi_period_lock_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('HR_kpi_period_scores')) {
            Schema::create('HR_kpi_period_scores', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('period_id');
                $t->foreignUlid('employee_id');
                $t->unsignedBigInteger('squad_id')->nullable();
                $t->string('nisj_snapshot', 100)->nullable();
                $t->string('name_snapshot', 180);
                $t->string('division_snapshot', 120)->nullable();
                $t->decimal('grooming_raw', 12, 2)->default(0);
                $t->decimal('grooming_workbook_max', 12, 2)->default(0);
                $t->decimal('grooming_component_40', 8, 4)->default(0);
                $t->unsignedInteger('scheduled_shifts')->default(0);
                $t->unsignedInteger('attendance_shifts')->default(0);
                $t->unsignedInteger('late_count')->default(0);
                $t->decimal('late_percent', 8, 4)->default(0);
                $t->decimal('discipline_component_60', 8, 4)->default(0);
                $t->decimal('kpi_total', 8, 4)->default(0);
                $t->string('grade_snapshot', 8)->nullable();
                $t->json('source_snapshot')->nullable();
                $t->timestamps();
                $t->unique(['period_id','employee_id'], 'hr_kpi_period_score_emp_uq');
                $t->index(['employee_id','period_id'], 'hr_kpi_period_score_idx');
                $t->foreign('period_id', 'hr_kpi_ps_period_fk')->references('id')->on('HR_kpi_periods')->cascadeOnDelete();
                $t->foreign('employee_id', 'hr_kpi_ps_emp_fk')->references('id')->on('employees')->restrictOnDelete();
                $t->foreign('squad_id', 'hr_kpi_ps_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            });
        }
    }

    private function createBonusPolicies(): void
    {
        if (Schema::hasTable('HR_bonus_policies')) return;
        Schema::create('HR_bonus_policies', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('code', 60);
            $t->unsignedInteger('version')->default(1);
            $t->json('config_json');
            $t->boolean('is_active')->default(true);
            $t->text('description')->nullable();
            $t->foreignUlid('created_by_user_id')->nullable();
            $t->timestamps();
            $t->unique(['code','version'], 'hr_bonus_policy_code_ver_uq');
            $t->index(['code','is_active'], 'hr_bonus_policy_active_idx');
            $t->foreign('created_by_user_id', 'hr_bonus_policy_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createBonusProjections(): void
    {
        if (! Schema::hasTable('HR_bonus_projections')) {
            Schema::create('HR_bonus_projections', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('kpi_period_id');
                $t->foreignUlid('outlet_id');
                $t->date('period_from');
                $t->date('period_to');
                $t->string('status', 28)->default('draft'); // draft|submitted|finance_processing|finalized
                $t->string('policy_code', 60)->default('BONUS_WORKBOOK_V1');
                $t->unsignedInteger('policy_version')->default(1);
                $t->json('policy_snapshot');
                $t->string('revenue_source', 100)->default('report_daily_sales_summaries.grand_sales');
                $t->decimal('revenue_snapshot', 20, 2)->default(0);
                $t->unsignedInteger('total_shifts_snapshot')->default(0);
                $t->decimal('finance_budget_percentage', 8, 4)->nullable();
                $t->decimal('bonus_budget', 20, 2)->default(0);
                $t->decimal('bonus_per_shift', 20, 6)->default(0);
                $t->decimal('total_payout', 20, 2)->default(0);
                $t->decimal('remaining_budget', 20, 2)->default(0);
                $t->foreignUlid('finance_posting_id')->nullable();
                $t->char('calculation_hash', 64)->nullable();
                $t->json('finalized_snapshot')->nullable();
                $t->foreignUlid('created_by_user_id')->nullable();
                $t->foreignUlid('submitted_by_user_id')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->foreignUlid('finalized_by_user_id')->nullable();
                $t->timestamp('finalized_at')->nullable();
                $t->timestamps();
                $t->unique('kpi_period_id', 'hr_bonus_projection_period_uq');
                $t->index(['outlet_id','period_to','status'], 'hr_bonus_projection_scope_idx');
                $t->foreign('kpi_period_id', 'hr_bonus_projection_kpi_fk')->references('id')->on('HR_kpi_periods')->restrictOnDelete();
                $t->foreign('outlet_id', 'hr_bonus_projection_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
                $t->foreign('finance_posting_id', 'hr_bonus_projection_fin_fk')->references('id')->on('finance_payroll_posting_inbox')->nullOnDelete();
                $t->foreign('created_by_user_id', 'hr_bonus_projection_creator_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('submitted_by_user_id', 'hr_bonus_projection_submit_fk')->references('id')->on('users')->nullOnDelete();
                $t->foreign('finalized_by_user_id', 'hr_bonus_projection_final_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('HR_bonus_projection_lines')) {
            Schema::create('HR_bonus_projection_lines', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('projection_id');
                $t->foreignUlid('employee_id');
                $t->unsignedBigInteger('squad_id')->nullable();
                $t->string('nisj_snapshot', 100)->nullable();
                $t->string('name_snapshot', 180);
                $t->unsignedInteger('personal_shifts')->default(0);
                $t->decimal('kpi_score', 8, 4)->default(0);
                $t->string('grade', 8)->nullable();
                $t->decimal('grade_multiplier', 8, 4)->default(0);
                $t->string('contract_entitlement', 40)->default('TRAINING');
                $t->decimal('contract_multiplier', 8, 4)->default(0);
                $t->unsignedTinyInteger('sp_level')->default(0);
                $t->decimal('sp_multiplier', 8, 4)->default(1);
                $t->decimal('personal_base_budget', 20, 2)->default(0);
                $t->decimal('bonus_payout', 20, 2)->default(0);
                $t->decimal('forfeited_budget', 20, 2)->default(0);
                $t->json('source_snapshot')->nullable();
                $t->timestamps();
                $t->unique(['projection_id','employee_id'], 'hr_bonus_line_projection_emp_uq');
                $t->foreign('projection_id', 'hr_bonus_line_projection_fk')->references('id')->on('HR_bonus_projections')->cascadeOnDelete();
                $t->foreign('employee_id', 'hr_bonus_line_emp_fk')->references('id')->on('employees')->restrictOnDelete();
                $t->foreign('squad_id', 'hr_bonus_line_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('HR_bonus_projection_events')) {
            Schema::create('HR_bonus_projection_events', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('projection_id');
                $t->string('event', 48)->index();
                $t->string('from_status', 28)->nullable();
                $t->string('to_status', 28)->nullable();
                $t->foreignUlid('actor_user_id')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['projection_id','created_at'], 'hr_bonus_event_projection_idx');
                $t->foreign('projection_id', 'hr_bonus_event_projection_fk')->references('id')->on('HR_bonus_projections')->cascadeOnDelete();
                $t->foreign('actor_user_id', 'hr_bonus_event_actor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    private function extendFinanceInbox(): void
    {
        Schema::table('finance_payroll_posting_inbox', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_payroll_posting_inbox','hr_bonus_projection_id')) $t->char('hr_bonus_projection_id',26)->nullable()->index();
            if (! Schema::hasColumn('finance_payroll_posting_inbox','bonus_budget_percentage')) $t->decimal('bonus_budget_percentage',8,4)->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox','bonus_budget_amount')) $t->decimal('bonus_budget_amount',20,2)->default(0);
            if (! Schema::hasColumn('finance_payroll_posting_inbox','bonus_remaining_budget')) $t->decimal('bonus_remaining_budget',20,2)->default(0);
        });
        $indexes=collect(DB::select('SHOW INDEX FROM finance_payroll_posting_inbox'));
        if (! $indexes->contains(fn($r)=>(string)($r->Key_name??'')==='fin_pay_hr_bonus_uq')) {
            Schema::table('finance_payroll_posting_inbox', fn(Blueprint $t)=>$t->unique('hr_bonus_projection_id','fin_pay_hr_bonus_uq'));
        }
    }

    private function seedPolicies(): void
    {
        $now=now();
        $config=[
            'source'=>'CONTOH BONUS IT.xlsx',
            'grade_bands'=>[
                ['grade'=>'A','min'=>90,'max'=>100,'multiplier'=>1.00],
                ['grade'=>'B','min'=>80,'max'=>89.9999,'multiplier'=>0.85],
                ['grade'=>'C','min'=>70,'max'=>79.9999,'multiplier'=>0.70],
                ['grade'=>'D','min'=>60,'max'=>69.9999,'multiplier'=>0.55],
                ['grade'=>'E','min'=>0.0001,'max'=>59.9999,'multiplier'=>0.40],
                ['grade'=>'F','min'=>0,'max'=>0,'multiplier'=>0.00],
            ],
            'contract_rules'=>[
                ['code'=>'TRAINING','patterns'=>['TRAINING','TRAINEE','MAGANG'],'multiplier'=>0.00],
                ['code'=>'DEPOSIT','patterns'=>['PKWT'],'multiplier'=>0.50],
                ['code'=>'PENUH','patterns'=>['SPT','TETAP','PERMANENT','PKWTT'],'multiplier'=>1.00],
                ['code'=>'UNMAPPED','patterns'=>['*'],'multiplier'=>0.00],
            ],
            'sp_rules'=>['0'=>1.00,'1'=>0.75,'2'=>0.50,'3'=>0.00],
            'formulas'=>[
                'bonus_budget'=>'revenue * finance_budget_percentage / 100',
                'bonus_per_shift'=>'bonus_budget / total_shift',
                'personal_base_budget'=>'personal_shift * bonus_per_shift',
                'bonus_payout'=>'personal_base_budget * grade_multiplier * contract_multiplier * sp_multiplier',
            ],
        ];
        $existing=DB::table('HR_bonus_policies')->where('code','BONUS_WORKBOOK_V1')->where('version',1)->first();
        DB::table('HR_bonus_policies')->updateOrInsert(['code'=>'BONUS_WORKBOOK_V1','version'=>1],[
            'id'=>(string)($existing->id??Str::ulid()),'config_json'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'is_active'=>true,'description'=>'Policy bonus dari CONTOH BONUS IT.xlsx; versioned snapshot Iterasi 19.',
            'created_at'=>$existing->created_at??$now,'updated_at'=>$now,
        ]);
    }

    private function seedAccessMatrix(): void
    {
        $guard=config('auth.defaults.guard','web');
        foreach ([
            'hr.kpi.mapping.view','hr.kpi.mapping.recalculate','hr.kpi.mapping.export',
            'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.recalculate','hr.bonus.projection.submit','hr.bonus.projection.export',
            'finance.payroll_posting.bonus_budget','finance.payroll_posting.bonus_approve',
        ] as $permission) if (Schema::hasTable('permissions')) Permission::findOrCreate($permission,$guard);

        if (!Schema::hasTable('access_portals')||!Schema::hasTable('access_menus')) return;
        $portal=DB::table('access_portals')->where('code','human-resource')->first(); if(!$portal)return; $now=now();
        $mapping=$this->upsertMenu((string)$portal->id,'hr-mapping-kpi','Mapping KPI','/human-resource/mapping-kpi',43,'hr.kpi.mapping.view','hr.kpi.mapping.recalculate','hr.kpi.mapping.recalculate',null,$now);
        $projection=$this->upsertMenu((string)$portal->id,'hr-bonus-projection','Proyeksi Bonus','/human-resource/cutoff-proyeksi-bonus',52,'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.recalculate',null,$now);
        $cutoff=$this->upsertMenu((string)$portal->id,'hr-bonus-cutoff','Cutoff Bonus','/human-resource/cutoff-bonus',54,'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.submit',null,$now);
        $this->cloneMatrix('hr-mapping-assignment',$mapping,$now);
        $this->cloneMatrix('hr-payroll-cutoff',$projection,$now);
        $this->cloneMatrix('hr-payroll-cutoff',$cutoff,$now);
    }

    private function upsertMenu(string $portalId,string $code,string $name,string $path,int $sort,?string $view,?string $create,?string $update,?string $delete,$now): string
    {
        $old=DB::table('access_menus')->where('code',$code)->first();$id=(string)($old->id??Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code'=>$code],[
            'id'=>$id,'portal_id'=>$portalId,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
            'permission_view'=>$view,'permission_create'=>$create,'permission_update'=>$update,'permission_delete'=>$delete,'is_active'=>true,
            'created_at'=>$old->created_at??$now,'updated_at'=>$now,
        ]); return $id;
    }

    private function cloneMatrix(string $sourceCode,string $targetId,$now): void
    {
        if(!Schema::hasTable('access_role_menu_permissions'))return;$source=DB::table('access_menus')->where('code',$sourceCode)->value('id');if(!$source)return;
        foreach(DB::table('access_role_menu_permissions')->where('menu_id',$source)->get() as$row){$q=DB::table('access_role_menu_permissions')->where('access_role_id',$row->access_role_id)->where('menu_id',$targetId);$row->access_level_id===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$row->access_level_id);if($q->exists())continue;DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$row->access_role_id,'access_level_id'=>$row->access_level_id,'menu_id'=>$targetId,'can_view'=>(bool)$row->can_view,'can_create'=>(bool)$row->can_create,'can_edit'=>(bool)$row->can_edit,'can_delete'=>false,'created_at'=>$now,'updated_at'=>$now]);}
    }
};
