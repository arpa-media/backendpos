<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpV5I06Hotfix01IndexCheckCommand extends Command
{
    protected $signature = 'erp-v5:i06-hotfix01-index-check';
    protected $description = 'Validasi recovery index pur_invoice_liability_ownerships setelah MySQL identifier length error.';

    public function handle(): int
    {
        $table = 'pur_invoice_liability_ownerships';
        if (! Schema::hasTable($table)) {
            $this->error("Tabel {$table} belum tersedia. Jalankan php artisan migrate terlebih dahulu.");
            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $name = (string) $row->INDEX_NAME;
            if (! isset($indexes[$name])) {
                $indexes[$name] = [
                    'unique' => ((int) $row->NON_UNIQUE) === 0,
                    'columns' => [],
                ];
            }
            $indexes[$name]['columns'][] = (string) $row->COLUMN_NAME;
        }

        $required = [
            [['invoice_id'], true, 'invoice unique'],
            [['warehouse_outgoing_invoice_id'], true, 'warehouse outgoing invoice unique'],
            [['canonical_recognition_event_key'], false, 'canonical recognition event'],
            [['canonical_general_posting_id'], false, 'canonical general posting'],
            [['stock_request_id', 'coverage_status'], false, 'stock request + coverage'],
            [['order_kind', 'order_id', 'coverage_status'], false, 'order + coverage'],
        ];

        $result = [];
        $failed = false;
        foreach ($required as [$columns, $unique, $label]) {
            $found = false;
            $foundName = '-';
            foreach ($indexes as $name => $index) {
                if ($index['unique'] === $unique && $index['columns'] === $columns) {
                    $found = true;
                    $foundName = $name;
                    break;
                }
            }
            $result[] = [$label, implode(', ', $columns), $unique ? 'UNIQUE' : 'INDEX', $found ? 'OK' : 'FAILED', $foundName];
            $failed = $failed || ! $found;
        }

        $tooLong = array_values(array_filter(array_keys($indexes), static fn (string $name): bool => strlen($name) > 64));
        $result[] = ['Identifier <= 64 chars', '-', '-', $tooLong === [] ? 'OK' : 'FAILED', $tooLong ? implode(', ', $tooLong) : '-'];
        $failed = $failed || $tooLong !== [];

        $this->table(['Check', 'Columns', 'Type', 'Result', 'Index Name'], $result);
        $this->newLine();
        $this->line('Status: ' . ($failed ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
