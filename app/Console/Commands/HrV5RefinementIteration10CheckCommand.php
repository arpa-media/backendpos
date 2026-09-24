<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrUniformI10XlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration10CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i10-check';
    protected $description = 'Verify HR v5 Iteration 10 Uniform master, stock ledger, inbound flow, Access Matrix and canonical Excel recap.';

    private const TEMPLATE_SHA256 = '649b91d09cb6f4f6b8190cd8ae1d6ff5ae5441623442674082b4012cff3ea17b';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $masterPage = $frontendRoot.'/src/pages/human-resource/HumanResourceUniformMasterI10Page.vue';
        $stockPage = $frontendRoot.'/src/pages/human-resource/HumanResourceUniformStockI10Page.vue';
        $inboundPage = $frontendRoot.'/src/pages/human-resource/HumanResourceUniformInboundI10Page.vue';
        $api = $frontendRoot.'/src/lib/humanResourceUniformI10Api.js';
        $routeModule = $frontendRoot.'/src/modules/human-resource/route-modules/25-uniform-inventory-i10.js';
        $sidebar = $frontendRoot.'/src/modules/sidebar-menu-modules/modules/09-human-resource-uniform-i10.js';

        $routeSource = (string) @file_get_contents(base_path('routes/hr_modules/35-uniform-inventory-i10.php'));
        $inventorySource = (string) @file_get_contents(base_path('app/Services/HumanResource/HrUniformInventoryI10Service.php'));
        $xlsxSource = (string) @file_get_contents(base_path('app/Services/HumanResource/HrUniformI10XlsxService.php'));
        $masterSource = (string) @file_get_contents($masterPage);
        $stockSource = (string) @file_get_contents($stockPage);
        $inboundSource = (string) @file_get_contents($inboundPage);
        $routeModuleSource = (string) @file_get_contents($routeModule);
        $sidebarSource = (string) @file_get_contents($sidebar);
        $catalogFile = database_path('data/hr_uniform_i10_catalog.php');
        $template = storage_path('app/hr/templates/i10/TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx');

        $catalog = is_file($catalogFile) ? require $catalogFile : [];
        $catalogValid = is_array($catalog)
            && count($catalog) === 160
            && (($catalog[0]['name'] ?? null) === 'MDMF - Hitam Hijau-S')
            && (($catalog[159]['name'] ?? null) === 'BKJB - Apron Half');

        $xlsxSmoke = $this->xlsxSmoke($template);
        $checks = [
            'Uniform item schema exists' => Schema::hasTable('HR_uniform_items'),
            'Uniform stock balance schema exists' => Schema::hasTable('HR_uniform_stock_balances'),
            'Uniform inbound schema exists' => Schema::hasTable('HR_uniform_inbounds') && Schema::hasTable('HR_uniform_inbound_lines'),
            'Immutable uniform movement ledger exists' => Schema::hasTable('HR_uniform_movements'),
            'Low-stock alert schema exists' => Schema::hasTable('HR_uniform_stock_alerts'),
            'Canonical 160-item catalog is present' => $catalogValid,
            'Canonical catalog is seeded in database' => $catalogValid && Schema::hasTable('HR_uniform_items') && DB::table('HR_uniform_items')->where('source_template', 'TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx')->count() >= 160,
            'Data Uniform Access Matrix exists' => $this->menuExists('hr-uniform-master', '/human-resource/data-master/uniform', 'hr.uniform.master.view'),
            'Stok Uniform Access Matrix exists' => $this->menuExists('hr-uniform-stock', '/human-resource/manage-uniform/stock', 'hr.uniform.stock.view'),
            'Barang Masuk Access Matrix exists' => $this->menuExists('hr-uniform-inbound', '/human-resource/manage-uniform/inbound', 'hr.uniform.inbound.view'),
            'Uniform permissions exist' => $this->permissionsExist([
                'hr.uniform.master.view', 'hr.uniform.master.create', 'hr.uniform.master.update', 'hr.uniform.master.delete',
                'hr.uniform.stock.view', 'hr.uniform.inbound.view', 'hr.uniform.inbound.create',
            ]),
            'Uniform references endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/references'),
            'Uniform master endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/master'),
            'Uniform stock endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/stock'),
            'Uniform inbound endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/inbounds'),
            'Uniform recap preview endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/recap/preview'),
            'Uniform recap export endpoint registered' => $this->uriExists('api/v1/human-resource/uniform-i10/recap/export'),
            'Low-stock rule is actual stock <= threshold' => str_contains($inventorySource, '$current <= $threshold') && str_contains($inventorySource, 'Tambah Barang Masuk'),
            'Stock cannot become negative' => str_contains($inventorySource, 'if ($after < 0)') && str_contains($inventorySource, 'Stok tidak boleh minus'),
            'Inbound posts through immutable ledger' => str_contains($inventorySource, "movementType: 'INBOUND'") && str_contains($inventorySource, "sourceType: 'UNIFORM_INBOUND'"),
            'Inbound keeps idempotency protection' => str_contains($inventorySource, 'idempotency_key') && str_contains($inventorySource, 'source_line_id'),
            'Future I11 can reuse general postMovement without editing I10' => str_contains($inventorySource, 'public function postMovement('),
            'Canonical template exists' => is_file($template),
            'Canonical template is byte-identical to uploaded reference' => is_file($template) && hash_file('sha256', $template) === self::TEMPLATE_SHA256,
            'Excel writer has no ext-zip runtime dependency' => ! str_contains($xlsxSource, 'ZipArchive'),
            'Excel writer targets Rekap UPDATE + Barang MASUK only' => str_contains($xlsxSource, "STOCK_SHEET = 'xl/worksheets/sheet1.xml'") && str_contains($xlsxSource, "INBOUND_SHEET = 'xl/worksheets/sheet2.xml'"),
            'Excel smoke output is structurally valid' => $xlsxSmoke['valid'],
            'Excel smoke writes stock recap values' => $xlsxSmoke['stock'],
            'Excel smoke writes inbound recap values' => $xlsxSmoke['inbound'],
            'Excel smoke preserves outbound sheets for I11' => $xlsxSmoke['outbound_preserved'],
            'Data Uniform page exists' => is_file($masterPage) && str_contains($masterSource, 'Data Uniform'),
            'Stok Uniform page has low-stock recommendation + preview/export' => is_file($stockPage) && str_contains($stockSource, 'Tambah Barang Masuk') && str_contains($stockSource, 'Preview Rekap') && str_contains($stockSource, 'Unduh Excel'),
            'Barang Masuk page supports multi-item and manual charges' => is_file($inboundPage) && str_contains($inboundSource, 'Tambah Item') && str_contains($inboundSource, 'Harga Beli / unit') && str_contains($inboundSource, 'Beban Squad / unit') && str_contains($inboundSource, 'Beban PT / unit'),
            'Frontend Uniform API exists' => is_file($api),
            'Frontend routes use independent Access Matrix paths' => str_contains($routeModuleSource, "accessPath: '/human-resource/data-master/uniform'") && str_contains($routeModuleSource, "accessPath: '/human-resource/manage-uniform/stock'") && str_contains($routeModuleSource, "accessPath: '/human-resource/manage-uniform/inbound'"),
            'Sidebar groups Data Uniform + Manage Uniform' => str_contains($sidebarSource, "label: 'Data Uniform'") && str_contains($sidebarSource, "label: 'Manage Uniform'") && str_contains($sidebarSource, "label: 'Stok Uniform'") && str_contains($sidebarSource, "label: 'Barang Masuk'"),
            'Backend route permissions are Access Matrix aligned' => str_contains($routeSource, 'hr.uniform.master.view') && str_contains($routeSource, 'hr.uniform.stock.view') && str_contains($routeSource, 'hr.uniform.inbound.create'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        if ($xlsxSmoke['error'] !== '') $this->line('<error>[XLSX]</error> '.$xlsxSmoke['error']);
        $this->line('<info>[INFO]</info> I10 seeds 160 canonical master items with opening stock 0; all seeded items start low-stock because 0 <= 10.');
        $this->line('<info>[INFO]</info> I10 only writes Rekap UPDATE + Barang MASUK. Barang KELUAR SERAGAM/ATRIBUT remain untouched for I11.');
        $this->line('<info>[INFO]</info> Price, Squad charge and PT charge are stored per inbound line; canonical Barang MASUK export remains the exact 8-column template structure.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function menuExists(string $code, string $path, string $view): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', $code)->where('path', $path)->where('permission_view', $view)->where('is_active', true)->exists();
    }

    private function permissionsExist(array $permissions): bool
    {
        if (! Schema::hasTable('permissions')) return false;
        return DB::table('permissions')->whereIn('name', $permissions)->distinct()->count('name') === count(array_unique($permissions));
    }

    private function uriExists(string $needle): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim((string) $route->uri(), '/') === trim($needle, '/')) return true;
        }
        return false;
    }

    private function xlsxSmoke(string $template): array
    {
        $result = ['valid' => false, 'stock' => false, 'inbound' => false, 'outbound_preserved' => false, 'error' => ''];
        try {
            if (! is_file($template)) throw new \RuntimeException('Template I10 tidak ditemukan.');
            $sourceFiles = $this->readZipBinary((string) file_get_contents($template));
            $binary = app(HrUniformI10XlsxService::class)->build([
                ['id' => '01', 'code' => 'SMOKE-S', 'name' => 'SMOKE UNIFORM-S', 'company_code' => 'MDMF', 'item_kind' => 'UNIFORM', 'size' => 'S', 'opening_qty' => 0, 'inbound_qty' => 12, 'outbound_qty' => 2, 'current_qty' => 10],
                ['id' => '02', 'code' => 'SMOKE-APH', 'name' => 'SMOKE APRON HALF', 'company_code' => 'BKJB', 'item_kind' => 'ATTRIBUTE', 'size' => null, 'opening_qty' => 0, 'inbound_qty' => 5, 'outbound_qty' => 0, 'current_qty' => 5],
            ], [[
                'line_id' => 'L1', 'inbound_date' => '2026-08-28', 'code' => 'SMOKE-S', 'size' => 'S', 'name' => 'SMOKE UNIFORM-S',
                'quantity' => 12, 'company_code' => 'MDMF', 'receiver_name' => 'SMOKE RECEIVER', 'input_by_name' => 'SMOKE HR',
            ]]);
            $files = $this->readZipBinary($binary);
            $sheet1 = (string) ($files['xl/worksheets/sheet1.xml'] ?? '');
            $sheet2 = (string) ($files['xl/worksheets/sheet2.xml'] ?? '');
            $result['valid'] = isset($files['[Content_Types].xml'], $files['xl/workbook.xml'], $files['xl/styles.xml']) && $sheet1 !== '' && $sheet2 !== '';
            $result['stock'] = str_contains($sheet1, 'SMOKE-S') && str_contains($sheet1, 'SMOKE APRON HALF') && str_contains($sheet1, 'A1:H');
            $result['inbound'] = str_contains($sheet2, 'SMOKE RECEIVER') && str_contains($sheet2, 'SMOKE HR') && str_contains($sheet2, 'SMOKE-S');
            $result['outbound_preserved'] = isset($sourceFiles['xl/worksheets/sheet3.xml'], $sourceFiles['xl/worksheets/sheet4.xml'])
                && ($files['xl/worksheets/sheet3.xml'] ?? null) === $sourceFiles['xl/worksheets/sheet3.xml']
                && ($files['xl/worksheets/sheet4.xml'] ?? null) === $sourceFiles['xl/worksheets/sheet4.xml'];
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }

    /** @return array<string,string> */
    private function readZipBinary(string $binary): array
    {
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new \RuntimeException('XLSX tidak memiliki EOCD.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        if (! is_array($eocd)) throw new \RuntimeException('EOCD XLSX tidak valid.');
        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (! is_array($header) || ($header['sig'] ?? null) !== 0x02014b50) throw new \RuntimeException('Central directory XLSX tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (! is_array($local) || ($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new \RuntimeException('Data XLSX tidak dapat di-inflate.');
                $entries[$name] = $inflated;
            }
        }
        return $entries;
    }
}
