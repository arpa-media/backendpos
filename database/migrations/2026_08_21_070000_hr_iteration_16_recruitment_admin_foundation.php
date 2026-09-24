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
        $this->createRecruitments();
        $this->createPositions();
        $this->createQualifications();
        $this->createRegistrationRequests();
        $this->createApplications();
        $this->seedAccessMatrix();
    }

    private function createRecruitments(): void
    {
        if (Schema::hasTable('HR_recruitments')) return;
        Schema::create('HR_recruitments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80)->unique('hr_rec_code_uq');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft');
            $table->dateTime('active_from')->nullable();
            $table->dateTime('active_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->foreignUlid('published_by_user_id')->nullable();
            $table->foreignUlid('closed_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'active_from', 'active_until'], 'hr_rec_status_window_idx');
            $table->foreign('created_by_user_id', 'hr_rec_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_rec_updater_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('published_by_user_id', 'hr_rec_publisher_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by_user_id', 'hr_rec_closer_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createPositions(): void
    {
        if (Schema::hasTable('HR_recruitment_positions')) return;
        Schema::create('HR_recruitment_positions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_id');
            $table->string('code', 80);
            $table->string('position_name', 180);
            $table->string('destination_type', 24); // outlet / management / warehouse
            $table->foreignUlid('destination_outlet_id');
            $table->unsignedInteger('quota')->default(1);
            $table->string('employment_type_target', 24)->default('any'); // any / spt / pkwt
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['recruitment_id', 'code'], 'hr_rec_pos_code_uq');
            $table->index(['destination_outlet_id', 'is_active'], 'hr_rec_pos_dest_idx');
            $table->foreign('recruitment_id', 'hr_rec_pos_rec_fk')->references('id')->on('HR_recruitments')->cascadeOnDelete();
            $table->foreign('destination_outlet_id', 'hr_rec_pos_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
        });
    }

    private function createQualifications(): void
    {
        if (Schema::hasTable('HR_recruitment_qualifications')) return;
        Schema::create('HR_recruitment_qualifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_position_id');
            $table->string('requirement_type', 24)->default('required');
            $table->string('label', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['recruitment_position_id', 'sort_order'], 'hr_rec_qual_pos_idx');
            $table->foreign('recruitment_position_id', 'hr_rec_qual_pos_fk')->references('id')->on('HR_recruitment_positions')->cascadeOnDelete();
        });
    }

    private function createRegistrationRequests(): void
    {
        if (Schema::hasTable('HR_career_registration_requests')) return;
        Schema::create('HR_career_registration_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('nik', 40);
            $table->string('full_name', 200);
            $table->string('phone', 40);
            $table->string('email', 190)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('request_source', 32)->default('career');
            $table->char('career_account_id', 26)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->foreignUlid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['nik', 'status'], 'hr_car_reg_nik_idx');
            $table->index(['phone', 'status'], 'hr_car_reg_phone_idx');
            $table->index(['status', 'requested_at'], 'hr_car_reg_status_idx');
            $table->foreign('reviewed_by_user_id', 'hr_car_reg_reviewer_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createApplications(): void
    {
        if (Schema::hasTable('HR_applications')) return;
        Schema::create('HR_applications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('recruitment_id');
            $table->foreignUlid('recruitment_position_id');
            $table->char('registration_request_id', 26)->nullable();
            $table->char('career_account_id', 26)->nullable();
            $table->string('nik', 40);
            $table->string('applicant_name', 200);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('stage', 40)->default('applied');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['recruitment_id', 'stage'], 'hr_app_rec_stage_idx');
            $table->index(['recruitment_position_id', 'stage'], 'hr_app_pos_stage_idx');
            $table->index(['nik', 'stage'], 'hr_app_nik_stage_idx');
            $table->foreign('recruitment_id', 'hr_app_rec_fk')->references('id')->on('HR_recruitments')->restrictOnDelete();
            $table->foreign('recruitment_position_id', 'hr_app_pos_fk')->references('id')->on('HR_recruitment_positions')->restrictOnDelete();
            $table->foreign('registration_request_id', 'hr_app_reg_fk')->references('id')->on('HR_career_registration_requests')->nullOnDelete();
        });
    }

    private function seedAccessMatrix(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (!$portal) return;
        $now = now();
        $existing = DB::table('access_menus')->where('code', 'hr-recruitment')->first();
        $menuId = $existing?->id ?: (string) Str::ulid();
        DB::table('access_menus')->updateOrInsert(['code' => 'hr-recruitment'], [
            'id' => $menuId,
            'portal_id' => $portal->id,
            'name' => 'Recruitment',
            'path' => '/human-resource/recruitment',
            'sort_order' => 27,
            'permission_view' => 'hr.recruitment.view',
            'permission_create' => 'hr.recruitment.create',
            'permission_update' => 'hr.recruitment.update',
            'permission_delete' => 'hr.recruitment.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.recruitment.view','hr.recruitment.create','hr.recruitment.update','hr.recruitment.delete',
            'hr.recruitment.publish','hr.recruitment.applicant.view','hr.recruitment.registration.approve',
        ] as $name) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($name, $guard);
        }

        if (!Schema::hasTable('access_roles') || !Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn($v)=>(string)$v)->all() : [];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $code = strtoupper(trim((string) $role->code));
            $admin = $code === 'ADMIN';
            $manager = $code === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
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
        // Non-destructive: recruitment and applicant history are HR records.
    }
};
