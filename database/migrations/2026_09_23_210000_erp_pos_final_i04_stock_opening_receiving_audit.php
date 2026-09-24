<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stk_opening_stocks')) {
            Schema::create('stk_opening_stocks', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
                $table->decimal('opening_qty', 18, 4)->default(0);
                $table->decimal('unit_cost', 20, 6)->default(0);
                $table->decimal('inventory_value', 22, 2)->default(0);
                $table->date('effective_date');
                $table->string('source', 40)->default('PAR_STOCK_INITIAL');
                $table->text('notes')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['outlet_id', 'sku_id'], 'stk_opening_outlet_sku_uq');
                $table->index(['outlet_id', 'effective_date'], 'stk_opening_outlet_date_idx');
            });
        }

        if (Schema::hasTable('stk_goods_receipts')) {
            Schema::table('stk_goods_receipts', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_goods_receipts', 'delivery_not_before_at')) {
                    $table->timestamp('delivery_not_before_at')->nullable()->after('received_at');
                }
                if (! Schema::hasColumn('stk_goods_receipts', 'delivery_reference_type')) {
                    $table->string('delivery_reference_type', 40)->nullable()->after('delivery_not_before_at');
                }
                if (! Schema::hasColumn('stk_goods_receipts', 'delivery_reference_id')) {
                    $table->string('delivery_reference_id', 80)->nullable()->after('delivery_reference_type');
                }
                if (! Schema::hasColumn('stk_goods_receipts', 'delivery_reference_number')) {
                    $table->string('delivery_reference_number', 100)->nullable()->after('delivery_reference_id');
                }
                if (! Schema::hasColumn('stk_goods_receipts', 'received_at_input_by_user_id')) {
                    $table->foreignUlid('received_at_input_by_user_id')->nullable()->after('delivery_reference_number')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_goods_receipts', 'received_at_input_at')) {
                    $table->timestamp('received_at_input_at')->nullable()->after('received_at_input_by_user_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stk_goods_receipts')) {
            if (Schema::hasColumn('stk_goods_receipts', 'received_at_input_by_user_id')) {
                Schema::table('stk_goods_receipts', function (Blueprint $table): void {
                    $table->dropForeign(['received_at_input_by_user_id']);
                });
            }

            Schema::table('stk_goods_receipts', function (Blueprint $table): void {
                foreach ([
                    'received_at_input_at',
                    'received_at_input_by_user_id',
                    'delivery_reference_number',
                    'delivery_reference_id',
                    'delivery_reference_type',
                    'delivery_not_before_at',
                ] as $column) {
                    if (Schema::hasColumn('stk_goods_receipts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('stk_opening_stocks');
    }
};
