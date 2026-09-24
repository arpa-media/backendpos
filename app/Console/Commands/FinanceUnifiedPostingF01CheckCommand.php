<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceUnifiedPostingF01CheckCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f01-check';
    protected $description = 'Verify Unified General Posting F01 core and Purchasing routing.';

    public function handle(): int
    {
        $service = @file_get_contents(app_path('Services/Finance/FinancePurchasingPostingService.php')) ?: '';
        $general = @file_get_contents(app_path('Services/Finance/FinanceGeneralPostingService.php')) ?: '';
        $checks = [
            'General Posting tables' => Schema::hasTable('finance_general_postings') && Schema::hasTable('finance_general_posting_journals'),
            'AUTO snapshot system template' => Schema::hasTable('finance_posting_templates') && DB::table('finance_posting_templates')->where('code','SYS-GENERAL-AUTO-SNAPSHOT')->where('is_active',true)->exists(),
            'General Posting supports stageSystem' => str_contains($general, 'function stageSystem('),
            'General Posting supports legacy adoption' => str_contains($general, 'function adoptExistingJournal('),
            'General Posting delete after unpost' => str_contains($general, 'Journal asli + reversal sengaja dipertahankan'),
            'Purchasing routes through General Posting' => substr_count($service, '->stageSystem(') >= 3,
            'No direct Purchasing createDraft to GL' => ! str_contains($service, '$this->journal->createDraft('),
        ];
        if (Schema::hasTable('access_menus')) {
            $checks['General Posting Access Matrix'] = DB::table('access_menus')->where('path','/finance/general-posting')->where('is_active',true)->exists();
        }
        if (Schema::hasTable('finance_journal_entries') && Schema::hasTable('finance_general_posting_journals')) {
            $orphan = DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as g','g.journal_entry_id','=','j.id')
                ->where('j.status','POSTED')->whereNull('j.reversal_of_journal_id')->whereNull('g.id')
                ->where(function($q):void{$q->where('j.source_type','PURCHASING')->orWhere('j.source_key','like','FIN-PUR-%');})
                ->count();
            $checks['Legacy Purchasing active GL adopted'] = $orphan===0;
        }
        $rows=[];$ok=true;foreach($checks as $name=>$pass){$rows[]=[$name,$pass?'PASS':'FAIL'];$ok=$ok&&$pass;}
        $this->table(['Check','Result'],$rows);$this->line('Status: '.($ok?'PASSED':'FAILED'));
        return $ok?self::SUCCESS:self::FAILURE;
    }
}
