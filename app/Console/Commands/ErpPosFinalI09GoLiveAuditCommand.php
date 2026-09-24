<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpPosFinalI09GoLiveAuditCommand extends Command
{
    protected $signature = 'erp-pos-final:i09-go-live-audit
        {--strict : Treat operational warnings such as stale recovery/due auto approval as failures}
        {--skip-iteration-checks : Run only I09 release-gate checks}
        {--max-recovery-age=30 : Minutes before queued/attached demand recovery is considered stale}';

    protected $description = 'Final cross-domain I01-I08 regression, performance, access, and go-live audit.';

    /** @var array<int,string> */
    private array $failures = [];

    /** @var array<int,string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->info('ERP POS FINAL I09 - Cross-Domain Go-Live Audit');
        $this->line('Timezone audit: Asia/Jakarta');

        $this->section('Release-gate schema & indexes');
        $this->checkSchemaAndIndexes();

        $this->section('Access Matrix contracts');
        $this->checkAccessMatrix();

        $this->section('Reporting engine health');
        $this->checkReportingHealth();

        $this->section('Finance/Treasury structural contracts');
        $this->checkFinanceTreasury();

        $this->section('Stock opening & auto approval');
        $this->checkStockControls();

        if (! $this->option('skip-iteration-checks')) {
            $this->section('I01-I08 regression suite');
            $this->runIterationChecks();
        }

        $this->newLine();
        foreach ($this->warnings as $warning) $this->warn('[WARN] '.$warning);
        foreach ($this->failures as $failure) $this->error('[FAIL] '.$failure);

        $this->newLine();
        $this->line(sprintf('Failures : %d', count($this->failures)));
        $this->line(sprintf('Warnings : %d', count($this->warnings)));

        if ($this->failures !== []) {
            $this->error('ERP POS FINAL I09 validation FAILED. Jangan go-live sebelum failure diselesaikan.');
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I09 validation PASS. Release gate inti I01-I08 terpenuhi.');
        return self::SUCCESS;
    }

    private function checkSchemaAndIndexes(): void
    {
        $requiredTables = [
            'finance_general_postings',
            'finance_general_posting_journals',
            'finance_treasury_transactions',
            'pur_invoice_payments',
            'stk_opening_stocks',
            'stk_requests',
            'report_materialization_runs',
            'report_materialization_run_chunks',
            'report_materialization_recovery_requests',
            'access_menus',
        ];
        foreach ($requiredTables as $table) {
            $this->assertCheck('table '.$table, Schema::hasTable($table));
        }

        $indexes = [
            ['finance_general_posting_journals', 'erp_i09_gp_gate_idx'],
            ['stk_requests', 'erp_i09_stockreq_autoapprove_idx'],
            ['report_materialization_recovery_requests', 'erp_i09_recovery_scope_state_idx'],
            ['finance_treasury_transactions', 'erp_i09_treasury_type_date_idx'],
            // Existing I01/I11 indexes that must remain after cumulative overlay.
            ['report_materialization_run_chunks', 'rmchunks_run_stage_prio_idx'],
            ['report_materialization_run_chunks', 'rmchunks_dispatch_ready_idx'],
            ['report_materialization_run_chunks', 'rmchunks_lease_idx'],
        ];
        foreach ($indexes as [$table, $index]) {
            if (! Schema::hasTable($table)) continue;
            $this->assertCheck('index '.$table.'.'.$index, $this->indexExists($table, $index));
        }
    }

    private function checkAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;

        $contracts = [
            ['/console/control-center', 'Control Center', 'console.control_center.view'],
            ['/finance/expense-report', 'Expense Report', 'finance.expense_report.view'],
            ['/warehouse/stock/par-stock', 'Par Stock Warehouse', 'warehouse.inventory.par_stock.view'],
            ['/operational/sales-analytic/hourly-summary', 'Summary Per Hour', 'operational.sales_analytic.hourly_summary.view'],
        ];

        foreach ($contracts as [$path, $name, $permission]) {
            $menu = DB::table('access_menus')->where('path', $path)->first();
            $this->assertCheck('menu '.$path, (bool) $menu);
            if (! $menu) continue;
            $this->assertCheck($path.' name', trim((string) $menu->name) === $name);
            $this->assertCheck($path.' permission_view', trim((string) $menu->permission_view) === $permission);
            $this->assertCheck($path.' active', (bool) $menu->is_active === true);
        }
    }

    private function checkReportingHealth(): void
    {
        $queue = config('queue.connections.reporting');
        $this->assertCheck('queue.connections.reporting exists', is_array($queue));
        if (is_array($queue)) {
            $this->assertCheck('reporting queue name', (string) ($queue['queue'] ?? '') === 'reporting');
            $retryAfter = (int) ($queue['retry_after'] ?? 0);
            $this->assertCheck('reporting retry_after >= 3300s', $retryAfter >= 3300);
        }

        if (Schema::hasTable('report_materialization_runs')) {
            $activeStatuses = ['queued', 'running', 'pause_requested', 'paused', 'cancel_requested', 'waiting_window'];
            $active = DB::table('report_materialization_runs')->whereIn('status', $activeStatuses)->count();
            $this->assertCheck('single active materialization run', $active <= 1, 'active='.$active);
        }

        if (Schema::hasTable('report_materialization_run_chunks') && Schema::hasColumn('report_materialization_run_chunks', 'lease_expires_at')) {
            $stale = DB::table('report_materialization_run_chunks')
                ->whereIn('status', ['running', 'dispatched'])
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<', now())
                ->count();
            $this->operationalCheck('stale materialization leases', $stale === 0, 'stale='.$stale);
        }

        if (Schema::hasTable('report_materialization_recovery_requests')) {
            $minutes = max(5, min((int) $this->option('max-recovery-age'), 1440));
            $stale = DB::table('report_materialization_recovery_requests')
                ->whereIn('status', ['queued', 'attached'])
                ->where('created_at', '<', now()->subMinutes($minutes))
                ->count();
            $this->operationalCheck("recovery age <= {$minutes}m", $stale === 0, 'stale_requests='.$stale);
        }
    }

    private function checkFinanceTreasury(): void
    {
        if (Schema::hasTable('pur_invoice_payments') && Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id')) {
            $duplicateLinks = DB::table('pur_invoice_payments')
                ->whereNotNull('treasury_transaction_id')
                ->select('treasury_transaction_id')
                ->groupBy('treasury_transaction_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $this->assertCheck('Treasury link one-to-one', $duplicateLinks === 0, 'duplicates='.$duplicateLinks);
        }

        if (Schema::hasTable('finance_treasury_transactions')) {
            $duplicateSource = DB::table('finance_treasury_transactions')
                ->select('source_key')->groupBy('source_key')->havingRaw('COUNT(*) > 1')->count();
            $this->assertCheck('Treasury source_key idempotent', $duplicateSource === 0, 'duplicates='.$duplicateSource);

            $brokenPosting = 0;
            if (Schema::hasTable('finance_general_postings')) {
                $brokenPosting = DB::table('finance_treasury_transactions as t')
                    ->leftJoin('finance_general_postings as gp', 'gp.id', '=', 't.general_posting_id')
                    ->where('t.status', 'APPROVED')
                    ->where(function ($q): void {
                        $q->whereNull('t.general_posting_id')->orWhereNull('gp.id');
                    })->count();
            }
            $this->assertCheck('Approved Treasury has General Posting', $brokenPosting === 0, 'broken='.$brokenPosting);
        }
    }

    private function checkStockControls(): void
    {
        if (Schema::hasTable('stk_opening_stocks')) {
            $duplicates = DB::table('stk_opening_stocks')
                ->select('outlet_id', 'sku_id')
                ->groupBy('outlet_id', 'sku_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $this->assertCheck('Opening stock unique outlet+SKU', $duplicates === 0, 'duplicates='.$duplicates);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email')) {
            $actors = DB::table('users')->where('email', 'system-auto-approval@pos.local.invalid')->get();
            if ($actors->count() > 1) {
                $this->failures[] = 'SYSTEM_AUTO_APPROVAL duplicate account: '.$actors->count();
                $this->line('<error>[FAIL]</error> SYSTEM_AUTO_APPROVAL unique · count='.$actors->count());
            } elseif ($actors->count() === 1) {
                $actor = $actors->first();
                $this->assertCheck('SYSTEM_AUTO_APPROVAL disabled', ! (bool) ($actor->is_active ?? true));
            } else {
                $this->warnings[] = 'SYSTEM_AUTO_APPROVAL belum dibuat; akun dibuat lazy pada run auto-approval pertama.';
                $this->line('<comment>[WARN]</comment> SYSTEM_AUTO_APPROVAL belum dibuat');
            }
        }

        if (Schema::hasTable('stk_requests') && $this->allColumns('stk_requests', ['request_channel', 'status', 'request_approval_status', 'submitted_at'])) {
            $jakartaNow = Carbon::now('Asia/Jakarta');
            if ($jakartaNow->format('H:i') >= '06:05') {
                $cutoffUtc = $jakartaNow->copy()->startOfDay()->utc();
                $due = DB::table('stk_requests')
                    ->where('request_channel', 'warehouse_operations')
                    ->where('status', 'submitted')
                    ->where('request_approval_status', 'awaiting_approval1')
                    ->whereNotNull('submitted_at')
                    ->where('submitted_at', '<', $cutoffUtc)
                    ->count();
                $this->operationalCheck('06:00 auto-approval due queue empty', $due === 0, 'due='.$due);
            } else {
                $this->line('<info>[INFO]</info> 06:00 auto-approval due check skipped before 06:05 Asia/Jakarta');
            }
        }
    }

    private function runIterationChecks(): void
    {
        $checks = [
            ['I01', 'erp-finance-v8:i14-demand-recovery-check', []],
            ['I02', 'erp-pos-final:i02-finance-single-posting-source-check', []],
            ['I03', 'erp-pos-final:i03-expense-treasury-check', []],
            ['I04', 'erp-pos-final:i04-stock-inventory-check', $this->strictDueOptions()],
            ['I05', 'erp-pos-final:i05-warehouse-par-stock-check', []],
            ['I06', 'erp-pos-final:i06-purchasing-privacy-check', []],
            ['I07', 'erp-pos-final:i07-user-management-filter-check', []],
            ['I08', 'erp-pos-final:i08-chamber-hourly-ux-check', []],
        ];

        foreach ($checks as [$label, $name, $options]) {
            try {
                if (! $this->getApplication()?->has($name)) {
                    $this->failures[] = "{$label} verification command missing: {$name}";
                    $this->line("<error>[FAIL]</error> {$label} {$name} missing");
                    continue;
                }
                $this->newLine();
                $this->line("--- {$label}: {$name} ---");
                $code = $this->call($name, $options);
                if ($code !== self::SUCCESS) $this->failures[] = "{$label} verification failed ({$name}, exit={$code})";
            } catch (Throwable $e) {
                $this->failures[] = "{$label} verification exception: {$e->getMessage()}";
                $this->error("{$label} exception: {$e->getMessage()}");
            }
        }
    }

    /** @return array<string,bool> */
    private function strictDueOptions(): array
    {
        if (! $this->option('strict')) return [];
        if (Carbon::now('Asia/Jakarta')->format('H:i') < '06:05') return [];
        return ['--strict-due' => true];
    }

    private function operationalCheck(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->line('<info>[PASS]</info> '.$label.($detail !== '' ? ' · '.$detail : ''));
            return;
        }
        $message = $label.($detail !== '' ? ' · '.$detail : '');
        if ($this->option('strict')) {
            $this->failures[] = $message;
            $this->line('<error>[FAIL]</error> '.$message);
        } else {
            $this->warnings[] = $message;
            $this->line('<comment>[WARN]</comment> '.$message);
        }
    }

    private function assertCheck(string $label, bool $ok, string $detail = ''): void
    {
        $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label.($detail !== '' ? ' · '.$detail : ''));
        if (! $ok) $this->failures[] = $label.($detail !== '' ? ' · '.$detail : '');
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('<comment>'.$title.'</comment>');
    }

    private function allColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) return false;
        return true;
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }
        try {
            return collect(Schema::getIndexes($table))->contains(fn (array $row): bool => ($row['name'] ?? null) === $index);
        } catch (Throwable) {
            return false;
        }
    }
}
