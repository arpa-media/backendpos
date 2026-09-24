<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration10WarehouseCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-10-check';

    protected $description = 'Smoke check ERP-V5 Iteration 10 Warehouse Stock-In Purchase UOM/date defaults.';

    public function handle(): int
    {
        $controller = $this->source('app/Http/Controllers/Api/V1/Warehouse/PurchasingV3/WarehousePurchaseOrderV3Controller.php');
        $service = $this->source('app/Services/Warehouse/PurchasingV3/WarehousePurchasingV3Service.php');
        $page = $this->source('../frontend - Backoffice/src/modules/warehouse/pages/WarehousePurchasingV3Page.vue');
        $layout = $this->source('../frontend - Backoffice/src/modules/warehouse/layouts/WarehouseLayout.vue');

        $portalHome = $this->between($layout, '<template v-else-if="isPortalHome">', '<template v-else>');

        $checks = [
            'API accepts Purchase UOM receive qty' => str_contains($controller, "'items.*.received_qty_uom'"),
            'Legacy Base UOM payload remains compatible' => str_contains($controller, "'items.*.received_qty_base'"),
            'Backend converts Purchase UOM using snapshot factor' => str_contains($service, "purchase_conversion_factor")
                && str_contains($service, '$received = round($receivedUom * $factor, 4);'),
            'Backend defaults Production Date Asia/Jakarta' => str_contains($service, "now('Asia/Jakarta')->toDateString()"),
            'Backend defaults Expiry +1 month no overflow' => str_contains($service, 'addMonthNoOverflow()'),
            'Order API exposes expected/received Purchase UOM' => str_contains($service, "'expected_qty_uom'")
                && str_contains($service, "'received_qty_uom'"),
            'Frontend submits received_qty_uom' => str_contains($page, 'received_qty_uom: Number(form?.received || 0)'),
            'Frontend defaults Production/Expiry dates' => str_contains($page, 'function jakartaToday()')
                && str_contains($page, 'function addMonthNoOverflow(value)'),
            'Portal-home Request button removed beside Dashboard' => $portalHome !== ''
                && ! str_contains($portalHome, '+ Request')
                && str_contains($portalHome, '>\n            Dashboard\n'),
            'Existing Purchase Order Access Matrix remains present' => $this->accessMatrixOkay(),
        ];

        $rows = collect($checks)->map(fn (bool $ok, string $name): array => [$name, $ok ? 'PASS' : 'FAIL'])->values()->all();
        $this->table(['Check', 'Result'], $rows);

        $failed = collect($checks)->filter(fn (bool $ok): bool => ! $ok)->keys()->all();
        if ($failed !== []) {
            $this->error('ERP-V5 Iteration 10 check FAILED: '.implode('; ', $failed));
            return self::FAILURE;
        }

        $this->info('ERP-V5 Iteration 10 check PASSED.');
        return self::SUCCESS;
    }

    private function source(string $relative): string
    {
        $path = base_path($relative);
        return File::exists($path) ? File::get($path) : '';
    }

    private function between(string $source, string $start, string $end): string
    {
        $a = strpos($source, $start);
        if ($a === false) return '';
        $b = strpos($source, $end, $a + strlen($start));
        if ($b === false) return substr($source, $a);
        return substr($source, $a, $b - $a);
    }

    private function accessMatrixOkay(): bool
    {
        if (! Schema::hasTable('access_menus')) return true;

        return DB::table('access_menus')
            ->where('path', '/warehouse/purchasing/purchase-orders')
            ->where('is_active', true)
            ->exists();
    }
}
