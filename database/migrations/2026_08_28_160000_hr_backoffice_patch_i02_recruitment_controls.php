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
        $this->createDeletionLog();
        $this->seedDeletePermissionAndAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Account deletion logs and Access Matrix mapping
        // are retained so recruitment audit history is never silently removed.
    }

    private function createDeletionLog(): void
    {
        if (Schema::hasTable('HR_career_account_deletion_logs')) return;

        Schema::create('HR_career_account_deletion_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('career_account_id')->nullable();
            $table->char('registration_request_id', 26)->nullable();
            $table->string('nik_snapshot', 40)->nullable();
            $table->string('full_name_snapshot', 200)->nullable();
            $table->string('phone_snapshot', 40)->nullable();
            $table->string('email_snapshot', 190)->nullable();
            $table->foreignUlid('deleted_by_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('closed_presence_windows')->default(0);
            $table->unsignedInteger('rejected_password_reset_requests')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->index(['career_account_id', 'deleted_at'], 'hr_car_del_acc_time_idx');
            $table->index(['nik_snapshot', 'deleted_at'], 'hr_car_del_nik_time_idx');
            $table->foreign('career_account_id', 'hr_car_del_acc_fk')
                ->references('id')->on('HR_career_accounts')->nullOnDelete();
            $table->foreign('registration_request_id', 'hr_car_del_reg_fk')
                ->references('id')->on('HR_career_registration_requests')->nullOnDelete();
            $table->foreign('deleted_by_user_id', 'hr_car_del_user_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedDeletePermissionAndAccessMatrix(): void
    {
        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate(
                'hr.recruitment.applicant_register.delete',
                config('auth.defaults.guard', 'web')
            );
        }

        if (! Schema::hasTable('access_menus')) return;

        $menu = DB::table('access_menus')
            ->where('code', 'hr-recruitment-applicant-register')
            ->orWhere('path', '/human-resource/recruitment/applicant-register')
            ->first();
        if (! $menu) return;

        $now = now();
        DB::table('access_menus')->where('id', $menu->id)->update([
            'permission_delete' => 'hr.recruitment.applicant_register.delete',
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;

        // Existing users who can Edit Applicant Register receive Delete by default so
        // the feature is immediately usable after migration. Admin can turn Delete OFF
        // independently through Access Matrix afterwards.
        DB::table('access_role_menu_permissions')
            ->where('menu_id', $menu->id)
            ->where('can_edit', true)
            ->update(['can_delete' => true, 'updated_at' => $now]);
    }
};
