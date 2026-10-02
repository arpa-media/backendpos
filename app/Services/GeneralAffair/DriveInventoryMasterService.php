<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\InventoryDriveFolderMapping;
use App\Models\GeneralAffair\InventoryItem;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DriveInventoryMasterService
{
    public const DISK = 'public';
    public const ROOT = 'general-affair/profile-inventory/drive';

    public function __construct(private readonly SimpleXlsxService $xlsx) {}

    public function regenerateForOutlet(string $outletId): ?array
    {
        $mapping = InventoryDriveFolderMapping::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->first();
        if (! $mapping) return null;

        $disk = Storage::disk(self::DISK);
        $folder = trim((string) $mapping->folder_path, '/');
        $disk->makeDirectory(self::ROOT.'/'.$folder);

        $rows = [array_merge(AssetInventorySpreadsheetService::INVENTORY_HEADERS, ['Total Nilai', 'Foto'])];
        InventoryItem::query()
            ->where('outlet_id', $outletId)
            ->orderBy('item_name')
            ->orderBy('inventory_code')
            ->chunkById(500, function ($items) use (&$rows): void {
                foreach ($items as $row) {
                    $rows[] = [
                        $row->inventory_code,
                        $row->item_name,
                        $row->item_type,
                        (string) $row->quantity,
                        $row->condition,
                        $row->location,
                        (string) $row->unit_price,
                        $row->outlet_code_snapshot,
                        (string) $row->total_value,
                        $row->photo_path,
                    ];
                }
            }, 'id');

        $code = Str::upper(Str::slug((string) ($mapping->outlet_code_snapshot ?: 'OUTLET'), '_')) ?: 'OUTLET';
        $filename = 'MASTER_INVENTORY_'.$code.'.xlsx';
        $relative = self::ROOT.'/'.$folder.'/'.$filename;
        $response = $this->xlsx->download($filename, 'Inventory Recap', $rows);
        $binary = $response->getContent();
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Gagal membangun workbook Master Inventory.');
        }

        $absolute = $disk->path($relative);
        File::ensureDirectoryExists(dirname($absolute));
        $this->atomicReplace($absolute, $binary);

        $mapping->forceFill([
            'last_master_path' => $relative,
            'last_master_row_count' => max(0, count($rows) - 1),
            'last_master_generated_at' => now(),
        ])->save();

        return [
            'outlet_id' => (string) $mapping->outlet_id,
            'outlet_code' => $mapping->outlet_code_snapshot,
            'outlet_name' => $mapping->outlet_name_snapshot,
            'folder_path' => $folder,
            'master_path' => $relative,
            'row_count' => max(0, count($rows) - 1),
            'generated_at' => $mapping->last_master_generated_at?->toIso8601String(),
        ];
    }

    public function syncAll(): array
    {
        $results = [];
        $errors = [];
        $mappings = InventoryDriveFolderMapping::query()->where('is_active', true)->orderBy('folder_path')->get();
        foreach ($mappings as $mapping) {
            try {
                $row = $this->regenerateForOutlet((string) $mapping->outlet_id);
                if ($row) $results[] = $row;
            } catch (\Throwable $e) {
                $errors[] = [
                    'outlet_id' => (string) $mapping->outlet_id,
                    'outlet_code' => $mapping->outlet_code_snapshot,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return [
            'generated' => count($results),
            'failed' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    public function removeMasterForMapping(InventoryDriveFolderMapping $mapping): void
    {
        $path = trim((string) $mapping->last_master_path);
        if ($path !== '') {
            try { Storage::disk(self::DISK)->delete($path); } catch (\Throwable) {}
        }
    }

    private function atomicReplace(string $absolute, string $binary): void
    {
        if (method_exists(File::getFacadeRoot(), 'replace')) {
            File::replace($absolute, $binary);
            return;
        }

        $tmp = $absolute.'.tmp.'.Str::ulid();
        if (@file_put_contents($tmp, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Gagal menulis file sementara Master Inventory.');
        }
        if (is_file($absolute) && ! @unlink($absolute)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal mengganti Master Inventory lama.');
        }
        if (! @rename($tmp, $absolute)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal memfinalisasi Master Inventory.');
        }
    }
}
