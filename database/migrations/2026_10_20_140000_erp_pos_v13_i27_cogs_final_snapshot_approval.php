<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['cogs_stock_variances', 'cogs_sale_consumptions', 'outlets', 'users'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("I27 requires table {$table}; apply earlier COGS migrations first.");
            }
        }
        Schema::create('cogs_v13_final_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $t->foreignUlid('variance_id')->constrained('cogs_stock_variances')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('status', 24)->default('staged');
            $t->char('source_fingerprint', 64);
            $t->char('snapshot_hash', 64);
            $t->char('lines_hash', 64);
            $t->char('snapshot_signature', 64);
            $t->string('period_from', 40);
            $t->string('period_to', 40);
            $t->unsignedInteger('sale_item_count');
            $t->unsignedInteger('ingredient_line_count');
            $t->decimal('historical_hpp', 24, 2);
            $t->decimal('variance_adjustment', 24, 2);
            $t->decimal('final_hpp', 24, 2);
            $t->decimal('unallocated_variance', 24, 2)->default(0);
            $t->foreignUlid('prepared_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('prepared_at');
            $t->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignUlid('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('rejected_at')->nullable();
            $t->string('reject_reason', 500)->nullable();
            $t->string('approval_notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['variance_id', 'revision'], 'cogs_v13_final_rev_uq');
            $t->index(['outlet_id', 'status', 'created_at'], 'cogs_v13_final_scope_idx');
        });
        Schema::create('cogs_v13_final_lines', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('run_id')->constrained('cogs_v13_final_runs')->restrictOnDelete();
            $t->foreignUlid('consumption_id')->constrained('cogs_sale_consumptions')->restrictOnDelete();
            $t->string('sale_id', 36)->nullable();
            $t->string('sale_item_id', 36)->nullable();
            $t->date('business_date');
            $t->string('product_name', 255);
            $t->string('variant_name', 255)->nullable();
            $t->decimal('quantity_sold', 24, 8)->default(0);
            $t->decimal('historical_hpp', 24, 2);
            $t->decimal('allocation_cost', 24, 2);
            $t->decimal('final_hpp', 24, 2);
            $t->json('ingredient_snapshot');
            $t->timestamps();
            $t->unique(['run_id', 'consumption_id'], 'cogs_v13_final_line_uq');
            $t->index(['run_id', 'business_date'], 'cogs_v13_final_line_date_idx');
        });
        Schema::create('cogs_v13_final_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('run_id')->constrained('cogs_v13_final_runs')->restrictOnDelete();
            $t->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 32);
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->char('snapshot_hash', 64);
            $t->char('lines_hash', 64);
            $t->string('notes', 500)->nullable();
            $t->timestamp('created_at');
            $t->index(['run_id', 'created_at'], 'cogs_v13_final_event_idx');
        });
    }
    public function down(): void
    {
        // Fail closed: preserving approved audit evidence takes precedence over automatic rollback.
        if (Schema::hasTable('cogs_v13_final_runs') && \Illuminate\Support\Facades\DB::table('cogs_v13_final_runs')->exists()) {
            throw new RuntimeException('I27 contains audit snapshots. Export/reconcile and use a reviewed manual rollback.');
        }
        Schema::dropIfExists('cogs_v13_final_events');
        Schema::dropIfExists('cogs_v13_final_lines');
        Schema::dropIfExists('cogs_v13_final_runs');
    }
};
