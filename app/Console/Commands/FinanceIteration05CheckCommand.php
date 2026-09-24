<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration05CheckCommand extends Command
{
    protected $signature = 'finance:iteration-05-check';
    protected $description = 'Smoke-check Finance Iterasi 05 Daily Reconciliation.';

    public function handle(): int
    {
        $failed = [];
        $tables = [
            'finance_reconciliations', 'finance_reconciliation_payments',
            'finance_reconciliation_payment_allocations', 'finance_reconciliation_scope_summaries',
            'finance_reconciliation_postings',
        ];
        foreach ($tables as $table) if (! Schema::hasTable($table)) $failed[] = "Missing table {$table}";

        foreach ([
            'finance.iter05.reconciliation.options','finance.iter05.reconciliation.source','finance.iter05.reconciliation.index',
            'finance.iter05.reconciliation.draft','finance.iter05.reconciliation.show','finance.iter05.reconciliation.update',
            'finance.iter05.reconciliation.refresh','finance.iter05.reconciliation.preview','finance.iter05.reconciliation.post',
            'finance.iter05.reconciliation.reopen','finance.iter05.reconciliation.destroy',
        ] as $route) if (! Route::has($route)) $failed[] = "Missing route {$route}";

        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('code','finance-reconciliation')->where('is_active',true)->first();
            if (! $menu) $failed[] = 'Access Matrix menu finance-reconciliation belum aktif.';
        }
        if (Schema::hasTable('finance_posting_templates')) {
            foreach (['MARKING','UNMARKING'] as $marking) {
                $template = DB::table('finance_posting_templates')->whereNull('deleted_at')->where('is_active',true)
                    ->where('source_type','RECONCILIATION')->where('marking',$marking)->exists();
                if (! $template) $failed[] = "Template RECONCILIATION {$marking} tidak tersedia.";
            }
        }
        if (Schema::hasTable('finance_chart_of_accounts')) {
            foreach (['1-10400','4-40000','4-40100','2-20603','7-70099','8-80999'] as $code) {
                if (! DB::table('finance_chart_of_accounts')->where('code',$code)->where('is_active',true)->where('is_postable',true)->exists()) {
                    $failed[] = "COA default {$code} tidak aktif/postable.";
                }
            }
        }

        $this->table(['Check','Result'], [
            ['Reconciliation tables', collect($tables)->every(fn($t)=>Schema::hasTable($t)) ? 'OK' : 'FAILED'],
            ['Named routes', collect(['finance.iter05.reconciliation.index','finance.iter05.reconciliation.post'])->every(fn($r)=>Route::has($r)) ? 'OK' : 'FAILED'],
            ['Access Matrix', Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','finance-reconciliation')->where('is_active',true)->exists() ? 'OK' : 'FAILED'],
            ['Default templates', Schema::hasTable('finance_posting_templates') && DB::table('finance_posting_templates')->whereNull('deleted_at')->where('source_type','RECONCILIATION')->whereIn('marking',['MARKING','UNMARKING'])->where('is_active',true)->count() >= 2 ? 'OK' : 'FAILED'],
            ['Status', $failed ? 'FAILED' : 'PASSED'],
        ]);
        foreach ($failed as $message) $this->error($message);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
