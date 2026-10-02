<?php

namespace App\Console\Commands;

use App\Services\GeneralAffair\DriveInventoryMasterService;
use App\Services\GeneralAffair\DriveInventoryStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ErpPosV10I11GeneralAffairDriveInventoryCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i11-general-affair-drive-inventory-check {--no-sync : Skip Master Inventory regeneration}';
    protected $description = 'Verify ERP POS V10 I11 General Affair Drive Inventory storage, mapping, permissions, routes and master XLSX';

    public function handle(DriveInventoryStorageService $drive, DriveInventoryMasterService $masters): int
    {
        try { $drive->ensureInitialStructure(); }
        catch (\Throwable $e) { $this->error('Storage repair failed: '.$e->getMessage()); }

        $checks = [
            'Folder mapping table' => Schema::hasTable('ga_inventory_drive_folder_mappings'),
            'Drive audit table' => Schema::hasTable('ga_inventory_drive_audit_logs'),
            'Mapping columns' => Schema::hasTable('ga_inventory_drive_folder_mappings') && Schema::hasColumns('ga_inventory_drive_folder_mappings', ['outlet_id','folder_path','last_master_path','last_master_row_count','last_master_generated_at']),
            'Drive Inventory menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'ga-drive-inventory')->where('path', '/general-affair/drive-inventory')->exists(),
            'View permission' => $this->permissionExists('ga.inventory_drive.view'),
            'Create permission' => $this->permissionExists('ga.inventory_drive.create'),
            'Update permission' => $this->permissionExists('ga.inventory_drive.update'),
            'Delete permission' => $this->permissionExists('ga.inventory_drive.delete'),
            'Storage service' => class_exists(DriveInventoryStorageService::class),
            'Master service' => class_exists(DriveInventoryMasterService::class),
            'Runtime bridge' => class_exists(\App\Services\GeneralAffair\DriveInventoryRuntimeBridge::class),
            'Drive controller' => class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairDriveInventoryController::class),
            'Drive root available' => $this->driveRootExists(),
        ];

        foreach (DriveInventoryStorageService::INITIAL_FOLDERS as $folder) {
            $checks['Seed folder: '.$folder] = Storage::disk('public')->directoryExists(DriveInventoryStorageService::ROOT.'/'.$folder);
        }

        $routes = collect(Route::getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->all();
        foreach ([
            'Browse API' => 'api/v1/general-affair/drive-inventory',
            'Upload API' => 'api/v1/general-affair/drive-inventory/upload',
            'Rename API' => 'api/v1/general-affair/drive-inventory/rename-folder',
            'Mapping API' => 'api/v1/general-affair/drive-inventory/mappings/{outletId}',
            'Master sync API' => 'api/v1/general-affair/drive-inventory/masters/sync',
        ] as $label => $needle) {
            $checks[$label] = collect($routes)->contains(fn ($row) => str_contains($row, $needle));
        }

        if (! $this->option('no-sync') && Schema::hasTable('ga_inventory_drive_folder_mappings')) {
            $this->newLine();
            $this->info('Regenerating mapped outlet Master Inventory XLSX...');
            $sync = $masters->syncAll();
            $this->line('Generated: '.$sync['generated'].' | Failed: '.$sync['failed']);
            foreach ($sync['errors'] as $error) {
                $this->warn(($error['outlet_code'] ?: $error['outlet_id']).': '.$error['message']);
            }
            $checks['Master XLSX sync'] = $sync['failed'] === 0;
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }

        if ($failed) {
            $this->error("ERP POS V10 I11 Drive Inventory check failed: {$failed} check(s).");
            return self::FAILURE;
        }
        $this->info('ERP POS V10 I11 General Affair Drive Inventory is READY.');
        return self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        return Schema::hasTable($table) && DB::table($table)->where('name', $name)->exists();
    }

    private function driveRootExists(): bool
    {
        try { return Storage::disk('public')->directoryExists(DriveInventoryStorageService::ROOT); }
        catch (\Throwable) { return false; }
    }
}
