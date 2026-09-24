<?php

namespace App\Console\Commands;

use App\Services\Console\ConsoleCanonicalAccessRecoveryService;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Services\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalHotfixI01Hf03IntegrityCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:hotfix-i01-hf03-check';
    protected $description = 'Verify cumulative integrity after HOTFIX I01 HF03';

    public function handle(): int
    {
        $checks = [];
        $checks['UserManagementService class'] = class_exists(UserManagementService::class);
        $checks['ConsoleCanonicalAccessRecoveryService class'] = class_exists(ConsoleCanonicalAccessRecoveryService::class);
        $checks['Reporting orchestrator class'] = class_exists(ReportingMaterializationOrchestrator::class);
        $checks['Maintenance settings table'] = Schema::hasTable('system_maintenance_settings');

        $requiredRoles = ['Administrator', 'Executive', 'Finance', 'Operation', 'GA', 'HR', 'Brand', 'Squad Default', 'Squad Management', 'Squad Warehouse'];
        $requiredLevels = ['Default', 'Admin', 'GA', 'Finance', 'Operation', 'Brand', 'HR', 'Warehouse', 'SPV'];

        if (Schema::hasTable('access_roles')) {
            $existing = DB::table('access_roles')->pluck('name')->map(fn ($v) => strtolower(trim((string) $v)))->all();
            $checks['Required Access Roles'] = collect($requiredRoles)->every(fn ($name) => in_array(strtolower($name), $existing, true));
        } else {
            $checks['Required Access Roles'] = false;
        }

        if (Schema::hasTable('access_levels')) {
            $existing = DB::table('access_levels')->pluck('name')->map(fn ($v) => strtolower(trim((string) $v)))->all();
            $checks['Required Access Levels'] = collect($requiredLevels)->every(fn ($name) => in_array(strtolower($name), $existing, true));
        } else {
            $checks['Required Access Levels'] = false;
        }

        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $console = DB::table('access_portals')->where('code', 'console')->first();
            $maxSort = (int) (DB::table('access_portals')->max('sort_order') ?? 0);
            $checks['Console portal exists + last'] = $console && (int) $console->sort_order === $maxSort;

            $consoleCodes = ['console-control-center', 'console-system-health', 'console-file-management', 'console-maintenance'];
            $checks['Console 4 menus'] = $console
                && DB::table('access_menus')->where('portal_id', $console->id)->whereIn('code', $consoleCodes)->where('is_active', true)->count() === 4;

            $maintenance = DB::table('access_menus')->where('code', 'console-maintenance')->first();
            $checks['Maintenance sort 40'] = $maintenance && (int) $maintenance->sort_order === 40;

            $expense = DB::table('access_menus')->where(function ($q): void {
                $q->where('code', 'finance-i08-expense-report')->orWhere('path', '/finance/expense-report');
            })->first();
            $checks['Expense Report canonical label'] = ! $expense || trim((string) $expense->name) === 'Expense Report';
        } else {
            $checks['Console portal exists + last'] = false;
            $checks['Console 4 menus'] = false;
            $checks['Maintenance sort 40'] = false;
            $checks['Expense Report canonical label'] = false;
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $ok ? $this->info('PASS  '.$label) : $this->error('FAIL  '.$label);
            if (! $ok) $failed++;
        }

        $this->newLine();
        $failed === 0 ? $this->info('Result: OK') : $this->error("Result: FAIL ({$failed})");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
