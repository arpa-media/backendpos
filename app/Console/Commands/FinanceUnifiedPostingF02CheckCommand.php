<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceUnifiedPostingF02CheckCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f02-check';
    protected $description = 'Verify Unified General Posting F02 routing for COGS, Payroll, and Reimburse.';

    public function handle(): int
    {
        $cogs = @file_get_contents(app_path('Services/Finance/FinanceCogsPostingService.php')) ?: '';
        $payroll = @file_get_contents(app_path('Services/Finance/FinancePayrollPostingService.php')) ?: '';
        $reimburse = @file_get_contents(app_path('Services/Purchasing/ReimbursePayableService.php')) ?: '';
        $general = @file_get_contents(app_path('Services/Finance/FinanceGeneralPostingService.php')) ?: '';

        $checks = [
            'F01 General Posting stageSystem available' => str_contains($general,'function stageSystem('),
            'COGS routes through General Posting' => substr_count($cogs,'->stageSystem(') >= 1,
            'COGS no direct journal create/post/reverse' => ! str_contains($cogs,'journalService->createDraft') && ! str_contains($cogs,'journalService->post') && ! str_contains($cogs,'journalService->reverse'),
            'Payroll accrual + payment route through General Posting' => substr_count($payroll,'->stageSystem(') >= 2,
            'Payroll no direct journal create/post' => ! str_contains($payroll,'journalService->createDraft') && ! str_contains($payroll,'journalService->post'),
            'Reimburse recognition + settlement route through General Posting' => substr_count($reimburse,'->stageSystem(') >= 2,
            'Reimburse no direct journal create/post' => ! str_contains($reimburse,'journalService->createDraft') && ! str_contains($reimburse,'journalService->post'),
            'General Posting tables' => Schema::hasTable('finance_general_postings') && Schema::hasTable('finance_general_posting_journals'),
            'AUTO snapshot template' => Schema::hasTable('finance_posting_templates') && DB::table('finance_posting_templates')->where('code','SYS-GENERAL-AUTO-SNAPSHOT')->where('is_active',true)->exists(),
        ];

        if (Schema::hasTable('access_menus')) {
            $checks['General Posting Access Matrix'] = DB::table('access_menus')->where('path','/finance/general-posting')->where('is_active',true)->exists();
        }
        if (Schema::hasTable('finance_journal_entries') && Schema::hasTable('finance_general_posting_journals')) {
            $orphan = DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as g','g.journal_entry_id','=','j.id')
                ->where('j.status','POSTED')->whereNull('j.reversal_of_journal_id')->whereNull('g.id')
                ->whereIn('j.source_type',['COGS','PAYROLL','PURCH_REIMBURSE'])
                ->count();
            $checks['Legacy F02 active GL adopted'] = $orphan===0;
        }

        $rows=[];$ok=true;
        foreach($checks as $name=>$pass){$rows[]=[$name,$pass?'PASS':'FAIL'];$ok=$ok&&$pass;}
        $this->table(['Check','Result'],$rows);
        $this->line('Status: '.($ok?'PASSED':'FAILED'));
        return $ok?self::SUCCESS:self::FAILURE;
    }
}
