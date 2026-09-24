<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I06CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i06-check {--from=} {--to=}';
    protected $description = 'Health check ERP Finance V7 Iteration 06 (journal template, balance sheet, GL period, reconciliation overhandle)';

    public function handle(): int
    {
        $required = [
            'finance_journal_entries',
            'finance_journal_entry_lines',
            'finance_posting_templates',
            'finance_reconciliations',
            'finance_reconciliation_payments',
            'finance_overhandle_reports',
        ];

        foreach ($required as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("MISSING TABLE: {$table}");
                return self::FAILURE;
            }
        }

        $selectableTemplates = DB::table('finance_posting_templates')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->where('source_type', 'MANUAL_JOURNAL');
                if (Schema::hasColumn('finance_posting_templates', 'manual_selectable')) {
                    $q->orWhere('manual_selectable', true);
                }
            })
            ->count();

        $missingOverhandle = DB::table('finance_reconciliations')
            ->whereIn('status', ['DRAFT', 'POSTED'])
            ->where('has_overhandle', false)
            ->count();
        $legacyNonZeroOverhandle = DB::table('finance_reconciliations')
            ->where('has_overhandle', false)
            ->whereRaw('ABS(COALESCE(overhandle_total, 0)) > 0.005')
            ->count();

        $this->table(
            ['Check', 'Result'],
            [
                ['Manual-selectable journal templates', (string) $selectableTemplates],
                ['Reconciliation without Overhandle Report', (string) $missingOverhandle],
                ['Legacy missing-report rows with stored non-zero OH', (string) $legacyNonZeroOverhandle],
            ]
        );

        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        if ($from !== '' || $to !== '') {
            if ($from === '' || $to === '') {
                $this->error('Gunakan --from dan --to bersamaan (YYYY-MM-DD).');
                return self::FAILURE;
            }

            $period = DB::table('finance_journal_entry_lines as l')
                ->join('finance_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.status', 'POSTED')
                ->whereNull('e.reversal_of_journal_id')
                ->whereNull('e.reversal_journal_id')
                ->whereDate('e.journal_date', '>=', $from)
                ->whereDate('e.journal_date', '<=', $to)
                ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit, COUNT(DISTINCT e.id) as journals')
                ->first();

            $this->table(
                ["GL Journal Date {$from}..{$to}", 'Value'],
                [
                    ['Journal Count', (string) ($period->journals ?? 0)],
                    ['Period Debit', number_format((float) ($period->debit ?? 0), 2, '.', '')],
                    ['Period Credit', number_format((float) ($period->credit ?? 0), 2, '.', '')],
                ]
            );
        }

        if ($selectableTemplates === 0) {
            $this->warn('Tidak ada Jurnal Template aktif yang selectable untuk Manual Journal. Buat/aktifkan template sebelum test Preview & Terapkan.');
        }
        if ($legacyNonZeroOverhandle > 0) {
            $this->warn('Ada data legacy tanpa Overhandle Report tetapi nilai OH tersimpan non-zero. I06 menampilkan nilai 0 secara canonical; buka draft lalu Refresh Source untuk menyelaraskan snapshot tersimpan.');
        }

        $this->info('I06 health check selesai.');
        return self::SUCCESS;
    }
}
