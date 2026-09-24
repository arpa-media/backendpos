<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration03CheckCommand extends Command
{
    protected $signature = 'finance:iteration-03-check';
    protected $description = 'Smoke check Finance Iterasi 03: COA, scope PT/outlet, manual journal, dan journal template.';

    public function handle(): int
    {
        $requiredTables = [
            'finance_companies',
            'finance_outlet_company_mappings',
            'finance_chart_of_accounts',
            'finance_journal_entries',
            'finance_journal_entry_lines',
            'finance_posting_templates',
            'finance_posting_template_lines',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table) => ! Schema::hasTable($table)));

        $requiredRoutes = [
            'finance.iter03.coa.index',
            'finance.iter03.scope.outlets.index',
            'finance.iter03.journal.index',
            'finance.iter03.journal.store',
            'finance.iter03.journal.post',
            'finance.iter03.journal.reverse',
            'finance.iter03.template.index',
            'finance.iter03.template.preview',
        ];
        $missingRoutes = array_values(array_filter($requiredRoutes, fn (string $route) => ! Route::has($route)));

        $requiredMenus = [
            'finance-chart-of-accounts' => '/finance/chart-of-accounts',
            'finance-manual-journal' => '/finance/manual-journal',
            'finance-journal-templates' => '/finance/journal-templates',
        ];
        $menuProblems = [];
        $financePortalId = Schema::hasTable('access_portals') ? DB::table('access_portals')->where('code', 'finance')->value('id') : null;
        if (! Schema::hasTable('access_menus')) {
            $menuProblems[] = 'access_menus table missing';
        } else {
            foreach ($requiredMenus as $code => $path) {
                $row = DB::table('access_menus')->where('code', $code)->first(['code', 'path', 'portal_id', 'is_active']);
                if (! $row) {
                    $menuProblems[] = "{$code}: missing";
                    continue;
                }
                if ((string) $row->path !== $path || (string) $row->portal_id !== (string) $financePortalId || ! (bool) $row->is_active) {
                    $menuProblems[] = "{$code}: metadata invalid";
                }
            }
        }

        $requiredPermissions = [
            'finance.coa.view', 'finance.coa.create', 'finance.coa.update', 'finance.coa.delete',
            'finance.journal.view', 'finance.journal.create', 'finance.journal.update', 'finance.journal.delete', 'finance.journal.post', 'finance.journal.reverse',
            'finance.journal_template.view', 'finance.journal_template.create', 'finance.journal_template.update', 'finance.journal_template.delete',
        ];
        $missingPermissions = [];
        if (Schema::hasTable('permissions')) {
            $existing = DB::table('permissions')->whereIn('name', $requiredPermissions)->pluck('name')->all();
            $missingPermissions = array_values(array_diff($requiredPermissions, $existing));
        } else {
            $missingPermissions = ['permissions table missing'];
        }

        $companyCount = Schema::hasTable('finance_companies')
            ? DB::table('finance_companies')->whereIn('code', ['BKJB', 'MDMF'])->where('is_active', true)->count()
            : 0;
        $coaCount = Schema::hasTable('finance_chart_of_accounts') ? DB::table('finance_chart_of_accounts')->count() : 0;
        $legacyKeyExists = Schema::hasTable('finance_chart_of_accounts')
            ? DB::table('finance_chart_of_accounts')->where('code', '1-10400')->where('name', 'Dana Belum Disetor')->exists()
            : false;

        $requiredJournalColumns = [
            'business_date', 'company_code', 'outlet_id', 'marking', 'source_type', 'source_id', 'source_key',
            'status', 'total_debit', 'total_credit', 'reversal_of_journal_id', 'reversal_journal_id', 'posted_at', 'reversed_at',
        ];
        $missingJournalColumns = [];
        if (Schema::hasTable('finance_journal_entries')) {
            $missingJournalColumns = array_values(array_filter($requiredJournalColumns, fn (string $column) => ! Schema::hasColumn('finance_journal_entries', $column)));
        } else {
            $missingJournalColumns = $requiredJournalColumns;
        }

        $mappingCount = Schema::hasTable('finance_outlet_company_mappings')
            ? DB::table('finance_outlet_company_mappings')->where('is_active', true)->count()
            : 0;
        $unmappedOutletCount = 0;
        if (Schema::hasTable('outlets') && Schema::hasTable('finance_outlet_company_mappings')) {
            $outletQuery = DB::table('outlets as o')
                ->leftJoin('finance_outlet_company_mappings as m', function ($join): void {
                    $join->on('m.outlet_id', '=', 'o.id')->where('m.is_active', true);
                })
                ->where('o.is_active', true)
                ->whereNull('m.outlet_id');
            if (Schema::hasColumn('outlets', 'type')) {
                $outletQuery->whereRaw("LOWER(COALESCE(o.type, '')) = 'outlet'");
            }
            $unmappedOutletCount = $outletQuery->count();
        }

        $ok = empty($missingTables)
            && empty($missingRoutes)
            && empty($menuProblems)
            && empty($missingPermissions)
            && $companyCount === 2
            && $coaCount >= 173
            && $legacyKeyExists
            && empty($missingJournalColumns);

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing named routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Access Matrix menu problems', $menuProblems ? implode('; ', $menuProblems) : '-'],
            ['Missing permissions', $missingPermissions ? implode(', ', $missingPermissions) : '-'],
            ['BKJB + MDMF company rows', $companyCount.'/2'],
            ['COA rows', (string) $coaCount.' (expected >= 173)'],
            ['Legacy key COA 1-10400', $legacyKeyExists ? 'OK' : 'MISSING'],
            ['Missing journal columns', $missingJournalColumns ? implode(', ', $missingJournalColumns) : '-'],
            ['Active outlet→PT mappings', (string) $mappingCount],
            ['Unmapped active outlet-type rows', (string) $unmappedOutletCount.' (warning only; map explicitly in UI)'],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
