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
        $this->createRules();
        $this->createViolations();
        $this->createRecommendations();
        $this->extendViolationsRecommendationLink();
        $this->createWarningLetters();
        $this->createWarningLetterApprovals();
        $this->seedDefaultRules();
        $this->seedAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createRules(): void
    {
        if (Schema::hasTable('HR_punishment_rules')) return;
        Schema::create('HR_punishment_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80)->unique('hr_pun_rule_code_uq');
            $table->string('name', 160);
            $table->string('event_type', 40); // late|alpha|manual|kpi
            $table->unsignedSmallInteger('threshold_count')->default(1);
            $table->unsignedSmallInteger('window_days')->default(30);
            $table->boolean('auto_create_violation')->default(false);
            $table->boolean('auto_recommend_sp')->default(true);
            $table->unsignedSmallInteger('grace_minutes')->default(60);
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'is_active'], 'hr_pun_rule_event_idx');
            $table->foreign('created_by_user_id', 'hr_pun_rule_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_pun_rule_updater_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createViolations(): void
    {
        if (Schema::hasTable('HR_violations')) return;
        Schema::create('HR_violations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->foreignUlid('outlet_id')->nullable();
            $table->string('violation_type', 40);
            $table->date('violation_date');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('medium');
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('source_type', 40)->default('manual');
            $table->string('source_ref', 100)->nullable();
            $table->char('fingerprint', 64)->unique('hr_violation_fp_uq');
            $table->string('status', 24)->default('draft');
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'violation_date'], 'hr_violation_emp_date_idx');
            $table->index(['status', 'violation_type'], 'hr_violation_status_type_idx');
            $table->index(['outlet_id', 'violation_date'], 'hr_violation_outlet_date_idx');
            $table->foreign('employee_id', 'hr_violation_emp_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('squad_id', 'hr_violation_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            $table->foreign('outlet_id', 'hr_violation_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('created_by_user_id', 'hr_violation_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by_user_id', 'hr_violation_submitter_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id', 'hr_violation_approver_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by_user_id', 'hr_violation_rejecter_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createRecommendations(): void
    {
        if (Schema::hasTable('HR_violation_recommendations')) return;
        Schema::create('HR_violation_recommendations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->foreignUlid('outlet_id')->nullable();
            $table->foreignUlid('rule_id')->nullable();
            $table->unsignedTinyInteger('recommended_sp_level');
            $table->date('window_from');
            $table->date('window_to');
            $table->unsignedSmallInteger('qualifying_count')->default(1);
            $table->json('violation_ids')->nullable();
            $table->text('reason');
            $table->string('status', 24)->default('pending'); // pending|converted|dismissed
            $table->char('fingerprint', 64)->unique('hr_violation_rec_fp_uq');
            $table->foreignUlid('warning_letter_id')->nullable();
            $table->foreignUlid('acted_by_user_id')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->text('action_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'hr_violation_rec_emp_idx');
            $table->index(['outlet_id', 'status'], 'hr_violation_rec_outlet_idx');
            $table->index(['status', 'created_at'], 'hr_violation_rec_status_idx');
            $table->foreign('employee_id', 'hr_violation_rec_emp_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('squad_id', 'hr_violation_rec_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            $table->foreign('outlet_id', 'hr_violation_rec_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('rule_id', 'hr_violation_rec_rule_fk')->references('id')->on('HR_punishment_rules')->nullOnDelete();
            $table->foreign('acted_by_user_id', 'hr_violation_rec_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function extendViolationsRecommendationLink(): void
    {
        if (!Schema::hasTable('HR_violations') || Schema::hasColumn('HR_violations', 'recommendation_id')) return;
        Schema::table('HR_violations', function (Blueprint $table): void {
            $table->char('recommendation_id', 26)->nullable()->after('metadata');
            $table->index('recommendation_id', 'hr_violation_rec_idx');
            $table->foreign('recommendation_id', 'hr_violation_rec_fk')->references('id')->on('HR_violation_recommendations')->nullOnDelete();
        });
    }

    private function createWarningLetters(): void
    {
        if (Schema::hasTable('HR_warning_letters')) return;
        Schema::create('HR_warning_letters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->foreignUlid('outlet_id')->nullable();
            $table->foreignUlid('contract_id')->nullable();
            $table->foreignUlid('recommendation_id')->nullable();
            $table->unsignedTinyInteger('sp_level');
            $table->string('letter_no', 100)->unique('hr_sp_letter_no_uq');
            $table->date('issue_date');
            $table->date('effective_date');
            $table->string('title', 220);
            $table->text('reason');
            $table->longText('body_snapshot')->nullable();
            $table->json('employee_snapshot')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUlid('termination_contract_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'sp_level', 'status'], 'hr_sp_emp_level_idx');
            $table->index(['outlet_id', 'status'], 'hr_sp_outlet_status_idx');
            $table->index(['status', 'submitted_at'], 'hr_sp_status_idx');
            $table->foreign('employee_id', 'hr_sp_emp_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('squad_id', 'hr_sp_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            $table->foreign('outlet_id', 'hr_sp_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('contract_id', 'hr_sp_contract_fk')->references('id')->on('HR_contracts')->nullOnDelete();
            $table->foreign('recommendation_id', 'hr_sp_rec_fk')->references('id')->on('HR_violation_recommendations')->nullOnDelete();
            $table->foreign('created_by_user_id', 'hr_sp_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by_user_id', 'hr_sp_submitter_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id', 'hr_sp_approver_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by_user_id', 'hr_sp_rejecter_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('termination_contract_document_id', 'hr_sp_term_doc_fk')->references('id')->on('HR_contract_documents')->nullOnDelete();
        });

        if (Schema::hasTable('HR_violation_recommendations')) {
            Schema::table('HR_violation_recommendations', function (Blueprint $table): void {
                $table->foreign('warning_letter_id', 'hr_violation_rec_sp_fk')->references('id')->on('HR_warning_letters')->nullOnDelete();
            });
        }
    }

    private function createWarningLetterApprovals(): void
    {
        if (Schema::hasTable('HR_warning_letter_approvals')) return;
        Schema::create('HR_warning_letter_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warning_letter_id');
            $table->unsignedTinyInteger('step_number')->default(1);
            $table->string('status', 24)->default('pending');
            $table->foreignUlid('requested_by_user_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->foreignUlid('approver_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['warning_letter_id', 'step_number'], 'hr_sp_app_letter_step_uq');
            $table->index(['status', 'requested_at'], 'hr_sp_app_status_idx');
            $table->foreign('warning_letter_id', 'hr_sp_app_letter_fk')->references('id')->on('HR_warning_letters')->cascadeOnDelete();
            $table->foreign('requested_by_user_id', 'hr_sp_app_req_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approver_user_id', 'hr_sp_app_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedDefaultRules(): void
    {
        if (!Schema::hasTable('HR_punishment_rules')) return;
        $now = now();
        foreach ([
            ['code' => 'LATE_2X', 'name' => 'Terlambat 2x', 'event_type' => 'late', 'threshold_count' => 2, 'window_days' => 30, 'auto_create_violation' => true, 'auto_recommend_sp' => true, 'grace_minutes' => 60],
            ['code' => 'ALPHA_1X', 'name' => 'Alpha 1x', 'event_type' => 'alpha', 'threshold_count' => 1, 'window_days' => 30, 'auto_create_violation' => true, 'auto_recommend_sp' => true, 'grace_minutes' => 60],
        ] as $rule) {
            $existing = DB::table('HR_punishment_rules')->where('code', $rule['code'])->first();
            DB::table('HR_punishment_rules')->updateOrInsert(['code' => $rule['code']], array_merge($rule, [
                'id' => (string) ($existing->id ?? Str::ulid()),
                'config' => json_encode(['version' => 1], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]));
        }
    }

    private function seedAccessMatrix(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (!$portal) return;
        $now = now();
        $punishmentId = $this->upsertMenu((string)$portal->id, 'hr-punishment', 'Punishment', '/human-resource/punishment', 25,
            'hr.punishment.view', 'hr.punishment.create', 'hr.punishment.update', 'hr.punishment.delete', $now);
        $spId = $this->upsertMenu((string)$portal->id, 'hr-approval-sp', 'Approval SP', '/human-resource/approval-sp', 45,
            'hr.sp.view', 'hr.sp.create', 'hr.sp.approve', null, $now);

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.punishment.view','hr.punishment.create','hr.punishment.update','hr.punishment.delete','hr.punishment.submit','hr.punishment.approve',
            'hr.sp.view','hr.sp.create','hr.sp.submit','hr.sp.approve',
        ] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }

        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn($v)=>(string)$v)->all() : [];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $code = strtoupper(trim((string)$role->code));
            $admin = $code === 'ADMIN';
            $manager = $code === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $this->insertMatrixIfMissing($role->id, $levelId, $punishmentId, $admin || $manager, $admin || $manager, $admin || $manager, $admin, $now);
                $this->insertMatrixIfMissing($role->id, $levelId, $spId, $admin || $manager, $admin || $manager, $admin || $manager, false, $now);
            }
        }
    }

    private function upsertMenu(string $portalId, string $code, string $name, string $path, int $sort, ?string $view, ?string $create, ?string $update, ?string $delete, $now): string
    {
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $id = (string)($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id'=>$id,'portal_id'=>$portalId,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
            'permission_view'=>$view,'permission_create'=>$create,'permission_update'=>$update,'permission_delete'=>$delete,
            'is_active'=>true,'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now,
        ]);
        return $id;
    }

    private function insertMatrixIfMissing($roleId, $levelId, string $menuId, bool $view, bool $create, bool $edit, bool $delete, $now): void
    {
        $q = DB::table('access_role_menu_permissions')->where('access_role_id',$roleId)->where('menu_id',$menuId);
        $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$levelId);
        if ($q->exists()) return;
        DB::table('access_role_menu_permissions')->insert([
            'id'=>(string)Str::ulid(),'access_role_id'=>$roleId,'access_level_id'=>$levelId,'menu_id'=>$menuId,
            'can_view'=>$view,'can_create'=>$create,'can_edit'=>$edit,'can_delete'=>$delete,'created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive by design: punishment/SP records are HR disciplinary audit evidence.
    }
};
