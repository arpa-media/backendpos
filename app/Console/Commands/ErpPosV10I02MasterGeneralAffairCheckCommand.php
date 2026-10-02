<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I02MasterGeneralAffairCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i02-general-affair-master-check';
    protected $description = 'Verify ERP POS V10 I02 Master General Affair deployment.';

    public function handle(): int
    {
        $checks = [
            'I01 Portal General Affair tersedia' => fn () => Schema::hasTable('access_portals')
                && DB::table('access_portals')->where('code', 'general-affair')->where('is_active', true)->exists(),
            'Table kategori kerusakan tersedia' => fn () => Schema::hasTable('ga_damage_categories'),
            'Table kategori CCTV tersedia' => fn () => Schema::hasTable('ga_cctv_categories'),
            'Table kategori Costing GA tersedia' => fn () => Schema::hasTable('ga_costing_categories'),
            '10 default kategori kerusakan tersedia' => fn () => Schema::hasTable('ga_damage_categories')
                && DB::table('ga_damage_categories')->whereNull('deleted_at')->count() >= 10,
            '5 default kategori CCTV tersedia' => fn () => Schema::hasTable('ga_cctv_categories')
                && DB::table('ga_cctv_categories')->whereNull('deleted_at')->count() >= 5,
            'CCTV Lainnya mengizinkan input bebas' => fn () => Schema::hasTable('ga_cctv_categories')
                && DB::table('ga_cctv_categories')->where('code', 'OTHER')->where('allow_custom_value', true)->exists(),
            '6 default kategori Costing GA tersedia' => fn () => Schema::hasTable('ga_costing_categories')
                && DB::table('ga_costing_categories')->whereNull('deleted_at')->count() >= 6,
            'Pembelian diarahkan ke Purchase Aktiva Order' => fn () => Schema::hasTable('ga_costing_categories')
                && DB::table('ga_costing_categories')->where('code', 'PURCHASE')->where('workflow_code', 'PURCHASE_ASSET_ORDER')->exists(),
            'Kategori costing non-pembelian memakai Reimburse Order' => fn () => Schema::hasTable('ga_costing_categories')
                && DB::table('ga_costing_categories')->where('code', 'TRANSPORTATION')->where('workflow_code', 'REIMBURSE_ORDER')->exists(),
            '3 menu master terdaftar di Access Matrix' => fn () => Schema::hasTable('access_menus')
                && DB::table('access_menus')->whereIn('code', ['ga-master-damage', 'ga-master-cctv', 'ga-master-costing'])->count() === 3,
            'Minimal 12 permission CRUD GA tersedia (guard web)' => fn () => $this->permissionCount() >= 12,
            'Route master kerusakan tersedia' => fn () => Route::getRoutes()->getByName('general-affair.i02.damage.index') !== null,
            'Route master CCTV tersedia' => fn () => Route::getRoutes()->getByName('general-affair.i02.cctv.index') !== null,
            'Route master Costing tersedia' => fn () => Route::getRoutes()->getByName('general-affair.i02.costing.index') !== null,
            'Mutation route dijaga permission action' => fn () => $this->mutationRoutesGuarded(),
            'Frontend route module I02 tersedia' => fn () => is_file(base_path('../frontend - Backoffice/src/modules/general-affair/route-modules/02-master.js')),
            'Frontend page Master GA tersedia' => fn () => is_file(base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairMasterCategoryPage.vue')),
            'Sidebar grouping Master GA tersedia' => fn () => is_file(base_path('../frontend - Backoffice/src/modules/sidebar-menu-modules/modules/90-general-affair-master-i02.js')),
        ];

        $failed = 0;
        $this->newLine();
        $this->info('ERP POS V10 I02 — Master General Affair Check');
        $this->line(str_repeat('─', 58));

        foreach ($checks as $label => $check) {
            try {
                $ok = (bool) $check();
            } catch (\Throwable $e) {
                $ok = false;
                $this->line(sprintf('  <fg=red>FAIL</> %-46s %s', $label, $e->getMessage()));
                $failed++;
                continue;
            }

            $this->line(sprintf('  %s %s', $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $label));
            if (! $ok) $failed++;
        }

        $this->newLine();
        if ($failed > 0) {
            $this->error("I02 belum READY. {$failed} check gagal.");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I02 Master General Affair is READY.');
        return self::SUCCESS;
    }

    private function permissionCount(): int
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return 0;
        return DB::table($table)
            ->where('guard_name', 'web')
            ->where(function ($query): void {
                $query->where('name', 'like', 'ga.master.damage.%')
                    ->orWhere('name', 'like', 'ga.master.cctv.%')
                    ->orWhere('name', 'like', 'ga.master.costing.%');
            })
            ->count();
    }

    private function mutationRoutesGuarded(): bool
    {
        foreach (['damage', 'cctv', 'costing'] as $type) {
            foreach (['store' => 'create', 'update' => 'update', 'destroy' => 'delete'] as $routeAction => $permissionAction) {
                $route = Route::getRoutes()->getByName("general-affair.i02.{$type}.{$routeAction}");
                if (! $route) return false;
                $middleware = implode('|', $route->gatherMiddleware());
                if (! str_contains($middleware, "ga.master.{$type}.{$permissionAction}")) return false;
            }
        }
        return true;
    }
}
