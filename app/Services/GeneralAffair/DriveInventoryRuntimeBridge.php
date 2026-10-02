<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Asset;
use App\Models\GeneralAffair\InventoryItem;
use Illuminate\Support\Facades\Schema;

class DriveInventoryRuntimeBridge
{
    private static bool $booted = false;
    /** @var array<string,bool> */
    private array $pendingOutletIds = [];

    public function __construct(
        private readonly DriveInventoryStorageService $drive,
        private readonly DriveInventoryMasterService $masters,
    ) {}

    public function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        Asset::saved(function (Asset $asset): void {
            if (! Schema::hasTable('ga_inventory_drive_folder_mappings')) return;
            $this->drive->routeModelPhoto($asset);
        });

        InventoryItem::saved(function (InventoryItem $item): void {
            if (! Schema::hasTable('ga_inventory_drive_folder_mappings')) return;
            $this->drive->routeModelPhoto($item);
            $this->scheduleMaster((string) $item->outlet_id);
        });

        InventoryItem::deleted(function (InventoryItem $item): void {
            if (! Schema::hasTable('ga_inventory_drive_folder_mappings')) return;
            $this->scheduleMaster((string) $item->outlet_id);
        });

        InventoryItem::restored(function (InventoryItem $item): void {
            if (! Schema::hasTable('ga_inventory_drive_folder_mappings')) return;
            $this->scheduleMaster((string) $item->outlet_id);
        });

        app()->terminating(function (): void {
            $this->flushMasters();
        });
    }

    private function scheduleMaster(string $outletId): void
    {
        if ($outletId !== '') $this->pendingOutletIds[$outletId] = true;
    }

    private function flushMasters(): void
    {
        $ids = array_keys($this->pendingOutletIds);
        $this->pendingOutletIds = [];
        foreach ($ids as $outletId) {
            try { $this->masters->regenerateForOutlet($outletId); }
            catch (\Throwable $e) { report($e); }
        }
    }
}
