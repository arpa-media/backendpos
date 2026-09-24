<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wh_v3_sales_order_items', 'wh_v3_transfer_order_items'] as $table) {
            $this->addTransactionSnapshots($table);
            $this->backfillTransactionSnapshots($table);
        }

        if (Schema::hasTable('wh_production_inputs')) {
            Schema::table('wh_production_inputs', function (Blueprint $table): void {
                if (! Schema::hasColumn('wh_production_inputs', 'request_uom_code_snapshot')) $table->string('request_uom_code_snapshot', 30)->nullable()->after('request_uom_id');
                if (! Schema::hasColumn('wh_production_inputs', 'request_uom_name_snapshot')) $table->string('request_uom_name_snapshot', 100)->nullable()->after('request_uom_code_snapshot');
                if (! Schema::hasColumn('wh_production_inputs', 'base_uom_code_snapshot')) $table->string('base_uom_code_snapshot', 30)->nullable()->after('base_uom_id');
                if (! Schema::hasColumn('wh_production_inputs', 'base_uom_name_snapshot')) $table->string('base_uom_name_snapshot', 100)->nullable()->after('base_uom_code_snapshot');
                if (! Schema::hasColumn('wh_production_inputs', 'actual_qty_uom')) $table->decimal('actual_qty_uom', 18, 4)->default(0)->after('planned_qty_base');
            });
            DB::statement("UPDATE wh_production_inputs i LEFT JOIN stk_uoms u ON u.id=i.request_uom_id LEFT JOIN stk_uoms b ON b.id=i.base_uom_id SET i.request_uom_code_snapshot=COALESCE(i.request_uom_code_snapshot,u.code), i.request_uom_name_snapshot=COALESCE(i.request_uom_name_snapshot,u.name), i.base_uom_code_snapshot=COALESCE(i.base_uom_code_snapshot,b.code), i.base_uom_name_snapshot=COALESCE(i.base_uom_name_snapshot,b.name), i.actual_qty_uom=CASE WHEN i.actual_qty_uom=0 AND i.conversion_factor_snapshot>0 AND i.actual_qty_base>0 THEN ROUND(i.actual_qty_base/i.conversion_factor_snapshot,4) ELSE i.actual_qty_uom END");
        }

        if (Schema::hasTable('wh_production_outputs')) {
            Schema::table('wh_production_outputs', function (Blueprint $table): void {
                if (! Schema::hasColumn('wh_production_outputs', 'output_uom_code_snapshot')) $table->string('output_uom_code_snapshot', 30)->nullable()->after('output_uom_id');
                if (! Schema::hasColumn('wh_production_outputs', 'output_uom_name_snapshot')) $table->string('output_uom_name_snapshot', 100)->nullable()->after('output_uom_code_snapshot');
                if (! Schema::hasColumn('wh_production_outputs', 'base_uom_code_snapshot')) $table->string('base_uom_code_snapshot', 30)->nullable()->after('base_uom_id');
                if (! Schema::hasColumn('wh_production_outputs', 'base_uom_name_snapshot')) $table->string('base_uom_name_snapshot', 100)->nullable()->after('base_uom_code_snapshot');
            });
            DB::statement("UPDATE wh_production_outputs o LEFT JOIN stk_uoms u ON u.id=o.output_uom_id LEFT JOIN stk_uoms b ON b.id=o.base_uom_id SET o.output_uom_code_snapshot=COALESCE(o.output_uom_code_snapshot,u.code), o.output_uom_name_snapshot=COALESCE(o.output_uom_name_snapshot,u.name), o.base_uom_code_snapshot=COALESCE(o.base_uom_code_snapshot,b.code), o.base_uom_name_snapshot=COALESCE(o.base_uom_name_snapshot,b.name)");
        }

        if (Schema::hasTable('wh_v3_production_material_request_items')) {
            Schema::table('wh_v3_production_material_request_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'conversion_factor_snapshot')) $table->decimal('conversion_factor_snapshot', 24, 8)->default(1)->after('request_uom_id');
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'request_uom_code_snapshot')) $table->string('request_uom_code_snapshot', 30)->nullable()->after('request_uom_id');
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'request_uom_name_snapshot')) $table->string('request_uom_name_snapshot', 100)->nullable()->after('request_uom_code_snapshot');
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'base_uom_id_snapshot')) $table->ulid('base_uom_id_snapshot')->nullable()->after('conversion_factor_snapshot');
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'base_uom_code_snapshot')) $table->string('base_uom_code_snapshot', 30)->nullable()->after('base_uom_id_snapshot');
                if (! Schema::hasColumn('wh_v3_production_material_request_items', 'base_uom_name_snapshot')) $table->string('base_uom_name_snapshot', 100)->nullable()->after('base_uom_code_snapshot');
            });
            DB::statement("UPDATE wh_v3_production_material_request_items r JOIN wh_production_inputs i ON i.id=r.production_input_id LEFT JOIN stk_uoms u ON u.id=r.request_uom_id LEFT JOIN stk_uoms b ON b.id=i.base_uom_id SET r.conversion_factor_snapshot=CASE WHEN i.conversion_factor_snapshot>0 THEN i.conversion_factor_snapshot WHEN r.requested_qty_uom>0 THEN r.requested_qty_base/r.requested_qty_uom ELSE 1 END, r.request_uom_code_snapshot=COALESCE(r.request_uom_code_snapshot,i.request_uom_code_snapshot,u.code), r.request_uom_name_snapshot=COALESCE(r.request_uom_name_snapshot,i.request_uom_name_snapshot,u.name), r.base_uom_id_snapshot=COALESCE(r.base_uom_id_snapshot,i.base_uom_id), r.base_uom_code_snapshot=COALESCE(r.base_uom_code_snapshot,i.base_uom_code_snapshot,b.code), r.base_uom_name_snapshot=COALESCE(r.base_uom_name_snapshot,i.base_uom_name_snapshot,b.name)");
        }

        if (Schema::hasTable('wh_v3_production_result_items')) {
            Schema::table('wh_v3_production_result_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('wh_v3_production_result_items', 'uom_code_snapshot')) $table->string('uom_code_snapshot', 30)->nullable()->after('uom_id');
                if (! Schema::hasColumn('wh_v3_production_result_items', 'uom_name_snapshot')) $table->string('uom_name_snapshot', 100)->nullable()->after('uom_code_snapshot');
                if (! Schema::hasColumn('wh_v3_production_result_items', 'base_uom_id_snapshot')) $table->ulid('base_uom_id_snapshot')->nullable()->after('conversion_factor_snapshot');
                if (! Schema::hasColumn('wh_v3_production_result_items', 'base_uom_code_snapshot')) $table->string('base_uom_code_snapshot', 30)->nullable()->after('base_uom_id_snapshot');
                if (! Schema::hasColumn('wh_v3_production_result_items', 'base_uom_name_snapshot')) $table->string('base_uom_name_snapshot', 100)->nullable()->after('base_uom_code_snapshot');
            });
            DB::statement("UPDATE wh_v3_production_result_items r JOIN wh_production_outputs o ON o.id=r.production_output_id LEFT JOIN stk_uoms u ON u.id=r.uom_id LEFT JOIN stk_uoms b ON b.id=o.base_uom_id SET r.uom_code_snapshot=COALESCE(r.uom_code_snapshot,o.output_uom_code_snapshot,u.code), r.uom_name_snapshot=COALESCE(r.uom_name_snapshot,o.output_uom_name_snapshot,u.name), r.base_uom_id_snapshot=COALESCE(r.base_uom_id_snapshot,o.base_uom_id), r.base_uom_code_snapshot=COALESCE(r.base_uom_code_snapshot,o.base_uom_code_snapshot,b.code), r.base_uom_name_snapshot=COALESCE(r.base_uom_name_snapshot,o.base_uom_name_snapshot,b.name)");
        }
    }

    private function addTransactionSnapshots(string $table): void
    {
        if (! Schema::hasTable($table)) return;
        Schema::table($table, function (Blueprint $t) use ($table): void {
            if (! Schema::hasColumn($table, 'uom_code_snapshot')) $t->string('uom_code_snapshot', 30)->nullable()->after('uom_id');
            if (! Schema::hasColumn($table, 'uom_name_snapshot')) $t->string('uom_name_snapshot', 100)->nullable()->after('uom_code_snapshot');
            if (! Schema::hasColumn($table, 'base_uom_id_snapshot')) $t->ulid('base_uom_id_snapshot')->nullable()->after('conversion_factor_snapshot');
            if (! Schema::hasColumn($table, 'base_uom_code_snapshot')) $t->string('base_uom_code_snapshot', 30)->nullable()->after('base_uom_id_snapshot');
            if (! Schema::hasColumn($table, 'base_uom_name_snapshot')) $t->string('base_uom_name_snapshot', 100)->nullable()->after('base_uom_code_snapshot');
        });
    }

    private function backfillTransactionSnapshots(string $table): void
    {
        if (! Schema::hasTable($table)) return;
        DB::statement("UPDATE {$table} i JOIN stk_skus s ON s.id=i.sku_id LEFT JOIN stk_uoms u ON u.id=i.uom_id LEFT JOIN stk_uoms b ON b.id=s.base_uom_id SET i.uom_code_snapshot=COALESCE(i.uom_code_snapshot,u.code), i.uom_name_snapshot=COALESCE(i.uom_name_snapshot,u.name), i.base_uom_id_snapshot=COALESCE(i.base_uom_id_snapshot,s.base_uom_id), i.base_uom_code_snapshot=COALESCE(i.base_uom_code_snapshot,b.code), i.base_uom_name_snapshot=COALESCE(i.base_uom_name_snapshot,b.name)");
    }

    public function down(): void
    {
        // Non-destructive by design: approved document/UOM snapshots are audit data.
    }
};
