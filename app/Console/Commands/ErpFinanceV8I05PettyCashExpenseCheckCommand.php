<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpFinanceV8I05PettyCashExpenseCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i05-petty-cash-expense-check
        {--days=90 : Date window for EXPLAIN probe (max 370)}
        {--outlet= : Optional outlet id. Defaults to first outlet with Petty Cash data}';

    protected $description = 'V8 I05 gate for Petty Cash -> Finance Expense Report consolidation, access matrix, automatic marking, and posting read contract.';

    public function handle(): int
    {
        $days = max(1, min(370, (int) $this->option('days')));
        $failed = false;

        $this->info("ERP Finance V8 I05 Petty Cash / Finance Expense Report check ({$days} days)");

        foreach ([
            'finance_expense_reports',
            'finance_expense_report_items',
            'finance_general_postings',
            'finance_chart_of_accounts',
            'access_portals',
            'access_menus',
            'access_roles',
            'access_role_menu_permissions',
        ] as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'MISSING', $table));
            $failed = $failed || ! $ok;
        }

        if (! $failed) {
            $failed = $this->checkMenusAndMatrix() || $failed;
            $failed = $this->checkAutomaticMarking() || $failed;
        }

        $failed = $this->checkSourceContracts() || $failed;

        if (Schema::hasTable('finance_expense_reports') && Schema::hasTable('finance_expense_report_items')) {
            $outletId = trim((string) $this->option('outlet'));
            if ($outletId === '') {
                $outletId = (string) (DB::table('finance_expense_reports')
                    ->orderByDesc('business_date')
                    ->value('outlet_id') ?? '');
            }

            if ($outletId !== '') {
                $to = now('Asia/Jakarta')->toDateString();
                $from = now('Asia/Jakarta')->subDays($days - 1)->toDateString();
                $this->newLine();
                $this->line("Outlet={$outletId} range={$from}..{$to}");
                $this->explain(
                    'Finance Expense Report base page',
                    'SELECT i.id FROM finance_expense_report_items i INNER JOIN finance_expense_reports r ON r.id = i.report_id WHERE r.outlet_id = ? AND r.business_date BETWEEN ? AND ? ORDER BY r.business_date DESC, i.entry_time DESC LIMIT 50',
                    [$outletId, $from, $to],
                );
            } else {
                $this->warn('No Petty Cash report data found; EXPLAIN outlet probe skipped.');
            }
        }

        $this->newLine();
        $this->line('I05 invariant: Petty Cash input is /report/expense-request and new/edited rows are always MARKING.');
        $this->line('I05 invariant: /finance/expense-report is the canonical monitoring/posting surface in Cash & Bank.');
        $this->line('I05 invariant: per-line and bulk posting require finance.expense_report.post or equivalent Access Matrix can_edit.');
        $this->line('I05 invariant: posted financial history is not rewritten by the migration.');

        if ($failed) {
            $this->error('ERP Finance V8 I05 gate failed. Review menu/matrix, automatic marking, or source-contract failures.');
            return self::FAILURE;
        }

        $this->info('ERP Finance V8 I05 gate passed.');
        return self::SUCCESS;
    }

    private function checkMenusAndMatrix(): bool
    {
        $failed = false;

        $finance = DB::table('access_menus as m')
            ->leftJoin('access_portals as p', 'p.id', '=', 'm.portal_id')
            ->where('m.code', 'finance-i08-expense-report')
            ->first(['m.id', 'm.name', 'm.path', 'm.permission_view', 'm.permission_update', 'm.is_active', 'p.code as portal_code']);

        $financeOk = $finance
            && strtolower((string) $finance->portal_code) === 'finance'
            && (string) $finance->path === '/finance/expense-report'
            && (string) $finance->permission_view === 'finance.expense_report.view'
            && (string) $finance->permission_update === 'finance.expense_report.post'
            && (bool) $finance->is_active;
        $this->line(sprintf('[%s] canonical Cash & Bank Finance Expense Report menu', $financeOk ? 'OK' : 'FAIL'));
        $failed = $failed || ! $financeOk;

        $petty = DB::table('access_menus as m')
            ->leftJoin('access_portals as p', 'p.id', '=', 'm.portal_id')
            ->where('m.code', 'report-expense-request')
            ->first(['m.name', 'm.path', 'm.permission_view', 'm.permission_create', 'm.permission_update', 'm.permission_delete', 'm.is_active', 'p.code as portal_code']);
        $pettyOk = $petty
            && strtolower((string) $petty->portal_code) === 'report'
            && (string) $petty->name === 'Petty Cash'
            && (string) $petty->path === '/report/expense-request'
            && (bool) $petty->is_active;
        $this->line(sprintf('[%s] Petty Cash daily-input canonical menu', $pettyOk ? 'OK' : 'FAIL'));
        $failed = $failed || ! $pettyOk;

        $legacyActive = DB::table('access_menus')->where('code', 'report-expense-report')->where('is_active', true)->exists();
        $this->line(sprintf('[%s] legacy duplicate report-expense-report inactive', ! $legacyActive ? 'OK' : 'FAIL'));
        $failed = $failed || $legacyActive;

        if ($finance?->id) {
            $matrixRows = DB::table('access_role_menu_permissions')->where('menu_id', $finance->id)->count();
            $this->line(sprintf('[%s] Finance Expense Report Access Matrix rows=%d', $matrixRows > 0 ? 'OK' : 'FAIL', $matrixRows));
            $failed = $failed || $matrixRows < 1;

            $defaultPoster = DB::table('access_role_menu_permissions as rpm')
                ->join('access_roles as ar', 'ar.id', '=', 'rpm.access_role_id')
                ->where('rpm.menu_id', $finance->id)
                ->where('rpm.can_view', true)
                ->where('rpm.can_edit', true)
                ->where(function ($q): void {
                    $q->whereRaw("UPPER(COALESCE(ar.code, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN','FINANCE')")
                        ->orWhereRaw("UPPER(COALESCE(ar.name, '')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN','FINANCE')");
                })
                ->exists();
            $this->line(sprintf('[%s] Admin/Finance default view + post matrix capability', $defaultPoster ? 'OK' : 'FAIL'));
            $failed = $failed || ! $defaultPoster;
        }

        return $failed;
    }

    private function checkAutomaticMarking(): bool
    {
        if (! Schema::hasColumn('finance_expense_report_items', 'marking')) {
            $this->line('[FAIL] finance_expense_report_items.marking column missing');
            return true;
        }

        $unpostedNonMarking = DB::table('finance_expense_report_items')
            ->when(
                Schema::hasColumn('finance_expense_report_items', 'general_posting_id'),
                fn ($q) => $q->whereNull('general_posting_id')
            )
            ->whereRaw("UPPER(COALESCE(marking, '')) <> 'MARKING'")
            ->count();
        $this->line(sprintf('[%s] unposted Petty Cash rows forced MARKING (non-marking=%d)', $unpostedNonMarking === 0 ? 'OK' : 'FAIL', $unpostedNonMarking));

        $draftNonMarking = 0;
        if (Schema::hasColumn('finance_general_postings', 'marking')) {
            $draftNonMarking = DB::table('finance_general_postings')
                ->where('source_code', 'PETTY_CASH_EXPENSE')
                ->where('status', 'DRAFT')
                ->whereRaw("UPPER(COALESCE(marking, '')) <> 'MARKING'")
                ->count();
        }
        $this->line(sprintf('[%s] draft Petty Cash General Posting forced MARKING (non-marking=%d)', $draftNonMarking === 0 ? 'OK' : 'FAIL', $draftNonMarking));

        return $unpostedNonMarking > 0 || $draftNonMarking > 0;
    }

    private function checkSourceContracts(): bool
    {
        $checks = [
            [
                app_path('Http/Controllers/Api/V1/Finance/FinanceExpenseReportController.php'),
                fn (string $c): bool => str_contains($c, "'marking' => 'MARKING'")
                    && ! preg_match("/'marking'\\s*=>\\s*\\[/", $this->validateItemBlock($c))
                    && str_contains($c, "report.expense_request.create")
                    && str_contains($c, "report.expense_request.update"),
                'Daily Petty Cash forces MARKING and enforces create/update capability',
            ],
            [
                app_path('Http/Controllers/Api/V1/Finance/FinancePettyCashExpensePostingController.php'),
                fn (string $c): bool => str_contains($c, "'page' => ['nullable', 'integer', 'min:1']")
                    && str_contains($c, "finance.expense_report.post")
                    && str_contains($c, "'capabilities'")
                    && str_contains($c, "'can_post'"),
                'Finance Expense Report validates page and exposes authoritative posting capability',
            ],
            [
                app_path('Services/Finance/FinancePettyCashExpensePostingService.php'),
                fn (string $c): bool => str_contains($c, "$marking = 'MARKING';")
                    && str_contains($c, "'draft_rows'")
                    && str_contains($c, '$pageNumber')
                    && ! str_contains($c, '->paginate('),
                'Posting service uses auto MARKING + summary-known manual pagination',
            ],
            [
                base_path('../frontend - Backoffice/src/lib/accessMatrix.js'),
                fn (string $c): bool => str_contains($c, "code === 'finance-i08-expense-report'")
                    && str_contains($c, "path === '/finance/expense-report'"),
                'Access Matrix exposes only canonical Finance Expense Report menu',
            ],
            [
                base_path('../frontend - Backoffice/src/lib/portalConfig.js'),
                fn (string $c): bool => str_contains($c, "code === 'finance-i08-expense-report'")
                    && str_contains($c, "Finance Expense Report"),
                'Cash & Bank IA exposes canonical Finance Expense Report',
            ],
            [
                base_path('../frontend - Backoffice/src/modules/finance/pages/FinancePettyCashExpensePostingPage.vue'),
                fn (string $c): bool => str_contains($c, 'Buka Petty Cash Harian')
                    && str_contains($c, "capabilities.can_post")
                    && str_contains($c, 'AUTO · MARKING'),
                'Finance Expense Report UI links daily Petty Cash and trusts backend posting capability',
            ],
        ];

        $failed = false;
        foreach ($checks as [$path, $predicate, $label]) {
            if (! is_file($path)) {
                $this->line("[MISSING] {$label}: {$path}");
                $failed = true;
                continue;
            }
            $contents = (string) file_get_contents($path);
            $ok = (bool) $predicate($contents);
            $this->line(sprintf('[%s] %s', $ok ? 'OK' : 'FAIL', $label));
            $failed = $failed || ! $ok;
        }

        return $failed;
    }

    private function validateItemBlock(string $contents): string
    {
        $start = strpos($contents, 'private function validateItem');
        if ($start === false) return '';
        return substr($contents, $start, 1400);
    }

    private function explain(string $label, string $sql, array $bindings): void
    {
        try {
            $rows = DB::select('EXPLAIN '.$sql, $bindings);
            $first = $rows[0] ?? null;
            $type = $first->type ?? $first->access_type ?? '-';
            $key = $first->key ?? '-';
            $rowsEstimate = $first->rows ?? '-';
            $extra = $first->Extra ?? $first->extra ?? '';
            $this->line("[EXPLAIN] {$label}: type={$type} key={$key} rows={$rowsEstimate} extra={$extra}");
        } catch (Throwable $e) {
            $this->warn("EXPLAIN {$label} skipped: {$e->getMessage()}");
        }
    }
}
