<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpPosFinalI02FinanceSinglePostingSourceCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i02-finance-single-posting-source-check {--allow-orphans : Do not fail when historical detached GL still needs F04 reconcile}';
    protected $description = 'Validate I02 General Posting single-source accounting contract and historical reconcile readiness.';

    public function handle(): int
    {
        $checks = [];
        foreach ([
            'finance_journal_entries',
            'finance_journal_entry_lines',
            'finance_general_postings',
            'finance_general_posting_journals',
        ] as $table) {
            $checks["Table {$table}"] = Schema::hasTable($table);
        }

        $root = base_path();
        $gate = $this->read($root.'/app/Support/Finance/FinanceGeneralPostingReadGate.php');
        $ledger = $this->read($root.'/app/Services/Finance/FinanceGeneralLedgerService.php');
        $statement = $this->read($root.'/app/Services/Finance/FinanceFinancialStatementService.php');
        $journal = $this->read($root.'/app/Services/Finance/FinanceJournalService.php');
        $purchasing = $this->read($root.'/app/Services/Finance/FinancePurchasingPostingService.php');
        $gp = $this->read($root.'/app/Services/Finance/FinanceGeneralPostingService.php');
        $controller = $this->read($root.'/app/Http/Controllers/Api/V1/Finance/FinanceGeneralPostingController.php');
        $route = $this->read($root.'/routes/finance_modules/99-erp-pos-final-i02-general-posting-single-source.php');

        $checks['Canonical read gate requires POSTED General Posting'] = str_contains($gate, "->where('gp_i02.status', 'POSTED')")
            && str_contains($gate, "whereColumn('gpjl_i02.posting_version', 'gp_i02.posting_version')")
            && str_contains($gate, "whereNull('gpjl_i02.reversal_journal_id')");
        $checks['General Ledger uses canonical active/audit gate'] = str_contains($ledger, 'FinanceGeneralPostingReadGate::applyActive')
            && str_contains($ledger, 'FinanceGeneralPostingReadGate::applyAuditLinked');
        $checks['Financial Statements use canonical active gate'] = str_contains($statement, 'FinanceGeneralPostingReadGate::applyActive');
        $checks['Journal runtime guard enforces General Posting caller'] = str_contains($journal, 'assertGeneralPostingCaller');
        $checks['Purchasing has no direct FinanceJournalService bypass'] = ! str_contains($purchasing, 'FinanceJournalService')
            && ! str_contains($purchasing, '$this->journal->reverse');
        $checks['Historical GP cannot be physically deleted'] = str_contains($gp, 'General Posting yang sudah memiliki histori GL tidak boleh dihapus')
            && str_contains($gp, "'can_delete_draft'");
        $checks['Historical reconcile API invokes existing F04 command'] = str_contains($controller, "erp-v5:finance-unified-posting-f04-reconcile")
            && str_contains($route, 'reconcile-historical');

        $dbReady = collect($checks)->take(4)->every(fn ($ok) => $ok === true);
        $orphan = null;
        $orphanOriginals = null;
        $unlinkedReversals = null;
        $eligible = null;
        $invalidEligible = null;
        if ($dbReady) {
            $orphanOriginals = (int) DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->whereIn('j.status', ['POSTED', 'REVERSED'])
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('l.id')
                ->count();

            $unlinkedReversals = (int) DB::table('finance_journal_entries as r')
                ->join('finance_journal_entries as o', 'o.id', '=', 'r.reversal_of_journal_id')
                ->join('finance_general_posting_journals as gl', 'gl.journal_entry_id', '=', 'o.id')
                ->leftJoin('finance_general_posting_journals as rl', 'rl.reversal_journal_id', '=', 'r.id')
                ->where('r.status', 'POSTED')
                ->whereNotNull('r.reversal_of_journal_id')
                ->whereNull('rl.id')
                ->distinct()->count('r.id');
            $orphan = $orphanOriginals + $unlinkedReversals;

            $eligibleQuery = DB::table('finance_journal_entries as j')
                ->join('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->join('finance_general_postings as g', 'g.id', '=', 'l.general_posting_id')
                ->where('j.status', 'POSTED')
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('j.reversal_journal_id')
                ->where('g.status', 'POSTED')
                ->whereColumn('l.posting_version', 'g.posting_version')
                ->whereNull('l.reversal_journal_id');
            $eligible = (int) (clone $eligibleQuery)->distinct()->count('j.id');

            $invalidEligible = (int) DB::table('finance_journal_entries as j')
                ->join('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->join('finance_general_postings as g', 'g.id', '=', 'l.general_posting_id')
                ->where('j.status', 'POSTED')
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('j.reversal_journal_id')
                ->where(function ($q): void {
                    $q->where('g.status', '<>', 'POSTED')
                        ->orWhereColumn('l.posting_version', '<>', 'g.posting_version')
                        ->orWhereNotNull('l.reversal_journal_id');
                })->count();

            $checks['Historical detached GL reconciled'] = $this->option('allow-orphans') || $orphan === 0;
        }

        $rows = [];
        foreach ($checks as $name => $ok) {
            $rows[] = [$ok ? 'PASS' : 'FAIL', $name];
        }
        $this->table(['Status', 'Check'], $rows);

        if ($orphan !== null) {
            $this->line('Detached historical GL : '.$orphan.' (original '.$orphanOriginals.', reversal-link '.$unlinkedReversals.')');
            $this->line('Report-eligible journals : '.$eligible);
            $this->line('Linked but currently ineligible (draft/version/reversal) : '.$invalidEligible);
            if ($orphan > 0) {
                $this->warn('Historical detached GL masih ada. Jalankan Reconcile Historical GL dari UI atau: php artisan erp-v5:finance-unified-posting-f04-reconcile');
            }
        }

        $failed = collect($checks)->contains(fn ($ok) => $ok !== true);
        if ($failed) {
            $this->error('ERP POS FINAL I02 validation FAILED.');
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I02 validation PASS. Accounting reports are gated by active POSTED General Posting lineage.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
