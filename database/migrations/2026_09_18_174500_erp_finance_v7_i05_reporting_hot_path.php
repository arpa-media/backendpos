<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cashier Report repeatedly resolves approved CANCEL/VOID rows by type and sale.
        $this->addIndexIfMissing(
            'sale_cancel_requests',
            'scr_status_type_sale_outlet_idx',
            ['status', 'request_type', 'sale_id', 'outlet_id']
        );

        // Finance Overview groups a date range by outlet + payment identity. This
        // complements the existing date/outlet and payment-name/date indexes.
        $this->addIndexIfMissing(
            'report_daily_payment_summaries',
            'rdps_outlet_date_method_idx',
            ['outlet_id', 'business_date', 'payment_method_name', 'payment_method_type']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('sale_cancel_requests', 'scr_status_type_sale_outlet_idx');
        $this->dropIndexIfExists('report_daily_payment_summaries', 'rdps_outlet_date_method_idx');
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedColumns = implode(', ', array_map(fn (string $column) => $grammar->wrap($column), $columns));
        DB::statement('CREATE INDEX ' . $grammar->wrap($indexName) . ' ON ' . $grammar->wrapTable($table) . ' (' . $wrappedColumns . ')');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        DB::statement('DROP INDEX ' . $grammar->wrap($indexName) . ' ON ' . $grammar->wrapTable($table));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
