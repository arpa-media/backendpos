<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'pur_invoice_liability_ownerships';

    public function up(): void
    {
        foreach (['pur_invoices', 'wh_v3_outgoing_invoices', 'pur_purchase_orders', 'pur_order_ap_lifecycles', 'pur_finance_posting_outbox'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP-V5 I06 Hotfix 01 membutuhkan tabel {$table}.");
            }
        }

        // I06 original may have failed after CREATE TABLE but before all ALTER TABLE index commands.
        // Create the table without implicit index names when it does not exist, then repair/ensure
        // every required index by column signature using short explicit names.
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('invoice_id', 26);
                $table->string('liability_role', 24)->default('MIRROR');
                $table->string('policy_key', 80)->default('WAREHOUSE_STOCK_REQUEST_ORDER_AP');
                $table->char('warehouse_outgoing_invoice_id', 26)->nullable();
                $table->char('stock_request_id', 26)->nullable();
                $table->string('order_kind', 40)->nullable();
                $table->string('order_id', 64)->nullable();
                $table->char('ap_lifecycle_id', 26)->nullable();
                $table->char('canonical_ap_invoice_id', 26)->nullable();
                $table->string('canonical_recognition_event_key', 191)->nullable();
                $table->string('canonical_recognition_journal_no', 80)->nullable();
                $table->char('canonical_general_posting_id', 26)->nullable();
                $table->char('covered_outbox_id', 26)->nullable();
                $table->string('coverage_status', 40)->default('OWNER_PENDING');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex(['invoice_id'], true, 'pur_inv_liab_invoice_uq');
        $this->ensureIndex(['liability_role'], false, 'pur_inv_liab_role_idx');
        $this->ensureIndex(['policy_key'], false, 'pur_inv_liab_policy_idx');
        $this->ensureIndex(['warehouse_outgoing_invoice_id'], true, 'pur_inv_liab_wh_out_uq');
        $this->ensureIndex(['stock_request_id'], false, 'pur_inv_liab_sr_idx');
        $this->ensureIndex(['order_kind'], false, 'pur_inv_liab_kind_idx');
        $this->ensureIndex(['order_id'], false, 'pur_inv_liab_order_idx');
        $this->ensureIndex(['ap_lifecycle_id'], false, 'pur_inv_liab_aplife_idx');
        $this->ensureIndex(['canonical_ap_invoice_id'], false, 'pur_inv_liab_cap_idx');
        $this->ensureIndex(['canonical_recognition_event_key'], false, 'pur_inv_liab_rec_evt_idx');
        $this->ensureIndex(['canonical_general_posting_id'], false, 'pur_inv_liab_gp_idx');
        $this->ensureIndex(['covered_outbox_id'], false, 'pur_inv_liab_outbox_idx');
        $this->ensureIndex(['coverage_status'], false, 'pur_inv_liab_cov_idx');
        $this->ensureIndex(['stock_request_id', 'coverage_status'], false, 'pur_inv_liab_sr_cov_idx');
        $this->ensureIndex(['order_kind', 'order_id', 'coverage_status'], false, 'pur_inv_liab_ord_cov_idx');
    }

    public function down(): void
    {
        // Audit-safe and recovery-safe: do not drop the ownership table/indexes automatically.
    }

    private function ensureIndex(array $columns, bool $unique, string $name): void
    {
        if ($this->hasEquivalentIndex($columns, $unique)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $unique, $name): void {
            if ($unique) {
                $table->unique($columns, $name);
            } else {
                $table->index($columns, $name);
            }
        });
    }

    private function hasEquivalentIndex(array $columns, bool $unique): bool
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, self::TABLE]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexName = (string) $row->INDEX_NAME;
            if (! isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'unique' => ((int) $row->NON_UNIQUE) === 0,
                    'columns' => [],
                ];
            }
            $indexes[$indexName]['columns'][] = (string) $row->COLUMN_NAME;
        }

        foreach ($indexes as $index) {
            if ($index['unique'] === $unique && $index['columns'] === array_values($columns)) {
                return true;
            }
        }

        return false;
    }
};
