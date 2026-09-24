<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_price_policies_v3')) {
            Schema::create('wh_price_policies_v3', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_id')->index();
                $table->string('target_type', 20)->index(); // outlet | customer
                $table->ulid('target_id')->index();
                $table->ulid('sku_id')->index();
                $table->decimal('price', 20, 6)->default(0);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['warehouse_id', 'target_type', 'target_id', 'sku_id'], 'wh_price_v3_target_sku_unique');
            });
        }

        // Every Warehouse v3 must have a safe default storage. Existing rows are preserved.
        if (Schema::hasTable('wh_storages') && Schema::hasTable('outlets')) {
            $warehouses = DB::table('outlets')->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")->get(['id']);
            foreach ($warehouses as $warehouse) {
                if (! DB::table('wh_storages')->where('warehouse_id', $warehouse->id)->where('code', 'UNCATEGORIZED')->exists()) {
                    DB::table('wh_storages')->insert([
                        'id' => (string) Str::ulid(), 'warehouse_id' => $warehouse->id,
                        'code' => 'UNCATEGORIZED', 'name' => 'Uncategorized', 'storage_type' => 'rack',
                        'position_description' => 'Default storage Warehouse v3 ketika storage tidak dipilih.',
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Additive migration: do not drop pricing history automatically.
    }
};
