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
        if (! Schema::hasTable('stk_uoms')) {
            Schema::create('stk_uoms', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('code', 30)->unique();
                $table->string('name', 100);
                $table->string('symbol', 30);
                $table->unsignedTinyInteger('decimal_places')->default(2);
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('stk_categories')) {
            Schema::create('stk_categories', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('name', 150)->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('stk_skus')) {
            Schema::create('stk_skus', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('sku_code', 60)->unique();
                $table->foreignUlid('category_id')->constrained('stk_categories')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->string('name', 180);
                $table->string('barcode', 100)->nullable()->unique();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['category_id', 'is_active'], 'stk_skus_category_active_idx');
            });
        }

        if (! Schema::hasTable('stk_par_stocks')) {
            Schema::create('stk_par_stocks', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
                $table->decimal('par_qty', 18, 4)->default(0);
                $table->decimal('minimum_qty', 18, 4)->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['outlet_id', 'sku_id'], 'stk_par_outlet_sku_uq');
                $table->index(['outlet_id', 'is_active'], 'stk_par_outlet_active_idx');
            });
        }

        if (! Schema::hasTable('stk_stock_opnames')) {
            Schema::create('stk_stock_opnames', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->date('opname_date');
                $table->string('status', 20)->default('draft')->index();
                $table->text('notes')->nullable();
                $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['outlet_id', 'opname_date'], 'stk_opname_outlet_date_uq');
                $table->index(['outlet_id', 'opname_date', 'status'], 'stk_opname_lookup_idx');
            });
        }

        if (! Schema::hasTable('stk_stock_opname_items')) {
            Schema::create('stk_stock_opname_items', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_opname_id')->constrained('stk_stock_opnames')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('actual_qty', 18, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['stock_opname_id', 'sku_id'], 'stk_opname_item_uq');
                $table->index('sku_id', 'stk_opname_item_sku_idx');
            });
        }

        $now = now();
        $defaultUoms = [
            ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'Kg', 'decimal_places' => 3],
            ['code' => 'GR', 'name' => 'Gram', 'symbol' => 'Gr', 'decimal_places' => 2],
            ['code' => 'PAX', 'name' => 'Pax', 'symbol' => 'Pax', 'decimal_places' => 0],
            ['code' => 'PCS', 'name' => 'Pieces', 'symbol' => 'Pcs', 'decimal_places' => 0],
            ['code' => 'LTR', 'name' => 'Liter', 'symbol' => 'L', 'decimal_places' => 3],
            ['code' => 'ML', 'name' => 'Milliliter', 'symbol' => 'Ml', 'decimal_places' => 2],
        ];

        foreach ($defaultUoms as $uom) {
            $existingId = DB::table('stk_uoms')->where('code', $uom['code'])->value('id');
            DB::table('stk_uoms')->updateOrInsert(
                ['code' => $uom['code']],
                [
                    'id' => $existingId ?: (string) Str::ulid(),
                    'name' => $uom['name'],
                    'symbol' => $uom['symbol'],
                    'decimal_places' => $uom['decimal_places'],
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => DB::table('stk_uoms')->where('code', $uom['code'])->value('created_at') ?: $now,
                    'updated_at' => $now,
                ]
            );
        }

        $defaultCategories = [
            ['code' => 'KOL-SAYUR', 'name' => 'Kolom Sayur'],
            ['code' => 'KOL-GROCERIES', 'name' => 'Kolom Groceries'],
            ['code' => 'KOL-PROTEIN', 'name' => 'Kolom Protein'],
            ['code' => 'KOL-POWDER', 'name' => 'Kolom Powder'],
            ['code' => 'KOL-PREP', 'name' => 'Kolom Prep'],
            ['code' => 'KOL-PREP-PROTEIN', 'name' => 'Kolom Prep Protein'],
            ['code' => 'KOL-UTENSILS', 'name' => 'Kolom Utensils'],
            ['code' => 'PIZZA-SAYURAN', 'name' => 'Sayuran Pizza'],
            ['code' => 'PIZZA-PROTEIN', 'name' => 'Protein Pizza'],
            ['code' => 'PIZZA-GROCERIES', 'name' => 'Groceries Pizza'],
            ['code' => 'PIZZA-HALF-PREP', 'name' => 'Half Prep Pizza'],
            ['code' => 'PIZZA-UTENSILS', 'name' => 'Utensils Pizza'],
        ];

        foreach ($defaultCategories as $index => $category) {
            $existingId = DB::table('stk_categories')->where('code', $category['code'])->value('id');
            DB::table('stk_categories')->updateOrInsert(
                ['code' => $category['code']],
                [
                    'id' => $existingId ?: (string) Str::ulid(),
                    'name' => $category['name'],
                    'description' => null,
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => DB::table('stk_categories')->where('code', $category['code'])->value('created_at') ?: $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stk_stock_opname_items');
        Schema::dropIfExists('stk_stock_opnames');
        Schema::dropIfExists('stk_par_stocks');
        Schema::dropIfExists('stk_skus');
        Schema::dropIfExists('stk_categories');
        Schema::dropIfExists('stk_uoms');
    }
};
