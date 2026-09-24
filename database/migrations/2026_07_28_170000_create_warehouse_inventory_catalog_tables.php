<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();
        $this->extendSkuTable();
        $this->createSkuUoms();
        $this->createBatches();
        $this->createBatchBalances();
        $this->createStockUnits();
        $this->createOutletPricePolicies();
        $this->createScanEvents();
        $this->backfillSkuDefaults();
        $this->bootstrapLegacyWarehouseBalances();
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'users', 'stk_skus', 'stk_uoms', 'stk_inventory_balances',
            'wh_brands', 'wh_storages', 'wh_chain_supplies',
        ];

        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Patch Warehouse Iterasi 03 membutuhkan Iterasi 01-02. Missing tables: '.implode(', ', $missing)
            );
        }
    }

    private function extendSkuTable(): void
    {
        Schema::table('stk_skus', function (Blueprint $table): void {
            if (! Schema::hasColumn('stk_skus', 'brand_id')) {
                $table->ulid('brand_id')->nullable()->after('category_id')->index('stk_skus_brand_idx');
            }
            if (! Schema::hasColumn('stk_skus', 'purchase_uom_id')) {
                $table->ulid('purchase_uom_id')->nullable()->after('base_uom_id')->index('stk_skus_purchase_uom_idx');
            }
            if (! Schema::hasColumn('stk_skus', 'purchase_conversion_factor')) {
                $table->decimal('purchase_conversion_factor', 24, 8)->default(1)->after('purchase_uom_id');
            }
            if (! Schema::hasColumn('stk_skus', 'price_min')) {
                $table->decimal('price_min', 20, 6)->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('stk_skus', 'price_max')) {
                $table->decimal('price_max', 20, 6)->nullable()->after('price_min');
            }
        });

        $this->addForeignIfMissing('stk_skus', 'brand_id', 'wh_brands', 'stk_skus_brand_fk');
        $this->addForeignIfMissing('stk_skus', 'purchase_uom_id', 'stk_uoms', 'stk_skus_purchase_uom_fk');
    }

    private function addForeignIfMissing(string $table, string $column, string $references, string $constraint): void
    {
        $database = DB::getDatabaseName();
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $constraint): void {
            $blueprint->foreign($column, $constraint)->references('id')->on($references)->nullOnDelete();
        });
    }

    private function createSkuUoms(): void
    {
        if (Schema::hasTable('wh_sku_uoms')) {
            return;
        }

        Schema::create('wh_sku_uoms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
            $table->foreignUlid('uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->decimal('conversion_factor', 24, 8)->default(1);
            $table->boolean('is_purchase_default')->default(false)->index();
            $table->boolean('is_request_enabled')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['sku_id', 'uom_id'], 'wh_sku_uoms_sku_uom_uq');
            $table->index(['sku_id', 'is_active'], 'wh_sku_uoms_sku_active_idx');
        });
    }

    private function createBatches(): void
    {
        if (Schema::hasTable('wh_batches')) {
            return;
        }

        Schema::create('wh_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
            $table->string('batch_code', 100)->unique();
            $table->string('supplier_batch_code', 100)->nullable()->index();
            $table->string('source_type', 40)->default('manual')->index();
            $table->string('source_reference_type', 80)->nullable();
            $table->ulid('source_reference_id')->nullable();
            $table->ulid('source_reference_line_id')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->decimal('quantity_received_base', 18, 4)->default(0);
            $table->decimal('actual_unit_cost', 20, 6)->default(0);
            $table->decimal('price_min', 20, 6)->default(0);
            $table->decimal('price_avg', 20, 6)->default(0);
            $table->decimal('price_max', 20, 6)->default(0);
            $table->string('status', 24)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['warehouse_id', 'sku_id', 'status'], 'wh_batches_wh_sku_status_idx');
            $table->index(['warehouse_id', 'storage_id', 'status'], 'wh_batches_wh_storage_status_idx');
            $table->index(['source_reference_type', 'source_reference_id'], 'wh_batches_source_ref_idx');
        });
    }

    private function createBatchBalances(): void
    {
        if (Schema::hasTable('wh_batch_balances')) {
            return;
        }

        Schema::create('wh_batch_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->foreignUlid('batch_id')->constrained('wh_batches')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->decimal('on_hand_qty', 18, 4)->default(0);
            $table->decimal('reserved_qty', 18, 4)->default(0);
            $table->decimal('quarantine_qty', 18, 4)->default(0);
            $table->decimal('average_unit_cost', 20, 6)->default(0);
            $table->decimal('inventory_value', 22, 2)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['warehouse_id', 'storage_id', 'batch_id'], 'wh_batch_balances_location_uq');
            $table->index(['warehouse_id', 'sku_id'], 'wh_batch_balances_wh_sku_idx');
            $table->index(['warehouse_id', 'storage_id'], 'wh_batch_balances_wh_storage_idx');
        });
    }

    private function createStockUnits(): void
    {
        if (Schema::hasTable('wh_stock_units')) {
            return;
        }

        Schema::create('wh_stock_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('barcode', 120)->unique();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('batch_id')->constrained('wh_batches')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'sku_id', 'status'], 'wh_stock_units_wh_sku_status_idx');
            $table->index(['warehouse_id', 'storage_id', 'status'], 'wh_stock_units_wh_storage_status_idx');
            $table->index(['batch_id', 'status'], 'wh_stock_units_batch_status_idx');
        });
    }

    private function createOutletPricePolicies(): void
    {
        if (Schema::hasTable('wh_outlet_price_policies')) {
            return;
        }

        Schema::create('wh_outlet_price_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
            $table->string('price_band', 12)->default('AVG');
            $table->decimal('custom_price', 20, 6)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['warehouse_id', 'outlet_id', 'sku_id'], 'wh_outlet_price_policy_uq');
            $table->index(['warehouse_id', 'outlet_id', 'is_active'], 'wh_outlet_price_policy_outlet_idx');
        });
    }

    private function createScanEvents(): void
    {
        if (Schema::hasTable('wh_scan_events')) {
            return;
        }

        Schema::create('wh_scan_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('context_type', 60)->index();
            $table->string('context_id', 100)->index();
            $table->foreignUlid('stock_unit_id')->nullable()->constrained('wh_stock_units')->nullOnDelete();
            $table->string('barcode', 120)->index();
            $table->foreignUlid('expected_sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
            $table->foreignUlid('actual_sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
            $table->string('result', 20)->index();
            $table->string('message', 500)->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignUlid('scanned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at')->useCurrent()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['context_type', 'context_id', 'stock_unit_id'], 'wh_scan_events_context_unit_uq');
            $table->index(['warehouse_id', 'context_type', 'context_id'], 'wh_scan_events_wh_context_idx');
        });
    }

    private function backfillSkuDefaults(): void
    {
        $brandId = DB::table('wh_brands')->where('code', 'NO-BRAND')->value('id');
        $now = now();
        if (! $brandId) {
            $brandId = (string) Str::ulid();
            DB::table('wh_brands')->insert([
                'id' => $brandId,
                'code' => 'NO-BRAND',
                'name' => 'No Brand',
                'description' => 'Digunakan untuk item tanpa merek.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('wh_brands')->where('id', $brandId)->update(['is_active' => true, 'deleted_at' => null, 'updated_at' => $now]);
        }

        $skus = DB::table('stk_skus')->whereNull('deleted_at')->get(['id', 'base_uom_id', 'brand_id', 'purchase_uom_id']);
        foreach ($skus as $sku) {
            DB::table('stk_skus')->where('id', $sku->id)->update([
                'brand_id' => $sku->brand_id ?: $brandId,
                'purchase_uom_id' => $sku->purchase_uom_id ?: $sku->base_uom_id,
                'purchase_conversion_factor' => DB::raw('COALESCE(NULLIF(purchase_conversion_factor, 0), 1)'),
                'updated_at' => $now,
            ]);

            $existing = DB::table('wh_sku_uoms')
                ->where('sku_id', $sku->id)
                ->where('uom_id', $sku->base_uom_id)
                ->first();

            DB::table('wh_sku_uoms')->updateOrInsert(
                ['sku_id' => $sku->id, 'uom_id' => $sku->base_uom_id],
                [
                    'id' => $existing->id ?? (string) Str::ulid(),
                    'conversion_factor' => 1,
                    'is_purchase_default' => ($sku->purchase_uom_id ?: $sku->base_uom_id) === $sku->base_uom_id,
                    'is_request_enabled' => true,
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function bootstrapLegacyWarehouseBalances(): void
    {
        $balances = DB::table('stk_inventory_balances as balance')
            ->join('outlets as warehouse', 'warehouse.id', '=', 'balance.outlet_id')
            ->join('stk_skus as sku', 'sku.id', '=', 'balance.sku_id')
            ->whereRaw("LOWER(COALESCE(warehouse.type, '')) = 'warehouse'")
            ->select([
                'balance.id', 'balance.outlet_id', 'balance.sku_id', 'balance.on_hand_qty',
                'balance.average_unit_cost', 'balance.inventory_value', 'balance.last_movement_at',
                'warehouse.code as warehouse_code', 'sku.sku_code',
            ])
            ->get();

        $now = now();
        foreach ($balances as $balance) {
            $hasBatchBalance = DB::table('wh_batch_balances')
                ->where('warehouse_id', $balance->outlet_id)
                ->where('sku_id', $balance->sku_id)
                ->exists();
            if ($hasBatchBalance) {
                continue;
            }

            $storage = DB::table('wh_storages')
                ->where('warehouse_id', $balance->outlet_id)
                ->where('code', 'LEGACY')
                ->first();

            if ($storage) {
                DB::table('wh_storages')->where('id', $storage->id)->update([
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
            } else {
                $storageId = (string) Str::ulid();
                DB::table('wh_storages')->insert([
                    'id' => $storageId,
                    'warehouse_id' => $balance->outlet_id,
                    'code' => 'LEGACY',
                    'name' => 'Legacy / Belum Dipetakan',
                    'storage_type' => 'rack',
                    'position_description' => 'Dibuat otomatis Iterasi 03 untuk saldo sebelum implementasi batch.',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $storage = (object) ['id' => $storageId];
            }

            $hash = strtoupper(substr(sha1((string) $balance->id), 0, 16));
            $batchCode = 'LEGACY-'.$hash;
            $batch = DB::table('wh_batches')->where('batch_code', $batchCode)->first();
            $batchId = (string) ($batch->id ?? Str::ulid());
            $cost = (float) ($balance->average_unit_cost ?? 0);
            $qty = (float) ($balance->on_hand_qty ?? 0);
            $value = (float) ($balance->inventory_value ?? ($qty * $cost));

            if (! $batch) {
                DB::table('wh_batches')->insert([
                    'id' => $batchId,
                    'warehouse_id' => $balance->outlet_id,
                    'sku_id' => $balance->sku_id,
                    'storage_id' => $storage->id,
                    'batch_code' => $batchCode,
                    'source_type' => 'legacy_opening',
                    'source_reference_type' => 'stk_inventory_balances',
                    'source_reference_id' => $balance->id,
                    'received_at' => $balance->last_movement_at ?: $now,
                    'quantity_received_base' => max($qty, 0),
                    'actual_unit_cost' => $cost,
                    'price_min' => $cost,
                    'price_avg' => $cost,
                    'price_max' => $cost,
                    'status' => 'active',
                    'notes' => 'Bootstrap saldo aggregate sebelum Iterasi 03. Tidak membuat movement baru.',
                    'metadata' => json_encode(['bootstrap' => true, 'balance_id' => $balance->id]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('wh_batch_balances')->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $balance->outlet_id,
                'storage_id' => $storage->id,
                'batch_id' => $batchId,
                'sku_id' => $balance->sku_id,
                'on_hand_qty' => $qty,
                'reserved_qty' => 0,
                'quarantine_qty' => 0,
                'average_unit_cost' => $cost,
                'inventory_value' => $value,
                'last_movement_at' => $balance->last_movement_at,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Iterasi berikutnya akan memakai data batch/barcode ini.
    }
};
