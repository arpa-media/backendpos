<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfPossible('report_sale_business_dates', 'rsbd_outlet_date_marking_sale_idx', ['outlet_id', 'business_date', 'marking', 'sale_id']);
        $this->addIndexIfPossible('report_daily_summary_coverage', 'rds_cov_outlet_date_synced_idx', ['outlet_id', 'business_date', 'synced_at']);
        $this->addIndexIfPossible('report_daily_payment_summaries', 'rdps_outlet_date_payment_idx', ['outlet_id', 'business_date', 'payment_method_name', 'payment_method_type']);
        $this->addIndexIfPossible('report_daily_channel_summaries', 'rdcs_outlet_date_channel_idx', ['outlet_id', 'business_date', 'display_channel']);
        $this->addIndexIfPossible('report_daily_category_summaries', 'rdcat_outlet_date_name_kind_idx', ['outlet_id', 'business_date', 'category_name', 'category_kind']);
        $this->addIndexIfPossible('report_daily_product_summaries', 'rdprod_outlet_date_name_idx', ['outlet_id', 'business_date', 'product_name']);
        $this->addIndexIfPossible('report_daily_variant_summaries', 'rdvar_outlet_date_names_idx', ['outlet_id', 'business_date', 'product_name', 'variant_name']);
        $this->addIndexIfPossible('report_daily_summary_refresh_queue', 'rdsrq_status_queue_date_outlet_idx', ['status', 'queued_at', 'business_date', 'outlet_id']);
        $this->addIndexIfPossible('sale_items', 'sale_items_sale_voided_channel_idx', ['sale_id', 'voided_at', 'channel']);
        $this->addIndexIfPossible('sale_payments', 'sale_payments_sale_method_created_idx', ['sale_id', 'payment_method_id', 'created_at', 'id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('report_sale_business_dates', 'rsbd_outlet_date_marking_sale_idx');
        $this->dropIndexIfExists('report_daily_summary_coverage', 'rds_cov_outlet_date_synced_idx');
        $this->dropIndexIfExists('report_daily_payment_summaries', 'rdps_outlet_date_payment_idx');
        $this->dropIndexIfExists('report_daily_channel_summaries', 'rdcs_outlet_date_channel_idx');
        $this->dropIndexIfExists('report_daily_category_summaries', 'rdcat_outlet_date_name_kind_idx');
        $this->dropIndexIfExists('report_daily_product_summaries', 'rdprod_outlet_date_name_idx');
        $this->dropIndexIfExists('report_daily_variant_summaries', 'rdvar_outlet_date_names_idx');
        $this->dropIndexIfExists('report_daily_summary_refresh_queue', 'rdsrq_status_queue_date_outlet_idx');
        $this->dropIndexIfExists('sale_items', 'sale_items_sale_voided_channel_idx');
        $this->dropIndexIfExists('sale_payments', 'sale_payments_sale_method_created_idx');
    }

    private function addIndexIfPossible(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $indexName) || ! $this->tableHasColumns($table, $columns)) {
            return;
        }

        $wrappedColumns = implode(', ', array_map(
            fn (string $column) => DB::getSchemaBuilder()->getConnection()->getQueryGrammar()->wrap($column),
            $columns
        ));

        DB::statement('CREATE INDEX ' . $indexName . ' ON ' . $table . ' (' . $wrappedColumns . ')');
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
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        $existing = array_map('strtolower', Schema::getColumnListing($table));

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $existing, true)) {
                return false;
            }
        }

        return true;
    }
};
