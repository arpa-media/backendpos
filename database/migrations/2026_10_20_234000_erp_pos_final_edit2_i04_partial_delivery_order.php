<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple DOs may point to a single approved Checker Prepare / source line.
     * Keep a non-unique index BEFORE removing the old unique index; MySQL FKs require one.
     * Legacy Sales Order and Transfer Stock stay single-DO by service contract.
     */
    public function up(): void
    {
        foreach (['wh_v3_delivery_orders', 'wh_v3_delivery_order_items', 'wh_v3_logistics_prepare_requests', 'wh_v3_logistics_prepare_items'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("I04 requires {$table}. Run baseline migrations first.");
        }
        if (! Schema::hasColumn('wh_v3_delivery_orders','dispatch_request_key')) {
            Schema::table('wh_v3_delivery_orders', function (Blueprint $table): void {
                $table->string('dispatch_request_key', 100)->nullable()->unique('whv3_do_i04_dispatch_key_uq');
            });
        }
        if (! Schema::hasIndex('wh_v3_delivery_orders','whv3_do_i04_prepare_idx')) {
            Schema::table('wh_v3_delivery_orders', fn (Blueprint $table) => $table->index('prepare_request_id','whv3_do_i04_prepare_idx'));
        }
        if (! Schema::hasIndex('wh_v3_delivery_order_items','whv3_doi_i04_prepare_item_idx')) {
            Schema::table('wh_v3_delivery_order_items', fn (Blueprint $table) => $table->index('prepare_item_id','whv3_doi_i04_prepare_item_idx'));
        }
        $this->removeSingleColumnUnique('wh_v3_delivery_orders', 'prepare_request_id');
        $this->removeSingleColumnUnique('wh_v3_delivery_order_items', 'prepare_item_id');
    }

    private function removeSingleColumnUnique(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = array_map('strtolower', $index['columns'] ?? []);
            if ($columns === [strtolower($column)] && !empty($index['unique'])) {
                $name = (string) $index['name'];
                if (str_starts_with($name, 'whv3_do_i04_') || str_starts_with($name,'whv3_doi_i04_')) continue;
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
            }
        }
    }

    /** Do not silently restore uniqueness if production has already created multiple DOs. */
    public function down(): void
    {
        $hasMultipleDo = DB::table('wh_v3_delivery_orders')->select('prepare_request_id')
            ->groupBy('prepare_request_id')->havingRaw('COUNT(*) > 1')->exists();
        $hasMultipleItems = DB::table('wh_v3_delivery_order_items')->select('prepare_item_id')
            ->groupBy('prepare_item_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($hasMultipleDo || $hasMultipleItems) {
            throw new RuntimeException('I04 rollback is blocked: partial DO data exist. Restore DB backup or archive partial shipments before rollback.');
        }
        Schema::table('wh_v3_delivery_orders', fn (Blueprint $t) => $t->unique('prepare_request_id'));
        Schema::table('wh_v3_delivery_order_items', fn (Blueprint $t) => $t->unique('prepare_item_id'));
        Schema::table('wh_v3_delivery_orders', function (Blueprint $t): void {
            $t->dropIndex('whv3_do_i04_prepare_idx');
            $t->dropUnique('whv3_do_i04_dispatch_key_uq');
            $t->dropColumn('dispatch_request_key');
        });
        Schema::table('wh_v3_delivery_order_items', fn (Blueprint $t) => $t->dropIndex('whv3_doi_i04_prepare_item_idx'));
    }
};
