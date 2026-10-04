<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_refresh_reconciliation_runs')) {
            Schema::create('report_refresh_reconciliation_runs', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->string('mode', 16)->default('preview');
                $table->string('status', 20)->default('running');
                $table->date('date_from');
                $table->date('date_to');
                $table->unsignedSmallInteger('outlet_count')->default(0);
                $table->unsignedInteger('candidate_rows')->default(0);
                $table->unsignedInteger('stale_flag_rows')->default(0);
                $table->unsignedInteger('verified_ok_rows')->default(0);
                $table->unsignedInteger('needs_rebuild_rows')->default(0);
                $table->unsignedInteger('race_changed_rows')->default(0);
                $table->unsignedInteger('resolved_rows')->default(0);
                $table->unsignedInteger('unresolved_rows')->default(0);
                $table->char('created_by_user_id', 26)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->longText('summary_json')->nullable();
                $table->timestamps();

                $table->index(['date_from', 'date_to'], 'rrr_range_idx');
                $table->index(['status', 'created_at'], 'rrr_status_created_idx');
            });
        }

        if (! Schema::hasTable('report_refresh_reconciliation_items')) {
            Schema::create('report_refresh_reconciliation_items', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('run_id', 26);
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('queue_status', 16)->nullable();
                $table->unsignedInteger('snapshot_touch_count')->default(0);
                $table->timestamp('snapshot_last_touched_at')->nullable();
                $table->timestamp('coverage_synced_at')->nullable();
                $table->string('classification', 24);
                $table->boolean('resolved')->default(false);
                $table->string('message', 500)->nullable();
                $table->longText('audit_json')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'classification'], 'rri_run_class_idx');
                $table->index(['outlet_id', 'business_date'], 'rri_outlet_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_refresh_reconciliation_items');
        Schema::dropIfExists('report_refresh_reconciliation_runs');
    }
};
