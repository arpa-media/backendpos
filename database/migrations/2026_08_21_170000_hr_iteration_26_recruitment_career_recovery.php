<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_career_password_reset_requests')) {
            Schema::create('HR_career_password_reset_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('career_account_id')->nullable();
                $table->string('nik', 40);
                $table->string('full_name', 200)->nullable();
                $table->string('phone', 40);
                $table->string('email', 190)->nullable();
                $table->string('status', 24)->default('pending');
                $table->string('request_source', 32)->default('career');
                $table->timestamp('requested_at')->nullable();
                $table->foreignUlid('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['nik', 'status'], 'hr_car_pwd_nik_idx');
                $table->index(['career_account_id', 'status'], 'hr_car_pwd_acc_idx');
                $table->index(['status', 'requested_at'], 'hr_car_pwd_status_idx');
                $table->foreign('career_account_id', 'hr_car_pwd_acc_fk')->references('id')->on('HR_career_accounts')->nullOnDelete();
                $table->foreign('reviewed_by_user_id', 'hr_car_pwd_rev_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ([
                'hr.recruitment.registration.bulk_approve',
                'hr.recruitment.password_reset.approve',
            ] as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        // Access Matrix existing Recruitment tetap menjadi source of truth.
        // Action baru memakai permission_update (Edit) sebagai fallback snapshot,
        // sehingga tidak membuat menu baru dan tidak memperluas grant role secara diam-diam.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'hr-recruitment')->update(['updated_at' => now()]);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design: request reset password adalah audit recruitment/career.
    }
};
