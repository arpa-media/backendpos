<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceGeneralPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinanceUnifiedPostingF02ReconcileCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f02-reconcile {--dry-run} {--domain=} {--limit=1000}';
    protected $description = 'Adopt legacy COGS, Payroll, and Reimburse direct-to-GL journals into Unified General Posting.';

    public function handle(FinanceGeneralPostingService $general): int
    {
        foreach (['finance_journal_entries','finance_general_postings','finance_general_posting_journals'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table {$table}. Apply Finance Unified Posting F01 first.");
                return self::FAILURE;
            }
        }
        if (! Schema::hasTable('finance_posting_templates') || ! DB::table('finance_posting_templates')->where('code','SYS-GENERAL-AUTO-SNAPSHOT')->where('is_active',true)->exists()) {
            $this->error('System template SYS-GENERAL-AUTO-SNAPSHOT belum tersedia. Apply F01 terlebih dahulu.');
            return self::FAILURE;
        }

        $domain = strtoupper(trim((string)$this->option('domain')));
        $allowed = ['COGS','PAYROLL','REIMBURSE'];
        if ($domain !== '' && ! in_array($domain,$allowed,true)) {
            $this->error('Domain harus COGS, PAYROLL, REIMBURSE, atau kosong untuk semua domain.');
            return self::FAILURE;
        }
        $sourceTypes = match ($domain) {
            'COGS' => ['COGS'],
            'PAYROLL' => ['PAYROLL'],
            'REIMBURSE' => ['PURCH_REIMBURSE'],
            default => ['COGS','PAYROLL','PURCH_REIMBURSE'],
        };
        $limit = max(1,min(5000,(int)$this->option('limit')));

        $rows = DB::table('finance_journal_entries as j')
            ->leftJoin('finance_general_posting_journals as gl','gl.journal_entry_id','=','j.id')
            ->whereIn('j.source_type',$sourceTypes)
            ->where('j.status','POSTED')
            ->whereNull('j.reversal_of_journal_id')
            ->whereNull('gl.id')
            ->orderBy('j.journal_date')->orderBy('j.journal_no')
            ->limit($limit)
            ->get(['j.id','j.journal_no','j.journal_date','j.source_type','j.source_key','j.reference_no','j.total_debit']);

        $this->table(['Journal','Date','Domain','Source Key','Amount'], $rows->map(fn($r)=>[
            $r->journal_no,$r->journal_date,$this->domainLabel((string)$r->source_type),$r->source_key ?: '-',number_format((float)$r->total_debit,2,'.',','),
        ])->all());
        $this->info('Legacy F02 journals needing General Posting adoption: '.$rows->count());
        foreach ($rows->groupBy(fn($r)=>$this->domainLabel((string)$r->source_type)) as $name=>$group) {
            $this->line(" - {$name}: ".$group->count());
        }
        if ($this->option('dry-run')) return self::SUCCESS;

        $ok=0;$failed=0;
        foreach ($rows as $row) {
            try {
                $general->adoptExistingJournal((string)$row->id,null);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn($row->journal_no.' · '.$e->getMessage());
            }
        }
        $this->info("Adopted {$ok}; failed {$failed}.");
        return $failed===0 ? self::SUCCESS : self::FAILURE;
    }

    private function domainLabel(string $sourceType): string
    {
        return match (strtoupper($sourceType)) {
            'COGS' => 'COGS',
            'PAYROLL' => 'PAYROLL',
            'PURCH_REIMBURSE' => 'REIMBURSE',
            default => strtoupper($sourceType),
        };
    }
}
