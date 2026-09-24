<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_stock_units')) {
            return;
        }

        Schema::table('wh_stock_units', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_stock_units', 'original_qty_base')) $table->decimal('original_qty_base', 18, 4)->nullable()->after('qty_base');
            if (! Schema::hasColumn('wh_stock_units', 'remaining_qty_base')) $table->decimal('remaining_qty_base', 18, 4)->nullable()->after('original_qty_base');
            if (! Schema::hasColumn('wh_stock_units', 'package_uom_id')) $table->foreignUlid('package_uom_id')->nullable()->after('remaining_qty_base')->constrained('stk_uoms')->nullOnDelete();
            if (! Schema::hasColumn('wh_stock_units', 'package_uom_code')) $table->string('package_uom_code', 30)->nullable()->after('package_uom_id');
            if (! Schema::hasColumn('wh_stock_units', 'package_conversion_factor')) $table->decimal('package_conversion_factor', 18, 6)->nullable()->after('package_uom_code');
            if (! Schema::hasColumn('wh_stock_units', 'parent_stock_unit_id')) $table->foreignUlid('parent_stock_unit_id')->nullable()->after('package_conversion_factor')->constrained('wh_stock_units')->nullOnDelete();
            if (! Schema::hasColumn('wh_stock_units', 'root_stock_unit_id')) $table->foreignUlid('root_stock_unit_id')->nullable()->after('parent_stock_unit_id')->constrained('wh_stock_units')->nullOnDelete();
            if (! Schema::hasColumn('wh_stock_units', 'package_state')) $table->string('package_state', 24)->default('sealed')->after('root_stock_unit_id');
            if (! Schema::hasColumn('wh_stock_units', 'opened_at')) $table->timestamp('opened_at')->nullable()->after('package_state');
            if (! Schema::hasColumn('wh_stock_units', 'depleted_at')) $table->timestamp('depleted_at')->nullable()->after('opened_at');
            if (! Schema::hasColumn('wh_stock_units', 'lock_version')) $table->unsignedInteger('lock_version')->default(1)->after('depleted_at');
        });

        DB::table('wh_stock_units')->whereNull('original_qty_base')->update(['original_qty_base' => DB::raw('qty_base')]);
        DB::table('wh_stock_units')->whereNull('remaining_qty_base')->update(['remaining_qty_base' => DB::raw('qty_base')]);
        DB::table('wh_stock_units')->whereNull('root_stock_unit_id')->update(['root_stock_unit_id' => DB::raw('id')]);

        if (! Schema::hasTable('wh_stock_unit_events')) {
            Schema::create('wh_stock_unit_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('stock_unit_id')->constrained('wh_stock_units')->cascadeOnDelete();
                $table->foreignUlid('related_stock_unit_id')->nullable()->constrained('wh_stock_units')->nullOnDelete();
                $table->string('event_type', 30)->index();
                $table->decimal('qty_before_base', 18, 4)->default(0);
                $table->decimal('qty_change_base', 18, 4)->default(0);
                $table->decimal('qty_after_base', 18, 4)->default(0);
                $table->string('reference_type', 60)->nullable()->index();
                $table->string('reference_id', 100)->nullable()->index();
                $table->string('reason', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['stock_unit_id', 'created_at'], 'wh_stock_unit_events_unit_time_idx');
            });
        }

        if (! Schema::hasTable('wh_package_opname_counts')) {
            Schema::create('wh_package_opname_counts', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
                $table->string('session_key', 100);
                $table->foreignUlid('stock_unit_id')->constrained('wh_stock_units')->cascadeOnDelete();
                $table->decimal('system_qty_base', 18, 4);
                $table->decimal('counted_qty_base', 18, 4);
                $table->decimal('variance_qty_base', 18, 4);
                $table->string('status', 20)->default('counted');
                $table->string('notes', 500)->nullable();
                $table->foreignUlid('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('counted_at');
                $table->timestamps();
                $table->unique(['warehouse_id', 'session_key', 'stock_unit_id'], 'wh_package_opname_session_unit_uq');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive. Package history may already be referenced by operational documents.
    }
};
