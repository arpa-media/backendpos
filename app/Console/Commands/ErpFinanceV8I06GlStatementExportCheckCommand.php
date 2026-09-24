<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceOpenXmlXlsxWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpFinanceV8I06GlStatementExportCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i06-gl-statement-export-check {--rows=1500 : Synthetic XLSX rows used for writer smoke test}';
    protected $description = 'ERP FINANCE V8 I06 health gate for General Ledger UX and Financial Statement Export consolidation.';

    public function handle(FinanceOpenXmlXlsxWriter $writer): int
    {
        $checks = [];
        foreach (['finance_chart_of_accounts','finance_journal_entries','finance_journal_entry_lines','finance_financial_statement_rules'] as $table) {
            $checks["Table {$table}"] = Schema::hasTable($table);
        }

        foreach ([
            'finance.statement-export.options',
            'finance.statement-export.balance-sheet',
            'finance.statement-export.profit-loss',
            'finance.statement-export.cash-flow',
        ] as $route) {
            $checks["Route {$route}"] = Route::has($route);
        }

        if (Schema::hasTable('access_menus')) {
            $exportMenu = DB::table('access_menus')
                ->where('code', 'finance-financial-statement-export')
                ->where('path', '/finance/financial-statement-export')
                ->where('is_active', true)
                ->first();
            $checks['Export Center menu active'] = (bool) $exportMenu;
            $checks['Export Center view permission'] = (string) ($exportMenu->permission_view ?? '') === 'finance.financial_statement_export.view';
            $checks['Export Center Create=Download permission'] = (string) ($exportMenu->permission_create ?? '') === 'finance.financial_statement_export.download';

            $warehouseGl = DB::table('access_menus')->where('path', '/warehouse/finance/general-ledger')->where('is_active', true)->first();
            $checks['Warehouse GL menu active'] = (bool) $warehouseGl;
            $checks['Warehouse GL view permission retained'] = (string) ($warehouseGl->permission_view ?? '') === 'warehouse.finance.general_ledger.view';
            $checks['Warehouse GL Create=Export retained'] = (string) ($warehouseGl->permission_create ?? '') === 'warehouse.finance.general_ledger.export';
        } else {
            foreach (['Export Center menu active','Export Center view permission','Export Center Create=Download permission','Warehouse GL menu active','Warehouse GL view permission retained','Warehouse GL Create=Export retained'] as $name) {
                $checks[$name] = false;
            }
        }

        if (Schema::hasTable('permissions')) {
            $checks['Permission export view'] = DB::table('permissions')->where('name', 'finance.financial_statement_export.view')->exists();
            $checks['Permission export download'] = DB::table('permissions')->where('name', 'finance.financial_statement_export.download')->exists();
        } else {
            $checks['Permission export view'] = false;
            $checks['Permission export download'] = false;
        }

        $coaCount = Schema::hasTable('finance_chart_of_accounts')
            ? DB::table('finance_chart_of_accounts')->where('is_active', true)->count()
            : 0;
        $this->line("Active Finance COA: {$coaCount}. Financial Statement XLSX is account/section aggregate output, not raw transaction-ledger export.");

        try {
            $rowCount = min(5000, max(100, (int) $this->option('rows')));
            $rows = [[
                'cells' => [
                    FinanceOpenXmlXlsxWriter::cell('ERP FINANCE V8 I06 XLSX MEMORY SMOKE', FinanceOpenXmlXlsxWriter::STYLE_TITLE, 's'),
                ],
            ], [
                'cells' => [
                    FinanceOpenXmlXlsxWriter::cell('Account', FinanceOpenXmlXlsxWriter::STYLE_HEADER, 's'),
                    FinanceOpenXmlXlsxWriter::cell('Amount', FinanceOpenXmlXlsxWriter::STYLE_HEADER, 's'),
                ],
            ]];
            for ($i = 1; $i <= $rowCount; $i++) {
                $rows[] = ['cells' => [
                    FinanceOpenXmlXlsxWriter::cell('Synthetic COA '.$i, FinanceOpenXmlXlsxWriter::STYLE_TEXT, 's'),
                    FinanceOpenXmlXlsxWriter::cell((float) $i, FinanceOpenXmlXlsxWriter::STYLE_NUMBER, 'n'),
                ]];
            }

            $before = memory_get_usage(true);
            $path = storage_path('app/tmp/erp-finance-v8-i06-statement-smoke.xlsx');
            $writer->write($path, 'I06 Smoke', $rows, ['A1:B1'], [1 => 28, 2 => 20], 2, 'ERP Finance I06');
            $peakDelta = max(0, memory_get_peak_usage(true) - $before);
            $prefix = is_file($path) ? file_get_contents($path, false, null, 0, 2) : '';
            $checks['XLSX writer PK signature'] = $prefix === 'PK' && filesize($path) > 1000;
            $checks['XLSX writer bounded smoke (<64 MiB delta)'] = $peakDelta < (64 * 1024 * 1024);
            $this->line(sprintf('Synthetic XLSX rows: %d; peak memory delta: %.2f MiB.', $rowCount, $peakDelta / 1048576));
            @unlink($path);
        } catch (Throwable $e) {
            $checks['XLSX writer PK signature'] = false;
            $checks['XLSX writer bounded smoke (<64 MiB delta)'] = false;
            $this->warn('XLSX writer smoke: '.$e->getMessage());
        }

        $rows = collect($checks)->map(fn ($ok, $name) => [$name, $ok ? 'OK' : 'FAILED']);
        $this->table(['Check', 'Result'], $rows->values()->all());
        if ($rows->contains(fn ($row) => $row[1] !== 'OK')) {
            $this->error('ERP FINANCE V8 I06 health gate FAILED.');
            return self::FAILURE;
        }

        $this->info('ERP FINANCE V8 I06 health gate OK.');
        return self::SUCCESS;
    }
}
