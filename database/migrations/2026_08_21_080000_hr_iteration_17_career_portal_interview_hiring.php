<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCareerAccounts();
        $this->createCareerProfiles();
        $this->createCareerDocuments();
        $this->createStageHistories();
        $this->createInterviews();
        $this->createIdentitySequences();
        $this->createHiringConversions();
        $this->wireIteration16Columns();
        $this->seedIdentitySequences();
        $this->backfillApprovedRegistrationAccounts();
        $this->seedPermissions();
        $this->seedInterviewAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createCareerAccounts(): void
    {
        if (Schema::hasTable('HR_career_accounts')) return;
        Schema::create('HR_career_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_request_id')->nullable();
            $table->string('nik', 40)->unique('hr_car_acc_nik_uq');
            $table->string('username', 60)->unique('hr_car_acc_user_uq');
            $table->string('full_name', 200);
            $table->string('phone', 40);
            $table->string('email', 190)->nullable();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('application_blocked_at')->nullable();
            $table->text('application_block_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['is_active', 'application_blocked_at'], 'hr_car_acc_status_idx');
            $table->foreign('registration_request_id', 'hr_car_acc_reg_fk')->references('id')->on('HR_career_registration_requests')->nullOnDelete();
        });
    }

    private function createCareerProfiles(): void
    {
        if (Schema::hasTable('HR_career_profiles')) return;
        Schema::create('HR_career_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('career_account_id')->unique('hr_car_prof_acc_uq');
            $table->string('nickname', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('religion', 60)->nullable();
            $table->string('education', 80)->nullable();
            $table->string('school_name', 180)->nullable();
            $table->string('major', 150)->nullable();
            $table->string('marital_status', 80)->nullable();
            $table->unsignedSmallInteger('children_count')->default(0);
            $table->string('whatsapp', 40)->nullable();
            $table->string('emergency_contact_name', 180)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->text('work_experience')->nullable();
            $table->text('skills')->nullable();
            $table->timestamps();
            $table->foreign('career_account_id', 'hr_car_prof_acc_fk')->references('id')->on('HR_career_accounts')->cascadeOnDelete();
        });
    }

    private function createCareerDocuments(): void
    {
        if (Schema::hasTable('HR_career_documents')) return;
        Schema::create('HR_career_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('career_account_id');
            $table->string('document_type', 30)->default('cv');
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('original_size')->default(0);
            $table->unsignedBigInteger('stored_size')->default(0);
            $table->char('sha256', 64);
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 500);
            $table->string('compression_method', 30)->default('gzip');
            $table->boolean('is_current')->default(true);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->unique(['career_account_id', 'document_type', 'version'], 'hr_car_doc_ver_uq');
            $table->index(['career_account_id', 'document_type', 'is_current'], 'hr_car_doc_current_idx');
            $table->foreign('career_account_id', 'hr_car_doc_acc_fk')->references('id')->on('HR_career_accounts')->cascadeOnDelete();
        });
    }

    private function createStageHistories(): void
    {
        if (Schema::hasTable('HR_application_stage_histories')) return;
        Schema::create('HR_application_stage_histories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id');
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40);
            $table->string('source', 40)->default('admin');
            $table->foreignUlid('actor_user_id')->nullable();
            $table->foreignUlid('actor_career_account_id')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['application_id', 'changed_at'], 'hr_app_hist_app_idx');
            $table->foreign('application_id', 'hr_app_hist_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_app_hist_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('actor_career_account_id', 'hr_app_hist_car_fk')->references('id')->on('HR_career_accounts')->nullOnDelete();
        });
    }

    private function createInterviews(): void
    {
        if (Schema::hasTable('HR_interviews')) return;
        Schema::create('HR_interviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id');
            $table->unsignedSmallInteger('sequence_no')->default(1);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('conducted_at')->nullable();
            $table->foreignUlid('interviewer_user_id')->nullable();
            $table->string('interviewer_name_snapshot', 180)->nullable();
            $table->longText('notes')->nullable();
            $table->string('recommendation', 60)->nullable();
            $table->string('result', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['application_id', 'sequence_no'], 'hr_int_app_seq_uq');
            $table->index(['application_id', 'recorded_at'], 'hr_int_app_date_idx');
            $table->foreign('application_id', 'hr_int_app_fk')->references('id')->on('HR_applications')->cascadeOnDelete();
            $table->foreign('interviewer_user_id', 'hr_int_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createIdentitySequences(): void
    {
        if (Schema::hasTable('HR_identity_sequences')) return;
        Schema::create('HR_identity_sequences', function (Blueprint $table): void {
            $table->string('code', 40)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->unsignedSmallInteger('width')->default(11);
            $table->string('prefix', 40)->nullable();
            $table->timestamps();
        });
    }

    private function createHiringConversions(): void
    {
        if (Schema::hasTable('HR_hiring_conversions')) return;
        Schema::create('HR_hiring_conversions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->unique('hr_hire_app_uq');
            $table->char('conversion_key', 64)->unique('hr_hire_key_uq');
            $table->string('hire_type', 20);
            $table->string('status', 30)->default('processing');
            $table->string('assigned_nisj', 80)->nullable();
            $table->foreignUlid('operational_user_id')->nullable();
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->foreignUlid('employee_id')->nullable();
            $table->foreignUlid('contract_id')->nullable();
            $table->foreignUlid('converted_by_user_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['status', 'hire_type'], 'hr_hire_status_idx');
            $table->foreign('application_id', 'hr_hire_app_fk')->references('id')->on('HR_applications')->restrictOnDelete();
            $table->foreign('operational_user_id', 'hr_hire_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('squad_id', 'hr_hire_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            $table->foreign('employee_id', 'hr_hire_emp_fk')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('contract_id', 'hr_hire_contract_fk')->references('id')->on('HR_contracts')->nullOnDelete();
            $table->foreign('converted_by_user_id', 'hr_hire_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function wireIteration16Columns(): void
    {
        if (Schema::hasTable('HR_career_registration_requests') && Schema::hasColumn('HR_career_registration_requests', 'career_account_id')) {
            $exists = collect(DB::select("SHOW CREATE TABLE `HR_career_registration_requests`"))->first();
            $create = $exists ? (string) (array_values((array) $exists)[1] ?? '') : '';
            if (! str_contains($create, 'hr_car_reg_acc_fk')) {
                Schema::table('HR_career_registration_requests', function (Blueprint $table): void {
                    $table->foreign('career_account_id', 'hr_car_reg_acc_fk')->references('id')->on('HR_career_accounts')->nullOnDelete();
                });
            }
        }
        if (Schema::hasTable('HR_applications') && Schema::hasColumn('HR_applications', 'career_account_id')) {
            $exists = collect(DB::select("SHOW CREATE TABLE `HR_applications`"))->first();
            $create = $exists ? (string) (array_values((array) $exists)[1] ?? '') : '';
            Schema::table('HR_applications', function (Blueprint $table) use ($create): void {
                if (! str_contains($create, 'hr_app_car_fk')) {
                    $table->foreign('career_account_id', 'hr_app_car_fk')->references('id')->on('HR_career_accounts')->nullOnDelete();
                }
                if (! str_contains($create, 'hr_app_car_rec_uq')) {
                    $table->unique(['career_account_id', 'recruitment_id'], 'hr_app_car_rec_uq');
                }
            });
        }
    }

    private function seedIdentitySequences(): void
    {
        if (! Schema::hasTable('HR_identity_sequences')) return;
        $values = [];
        foreach (['users', 'HR_squads'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'nisj')) continue;
            DB::table($table)->whereNotNull('nisj')->orderBy('nisj')->pluck('nisj')->each(function ($value) use (&$values): void {
                $v = trim((string) $value);
                if ($v !== '' && preg_match('/^\d+$/', $v)) $values[] = $v;
            });
        }
        $width = max(11, ...array_map('strlen', $values ?: ['']));
        $max = 0;
        foreach ($values as $v) $max = max($max, (int) $v);
        DB::table('HR_identity_sequences')->updateOrInsert(['code' => 'OFFICIAL_NISJ'], [
            'last_number' => $max, 'width' => $width, 'prefix' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tempMax = 0;
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'nisj')) {
            DB::table('users')->where('nisj', 'like', 'trainee%')->pluck('nisj')->each(function ($value) use (&$tempMax): void {
                if (preg_match('/^trainee_?(\d+)/i', trim((string) $value), $m)) $tempMax = max($tempMax, (int) $m[1]);
            });
        }
        DB::table('HR_identity_sequences')->updateOrInsert(['code' => 'TEMP_ACCOUNT'], [
            'last_number' => $tempMax, 'width' => 3, 'prefix' => 'trainee', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function backfillApprovedRegistrationAccounts(): void
    {
        if (! Schema::hasTable('HR_career_registration_requests') || ! Schema::hasTable('HR_career_accounts')) return;
        DB::table('HR_career_registration_requests')->where('status', 'approved')->whereNull('career_account_id')->orderBy('id')->get()->each(function ($r): void {
            $existing = DB::table('HR_career_accounts')->where('nik', trim((string) $r->nik))->first();
            $accountId = $existing?->id ?: (string) Str::ulid();
            if (! $existing) {
                DB::table('HR_career_accounts')->insert([
                    'id' => $accountId,
                    'registration_request_id' => $r->id,
                    'nik' => trim((string) $r->nik),
                    'username' => trim((string) $r->nik),
                    'full_name' => trim((string) $r->full_name),
                    'phone' => trim((string) $r->phone),
                    'email' => $r->email ? strtolower(trim((string) $r->email)) : null,
                    'password' => Hash::make(trim((string) $r->phone)),
                    'is_active' => true,
                    'must_change_password' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('HR_career_registration_requests')->where('id', $r->id)->update(['career_account_id' => $accountId, 'updated_at' => now()]);
        });
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.recruitment.interview.view', 'hr.recruitment.interview.create', 'hr.recruitment.interview.update',
            'hr.recruitment.hire', 'hr.recruitment.career_document.view',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }
    }

    private function seedInterviewAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'hr-recruitment-interview')->first();
        $menuId = $existing?->id ?: (string) Str::ulid();
        DB::table('access_menus')->updateOrInsert(['code' => 'hr-recruitment-interview'], [
            'id' => $menuId, 'portal_id' => $portal->id, 'name' => 'Interview',
            'path' => '/human-resource/recruitment/interview', 'sort_order' => 28,
            'permission_view' => 'hr.recruitment.interview.view',
            'permission_create' => 'hr.recruitment.interview.create',
            'permission_update' => 'hr.recruitment.interview.update',
            'permission_delete' => null, 'is_active' => true,
            'created_at' => $existing->created_at ?? $now, 'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceMenuId = DB::table('access_menus')->where('code', 'hr-recruitment')->value('id');
        if ($sourceMenuId) {
            foreach (DB::table('access_role_menu_permissions')->where('menu_id', $sourceMenuId)->get() as $grant) {
                $q = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $grant->access_role_id)->where('menu_id', $menuId);
                $grant->access_level_id === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $grant->access_level_id);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(), 'access_role_id' => $grant->access_role_id,
                    'access_level_id' => $grant->access_level_id, 'menu_id' => $menuId,
                    'can_view' => (bool) $grant->can_view, 'can_create' => (bool) $grant->can_create,
                    'can_edit' => (bool) $grant->can_edit, 'can_delete' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: recruitment/career/interview/hiring history is an HR audit record.
    }
};
