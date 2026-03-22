<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'client_sync_id')) {
                $table->string('client_sync_id', 100)->nullable()->after('cashier_name');
                $table->unique('client_sync_id', 'sales_client_sync_id_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'client_sync_id')) {
                $table->dropUnique('sales_client_sync_id_unique');
                $table->dropColumn('client_sync_id');
            }
        });
    }
};
