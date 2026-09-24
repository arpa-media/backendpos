<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();

        if (! Schema::hasTable('wh_security_audit_events')) {
            Schema::create('wh_security_audit_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_id', 160)->index();
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('route_name', 180)->nullable()->index();
                $table->string('action', 180)->nullable()->index();
                $table->string('http_method', 12);
                $table->string('path', 500);
                $table->unsignedSmallInteger('response_status')->nullable()->index();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->string('idempotency_key_hash', 64)->nullable()->index();
                $table->string('payload_hash', 64)->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->string('user_agent_hash', 64)->nullable();
                $table->string('result', 24)->default('completed')->index();
                $table->string('error_class', 180)->nullable();
                $table->string('error_code', 80)->nullable();
                $table->json('metadata')->nullable();
                $table->dateTime('occurred_at')->useCurrent()->index();
                $table->timestamps();
                $table->index(['warehouse_id', 'occurred_at'], 'wh_sec_audit_wh_date_idx');
                $table->index(['user_id', 'occurred_at'], 'wh_sec_audit_user_date_idx');
            });
        }

        if (! Schema::hasTable('wh_signed_scan_tokens')) {
            Schema::create('wh_signed_scan_tokens', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
                $table->string('workflow', 40)->index();
                $table->ulid('target_id')->index();
                $table->string('nonce', 64)->unique();
                $table->string('signature_hash', 64);
                $table->string('status', 24)->default('issued')->index();
                $table->string('payload_hash', 64)->nullable();
                $table->string('request_id', 160)->nullable()->index();
                $table->dateTime('issued_at')->useCurrent();
                $table->dateTime('expires_at')->index();
                $table->dateTime('processing_at')->nullable();
                $table->dateTime('consumed_at')->nullable();
                $table->dateTime('failed_at')->nullable();
                $table->foreignUlid('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'user_id', 'status'], 'wh_scan_token_scope_status_idx');
            });
        }

        if (! Schema::hasTable('wh_health_check_runs')) {
            Schema::create('wh_health_check_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('scope_mode', 24)->default('selected');
                $table->string('status', 24)->default('running')->index();
                $table->unsignedInteger('pass_count')->default(0);
                $table->unsignedInteger('warning_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->json('checks')->nullable();
                $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('request_id', 160)->nullable()->index();
                $table->dateTime('started_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'created_at'], 'wh_health_run_wh_created_idx');
            });
        }

        if (! Schema::hasTable('wh_uat_runs')) {
            Schema::create('wh_uat_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('scope_mode', 24)->default('selected');
                $table->string('status', 24)->default('running')->index();
                $table->string('release_label', 120)->nullable();
                $table->unsignedInteger('pass_count')->default(0);
                $table->unsignedInteger('warning_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('request_id', 160)->nullable()->index();
                $table->dateTime('started_at')->useCurrent();
                $table->dateTime('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'created_at'], 'wh_uat_run_wh_created_idx');
            });
        }

        if (! Schema::hasTable('wh_uat_case_results')) {
            Schema::create('wh_uat_case_results', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('uat_run_id')->constrained('wh_uat_runs')->cascadeOnDelete();
                $table->string('case_code', 100)->index();
                $table->string('case_name', 220);
                $table->string('category', 60)->index();
                $table->string('status', 24)->index();
                $table->text('message')->nullable();
                $table->json('evidence')->nullable();
                $table->timestamps();
                $table->unique(['uat_run_id', 'case_code'], 'wh_uat_case_run_code_uniq');
            });
        }
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'users', 'access_portals', 'access_menus',
            'wh_ledger_postings', 'wh_ledger_entries', 'wh_batch_balances',
            'wh_task_assignments', 'wh_keeper_tasks', 'wh_production_tasks',
            'wh_stock_transfer_tasks', 'wh_operational_reconciliation_runs',
        ];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Patch Warehouse Iterasi 12 membutuhkan Iterasi 01-11. Missing tables: '.implode(', ', $missing)
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: security audit, UAT, dan health history tidak dihapus otomatis.
    }
};
