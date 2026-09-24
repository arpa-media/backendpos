<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reconciliation historical list: outlet scope + business-date range + status.
        // Existing outlet/date UNIQUE remains authoritative; this index only avoids
        // extra filtering/sort work when status is also present on long ranges.
        $this->addIndexIfMissing(
            'finance_reconciliations',
            'fin_v8_i04_rec_out_date_status_idx',
            ['outlet_id', 'business_date', 'status', 'created_at']
        );

        // COGS historical source list always scopes outlet, closed status and period.
        // Put equality predicates before range columns so MySQL can prune old rows
        // before page/count work.
        $this->addIndexIfMissing(
            'cogs_calculation_runs',
            'fin_v8_i04_cogs_out_status_to_from_idx',
            ['outlet_id', 'status', 'period_to', 'period_from', 'id']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('finance_reconciliations', 'fin_v8_i04_rec_out_date_status_idx');
        $this->dropIndexIfExists('cogs_calculation_runs', 'fin_v8_i04_cogs_out_status_to_from_idx');
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
        DB::statement('CREATE INDEX '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($table).' ('.$wrappedColumns.')');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        DB::statement('DROP INDEX '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($table));
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
