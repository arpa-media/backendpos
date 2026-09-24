<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceGeneralPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinanceUnifiedPostingF01ReconcileCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f01-reconcile {--dry-run} {--limit=500}';
    protected $description = 'Adopt legacy Purchasing direct-to-GL journals into General Posting without creating duplicate GL entries.';

    public function handle(FinanceGeneralPostingService $general): int
    {
        foreach (['finance_journal_entries','finance_general_postings','finance_general_posting_journals'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table {$table}.");
                return self::FAILURE;
            }
        }

        $limit = max(1, min(5000, (int) $this->option('limit')));
        $ids = collect();
        if (Schema::hasTable('finance_purchasing_posting_journals')) {
            $ids = $ids->merge(DB::table('finance_purchasing_posting_journals')->whereNotNull('journal_entry_id')->pluck('journal_entry_id'));
        }
        if (Schema::hasTable('finance_purchasing_auto_events')) {
            $ids = $ids->merge(DB::table('finance_purchasing_auto_events')->where('status','POSTED')->whereNotNull('journal_entry_id')->pluck('journal_entry_id'));
        }
        $ids = $ids->merge(
            DB::table('finance_journal_entries')
                ->whereNull('reversal_of_journal_id')
                ->where(function ($q): void {
                    $q->where('source_type','PURCHASING')->orWhere('source_key','like','FIN-PUR-%');
                })
                ->pluck('id')
        )->filter()->unique()->values();

        $rows = DB::table('finance_journal_entries as j')
            ->leftJoin('finance_general_posting_journals as gl','gl.journal_entry_id','=','j.id')
            ->whereIn('j.id',$ids)
            ->where('j.status','POSTED')
            ->whereNull('j.reversal_of_journal_id')
            ->whereNull('gl.id')
            ->orderBy('j.journal_date')
            ->limit($limit)
            ->get(['j.id','j.journal_no','j.journal_date','j.source_type','j.source_key','j.reference_no','j.total_debit']);

        $this->table(['Journal','Date','Source','Source Key','Amount'], $rows->map(fn($r)=>[
            $r->journal_no,$r->journal_date,$r->source_type,$r->source_key ?: '-',number_format((float)$r->total_debit,2,'.',','),
        ])->all());
        $this->info('Legacy Purchasing journals needing General Posting adoption: '.$rows->count());
        if ($this->option('dry-run')) return self::SUCCESS;

        $ok=0;$failed=0;
        foreach ($rows as $row) {
            try {
                $general->adoptExistingJournal((string)$row->id, null);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn($row->journal_no.' · '.$e->getMessage());
            }
        }
        $this->info("Adopted {$ok}; failed {$failed}.");
        return $failed===0 ? self::SUCCESS : self::FAILURE;
    }
}
