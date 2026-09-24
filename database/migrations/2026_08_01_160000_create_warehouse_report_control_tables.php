<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('wh_reconciliation_runs')) {
            Schema::create('wh_reconciliation_runs', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_id')->nullable()->index();
                $table->string('scope', 40)->default('all')->index();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->string('status', 20)->default('running')->index();
                $table->unsignedInteger('total_checks')->default(0);
                $table->unsignedInteger('passed_checks')->default(0);
                $table->unsignedInteger('failed_checks')->default(0);
                $table->json('summary_json')->nullable();
                $table->text('notes')->nullable();
                $table->ulid('created_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('wh_reconciliation_exceptions')) {
            Schema::create('wh_reconciliation_exceptions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('run_id')->index();
                $table->ulid('warehouse_id')->nullable()->index();
                $table->string('check_code', 80)->index();
                $table->string('severity', 20)->default('warning')->index();
                $table->string('entity_type', 80)->nullable()->index();
                $table->string('entity_id', 64)->nullable()->index();
                $table->string('document_number', 120)->nullable()->index();
                $table->decimal('expected_value', 20, 4)->nullable();
                $table->decimal('actual_value', 20, 4)->nullable();
                $table->decimal('variance_value', 20, 4)->nullable();
                $table->text('message');
                $table->string('status', 20)->default('open')->index();
                $table->text('resolution_notes')->nullable();
                $table->ulid('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->foreign('run_id')->references('id')->on('wh_reconciliation_runs')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('wh_report_snapshots')) {
            Schema::create('wh_report_snapshots', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_id')->nullable()->index();
                $table->string('report_code', 80)->index();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->json('filters_json')->nullable();
                $table->json('totals_json')->nullable();
                $table->char('payload_hash', 64)->index();
                $table->ulid('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_report_snapshots');
        Schema::dropIfExists('wh_reconciliation_exceptions');
        Schema::dropIfExists('wh_reconciliation_runs');
    }
};
