<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceUnifiedPostingF03CheckCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f03-check';
    protected $description = 'Verify F03 Reconciliation/Settlement unified posting and transaction reset finance guard.';

    public function handle(): int
    {
        $checks=[];

        $files=[
            'Reconciliation'=>base_path('app/Services/Finance/FinanceReconciliationService.php'),
            'Settlement'=>base_path('app/Services/Finance/FinanceSettlementService.php'),
            'Bulk Settlement'=>base_path('app/Services/Finance/FinanceSettlementBulkService.php'),
            'Transaction Reset'=>base_path('app/Services/Operations/TransactionResetService.php'),
        ];
        foreach($files as $name=>$file){
            $checks["{$name} source exists"]=is_file($file);
        }

        $recon=@file_get_contents($files['Reconciliation']) ?: '';
        $settlement=@file_get_contents($files['Settlement']) ?: '';
        $bulk=@file_get_contents($files['Bulk Settlement']) ?: '';
        $reset=@file_get_contents($files['Transaction Reset']) ?: '';

        $checks['Reconciliation uses General Posting']=str_contains($recon,'->stageSystem(')&&str_contains($recon,"'source_code' => 'RECONCILIATION'");
        $checks['Settlement uses General Posting']=str_contains($settlement,'->stageSystem(')&&str_contains($settlement,"'source_code' => 'SETTLEMENT'");
        $checks['Bulk Settlement uses General Posting']=str_contains($bulk,'->stageSystem(')&&str_contains($bulk,"'source_code'=>'SETTLEMENT'");

        $checks['Reconciliation no direct journal service']=!str_contains($recon,'FinanceJournalService')&&!str_contains($recon,'->createDraft(')&&!str_contains($recon,'->journalService');
        $checks['Settlement no direct journal service']=!str_contains($settlement,'FinanceJournalService')&&!str_contains($settlement,'->createDraft(')&&!str_contains($settlement,'->journalService');
        $checks['Bulk Settlement no direct journal service']=!str_contains($bulk,'FinanceJournalService')&&!str_contains($bulk,'->createDraft(')&&!str_contains($bulk,'->journalService');

        $checks['Reconciliation reopen via General Posting']=str_contains($recon,'generalPostingByJournal')&&str_contains($recon,'generalPosting->reopen');
        $checks['Settlement reopen via General Posting']=str_contains($settlement,'generalPostingByJournal')&&str_contains($settlement,'generalPosting->reopen');
        $checks['Bulk reopen via General Posting']=str_contains($bulk,'generalPostingByJournal')&&str_contains($bulk,'generalPosting->reopen');

        $checks['Reset has Finance preflight']=str_contains($reset,'assertFinancePostingResetReady')&&str_contains($reset,'financePostingResetBlockers');
        $checks['Reset blocks posted and draft GP']=str_contains($reset,"->whereIn('status',['POSTED','DRAFT'])");
        $checks['Reset checks legacy orphan journal']=str_contains($reset,'LEGACY_ORPHAN_JOURNAL')&&str_contains($reset,'finance_general_posting_journals');

        foreach(['finance_general_postings','finance_general_posting_journals','finance_journal_entries'] as $table){
            $checks["Table {$table} exists"]=Schema::hasTable($table);
        }

        if(Schema::hasTable('finance_journal_entries')&&Schema::hasTable('finance_general_posting_journals')){
            $orphan=DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as g','g.journal_entry_id','=','j.id')
                ->whereIn('j.source_type',['RECONCILIATION','SETTLEMENT'])
                ->where('j.status','POSTED')
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('g.id')
                ->count();
            $checks['Legacy F03 orphan journals = 0']=$orphan===0;
        }

        $rows=[];
        foreach($checks as $name=>$ok)$rows[]=[$name,$ok?'PASS':'FAIL'];
        $this->table(['Check','Result'],$rows);
        $passed=!in_array(false,$checks,true);
        $this->line('Status: '.($passed?'PASSED':'FAILED'));
        if(!$passed)$this->warn('Jika hanya orphan journal yang FAIL, jalankan erp-v5:finance-unified-posting-f03-reconcile lalu ulangi check.');
        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
