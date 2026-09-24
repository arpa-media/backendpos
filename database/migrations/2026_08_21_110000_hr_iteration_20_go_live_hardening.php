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
    private const MENUS = [
        ['human-resource','hr-mapping-schedule','Schedule','/human-resource/mapping-schedule',31,'hr.schedule.view','hr.schedule.create','hr.schedule.update','hr.schedule.delete'],
        ['human-resource','hr-user-management','User Management','/user-management',20,'user_management.view',null,'user_management.edit',null],
        ['human-resource','hr-announcement','Announcement','/human-resource/announcement',24,'hr.announcement.view','hr.announcement.create','hr.announcement.update','hr.announcement.delete'],
        ['human-resource','hr-mapping-contract','Contract','/human-resource/mapping-contract',32,'hr.contract.view','hr.contract.create','hr.contract.update','hr.contract.delete'],
        ['human-resource','hr-punishment','Punishment','/human-resource/punishment',25,'hr.punishment.view','hr.punishment.create','hr.punishment.update','hr.punishment.delete'],
        ['human-resource','hr-approval-sp','Approval SP','/human-resource/approval-sp',45,'hr.sp.view','hr.sp.create','hr.sp.approve',null],
        ['human-resource','hr-development','Development','/human-resource/development',26,'hr.development.view','hr.development.create','hr.development.update','hr.development.delete'],
        ['human-resource','hr-recruitment','Recruitment','/human-resource/recruitment',27,'hr.recruitment.view','hr.recruitment.create','hr.recruitment.update','hr.recruitment.delete'],
        ['human-resource','hr-recruitment-interview','Interview','/human-resource/recruitment/interview',28,'hr.recruitment.interview.view','hr.recruitment.interview.create','hr.recruitment.interview.update',null],
        ['report','report-kpi-squad','KPI Squad','/report/kpi-squad',30,'hr.kpi.squad.view','hr.kpi.squad.input','hr.kpi.squad.update','hr.kpi.squad.reopen'],
        ['human-resource','hr-mapping-kpi','Mapping KPI','/human-resource/mapping-kpi',43,'hr.kpi.mapping.view','hr.kpi.mapping.recalculate','hr.kpi.mapping.recalculate',null],
        ['human-resource','hr-bonus-projection','Proyeksi Bonus','/human-resource/cutoff-proyeksi-bonus',52,'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.recalculate',null],
        ['human-resource','hr-bonus-cutoff','Cutoff Bonus','/human-resource/cutoff-bonus',54,'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.submit',null],
    ];

    private const PERMISSIONS = [
        'hr.schedule.view','hr.schedule.create','hr.schedule.update','hr.schedule.delete','hr.schedule.self.view',
        'hr.leave.self.view','hr.leave.self.create','hr.leave.self.cancel','hr.attendance.manual.create','hr.attendance.manual.update','hr.leave.manual.create',
        'hr.announcement.view','hr.announcement.create','hr.announcement.update','hr.announcement.delete','hr.announcement.publish','hr.announcement.results','hr.announcement.export',
        'hr.contract.view','hr.contract.create','hr.contract.update','hr.contract.delete','hr.contract.submit','hr.contract.approve','hr.contract.document.generate','hr.contract.reminder.manage',
        'hr.punishment.view','hr.punishment.create','hr.punishment.update','hr.punishment.delete','hr.punishment.submit','hr.punishment.approve',
        'hr.sp.view','hr.sp.create','hr.sp.submit','hr.sp.approve',
        'hr.payroll.cutoff.submit','hr.payroll.cutoff.reopen','hr.payroll.cutoff.import','hr.payroll.cutoff.export','hr.bonus.cutoff.view',
        'finance.payroll_posting.bpjs.import','finance.payroll_posting.bpjs.export',
        'hr.development.view','hr.development.create','hr.development.update','hr.development.delete','hr.development.participant.manage','hr.development.test.score','hr.development.result.publish','hr.development.import','hr.development.export',
        'hr.recruitment.view','hr.recruitment.create','hr.recruitment.update','hr.recruitment.delete','hr.recruitment.publish','hr.recruitment.applicant.view','hr.recruitment.registration.approve',
        'hr.recruitment.interview.view','hr.recruitment.interview.create','hr.recruitment.interview.update','hr.recruitment.hire','hr.recruitment.career_document.view',
        'hr.kpi.squad.view','hr.kpi.squad.input','hr.kpi.squad.update','hr.kpi.squad.lock','hr.kpi.squad.reopen','hr.kpi.squad.export','hr.kpi.squad.import','hr.kpi.squad.violation.create',
        'hr.kpi.mapping.view','hr.kpi.mapping.recalculate','hr.kpi.mapping.export',
        'hr.bonus.projection.view','hr.bonus.projection.create','hr.bonus.projection.recalculate','hr.bonus.projection.submit','hr.bonus.projection.export',
        'finance.payroll_posting.bonus_budget','finance.payroll_posting.bonus_approve',
    ];

    public function up(): void
    {
        $this->createAuditTables();
        $this->repairAccessContract();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createAuditTables(): void
    {
        if (! Schema::hasTable('HR_go_live_runs')) {
            Schema::create('HR_go_live_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('run_number', 80)->unique('hr_gl_run_no_uq');
                $table->string('status', 32)->default('running')->index('hr_gl_run_status_idx');
                $table->boolean('strict_mode')->default(false);
                $table->boolean('include_data_checks')->default(true);
                $table->string('environment', 64)->nullable();
                $table->string('app_timezone', 64)->nullable();
                $table->string('database_timezone', 64)->nullable();
                $table->foreignUlid('started_by_user_id')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('completed_at')->nullable();
                $table->json('summary')->nullable();
                $table->timestamps();
                $table->index(['started_at', 'status'], 'hr_gl_run_time_idx');
                $table->foreign('started_by_user_id', 'hr_gl_run_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_go_live_check_results')) {
            Schema::create('HR_go_live_check_results', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('go_live_run_id');
                $table->string('check_code', 120);
                $table->string('category', 40)->index('hr_gl_chk_cat_idx');
                $table->string('severity', 20);
                $table->string('status', 20);
                $table->text('message');
                $table->string('actual_value', 255)->nullable();
                $table->string('expected_value', 255)->nullable();
                $table->json('evidence')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['go_live_run_id', 'check_code'], 'hr_gl_chk_run_code_uq');
                $table->index(['status', 'severity'], 'hr_gl_chk_status_idx');
                $table->foreign('go_live_run_id', 'hr_gl_chk_run_fk')->references('id')->on('HR_go_live_runs')->cascadeOnDelete();
            });
        }
    }

    private function repairAccessContract(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach (self::PERMISSIONS as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $now = now();
        foreach (self::MENUS as [$portalCode,$code,$name,$path,$sort,$view,$create,$update,$delete]) {
            $portal = DB::table('access_portals')->where('code', $portalCode)->first();
            if (! $portal) continue;
            $existing = DB::table('access_menus')->where('code', $code)->first();
            $id = (string) ($existing->id ?? Str::ulid());
            DB::table('access_menus')->updateOrInsert(['code' => $code], [
                'id' => $id,
                'portal_id' => (string) $portal->id,
                'name' => $name,
                'path' => $path,
                'sort_order' => $sort,
                'permission_view' => $view,
                'permission_create' => $create,
                'permission_update' => $update,
                'permission_delete' => $delete,
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        $hrPortal = DB::table('access_portals')->where('code', 'human-resource')->value('id');
        if ($hrPortal) {
            DB::table('access_menus')->where('portal_id', $hrPortal)->where('code', 'hr-users')->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Go-live audit history and access repairs are intentionally non-destructive.
    }
};
