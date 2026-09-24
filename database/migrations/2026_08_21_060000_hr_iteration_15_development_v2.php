<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $this->createDevelopments();
        $this->createBatches();
        $this->createFields();
        $this->createScoringPolicies();
        $this->createParticipants();
        $this->createFieldValues();
        $this->createAchievements();
        $this->createCertificateTemplates();
        $this->seedAccessMatrix();
    }

    private function createDevelopments(): void
    {
        if (Schema::hasTable('HR_developments')) return;
        Schema::create('HR_developments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80)->unique('hr_dev_code_uq');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->string('status', 24)->default('draft');
            $table->boolean('has_test')->default(false);
            $table->boolean('output_badge')->default(false);
            $table->string('badge_name', 160)->nullable();
            $table->text('badge_description')->nullable();
            $table->boolean('output_certificate')->default(false);
            $table->string('certificate_title', 200)->nullable();
            $table->char('active_scoring_policy_id', 26)->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->foreignUlid('published_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at'], 'hr_dev_status_idx');
            $table->foreign('created_by_user_id', 'hr_dev_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_dev_updater_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('published_by_user_id', 'hr_dev_publisher_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createBatches(): void
    {
        if (Schema::hasTable('HR_development_batches')) return;
        Schema::create('HR_development_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->string('batch_code', 80);
            $table->string('name', 160);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 24)->default('planned');
            $table->unsignedInteger('capacity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['development_id', 'batch_code'], 'hr_dev_batch_code_uq');
            $table->index(['development_id', 'start_date'], 'hr_dev_batch_date_idx');
            $table->foreign('development_id', 'hr_dev_batch_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
        });
    }

    private function createFields(): void
    {
        if (Schema::hasTable('HR_development_fields')) return;
        Schema::create('HR_development_fields', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->string('kind', 16); // input/output
            $table->string('code', 80);
            $table->string('label', 160);
            $table->string('field_type', 24)->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['development_id', 'code'], 'hr_dev_field_code_uq');
            $table->index(['development_id', 'kind', 'sort_order'], 'hr_dev_field_kind_idx');
            $table->foreign('development_id', 'hr_dev_field_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
        });
    }

    private function createScoringPolicies(): void
    {
        if (Schema::hasTable('HR_development_scoring_policies')) return;
        Schema::create('HR_development_scoring_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('mode', 24)->default('bands'); // bands / linear
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['development_id', 'version'], 'hr_dev_score_ver_uq');
            $table->foreign('development_id', 'hr_dev_score_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'hr_dev_score_creator_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createParticipants(): void
    {
        if (Schema::hasTable('HR_development_participants')) return;
        Schema::create('HR_development_participants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->foreignUlid('batch_id');
            $table->foreignUlid('employee_id');
            $table->foreignUlid('outlet_id')->nullable();
            $table->string('assigned_via', 24)->default('manual');
            $table->string('status', 24)->default('assigned');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('score_raw', 10, 4)->nullable();
            $table->decimal('score_final', 10, 4)->nullable();
            $table->string('result_label', 100)->nullable();
            $table->boolean('result_passed')->nullable();
            $table->char('scoring_policy_id', 26)->nullable();
            $table->json('result_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('result_published_at')->nullable();
            $table->foreignUlid('result_published_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'employee_id'], 'hr_dev_part_batch_emp_uq');
            $table->index(['employee_id', 'status'], 'hr_dev_part_emp_idx');
            $table->index(['development_id', 'status'], 'hr_dev_part_status_idx');
            $table->foreign('development_id', 'hr_dev_part_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
            $table->foreign('batch_id', 'hr_dev_part_batch_fk')->references('id')->on('HR_development_batches')->cascadeOnDelete();
            $table->foreign('employee_id', 'hr_dev_part_emp_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('outlet_id', 'hr_dev_part_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('scoring_policy_id', 'hr_dev_part_score_fk')->references('id')->on('HR_development_scoring_policies')->nullOnDelete();
            $table->foreign('result_published_by_user_id', 'hr_dev_part_pub_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createFieldValues(): void
    {
        if (Schema::hasTable('HR_development_field_values')) return;
        Schema::create('HR_development_field_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('participant_id');
            $table->foreignUlid('field_id');
            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'field_id'], 'hr_dev_value_part_field_uq');
            $table->foreign('participant_id', 'hr_dev_value_part_fk')->references('id')->on('HR_development_participants')->cascadeOnDelete();
            $table->foreign('field_id', 'hr_dev_value_field_fk')->references('id')->on('HR_development_fields')->cascadeOnDelete();
            $table->foreign('updated_by_user_id', 'hr_dev_value_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createAchievements(): void
    {
        if (Schema::hasTable('HR_development_achievements')) return;
        Schema::create('HR_development_achievements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('participant_id');
            $table->foreignUlid('development_id');
            $table->foreignUlid('employee_id');
            $table->string('type', 24); // badge / certificate
            $table->string('achievement_code', 100)->nullable();
            $table->string('title', 200);
            $table->json('snapshot')->nullable();
            $table->timestamp('issued_at');
            $table->foreignUlid('issued_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['participant_id', 'type'], 'hr_dev_ach_part_type_uq');
            $table->index(['employee_id', 'issued_at'], 'hr_dev_ach_emp_idx');
            $table->foreign('participant_id', 'hr_dev_ach_part_fk')->references('id')->on('HR_development_participants')->cascadeOnDelete();
            $table->foreign('development_id', 'hr_dev_ach_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
            $table->foreign('employee_id', 'hr_dev_ach_emp_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('issued_by_user_id', 'hr_dev_ach_issue_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by_user_id', 'hr_dev_ach_revoke_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createCertificateTemplates(): void
    {
        if (Schema::hasTable('HR_development_certificate_templates')) return;
        Schema::create('HR_development_certificate_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('original_name', 240);
            $table->string('mime_type', 120);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->string('compression_method', 24)->default('gzip');
            $table->unsignedBigInteger('source_size_bytes');
            $table->unsignedBigInteger('compressed_size_bytes');
            $table->char('sha256', 64);
            $table->json('merge_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('uploaded_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['development_id', 'version'], 'hr_dev_cert_tpl_ver_uq');
            $table->index(['development_id', 'is_active'], 'hr_dev_cert_tpl_active_idx');
            $table->foreign('development_id', 'hr_dev_cert_tpl_dev_fk')->references('id')->on('HR_developments')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'hr_dev_cert_tpl_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedAccessMatrix(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (!$portal) return;
        $now = now();
        $existing = DB::table('access_menus')->where('code', 'hr-development')->first();
        $menuId = (string)($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => 'hr-development'], [
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>'Development','path'=>'/human-resource/development','sort_order'=>26,
            'permission_view'=>'hr.development.view','permission_create'=>'hr.development.create','permission_update'=>'hr.development.update','permission_delete'=>'hr.development.delete',
            'is_active'=>true,'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now,
        ]);

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.development.view','hr.development.create','hr.development.update','hr.development.delete',
            'hr.development.participant.manage','hr.development.test.score','hr.development.result.publish',
            'hr.development.import','hr.development.export',
        ] as $name) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($name, $guard);
        }

        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn($v)=>(string)$v)->all() : [];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $code = strtoupper(trim((string)$role->code));
            $admin = $code === 'ADMIN';
            $manager = $code === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $q = DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,
                    'can_view'=>$admin || $manager,'can_create'=>$admin || $manager,'can_edit'=>$admin || $manager,'can_delete'=>$admin,
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: development history/certificates are employee records.
    }
};
