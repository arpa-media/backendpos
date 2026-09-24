<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceHotfix03dRepairResetLedgerCommand extends Command
{
    protected $signature = 'finance:hotfix-03d-repair-reset-ledger {--dry-run : Tampilkan perubahan tanpa update}';
    protected $description = 'Samakan journal_date reversal hasil Finance Reset dengan journal_date jurnal asal agar GL periode sumber netral.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $fixed = 0;

        if (! Schema::hasTable('finance_journal_entries')) {
            $this->error('finance_journal_entries tidak tersedia.');
            return self::FAILURE;
        }

        // Reconciliation reset/archive.
        if (Schema::hasTable('finance_reconciliations') && Schema::hasTable('finance_reconciliation_postings')) {
            $items = DB::table('finance_reconciliation_postings as p')
                ->join('finance_reconciliations as r', 'r.id', '=', 'p.reconciliation_id')
                ->join('finance_journal_entries as original', 'original.id', '=', 'p.journal_entry_id')
                ->join('finance_journal_entries as reversal', 'reversal.id', '=', 'p.reversal_journal_id')
                ->where('r.status', 'CANCELLED')
                ->whereNotNull('p.reversal_journal_id')
                ->whereColumn('reversal.journal_date', '<>', 'original.journal_date')
                ->get(['r.reconciliation_no as document_no','p.marking','original.journal_date as original_date','reversal.journal_date as reversal_date','reversal.id as reversal_id']);
            foreach ($items as $row) {
                $rows[] = ['RECONCILIATION',(string)$row->document_no,(string)$row->marking,(string)$row->reversal_date.' → '.(string)$row->original_date];
                if (! $dry) DB::table('finance_journal_entries')->where('id',$row->reversal_id)->update(['journal_date'=>$row->original_date,'updated_at'=>now()]);
                $fixed++;
            }
        }

        // Single settlement reset/archive.
        if (Schema::hasTable('finance_settlements')) {
            $items = DB::table('finance_settlements as s')
                ->join('finance_journal_entries as reversal', 'reversal.id', '=', 's.reversal_journal_id')
                ->join('finance_journal_entries as original', 'original.id', '=', 'reversal.reversal_of_journal_id')
                ->where('s.status', 'CANCELLED')
                ->whereNotNull('s.reversal_journal_id')
                ->whereColumn('reversal.journal_date', '<>', 'original.journal_date')
                ->get(['s.settlement_no as document_no','s.marking','original.journal_date as original_date','reversal.journal_date as reversal_date','reversal.id as reversal_id']);
            foreach ($items as $row) {
                $rows[] = ['SETTLEMENT',(string)$row->document_no,(string)$row->marking,(string)$row->reversal_date.' → '.(string)$row->original_date];
                if (! $dry) DB::table('finance_journal_entries')->where('id',$row->reversal_id)->update(['journal_date'=>$row->original_date,'updated_at'=>now()]);
                $fixed++;
            }
        }

        // Bulk settlement reset/archive.
        if (Schema::hasTable('finance_settlement_batches') && Schema::hasTable('finance_settlement_bulk_journals')) {
            $items = DB::table('finance_settlement_bulk_journals as bj')
                ->join('finance_settlement_batches as b', 'b.id', '=', 'bj.batch_id')
                ->join('finance_journal_entries as original', 'original.id', '=', 'bj.journal_entry_id')
                ->join('finance_journal_entries as reversal', 'reversal.id', '=', 'bj.reversal_journal_id')
                ->where('b.status', 'CANCELLED')
                ->whereNotNull('bj.reversal_journal_id')
                ->whereColumn('reversal.journal_date', '<>', 'original.journal_date')
                ->get(['b.batch_no as document_no','bj.marking','original.journal_date as original_date','reversal.journal_date as reversal_date','reversal.id as reversal_id']);
            foreach ($items as $row) {
                $rows[] = ['SETTLEMENT BULK',(string)$row->document_no,(string)$row->marking,(string)$row->reversal_date.' → '.(string)$row->original_date];
                if (! $dry) DB::table('finance_journal_entries')->where('id',$row->reversal_id)->update(['journal_date'=>$row->original_date,'updated_at'=>now()]);
                $fixed++;
            }
        }

        if ($rows) $this->table(['Type','Document','Marking','Journal Date'], $rows);
        $this->info(($dry ? '[DRY RUN] ' : '')."Reset reversal journal yang perlu diselaraskan: {$fixed}");
        $this->line('Business date tidak diubah. Debit/credit dan audit reversal tidak diubah.');
        return self::SUCCESS;
    }
}
