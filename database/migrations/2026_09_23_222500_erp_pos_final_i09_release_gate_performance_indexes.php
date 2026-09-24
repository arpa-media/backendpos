<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,array<int,string>> */
    private array $indexes = [
        'finance_general_posting_journals' => [
            'erp_i09_gp_gate_idx' => ['journal_entry_id', 'posting_version', 'reversal_journal_id', 'general_posting_id'],
        ],
        'stk_requests' => [
            'erp_i09_stockreq_autoapprove_idx' => ['request_channel', 'status', 'request_approval_status', 'submitted_at', 'id'],
        ],
        'report_materialization_recovery_requests' => [
            'erp_i09_recovery_scope_state_idx' => ['pipeline', 'date_from', 'date_to', 'status', 'created_at'],
        ],
        'finance_treasury_transactions' => [
            'erp_i09_treasury_type_date_idx' => ['transaction_type', 'transaction_date', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) continue;
            foreach ($definitions as $name => $columns) {
                if ($this->indexExists($table, $name)) continue;
                if (! collect($columns)->every(fn (string $column): bool => Schema::hasColumn($table, $column))) continue;
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) continue;
            foreach (array_keys($definitions) as $name) {
                if (! $this->indexExists($table, $name)) continue;
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            try {
                return collect(Schema::getIndexes($table))->contains(
                    fn (array $row): bool => ($row['name'] ?? null) === $index
                );
            } catch (Throwable) {
                return false;
            }
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
