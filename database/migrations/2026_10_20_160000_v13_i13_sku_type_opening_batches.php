<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (! Schema::hasColumn('stk_skus', 'sku_type')) {
            Schema::table('stk_skus', function (Blueprint $table): void {
                $table->string('sku_type', 12)->nullable()->after('name')->index('stk_skus_v13_i13_type_idx');
            });
        }
        if (! Schema::hasTable('stk_v13_i13_opening_batches')) {
            Schema::create('stk_v13_i13_opening_batches', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->string('request_key', 100);
                $table->string('payload_hash', 64);
                $table->string('mode', 12);
                $table->date('effective_date');
                $table->unsignedInteger('item_count')->default(0);
                $table->decimal('total_inventory_value', 22, 2)->default(0);
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['outlet_id', 'request_key'], 'stk_v13_i13_batch_outlet_request_uq');
            });
        }
        if (! Schema::hasTable('stk_v13_i13_opening_lines')) {
            Schema::create('stk_v13_i13_opening_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('batch_id')->constrained('stk_v13_i13_opening_batches')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('opening_id')->constrained('stk_opening_stocks')->restrictOnDelete();
                $table->ulid('movement_id')->nullable();
                $table->decimal('qty_before', 18, 4)->default(0);
                $table->decimal('qty_after', 18, 4)->default(0);
                $table->decimal('unit_cost_before', 20, 6)->default(0);
                $table->decimal('unit_cost_after', 20, 6)->default(0);
                $table->decimal('value_before', 22, 2)->default(0);
                $table->decimal('value_after', 22, 2)->default(0);
                $table->timestamps();
                $table->unique(['batch_id', 'sku_id'], 'stk_v13_i13_line_batch_sku_uq');
                $table->index(['sku_id', 'created_at'], 'stk_v13_i13_line_sku_date_idx');
            });
        }
    }
    public function down(): void {
        // Do not drop posted inventory audit on rollback. Data must be reconciled first.
        // Intentionally no-op: reversing ledger data is a controlled business action.
    }
};
