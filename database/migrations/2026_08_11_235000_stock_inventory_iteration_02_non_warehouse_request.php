<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stk_requests')) {
            return;
        }

        Schema::table('stk_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('stk_requests', 'non_warehouse_supplier_source_id')) {
                $table->ulid('non_warehouse_supplier_source_id')->nullable()->after('request_channel');
                $table->index('non_warehouse_supplier_source_id', 'stk_req_nw_supplier_idx');
            }
            if (! Schema::hasColumn('stk_requests', 'supplier_name_snapshot')) {
                $table->string('supplier_name_snapshot', 180)->nullable()->after('non_warehouse_supplier_source_id');
            }
            if (! Schema::hasColumn('stk_requests', 'supplier_contact_snapshot')) {
                $table->string('supplier_contact_snapshot', 150)->nullable()->after('supplier_name_snapshot');
            }
            if (! Schema::hasColumn('stk_requests', 'supplier_phone_snapshot')) {
                $table->string('supplier_phone_snapshot', 80)->nullable()->after('supplier_contact_snapshot');
            }
        });

        if (Schema::hasTable('pur_supplier_sources')
            && Schema::hasColumn('stk_requests', 'non_warehouse_supplier_source_id')) {
            // Keep this relationship nullable and non-destructive. Some historical
            // dumps do not contain the same FK set as their migration history, so
            // the application validates the optional supplier match at runtime.
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive. Iteration patches are sequential and
        // approved request snapshots must remain traceable after deployment.
    }
};
