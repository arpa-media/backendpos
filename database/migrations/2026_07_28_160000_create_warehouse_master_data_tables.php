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
        if (Schema::hasTable('pur_supplier_sources')) {
            if (! Schema::hasColumn('pur_supplier_sources', 'email')) {
                Schema::table('pur_supplier_sources', function (Blueprint $table) {
                    $table->string('email', 180)->nullable()->after('phone');
                });
            }
            if (! Schema::hasColumn('pur_supplier_sources', 'address')) {
                Schema::table('pur_supplier_sources', function (Blueprint $table) {
                    $table->text('address')->nullable()->after('email');
                });
            }
            if (! Schema::hasColumn('pur_supplier_sources', 'tax_number')) {
                Schema::table('pur_supplier_sources', function (Blueprint $table) {
                    $table->string('tax_number', 80)->nullable()->after('address');
                });
            }

            DB::table('pur_supplier_sources')
                ->whereRaw("LOWER(source_type) IN ('external', 'vendor', 'supplier_external')")
                ->update(['source_type' => 'supplier', 'updated_at' => now()]);

            DB::table('pur_supplier_sources')
                ->where(function ($query) {
                    $query->whereNull('source_type')->orWhere('source_type', '');
                })
                ->where('code', '<>', 'WAREHOUSE-MAIN')
                ->update(['source_type' => 'supplier', 'updated_at' => now()]);
        }

        if (! Schema::hasTable('wh_brands')) {
            Schema::create('wh_brands', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('code', 60)->unique();
                $table->string('name', 150)->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('wh_chain_supplies')) {
            Schema::create('wh_chain_supplies', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->date('effective_from')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('outlet_id', 'wh_chain_supply_outlet_uq');
                $table->index(['warehouse_id', 'is_active'], 'wh_chain_supply_warehouse_active_idx');
            });
        }

        if (! Schema::hasTable('wh_storages')) {
            Schema::create('wh_storages', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->string('code', 60);
                $table->string('name', 150);
                $table->string('storage_type', 30)->index();
                $table->string('position_description', 500)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['warehouse_id', 'code'], 'wh_storage_warehouse_code_uq');
                $table->index(['warehouse_id', 'storage_type', 'is_active'], 'wh_storage_lookup_idx');
            });
        }

        $now = now();
        $noBrand = DB::table('wh_brands')->where('code', 'NO-BRAND')->first();
        DB::table('wh_brands')->updateOrInsert(
            ['code' => 'NO-BRAND'],
            [
                'id' => (string) ($noBrand->id ?? Str::ulid()),
                'name' => 'No Brand',
                'description' => 'Digunakan untuk item tanpa merek.',
                'is_active' => true,
                'deleted_at' => null,
                'created_at' => $noBrand->created_at ?? $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        // Non-destructive. Master data dapat sudah dipakai dokumen iterasi berikutnya.
    }
};
