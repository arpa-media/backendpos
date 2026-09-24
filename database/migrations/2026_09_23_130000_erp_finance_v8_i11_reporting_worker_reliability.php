<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_materialization_settings')) {
            Schema::table('report_materialization_settings', function (Blueprint $t): void {
                if (!Schema::hasColumn('report_materialization_settings', 'worker_lease_seconds')) $t->unsignedInteger('worker_lease_seconds')->default(3600)->after('monthly_enabled');
                if (!Schema::hasColumn('report_materialization_settings', 'max_attempts')) $t->unsignedTinyInteger('max_attempts')->default(3)->after('worker_lease_seconds');
                if (!Schema::hasColumn('report_materialization_settings', 'retry_backoff_seconds')) $t->unsignedSmallInteger('retry_backoff_seconds')->default(60)->after('max_attempts');
                if (!Schema::hasColumn('report_materialization_settings', 'dispatch_batch')) $t->unsignedTinyInteger('dispatch_batch')->default(4)->after('retry_backoff_seconds');
                if (!Schema::hasColumn('report_materialization_settings', 'last_dispatch_at')) $t->timestamp('last_dispatch_at')->nullable()->after('last_tick_at');
                if (!Schema::hasColumn('report_materialization_settings', 'last_worker_heartbeat_at')) $t->timestamp('last_worker_heartbeat_at')->nullable()->after('last_dispatch_at');
                if (!Schema::hasColumn('report_materialization_settings', 'last_recovery_at')) $t->timestamp('last_recovery_at')->nullable()->after('last_worker_heartbeat_at');
            });
        }

        if (Schema::hasTable('report_materialization_runs')) {
            Schema::table('report_materialization_runs', function (Blueprint $t): void {
                if (!Schema::hasColumn('report_materialization_runs', 'recovery_count')) $t->unsignedInteger('recovery_count')->default(0)->after('failed_chunks');
            });
        }

        if (Schema::hasTable('report_materialization_run_chunks')) {
            Schema::table('report_materialization_run_chunks', function (Blueprint $t): void {
                if (!Schema::hasColumn('report_materialization_run_chunks', 'dispatch_token')) $t->string('dispatch_token', 40)->nullable()->after('attempts');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'worker_token')) $t->string('worker_token', 100)->nullable()->after('dispatch_token');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'available_at')) $t->timestamp('available_at')->nullable()->after('worker_token');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'dispatched_at')) $t->timestamp('dispatched_at')->nullable()->after('available_at');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'claimed_at')) $t->timestamp('claimed_at')->nullable()->after('dispatched_at');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'heartbeat_at')) $t->timestamp('heartbeat_at')->nullable()->after('claimed_at');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'lease_expires_at')) $t->timestamp('lease_expires_at')->nullable()->after('heartbeat_at');
                if (!Schema::hasColumn('report_materialization_run_chunks', 'recovery_count')) $t->unsignedSmallInteger('recovery_count')->default(0)->after('lease_expires_at');
            });

            Schema::table('report_materialization_run_chunks', function (Blueprint $t): void {
                $t->index(['status', 'available_at', 'sequence'], 'rmchunks_dispatch_ready_idx');
                $t->index(['status', 'lease_expires_at'], 'rmchunks_lease_idx');
            });

            // I11 plans downstream stages up-front; existing unfinished runs remain compatible.
            DB::table('report_materialization_run_chunks')
                ->where('status', 'running')
                ->whereNull('lease_expires_at')
                ->update([
                    'status' => 'queued',
                    'available_at' => now(),
                    'worker_token' => null,
                    'dispatch_token' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: worker lease/recovery metadata is operational audit data.
    }
};
