<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpV5HotfixWarehouseMenuCheckCommand extends Command
{
    protected $signature = 'erp-v5:hotfix-warehouse-menu-check';

    protected $description = 'Verify Warehouse Purchase Request and Stock Request Outlet Access Matrix restoration.';

    private const EXPECTED = [
        [
            'code' => 'warehouse-v3-purchase-requests',
            'path' => '/warehouse/purchasing/purchase-requests',
            'permission' => 'warehouse.procurement.request.view',
            'frontend' => "label: 'Purchase Request'",
        ],
        [
            'code' => 'warehouse-v3-sales-stock-request',
            'path' => '/warehouse/stock-requests/inbox',
            'permission' => 'warehouse.stock_request.inbox.view',
            'frontend' => "label: 'Stock Request'",
        ],
    ];

    public function handle(): int
    {
        $checks = [];

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            $this->error('Access Matrix tables tidak tersedia.');
            return self::FAILURE;
        }

        $portalId = DB::table('access_portals')->where('code', 'warehouse-operations')->value('id');
        $checks['Warehouse portal exists'] = $portalId ? 'OK' : 'FAILED';

        foreach (self::EXPECTED as $expected) {
            $menu = $portalId
                ? DB::table('access_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', $expected['code'])
                    ->where('path', $expected['path'])
                    ->where('is_active', true)
                    ->first()
                : null;

            $label = $expected['code'];
            $checks[$label.' canonical active'] = $menu ? 'OK' : 'FAILED';
            $checks[$label.' permission contract'] = $menu && (string) $menu->permission_view === $expected['permission'] ? 'OK' : 'FAILED';

            $activeCount = $portalId
                ? DB::table('access_menus')
                    ->where('portal_id', $portalId)
                    ->where('path', $expected['path'])
                    ->where('is_active', true)
                    ->count()
                : 0;
            $checks[$label.' single active path'] = $activeCount === 1 ? 'OK' : 'FAILED';

            if ($menu && Schema::hasTable('access_role_menu_permissions')) {
                $viewGrantCount = DB::table('access_role_menu_permissions')
                    ->where('menu_id', $menu->id)
                    ->where('can_view', true)
                    ->count();
                $checks[$label.' has view grants'] = $viewGrantCount > 0 ? 'OK' : 'FAILED';
            } else {
                $checks[$label.' has view grants'] = 'FAILED';
            }
        }

        $frontendNavigation = dirname(base_path()).'/frontend - Backoffice/src/modules/warehouse/lib/warehouseV3Navigation.js';
        if (is_file($frontendNavigation)) {
            $source = (string) file_get_contents($frontendNavigation);
            $checks['Frontend Purchase Request navigation'] = str_contains($source, "path: '/warehouse/purchasing/purchase-requests'") ? 'OK' : 'FAILED';
            $checks['Frontend Stock Request navigation'] = str_contains($source, "path: '/warehouse/stock-requests/inbox'") ? 'OK' : 'FAILED';
        } else {
            $checks['Frontend navigation source'] = 'SKIPPED';
        }

        $failed = collect($checks)->contains('FAILED');
        $rows = collect($checks)->map(fn ($result, $check) => [$check, $result])->values()->all();
        $this->table(['Check', 'Result'], $rows);
        $this->newLine();
        $this->line('Status: '.($failed ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));

        if (! $failed) {
            $this->newLine();
            $this->info('Access Matrix sudah pulih. Logout/login ulang agar auth.access browser mengambil snapshot terbaru.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
