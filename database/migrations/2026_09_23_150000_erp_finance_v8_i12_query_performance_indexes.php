<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('finance_settlement_sources', 'fin_v8_i12_stls_out_date_stat_idx', ['outlet_id', 'business_date', 'status', 'expected_settlement_date', 'id']);
        $this->addIndexIfMissing('finance_settlements', 'fin_v8_i12_stl_out_date_stat_idx', ['outlet_id', 'settlement_date', 'status', 'id']);
        $this->addIndexIfMissing('stk_inventory_movements', 'fin_v8_i12_inv_out_date_type_idx', ['outlet_id', 'business_date', 'movement_type', 'id']);
        $this->addIndexIfMissing('wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_biz_idx', ['warehouse_id', 'status', 'business_date', 'source_type', 'id']);
        $this->addIndexIfMissing('wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_jrn_idx', ['warehouse_id', 'status', 'journal_date', 'source_type', 'id']);
        $this->addIndexIfMissing('wh_receivings', 'fin_v8_i12_recv_wh_stat_done_idx', ['warehouse_id', 'status', 'completed_at', 'id']);
        $this->addIndexIfMissing('wh_stock_ins', 'fin_v8_i12_stkin_wh_stat_appr_idx', ['warehouse_id', 'status', 'approved_at', 'id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('finance_settlement_sources', 'fin_v8_i12_stls_out_date_stat_idx');
        $this->dropIndexIfExists('finance_settlements', 'fin_v8_i12_stl_out_date_stat_idx');
        $this->dropIndexIfExists('stk_inventory_movements', 'fin_v8_i12_inv_out_date_type_idx');
        $this->dropIndexIfExists('wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_biz_idx');
        $this->dropIndexIfExists('wh_v4_finance_general_postings', 'fin_v8_i12_whgp_out_stat_jrn_idx');
        $this->dropIndexIfExists('wh_receivings', 'fin_v8_i12_recv_wh_stat_done_idx');
        $this->dropIndexIfExists('wh_stock_ins', 'fin_v8_i12_stkin_wh_stat_appr_idx');
    }

    private function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $indexName)) return;
        foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) return;

        $grammar = DB::connection()->getQueryGrammar();
        $wrapped = implode(', ', array_map(fn (string $column) => $grammar->wrap($column), $columns));
        DB::statement('CREATE INDEX '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($table).' ('.$wrapped.')');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) return;
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
