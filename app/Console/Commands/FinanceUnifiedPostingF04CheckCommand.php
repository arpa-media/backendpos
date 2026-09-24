<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceUnifiedPostingF04CheckCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f04-check';
    protected $description = 'Go-live invariant check for Unified Finance Posting F04.';

    public function handle(): int
    {
        $journal=base_path('app/Services/Finance/FinanceJournalService.php');
        $manual=base_path('app/Http/Controllers/Api/V1/Finance/FinanceManualJournalController.php');
        $bridge=base_path('app/Services/Finance/FinanceManualJournalUnifiedPostingService.php');
        $general=base_path('app/Services/Finance/FinanceGeneralPostingService.php');

        $j=is_file($journal)?file_get_contents($journal):'';
        $m=is_file($manual)?file_get_contents($manual):'';
        $b=is_file($bridge)?file_get_contents($bridge):'';
        $g=is_file($general)?file_get_contents($general):'';

        $checks=[
            'FinanceJournalService exists'=>is_file($journal),
            'General Posting service exists'=>is_file($general),
            'Manual Journal unified bridge exists'=>is_file($bridge),
            'Journal create hard guard'=>str_contains($j,'assertGeneralPostingCaller')&&str_contains($j,"sourceType !== 'GENERAL'"),
            'Journal post hard guard'=>str_contains($j,"posting GL hanya boleh dilakukan oleh General Posting"),
            'Journal reverse requires GP link'=>str_contains($j,'finance_general_posting_journals')&&str_contains($j,'belum mempunyai parent General Posting'),
            'Caller boundary to FinanceGeneralPostingService'=>str_contains($j,'FinanceGeneralPostingService::class'),
            'Manual POST delegates to unified bridge'=>str_contains($m,'unifiedManual->postDraft')&&!str_contains($m,'journalService->post('),
            'Manual reversal delegates to unified bridge'=>str_contains($m,'unifiedManual->reversePosted')&&!str_contains($m,'journalService->reverse('),
            'Manual bridge creates GP snapshot'=>str_contains($b,"'posting_origin' => 'MANUAL'")&&str_contains($b,"'posting_mode' => 'SNAPSHOT'"),
            'Manual bridge posts only via GP'=>str_contains($b,'generalPosting->postOne')&&!str_contains($b,'FinanceJournalService'),
            'General Posting owns journal create/post/reverse'=>str_contains($g,'journalService->createDraft')&&str_contains($g,'journalService->post')&&str_contains($g,'journalService->reverse'),
        ];

        foreach(['finance_journal_entries','finance_general_postings','finance_general_posting_journals'] as $table){
            $checks["Table {$table} exists"]=Schema::hasTable($table);
        }

        if(Schema::hasTable('finance_journal_entries')&&Schema::hasTable('finance_general_posting_journals')){
            $orphanOriginals=DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as g','g.journal_entry_id','=','j.id')
                ->whereIn('j.status',['POSTED','REVERSED'])
                ->whereNull('j.reversal_of_journal_id')->whereNull('g.id')->count();
            $unlinkedReversals=DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as g','g.reversal_journal_id','=','j.id')
                ->whereNotNull('j.reversal_of_journal_id')->where('j.status','POSTED')->whereNull('g.id')->count();
            $checks['Effective original GL journals without GP = 0']=$orphanOriginals===0;
            $checks['Reversal GL journals without GP history = 0']=$unlinkedReversals===0;
        }

        if(Schema::hasTable('finance_general_postings')&&Schema::hasTable('finance_general_posting_journals')){
            $broken=DB::table('finance_general_postings as gp')
                ->leftJoin('finance_general_posting_journals as l',function($join){
                    $join->on('l.general_posting_id','=','gp.id')->on('l.posting_version','=','gp.posting_version');
                })
                ->where('gp.status','POSTED')->whereNull('l.id')->count();
            $checks['POSTED General Posting without journal = 0']=$broken===0;
        }

        $rows=[];foreach($checks as $name=>$ok)$rows[]=[$name,$ok?'PASS':'FAIL'];
        $this->table(['Check','Result'],$rows);
        $passed=!in_array(false,$checks,true);
        $this->line('Status: '.($passed?'PASSED':'FAILED'));
        if(!$passed)$this->warn('Jika hanya coverage journal yang FAIL, jalankan F04 reconcile lalu check ulang.');
        return $passed?self::SUCCESS:self::FAILURE;
    }
}
