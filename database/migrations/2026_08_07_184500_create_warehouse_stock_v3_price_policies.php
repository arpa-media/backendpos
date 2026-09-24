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
        $this->createCustomerPricePolicies();
        $this->ensureUncategorizedStorage();
        $this->moveNullBatchesToUncategorized();
        $this->disableLegacyInventoryMenus();
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'users', 'stk_skus', 'wh_storages', 'wh_batches',
            'wh_outlet_price_policies', 'wh_customers',
        ];

        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Warehouse v3 Iterasi 03 membutuhkan baseline Warehouse + Iterasi 01-02. Missing tables: '.implode(', ', $missing)
            );
        }
    }

    private function createCustomerPricePolicies(): void
    {
        if (Schema::hasTable('wh_customer_price_policies')) {
            return;
        }

        Schema::create('wh_customer_price_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained('wh_customers')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
            $table->string('price_band', 12)->default('AVG');
            $table->decimal('custom_price', 20, 6)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['warehouse_id', 'customer_id', 'sku_id'], 'wh_customer_price_policy_uq');
            $table->index(['warehouse_id', 'customer_id', 'is_active'], 'wh_customer_price_policy_customer_idx');
        });
    }

    private function ensureUncategorizedStorage(): void
    {
        $warehouseIds = DB::table('outlets')
            ->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")
            ->pluck('id');

        $now = now();
        foreach ($warehouseIds as $warehouseId) {
            $existing = DB::table('wh_storages')
                ->where('warehouse_id', $warehouseId)
                ->whereRaw('UPPER(code) = ?', ['UNCATEGORIZED'])
                ->first();

            if ($existing) {
                DB::table('wh_storages')->where('id', $existing->id)->update([
                    'name' => 'Uncategorized',
                    'is_active' => true,
                    'deleted_at' => null,
                    'updated_at' => $now,
                ]);
                continue;
            }

            DB::table('wh_storages')->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => (string) $warehouseId,
                'code' => 'UNCATEGORIZED',
                'name' => 'Uncategorized',
                'storage_type' => 'rack',
                'position_description' => 'Default storage Warehouse v3 untuk batch/stock-in tanpa storage assignment.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function moveNullBatchesToUncategorized(): void
    {
        $warehouseIds = DB::table('wh_batches')
            ->whereNull('storage_id')
            ->distinct()
            ->pluck('warehouse_id');

        foreach ($warehouseIds as $warehouseId) {
            $storageId = DB::table('wh_storages')
                ->where('warehouse_id', $warehouseId)
                ->whereRaw('UPPER(code) = ?', ['UNCATEGORIZED'])
                ->whereNull('deleted_at')
                ->value('id');

            if (! $storageId) {
                continue;
            }

            DB::table('wh_batches')
                ->where('warehouse_id', $warehouseId)
                ->whereNull('storage_id')
                ->update(['storage_id' => $storageId, 'updated_at' => now()]);
        }
    }

    private function disableLegacyInventoryMenus(): void
    {
        if (! Schema::hasTable('access_menus')) {
            return;
        }

        DB::table('access_menus')
            ->where(function ($query): void {
                $query->whereIn('code', ['warehouse-inventory-barcodes', 'warehouse-inventory-outlet-prices'])
                    ->orWhereIn('path', ['/warehouse/inventory/barcodes', '/warehouse/inventory/outlet-prices']);
            })
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non-destructive: price policies, storage normalization, dan histori Warehouse tidak dihapus.
    }
};
