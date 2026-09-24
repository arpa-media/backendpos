<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_access_assignment_audits')) {
            Schema::create('user_access_assignment_audits', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('batch_id')->index();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event', 80)->default('ACCESS_UPDATE')->index();
                $table->foreignUlid('before_access_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
                $table->foreignUlid('before_access_level_id')->nullable()->constrained('access_levels')->nullOnDelete();
                $table->foreignUlid('after_access_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
                $table->foreignUlid('after_access_level_id')->nullable()->constrained('access_levels')->nullOnDelete();
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();

                $table->index(['subject_user_id', 'created_at'], 'uaa_subject_created_idx');
                $table->index(['actor_user_id', 'created_at'], 'uaa_actor_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Audit history is intentionally retained on rollback.
    }
};
