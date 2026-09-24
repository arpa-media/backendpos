<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'wh_productions','wh_production_inputs','wh_production_outputs','wh_production_events',
            'wh_v3_production_material_requests','wh_ledger_postings','wh_batches','wh_storages',
            'stk_skus','stk_uoms','outlets','users',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v3 Iterasi 06 membutuhkan tabel {$table}. Apply baseline Production + Iterasi 01-05 terlebih dahulu.");
            }
        }

        Schema::table('wh_productions', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_productions', 'flow_version')) {
                $table->unsignedTinyInteger('flow_version')->default(2)->index();
            }
            if (! Schema::hasColumn('wh_productions', 'production_request_id')) {
                $table->ulid('production_request_id')->nullable();
                $table->unique('production_request_id', 'wh_prod_v3_request_uq');
                $table->foreign('production_request_id', 'wh_prod_v3_request_fk')
                    ->references('id')->on('wh_v3_production_material_requests')->nullOnDelete();
            }
            if (! Schema::hasColumn('wh_productions', 'order_approved_by_user_id')) {
                $table->ulid('order_approved_by_user_id')->nullable();
                $table->foreign('order_approved_by_user_id', 'wh_prod_v3_order_approver_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('wh_productions', 'order_approved_at')) {
                $table->timestamp('order_approved_at')->nullable();
            }
            if (! Schema::hasColumn('wh_productions', 'finished_by_user_id')) {
                $table->ulid('finished_by_user_id')->nullable();
                $table->foreign('finished_by_user_id', 'wh_prod_v3_finisher_fk')
                    ->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('wh_productions', 'finished_at')) {
                $table->timestamp('finished_at')->nullable();
            }
        });

        if (! Schema::hasTable('wh_v3_production_results')) {
            Schema::create('wh_v3_production_results', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('result_number', 70)->unique();
                $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->date('result_date')->index();
                $table->string('status', 24)->default('draft')->index();
                $table->foreignUlid('ledger_posting_id')->nullable();
                $table->unique('ledger_posting_id', 'wh_prod_v3_result_ledger_uq');
                $table->foreign('ledger_posting_id', 'wh_prod_v3_result_ledger_fk')
                    ->references('id')->on('wh_ledger_postings')->nullOnDelete();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->char('payload_fingerprint', 64)->nullable();
                $table->decimal('total_qty_base', 18, 4)->default(0);
                $table->decimal('inventory_value', 22, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id','production_id','status','result_date'], 'wh_prod_v3_result_lookup_idx');
            });
        }

        if (! Schema::hasTable('wh_v3_production_result_items')) {
            Schema::create('wh_v3_production_result_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('result_id');
                $table->foreign('result_id', 'wh_prod_v3_result_item_result_fk')
                    ->references('id')->on('wh_v3_production_results')->cascadeOnDelete();
                $table->foreignUlid('production_output_id');
                $table->foreign('production_output_id', 'wh_prod_v3_result_item_output_fk')
                    ->references('id')->on('wh_production_outputs')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('qty_uom', 18, 4)->default(0);
                $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
                $table->decimal('qty_base', 18, 4)->default(0);
                $table->foreignUlid('storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
                $table->foreignUlid('batch_id')->nullable()->constrained('wh_batches')->nullOnDelete();
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->decimal('total_cost_snapshot', 22, 2)->default(0);
                $table->string('status', 24)->default('draft')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['result_id','production_output_id'], 'wh_prod_v3_result_output_uq');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Production result and ledger audit must remain available.
    }
};
