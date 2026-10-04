<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class ErpPosV10I22GeneralAffairImportDriveSearchCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i22-ga-import-drive-search-check';
    protected $description = 'Verify ERP POS V10 I22 GA XLSX import hardening and global Drive Inventory search';

    public function handle(): int
    {
        $base = base_path();
        $paths = [
            'xlsx rule' => $base.'/app/Rules/XlsxUpload.php',
            'spreadsheet batch controller' => $base.'/app/Http/Controllers/Api/V1/Support/SpreadsheetTransferBatchController.php',
            'GA asset controller' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairAssetController.php',
            'GA inventory controller' => $base.'/app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairInventoryController.php',
            'GA drive service' => $base.'/app/Services/GeneralAffair/DriveInventoryStorageService.php',
            'GA drive page' => $base.'/../frontend - Backoffice/src/pages/general-affair/GeneralAffairDriveInventoryPage.vue',
        ];

        $checks = [];
        foreach ($paths as $label => $path) $checks[$label.' exists'] = is_file($path);

        $rule = $this->read($paths['xlsx rule']);
        $batch = $this->read($paths['spreadsheet batch controller']);
        $asset = $this->read($paths['GA asset controller']);
        $inventory = $this->read($paths['GA inventory controller']);
        $drive = $this->read($paths['GA drive service']);
        $page = $this->read($paths['GA drive page']);

        $checks['XLSX rule ignores unreliable MIME and validates OpenXML signature'] =
            str_contains($rule, 'getClientOriginalExtension')
            && str_contains($rule, 'str_starts_with($signature, "PK")')
            && str_contains($rule, 'xl/workbook.xml')
            && ! str_contains($rule, 'getMimeType()');

        $checks['I18 GA Asset/Inventory batch upload uses hardened XLSX rule'] =
            str_contains($batch, "['ga.asset', 'ga.inventory']")
            && str_contains($batch, 'new XlsxUpload()');

        $checks['legacy GA Asset import uses hardened XLSX rule'] =
            str_contains($asset, 'new XlsxUpload()') && ! str_contains($asset, "'mimes:xlsx'");
        $checks['legacy GA Inventory import uses hardened XLSX rule'] =
            str_contains($inventory, 'new XlsxUpload()') && ! str_contains($inventory, "'mimes:xlsx'");

        $checks['Drive search switches to recursive global search'] =
            str_contains($drive, 'browseGlobalSearch(')
            && str_contains($drive, 'RecursiveDirectoryIterator')
            && str_contains($drive, "'scope' => 'global'");
        $checks['Drive global search has scan/time safeguards'] =
            str_contains($drive, 'GLOBAL_SEARCH_MAX_ENTRIES')
            && str_contains($drive, 'GLOBAL_SEARCH_MAX_SECONDS');
        $checks['Drive page explains global search and shows file location'] =
            str_contains($page, 'Pencarian lintas semua folder')
            && str_contains($page, 'entry.parent_path')
            && str_contains($page, 'Cari file/folder di semua folder');

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            if (! $ok) $failed = true;
        }

        $this->newLine();
        if ($failed) {
            $this->error('ERP POS V10 I22 GA Import + Drive Search is NOT READY. Review failed checks above.');
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I22 GA Import + Drive Search is READY.');
        $this->line('Tidak ada migration, menu/permission baru, atau perubahan frontend Android pada I22.');
        return self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
