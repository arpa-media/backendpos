<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensurePurchaseOrderShipmentCode();
        $this->ensureGoodsReceiptColumns();
        $this->ensureGoodsReceiptItemColumns();
        $this->relaxLegacyRequiredColumns();
        $this->backfillGoodsReceipts();
        $this->backfillGoodsReceiptItems();
        $this->ensureIndexes();
    }

    private function ensurePurchaseOrderShipmentCode(): void
    {
        if (! Schema::hasTable('pur_purchase_orders')) {
            return;
        }

        if (! Schema::hasColumn('pur_purchase_orders', 'shipment_code')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table): void {
                $table->string('shipment_code', 80)->nullable();
            });
        }

        DB::table('pur_purchase_orders')
            ->where('source_type', 'warehouse')
            ->whereNull('shipment_code')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('pur_purchase_orders')
                        ->where('id', $row->id)
                        ->update(['shipment_code' => 'SHIP-'.strtoupper((string) $row->id)]);
                }
            }, 'id');

        if (! $this->indexExists('pur_purchase_orders', 'pur_po_shipment_code_uq')) {
            $hasDuplicate = DB::table('pur_purchase_orders')
                ->select('shipment_code')
                ->whereNotNull('shipment_code')
                ->groupBy('shipment_code')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $hasDuplicate) {
                DB::statement('ALTER TABLE `pur_purchase_orders` ADD UNIQUE INDEX `pur_po_shipment_code_uq` (`shipment_code`)');
            }
        }
    }

    private function ensureGoodsReceiptColumns(): void
    {
        if (! Schema::hasTable('stk_goods_receipts')) {
            Schema::create('stk_goods_receipts', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('gr_number', 70)->unique();
                $table->string('receipt_type', 30)->index();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('purchase_order_id')->nullable()->constrained('pur_purchase_orders')->nullOnDelete();
                $table->foreignUlid('supplier_source_id')->nullable()->constrained('pur_supplier_sources')->nullOnDelete();
                $table->string('shipment_code', 80)->nullable()->index();
                $table->string('supplier_document_number', 120)->nullable()->index();
                $table->date('receipt_date')->index();
                $table->string('status', 30)->default('draft')->index();
                $table->string('currency', 3)->default('IDR');
                $table->decimal('total_amount', 20, 2)->default(0);
                $table->unsignedInteger('lock_version')->default(1);
                $table->text('notes')->nullable();
                $table->foreignUlid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignUlid('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('purchase_order_id', 'stk_gr_purchase_order_uq');
                $table->index(['outlet_id', 'receipt_date', 'status'], 'stk_gr_outlet_date_status_idx');
                $table->index(['receipt_type', 'status', 'receipt_date'], 'stk_gr_type_status_date_idx');
            });

            return;
        }

        $definitions = [
            'receipt_type' => fn (Blueprint $table) => $table->string('receipt_type', 30)->nullable(),
            'purchase_order_id' => fn (Blueprint $table) => $table->ulid('purchase_order_id')->nullable(),
            'supplier_source_id' => fn (Blueprint $table) => $table->ulid('supplier_source_id')->nullable(),
            'shipment_code' => fn (Blueprint $table) => $table->string('shipment_code', 80)->nullable(),
            'supplier_document_number' => fn (Blueprint $table) => $table->string('supplier_document_number', 120)->nullable(),
            'receipt_date' => fn (Blueprint $table) => $table->date('receipt_date')->nullable(),
            'currency' => fn (Blueprint $table) => $table->string('currency', 3)->default('IDR'),
            'total_amount' => fn (Blueprint $table) => $table->decimal('total_amount', 20, 2)->default(0),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'received_by_user_id' => fn (Blueprint $table) => $table->ulid('received_by_user_id')->nullable(),
            'received_at' => fn (Blueprint $table) => $table->timestamp('received_at')->nullable(),
            'created_by_user_id' => fn (Blueprint $table) => $table->ulid('created_by_user_id')->nullable(),
            'updated_by_user_id' => fn (Blueprint $table) => $table->ulid('updated_by_user_id')->nullable(),
        ];

        foreach ($definitions as $column => $definition) {
            if (Schema::hasColumn('stk_goods_receipts', $column)) {
                continue;
            }
            Schema::table('stk_goods_receipts', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    private function ensureGoodsReceiptItemColumns(): void
    {
        if (! Schema::hasTable('stk_goods_receipt_items')) {
            Schema::create('stk_goods_receipt_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('goods_receipt_id')->constrained('stk_goods_receipts')->cascadeOnDelete();
                $table->foreignUlid('purchase_order_item_id')->nullable()->constrained('pur_purchase_order_items')->nullOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('ordered_qty', 18, 4)->nullable();
                $table->decimal('received_qty', 18, 4)->default(0);
                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('line_total', 20, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['goods_receipt_id', 'sku_id'], 'stk_gr_item_receipt_sku_uq');
                $table->index(['sku_id', 'goods_receipt_id'], 'stk_gr_item_sku_receipt_idx');
            });

            return;
        }

        $definitions = [
            'purchase_order_item_id' => fn (Blueprint $table) => $table->ulid('purchase_order_item_id')->nullable(),
            'ordered_qty' => fn (Blueprint $table) => $table->decimal('ordered_qty', 18, 4)->nullable(),
            'received_qty' => fn (Blueprint $table) => $table->decimal('received_qty', 18, 4)->default(0),
            'line_total' => fn (Blueprint $table) => $table->decimal('line_total', 20, 2)->default(0),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
        ];

        foreach ($definitions as $column => $definition) {
            if (Schema::hasColumn('stk_goods_receipt_items', $column)) {
                continue;
            }
            Schema::table('stk_goods_receipt_items', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    private function relaxLegacyRequiredColumns(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('stk_goods_receipts') && Schema::hasColumn('stk_goods_receipts', 'receipt_id')) {
            DB::statement('ALTER TABLE `stk_goods_receipts` MODIFY COLUMN `receipt_id` CHAR(26) NULL');
        }

        if (Schema::hasTable('stk_goods_receipt_items') && Schema::hasColumn('stk_goods_receipt_items', 'receipt_item_id')) {
            DB::statement('ALTER TABLE `stk_goods_receipt_items` MODIFY COLUMN `receipt_item_id` CHAR(26) NULL');
        }

        if (Schema::hasTable('stk_goods_receipt_items') && Schema::hasColumn('stk_goods_receipt_items', 'sku_name_snapshot')) {
            DB::statement('ALTER TABLE `stk_goods_receipt_items` MODIFY COLUMN `sku_name_snapshot` VARCHAR(200) NULL');
        }
    }

    private function backfillGoodsReceipts(): void
    {
        if (! Schema::hasTable('stk_goods_receipts')) {
            return;
        }

        if (Schema::hasColumn('stk_goods_receipts', 'total_value')) {
            DB::statement('UPDATE `stk_goods_receipts` SET `total_amount` = COALESCE(`total_amount`, `total_value`, 0)');
            DB::statement('UPDATE `stk_goods_receipts` SET `total_amount` = `total_value` WHERE `total_amount` = 0 AND `total_value` <> 0');
        }

        if (Schema::hasTable('stk_receipts') && Schema::hasColumn('stk_goods_receipts', 'receipt_id')) {
            $poJoin = Schema::hasTable('pur_purchase_orders')
                ? ' LEFT JOIN `pur_purchase_orders` po ON po.`id` = r.`source_document_id` '
                : '';
            $poAssignment = Schema::hasTable('pur_purchase_orders')
                ? ', gr.`purchase_order_id` = COALESCE(gr.`purchase_order_id`, po.`id`)' 
                : '';

            DB::statement(<<<SQL
UPDATE `stk_goods_receipts` gr
LEFT JOIN `stk_receipts` r ON r.`id` = gr.`receipt_id`
{$poJoin}
SET
    gr.`receipt_type` = COALESCE(gr.`receipt_type`, CASE
        WHEN LOWER(COALESCE(r.`source_type`, '')) IN ('manual', 'other_supplier', 'supplier') THEN 'manual'
        ELSE 'warehouse'
    END),
    gr.`receipt_date` = COALESCE(gr.`receipt_date`, r.`receipt_date`, DATE(gr.`created_at`), CURRENT_DATE()),
    gr.`shipment_code` = COALESCE(gr.`shipment_code`, CASE WHEN LOWER(COALESCE(r.`source_type`, '')) = 'warehouse' THEN r.`source_reference` END),
    gr.`supplier_document_number` = COALESCE(gr.`supplier_document_number`, CASE WHEN LOWER(COALESCE(r.`source_type`, '')) <> 'warehouse' THEN r.`source_reference` END),
    gr.`notes` = COALESCE(gr.`notes`, r.`notes`),
    gr.`received_by_user_id` = COALESCE(gr.`received_by_user_id`, gr.`checked_by_user_id`, r.`checked_by_user_id`, r.`created_by_user_id`),
    gr.`received_at` = COALESCE(gr.`received_at`, gr.`checked_at`, r.`checked_at`, gr.`created_at`),
    gr.`created_by_user_id` = COALESCE(gr.`created_by_user_id`, r.`created_by_user_id`, gr.`checked_by_user_id`),
    gr.`updated_by_user_id` = COALESCE(gr.`updated_by_user_id`, gr.`released_by_user_id`, gr.`checked_by_user_id`, r.`created_by_user_id`)
    {$poAssignment}
SQL);
        }

        DB::statement("UPDATE `stk_goods_receipts` SET `receipt_type` = CASE WHEN UPPER(`gr_number`) LIKE 'GR-MN%' THEN 'manual' ELSE 'warehouse' END WHERE `receipt_type` IS NULL OR `receipt_type` = ''");
        DB::statement('UPDATE `stk_goods_receipts` SET `receipt_date` = COALESCE(`receipt_date`, DATE(`created_at`), CURRENT_DATE()) WHERE `receipt_date` IS NULL');
        DB::statement("UPDATE `stk_goods_receipts` SET `currency` = 'IDR' WHERE `currency` IS NULL OR `currency` = ''");
        DB::statement("UPDATE `stk_goods_receipts` SET `status` = CASE WHEN `released_at` IS NOT NULL THEN 'released' WHEN LOWER(`status`) IN ('released', 'posted') THEN 'released' WHEN LOWER(`status`) IN ('cancelled', 'canceled') THEN 'cancelled' ELSE 'draft' END");

        if (Schema::hasTable('pur_supplier_sources')) {
            $warehouseSupplier = DB::table('pur_supplier_sources')->where('source_type', 'warehouse')->where('is_active', true)->value('id');
            $manualSupplier = DB::table('pur_supplier_sources')->where('source_type', 'other_supplier')->where('is_active', true)->value('id');

            if ($warehouseSupplier) {
                DB::table('stk_goods_receipts')->where('receipt_type', 'warehouse')->whereNull('supplier_source_id')->update(['supplier_source_id' => $warehouseSupplier]);
            }
            if ($manualSupplier) {
                DB::table('stk_goods_receipts')->where('receipt_type', 'manual')->whereNull('supplier_source_id')->update(['supplier_source_id' => $manualSupplier]);
            }
        }
    }

    private function backfillGoodsReceiptItems(): void
    {
        if (! Schema::hasTable('stk_goods_receipt_items')) {
            return;
        }

        if (Schema::hasColumn('stk_goods_receipt_items', 'accepted_qty')) {
            DB::statement('UPDATE `stk_goods_receipt_items` SET `received_qty` = `accepted_qty` WHERE `received_qty` = 0 AND `accepted_qty` <> 0');
        }
        if (Schema::hasColumn('stk_goods_receipt_items', 'line_value')) {
            DB::statement('UPDATE `stk_goods_receipt_items` SET `line_total` = `line_value` WHERE `line_total` = 0 AND `line_value` <> 0');
        }

        if (Schema::hasTable('stk_receipt_items') && Schema::hasColumn('stk_goods_receipt_items', 'receipt_item_id')) {
            $poJoin = Schema::hasTable('pur_purchase_order_items')
                ? ' LEFT JOIN `pur_purchase_order_items` poi ON poi.`id` = ri.`source_line_id` '
                : '';
            $poAssignment = Schema::hasTable('pur_purchase_order_items')
                ? ', gri.`purchase_order_item_id` = COALESCE(gri.`purchase_order_item_id`, poi.`id`)' 
                : '';

            DB::statement(<<<SQL
UPDATE `stk_goods_receipt_items` gri
LEFT JOIN `stk_receipt_items` ri ON ri.`id` = gri.`receipt_item_id`
{$poJoin}
SET
    gri.`ordered_qty` = COALESCE(gri.`ordered_qty`, ri.`expected_qty`),
    gri.`received_qty` = CASE WHEN gri.`received_qty` = 0 THEN COALESCE(ri.`accepted_qty`, ri.`received_qty`, 0) ELSE gri.`received_qty` END,
    gri.`line_total` = CASE WHEN gri.`line_total` = 0 THEN ROUND(COALESCE(ri.`accepted_qty`, ri.`received_qty`, 0) * COALESCE(gri.`unit_cost`, ri.`unit_cost`, 0), 2) ELSE gri.`line_total` END,
    gri.`notes` = COALESCE(gri.`notes`, ri.`note`)
    {$poAssignment}
SQL);
        }
    }

    private function ensureIndexes(): void
    {
        if (Schema::hasTable('stk_goods_receipts')) {
            if (! $this->indexExists('stk_goods_receipts', 'stk_gr_outlet_date_status_idx')) {
                DB::statement('ALTER TABLE `stk_goods_receipts` ADD INDEX `stk_gr_outlet_date_status_idx` (`outlet_id`, `receipt_date`, `status`)');
            }
            if (! $this->indexExists('stk_goods_receipts', 'stk_gr_type_status_date_idx')) {
                DB::statement('ALTER TABLE `stk_goods_receipts` ADD INDEX `stk_gr_type_status_date_idx` (`receipt_type`, `status`, `receipt_date`)');
            }
            if (! $this->indexExists('stk_goods_receipts', 'stk_gr_shipment_code_idx')) {
                DB::statement('ALTER TABLE `stk_goods_receipts` ADD INDEX `stk_gr_shipment_code_idx` (`shipment_code`)');
            }
            if (! $this->indexExists('stk_goods_receipts', 'stk_gr_supplier_document_idx')) {
                DB::statement('ALTER TABLE `stk_goods_receipts` ADD INDEX `stk_gr_supplier_document_idx` (`supplier_document_number`)');
            }

            if (! $this->indexExists('stk_goods_receipts', 'stk_gr_purchase_order_uq')) {
                $hasDuplicate = DB::table('stk_goods_receipts')
                    ->select('purchase_order_id')
                    ->whereNotNull('purchase_order_id')
                    ->groupBy('purchase_order_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();
                if (! $hasDuplicate) {
                    DB::statement('ALTER TABLE `stk_goods_receipts` ADD UNIQUE INDEX `stk_gr_purchase_order_uq` (`purchase_order_id`)');
                }
            }
        }

        if (Schema::hasTable('stk_goods_receipt_items')) {
            if (! $this->indexExists('stk_goods_receipt_items', 'stk_gr_item_receipt_sku_uq')) {
                $hasDuplicate = DB::table('stk_goods_receipt_items')
                    ->select('goods_receipt_id', 'sku_id')
                    ->groupBy('goods_receipt_id', 'sku_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();
                if (! $hasDuplicate) {
                    DB::statement('ALTER TABLE `stk_goods_receipt_items` ADD UNIQUE INDEX `stk_gr_item_receipt_sku_uq` (`goods_receipt_id`, `sku_id`)');
                }
            }
            if (! $this->indexExists('stk_goods_receipt_items', 'stk_gr_item_sku_receipt_idx')) {
                DB::statement('ALTER TABLE `stk_goods_receipt_items` ADD INDEX `stk_gr_item_sku_receipt_idx` (`sku_id`, `goods_receipt_id`)');
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function down(): void
    {
        // Deliberately non-destructive. This migration upgrades and preserves legacy GR data.
    }
};
