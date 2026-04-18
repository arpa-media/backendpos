<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('report_sale_business_dates', 'rsbd_tz_outlet_date_sale_idx', ['business_timezone', 'outlet_id', 'business_date', 'sale_id']);
        $this->addIndexIfMissing('report_sale_business_dates', 'rsbd_tz_date_outlet_marking_sale_idx', ['business_timezone', 'business_date', 'outlet_id', 'marking', 'sale_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('report_sale_business_dates', 'rsbd_tz_outlet_date_sale_idx');
        $this->dropIndexIfExists('report_sale_business_dates', 'rsbd_tz_date_outlet_marking_sale_idx');
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
