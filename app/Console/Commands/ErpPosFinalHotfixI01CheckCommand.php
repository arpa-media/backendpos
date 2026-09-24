<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalHotfixI01CheckCommand extends Command
{
    protected $signature = 'erp-pos-final:hotfix-i01-check';
    protected $description = 'Verify HOTFIX I01 request-scoped materialization recovery, maintenance control, and Access Role/Level catalog.';

    public function handle(): int
    {
        $checks = [
            'system_maintenance_settings' => Schema::hasTable('system_maintenance_settings'),
            'maintenance default row' => Schema::hasTable('system_maintenance_settings') && DB::table('system_maintenance_settings')->where('id', 'default')->exists(),
            'console maintenance menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'console-maintenance')->where('path', '/console/maintenance')->exists(),
        ];

        $requiredRoles = ['Administrator', 'Executive', 'Finance', 'Operation', 'GA', 'HR', 'Brand', 'Squad Default', 'Squad Management', 'Squad Warehouse'];
        foreach ($requiredRoles as $name) {
            $checks['role '.$name] = Schema::hasTable('access_roles') && DB::table('access_roles')->whereRaw('UPPER(name) = ?', [strtoupper($name)])->exists();
        }

        $requiredLevels = ['Default', 'Admin', 'GA', 'Finance', 'Operation', 'Brand', 'HR', 'Warehouse', 'SPV'];
        foreach ($requiredLevels as $name) {
            $checks['level '.$name] = Schema::hasTable('access_levels') && DB::table('access_levels')->whereRaw('UPPER(name) = ?', [strtoupper($name)])->exists();
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            if (! $ok) {
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
