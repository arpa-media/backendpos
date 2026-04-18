<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('sales', 'sales_report_status_deleted_outlet_marking_created_idx', ['status', 'deleted_at', 'outlet_id', 'marking', 'created_at']);
        $this->addIndexIfMissing('sale_items', 'sale_items_sale_voided_product_variant_idx', ['sale_id', 'voided_at', 'product_name', 'variant_name']);
        $this->addIndexIfMissing('report_sale_scope_cache', 'report_sale_scope_cache_exp_scope_sale_idx', ['expires_at', 'scope_key', 'sale_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('sales', 'sales_report_status_deleted_outlet_marking_created_idx');
        $this->dropIndexIfExists('sale_items', 'sale_items_sale_voided_product_variant_idx');
        $this->dropIndexIfExists('report_sale_scope_cache', 'report_sale_scope_cache_exp_scope_sale_idx');
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        $wrapped = implode(', ', array_map(fn (string $column) => DB::getSchemaBuilder()->getConnection()->getQueryGrammar()->wrap($column), $columns));
        DB::statement('CREATE INDEX ' . $indexName . ' ON ' . $table . ' (' . $wrapped . ')');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        DB::statement('DROP INDEX ' . $indexName . ' ON ' . $table);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
