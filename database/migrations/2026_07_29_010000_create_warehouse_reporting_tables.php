<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();

        if (! Schema::hasTable('wh_report_runs')) {
            Schema::create('wh_report_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('report_key', 80)->index();
                $table->string('output_format', 20)->default('screen')->index();
                $table->string('status', 24)->default('completed')->index();
                $table->json('filters')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->foreignUlid('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->useCurrent()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'report_key', 'generated_at'], 'wh_report_runs_wh_key_date_idx');
            });
        }

        if (! Schema::hasTable('wh_operational_reconciliation_runs')) {
            Schema::create('wh_operational_reconciliation_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->string('status', 24)->default('running')->index();
                $table->unsignedInteger('checked_rule_count')->default(0);
                $table->unsignedInteger('issue_count')->default(0);
                $table->json('summary')->nullable();
                $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'created_at'], 'wh_operational_recon_wh_created_idx');
            });
        }
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'users', 'stk_skus', 'stk_inventory_balances',
            'wh_batches', 'wh_batch_balances', 'wh_ledger_postings', 'wh_ledger_entries',
        ];

        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Patch Warehouse Iterasi 11 membutuhkan Iterasi 01-10. Missing tables: '.implode(', ', $missing)
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: history report dan reconciliation tidak dihapus otomatis.
    }
};
