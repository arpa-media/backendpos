<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_recruitment_reset_audits')) {
            Schema::create('HR_recruitment_reset_audits', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('actor_user_id')->nullable();
                $table->string('scope_mode', 24)->default('allowed_outlets');
                $table->unsignedInteger('outlet_count')->default(0);
                $table->dateTime('range_from')->nullable();
                $table->dateTime('range_to')->nullable();
                $table->json('snapshot_counts');
                $table->json('deleted_counts');
                $table->json('preserved_counts')->nullable();
                $table->text('reason')->nullable();
                $table->string('confirmation_phrase', 40);
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('reset_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['reset_at', 'actor_user_id'], 'hr_rra_time_actor_idx');
                $table->foreign('actor_user_id', 'hr_rra_actor_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('hr.recruitment.reset', $guard);
            if (app()->bound(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        }
    }

    public function down(): void
    {
        // Audit records are intentionally retained. This patch is non-destructive on rollback.
    }
};
