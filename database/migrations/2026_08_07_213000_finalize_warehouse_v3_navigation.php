<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_menus')) return;

        // Historical rows stay in the database for audit/rollback, but are no longer
        // exposed as active Warehouse v3 navigation entries.
        DB::table('access_menus')
            ->where(function ($q): void {
                $q->whereIn('code', [
                    'warehouse-stock-request-fulfillment',
                    'warehouse-checker-keeper-tasks',
                    'warehouse-inventory-barcodes',
                    'warehouse-inventory-package-barcodes',
                ])->orWhereIn('path', [
                    '/warehouse/stock-requests/fulfillment',
                    '/warehouse/tasks/checker-keeper',
                    '/warehouse/inventory/barcodes',
                    '/warehouse/inventory/package-barcodes',
                ]);
            })
            ->update(['is_active'=>false,'updated_at'=>now()]);

        // Re-assert all v3 shell menu rows as active without creating duplicate menus.
        DB::table('access_menus')
            ->where('code','like','warehouse-v3-%')
            ->update(['is_active'=>true,'updated_at'=>now()]);
    }

    public function down(): void
    {
        // Non-destructive. Legacy navigation can be re-enabled manually if rollback is required.
    }
};
