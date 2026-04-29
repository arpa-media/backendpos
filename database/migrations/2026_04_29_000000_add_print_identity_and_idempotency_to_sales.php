<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $name): bool
    {
        try {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                $database = DB::getDatabaseName();
                $rows = DB::select(
                    'select index_name from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                    [$database, $table, $name]
                );

                return count($rows) > 0;
            }

            if ($driver === 'sqlite') {
                $rows = DB::select("PRAGMA index_list('{$table}')");
                foreach ($rows as $row) {
                    if (($row->name ?? null) === $name) {
                        return true;
                    }
                }

                return false;
            }

            $rows = DB::select(
                'select indexname from pg_indexes where tablename = ? and indexname = ? limit 1',
                [$table, $name]
            );

            return count($rows) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'printed_sale_number')) {
                $table->string('printed_sale_number', 40)->nullable()->after('queue_no')->index();
            }

            if (! Schema::hasColumn('sales', 'printed_queue_no')) {
                $table->string('printed_queue_no', 20)->nullable()->after('printed_sale_number')->index();
            }

            if (! Schema::hasColumn('sales', 'printed_cashier_name')) {
                $table->string('printed_cashier_name', 120)->nullable()->after('printed_queue_no');
            }

            if (! Schema::hasColumn('sales', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('printed_cashier_name')->index();
            }
        });

        if (
            Schema::hasColumn('sales', 'outlet_id')
            && Schema::hasColumn('sales', 'client_sync_id')
            && ! $this->indexExists('sales', 'sales_outlet_client_sync_id_unique')
        ) {
            try {
                Schema::table('sales', function (Blueprint $table) {
                    $table->unique(['outlet_id', 'client_sync_id'], 'sales_outlet_client_sync_id_unique');
                });
            } catch (Throwable $e) {
                // Existing deployments may already have a stricter global client_sync_id unique index.
                // Duplicate-key handling in PosCheckoutService still protects idempotency.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        try {
            if ($this->indexExists('sales', 'sales_outlet_client_sync_id_unique')) {
                Schema::table('sales', function (Blueprint $table) {
                    $table->dropUnique('sales_outlet_client_sync_id_unique');
                });
            }
        } catch (Throwable $e) {
            // ignore rollback index mismatch
        }

        Schema::table('sales', function (Blueprint $table) {
            foreach (['printed_at', 'printed_cashier_name', 'printed_queue_no', 'printed_sale_number'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
