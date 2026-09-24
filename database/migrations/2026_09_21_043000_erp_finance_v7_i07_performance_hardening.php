<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_daily_sales_summaries')) {
            Schema::table('report_daily_sales_summaries', function (Blueprint $table): void {
                if (! Schema::hasColumn('report_daily_sales_summaries', 'discounted_trx_count')) {
                    $table->unsignedInteger('discounted_trx_count')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'rounding_trx_count')) {
                    $table->unsignedInteger('rounding_trx_count')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'marked_discounted_trx_count')) {
                    $table->unsignedInteger('marked_discounted_trx_count')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'marked_rounding_trx_count')) {
                    $table->unsignedInteger('marked_rounding_trx_count')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'rounding_up_total')) {
                    $table->unsignedBigInteger('rounding_up_total')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'rounding_down_total')) {
                    $table->unsignedBigInteger('rounding_down_total')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'marked_rounding_up_total')) {
                    $table->unsignedBigInteger('marked_rounding_up_total')->default(0);
                }
                if (! Schema::hasColumn('report_daily_sales_summaries', 'marked_rounding_down_total')) {
                    $table->unsignedBigInteger('marked_rounding_down_total')->default(0);
                }
            });
        }

        // Existing coverage rows were built before I07 had positive/negative rounding
        // and discounted/rounding transaction counters. Invalidate only the derived
        // coverage marker; source sales remain untouched and requested windows will
        // be rebuilt set-based by ReportDailySummaryService.
        if (Schema::hasTable('report_daily_summary_coverage')) {
            DB::table('report_daily_summary_coverage')->delete();
        }

        $this->addIndexIfMissing('finance_journal_entries', 'fin_i07_outlet_jdate_status_mark_idx', ['outlet_id', 'journal_date', 'status', 'marking']);
        $this->addIndexIfMissing('finance_journal_entries', 'fin_i07_outlet_bdate_status_mark_idx', ['outlet_id', 'business_date', 'status', 'marking']);
        $this->addIndexIfMissing('finance_journal_entries', 'fin_i07_company_jdate_status_mark_idx', ['company_code', 'journal_date', 'status', 'marking']);
        $this->addIndexIfMissing('finance_journal_entries', 'fin_i07_company_bdate_status_mark_idx', ['company_code', 'business_date', 'status', 'marking']);

        $this->addIndexIfMissing('finance_financial_statement_rules', 'fin_i07_stmt_bs_account_idx', ['balance_sheet_section', 'account_id']);
        $this->addIndexIfMissing('finance_financial_statement_rules', 'fin_i07_stmt_pl_account_idx', ['profit_loss_section', 'account_id']);
        $this->addIndexIfMissing('finance_financial_statement_rules', 'fin_i07_stmt_cf_cash_account_idx', ['cash_flow_section', 'is_cash_account', 'account_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('finance_financial_statement_rules', 'fin_i07_stmt_cf_cash_account_idx');
        $this->dropIndexIfExists('finance_financial_statement_rules', 'fin_i07_stmt_pl_account_idx');
        $this->dropIndexIfExists('finance_financial_statement_rules', 'fin_i07_stmt_bs_account_idx');
        $this->dropIndexIfExists('finance_journal_entries', 'fin_i07_company_bdate_status_mark_idx');
        $this->dropIndexIfExists('finance_journal_entries', 'fin_i07_company_jdate_status_mark_idx');
        $this->dropIndexIfExists('finance_journal_entries', 'fin_i07_outlet_bdate_status_mark_idx');
        $this->dropIndexIfExists('finance_journal_entries', 'fin_i07_outlet_jdate_status_mark_idx');

        if (Schema::hasTable('report_daily_sales_summaries')) {
            $columns = array_values(array_filter([
                'discounted_trx_count',
                'rounding_trx_count',
                'marked_discounted_trx_count',
                'marked_rounding_trx_count',
                'rounding_up_total',
                'rounding_down_total',
                'marked_rounding_up_total',
                'marked_rounding_down_total',
            ], fn (string $column) => Schema::hasColumn('report_daily_sales_summaries', $column)));

            if ($columns !== []) {
                Schema::table('report_daily_sales_summaries', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
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
