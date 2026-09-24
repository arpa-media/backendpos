<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_supplier_purchase_order_items')) {
            return;
        }

        Schema::table('wh_supplier_purchase_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_supplier_purchase_order_items', 'sku_code_snapshot')) {
                $table->string('sku_code_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('wh_supplier_purchase_order_items', 'item_name_snapshot')) {
                $table->string('item_name_snapshot', 255)->nullable();
            }
            if (! Schema::hasColumn('wh_supplier_purchase_order_items', 'base_uom_code_snapshot')) {
                $table->string('base_uom_code_snapshot', 40)->nullable();
            }
            if (! Schema::hasColumn('wh_supplier_purchase_order_items', 'base_uom_name_snapshot')) {
                $table->string('base_uom_name_snapshot', 120)->nullable();
            }
        });

        // Recover copied IDs and snapshots from the approved Purchase Request line.
        DB::statement(<<<'SQL'
UPDATE wh_supplier_purchase_order_items poi
INNER JOIN wh_purchase_request_items pri ON pri.id = poi.purchase_request_item_id
LEFT JOIN stk_skus sku ON sku.id = pri.sku_id
LEFT JOIN stk_uoms uom ON uom.id = pri.base_uom_id
SET
    poi.sku_id = pri.sku_id,
    poi.base_uom_id = pri.base_uom_id,
    poi.sku_code_snapshot = COALESCE(NULLIF(poi.sku_code_snapshot, ''), sku.sku_code),
    poi.item_name_snapshot = COALESCE(NULLIF(poi.item_name_snapshot, ''), sku.name),
    poi.base_uom_code_snapshot = COALESCE(NULLIF(poi.base_uom_code_snapshot, ''), uom.code),
    poi.base_uom_name_snapshot = COALESCE(NULLIF(poi.base_uom_name_snapshot, ''), uom.name),
    poi.updated_at = CURRENT_TIMESTAMP
WHERE
    poi.sku_id <> pri.sku_id
    OR poi.base_uom_id <> pri.base_uom_id
    OR poi.sku_code_snapshot IS NULL OR poi.sku_code_snapshot = ''
    OR poi.item_name_snapshot IS NULL OR poi.item_name_snapshot = ''
    OR poi.base_uom_code_snapshot IS NULL OR poi.base_uom_code_snapshot = ''
    OR poi.base_uom_name_snapshot IS NULL OR poi.base_uom_name_snapshot = ''
SQL);
    }

    public function down(): void
    {
        // Non-destructive. Snapshots may already be referenced by historical PO documents.
    }
};
