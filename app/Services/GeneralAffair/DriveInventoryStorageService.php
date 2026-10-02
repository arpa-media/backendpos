<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Asset;
use App\Models\GeneralAffair\InventoryDriveFolderMapping;
use App\Models\GeneralAffair\InventoryItem;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class DriveInventoryStorageService
{
    public const DISK = 'public';
    public const ROOT = DriveInventoryMasterService::ROOT;
    private const LIST_LIMIT = 10000;
    private const SUMMARY_MAX_ENTRIES = 30000;
    private const SUMMARY_MAX_SECONDS = 2.0;

    public const INITIAL_FOLDERS = [
        '00 Office',
        '00 Office/00 Office Griyashanta',
        '00 Office/00 Office Klojen',
        '00 Office/00 Office Sawojajar',
        '00 Palmas',
        '00 Warehouse Malang',
        '00 Warehouse Malang/00 Warehouse Penataran 41',
        '00 Warehouse Malang/00 Warehouse Penataran 43',
        '00 Warehouse Malang/00 Warehouse Waringin',
        '01 JBDM Klojen',
        '02 TKJ Ijen',
        '03 JBDM Sawojajar',
        '04 TKJ Begawan',
        '05 TKJ Sukun',
        '06 TKJ Smoore',
        '07 TKJ Kepundung',
        '08 TKJ FE Brawijaya',
        '09 Cafetaria Jaya',
        '10 TKJ Soehat',
        '11 Medcafe',
        '12 TKJ MOG',
        '13 TKJ Denpasar',
        '14 TKJ Tenes',
        '15 TKJ Fia',
        '16 TKJ Borneo',
        '17 TKJ Jl Aceh, Bandung',
        '18 TKJ Kuta',
        '19 TKJ MCP',
        '20 TKJ Banjarbaru',
    ];

    public function __construct(private readonly DriveInventoryMasterService $masters) {}

    public function ensureInitialStructure(): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::ROOT);
        foreach (self::INITIAL_FOLDERS as $folder) {
            $disk->makeDirectory(self::ROOT.'/'.$folder);
        }
    }

    public function browse(?string $path, array $options = []): array
    {
        $this->ensureInitialStructure();
        $path = $this->normalizePath($path);
        $absolute = $this->existingDirectory($path);
        $search = mb_strtolower(trim((string) ($options['search'] ?? '')));
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(200, max(20, (int) ($options['per_page'] ?? 80)));

        $entries = [];
        $matchedTotal = 0;
        $listingTruncated = false;
        foreach (new \DirectoryIterator($absolute) as $entry) {
            if ($entry->isDot() || $entry->isLink()) continue;
            $name = $entry->getFilename();
            if ($search !== '' && ! str_contains(mb_strtolower($name), $search)) continue;
            $matchedTotal++;
            if (count($entries) >= self::LIST_LIMIT) {
                $listingTruncated = true;
                continue;
            }
            $relative = $this->joinPath($path, $name);
            $isDirectory = $entry->isDir();
            $entries[] = [
                'name' => $name,
                'path' => $relative,
                'type' => $isDirectory ? 'folder' : 'file',
                'size_bytes' => $isDirectory ? null : max(0, (int) $entry->getSize()),
                'extension' => $isDirectory ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified_at' => $entry->getMTime() ? date(DATE_ATOM, $entry->getMTime()) : null,
                'downloadable' => ! $isDirectory,
                'mapped_outlet' => $isDirectory ? $this->mappingAtPath($relative) : null,
                'protected' => $this->hasProtectedReference($relative),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        $visibleTotal = count($entries);
        $offset = ($page - 1) * $perPage;
        return [
            'root_label' => 'Profile Inventory',
            'root_storage_path' => self::ROOT,
            'current' => [
                'path' => $path,
                'breadcrumbs' => $this->breadcrumbs($path),
            ],
            'entries' => array_slice($entries, $offset, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $listingTruncated ? $visibleTotal : $matchedTotal,
                'matched_total' => $matchedTotal,
                'last_page' => max(1, (int) ceil(($listingTruncated ? $visibleTotal : $matchedTotal) / $perPage)),
                'listing_truncated' => $listingTruncated,
                'list_limit' => self::LIST_LIMIT,
            ],
            'summary' => $this->summary($absolute, $path),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    public function meta(): array
    {
        $this->ensureInitialStructure();
        $mappings = InventoryDriveFolderMapping::query()->orderBy('folder_path')->get();
        $disk = Storage::disk(self::DISK);
        return [
            'outlets' => Outlet::query()->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => $row->code,
                'name' => $row->name,
            ])->values(),
            'folders' => $this->folderOptions(),
            'mappings' => $mappings->map(fn (InventoryDriveFolderMapping $row) => [
                'id' => (string) $row->id,
                'outlet_id' => (string) $row->outlet_id,
                'outlet_code' => $row->outlet_code_snapshot,
                'outlet_name' => $row->outlet_name_snapshot,
                'folder_path' => $row->folder_path,
                'last_master_path' => $row->last_master_path,
                'last_master_row_count' => (int) $row->last_master_row_count,
                'last_master_generated_at' => $row->last_master_generated_at?->toIso8601String(),
                'master_exists' => filled($row->last_master_path) && $disk->exists((string) $row->last_master_path),
            ])->values(),
        ];
    }

    public function createFolder(?string $parentPath, string $name, ?string $userId): array
    {
        $parentPath = $this->normalizePath($parentPath);
        $name = $this->assertEntryName($name);
        $parent = $this->existingDirectory($parentPath);
        $relative = $this->joinPath($parentPath, $name);
        $target = $parent.DIRECTORY_SEPARATOR.$name;
        if (file_exists($target)) throw new RuntimeException('File/folder dengan nama tersebut sudah ada.');
        if (! @mkdir($target, 0775, false) && ! is_dir($target)) throw new RuntimeException('Gagal membuat folder.');
        $this->audit($userId, 'create_folder', [$relative], null, null, 'success');
        return ['path' => $relative, 'name' => $name];
    }

    public function renameFolder(string $path, string $newName, ?string $userId): array
    {
        $path = $this->normalizePath($path);
        if ($path === '') throw new InvalidArgumentException('Root Profile Inventory tidak dapat di-rename.');
        $source = $this->existingDirectory($path);
        $newName = $this->assertEntryName($newName);
        $parentPath = str_contains($path, '/') ? dirname($path) : '';
        $parentPath = $parentPath === '.' ? '' : str_replace('\\', '/', $parentPath);
        $targetPath = $this->joinPath($parentPath, $newName);
        if ($targetPath === $path) return ['old_path' => $path, 'new_path' => $path];
        $target = dirname($source).DIRECTORY_SEPARATOR.$newName;
        if (file_exists($target)) throw new RuntimeException('Nama folder tujuan sudah digunakan.');

        if (! File::moveDirectory($source, $target)) throw new RuntimeException('Gagal me-rename folder.');
        $syncOutletIds = [];
        try {
            $syncOutletIds = DB::transaction(fn () => $this->updateReferences($path, $targetPath), 3);
        } catch (\Throwable $e) {
            try { File::moveDirectory($target, $source); } catch (\Throwable) {}
            $this->audit($userId, 'rename_folder', [$path], $targetPath, null, 'failed', $e->getMessage());
            throw $e;
        }

        $masterSyncErrors = $this->syncMastersFor($syncOutletIds);
        $this->audit($userId, 'rename_folder', [$path], $targetPath, null, 'success', $masterSyncErrors ? implode(' | ', $masterSyncErrors) : null);
        return ['old_path' => $path, 'new_path' => $targetPath, 'name' => $newName, 'master_sync_errors' => $masterSyncErrors];
    }

    public function move(array $paths, ?string $destinationPath, ?string $userId): array
    {
        $destinationPath = $this->normalizePath($destinationPath);
        $destination = $this->existingDirectory($destinationPath);
        $sources = $this->selectedExistingPaths($paths);
        if ($sources === []) throw new InvalidArgumentException('Pilih minimal satu file/folder.');

        $planned = [];
        $targets = [];
        foreach ($sources as $source) {
            $sourceRelative = $source['relative'];
            if ($source['is_dir'] && ($destinationPath === $sourceRelative || str_starts_with($destinationPath.'/', $sourceRelative.'/'))) {
                throw new InvalidArgumentException("Folder {$sourceRelative} tidak dapat dipindahkan ke dalam dirinya sendiri.");
            }
            $baseName = basename($source['absolute']);
            $targetRelative = $this->joinPath($destinationPath, $baseName);
            $targetAbsolute = $destination.DIRECTORY_SEPARATOR.$baseName;
            if ($source['absolute'] === $targetAbsolute) throw new InvalidArgumentException("{$sourceRelative} sudah berada di folder tujuan.");
            if (file_exists($targetAbsolute) || isset($targets[$targetRelative])) throw new RuntimeException("Tujuan sudah memiliki {$baseName}.");
            $targets[$targetRelative] = true;
            $planned[] = $source + ['target_relative' => $targetRelative, 'target_absolute' => $targetAbsolute];
        }

        $moved = [];
        try {
            foreach ($planned as $item) {
                $ok = $item['is_dir']
                    ? File::moveDirectory($item['absolute'], $item['target_absolute'])
                    : File::move($item['absolute'], $item['target_absolute']);
                if (! $ok) throw new RuntimeException('Gagal memindahkan '.$item['relative'].'.');
                $moved[] = $item;
            }
            $syncOutletIds = DB::transaction(function () use ($moved): array {
                $ids = [];
                foreach ($moved as $item) {
                    foreach ($this->updateReferences($item['relative'], $item['target_relative']) as $outletId) $ids[$outletId] = true;
                }
                return array_keys($ids);
            }, 3);
        } catch (\Throwable $e) {
            foreach (array_reverse($moved) as $item) {
                try {
                    if (file_exists($item['target_absolute']) && ! file_exists($item['absolute'])) {
                        $item['is_dir']
                            ? File::moveDirectory($item['target_absolute'], $item['absolute'])
                            : File::move($item['target_absolute'], $item['absolute']);
                    }
                } catch (\Throwable) {}
            }
            $this->audit($userId, 'move', array_column($planned, 'relative'), $destinationPath, null, 'failed', $e->getMessage());
            throw $e;
        }

        $masterSyncErrors = $this->syncMastersFor($syncOutletIds ?? []);
        $this->audit($userId, 'move', array_column($planned, 'relative'), $destinationPath, null, 'success', $masterSyncErrors ? implode(' | ', $masterSyncErrors) : null);
        return ['moved' => count($moved), 'destination_path' => $destinationPath, 'paths' => array_column($moved, 'target_relative'), 'master_sync_errors' => $masterSyncErrors];
    }

    public function delete(array $paths, ?string $userId): array
    {
        $sources = $this->selectedExistingPaths($paths);
        if ($sources === []) throw new InvalidArgumentException('Pilih minimal satu file/folder.');
        foreach ($sources as $source) $this->assertDeletable($source['relative']);

        $deleted = [];
        foreach ($sources as $source) {
            $ok = $source['is_dir'] ? File::deleteDirectory($source['absolute']) : File::delete($source['absolute']);
            if (! $ok) {
                $this->audit($userId, 'delete', array_column($sources, 'relative'), null, null, 'failed', 'Gagal menghapus '.$source['relative']);
                throw new RuntimeException('Gagal menghapus '.$source['relative'].'.');
            }
            $deleted[] = $source['relative'];
        }
        $this->audit($userId, 'delete', $deleted, null, null, 'success');
        return ['deleted' => count($deleted), 'paths' => $deleted];
    }

    public function upload(string $folderPath, UploadedFile $file, ?string $userId): array
    {
        $folderPath = $this->normalizePath($folderPath);
        $this->existingDirectory($folderPath);
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $allowed = ['jpg','jpeg','png','webp','pdf','xlsx','xls','csv','doc','docx','txt'];
        if (! in_array($extension, $allowed, true)) throw new InvalidArgumentException('Tipe file tidak diizinkan untuk Drive Inventory.');

        $original = trim((string) $file->getClientOriginalName());
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\pL\pN _.-]+/u', '_', $base));
        $base = trim($base, '. ');
        if ($base === '') $base = 'file';
        $filename = mb_substr($base, 0, 180).'.'.$extension;
        $relative = $this->joinPath($folderPath, $filename);
        $disk = Storage::disk(self::DISK);
        $storagePath = self::ROOT.'/'.$relative;
        if ($disk->exists($storagePath)) {
            $filename = mb_substr($base, 0, 150).'_'.now()->format('Ymd_His').'_'.substr((string) Str::ulid(), -6).'.'.$extension;
            $relative = $this->joinPath($folderPath, $filename);
            $storagePath = self::ROOT.'/'.$relative;
        }

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false || ! $disk->put($storagePath, $stream)) {
            if (is_resource($stream)) fclose($stream);
            throw new RuntimeException('Gagal mengupload file ke Drive Inventory.');
        }
        if (is_resource($stream)) fclose($stream);

        $this->audit($userId, 'upload', [$relative], $folderPath, null, 'success');
        return [
            'name' => $filename,
            'path' => $relative,
            'size_bytes' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    public function fileForDownload(string $path): array
    {
        $path = $this->normalizePath($path);
        if ($path === '') throw new InvalidArgumentException('File tidak valid.');
        $absolute = $this->existingPath($path);
        if (! is_file($absolute)) throw new InvalidArgumentException('Path bukan file.');
        return ['absolute' => $absolute, 'name' => basename($absolute)];
    }

    public function mapOutlet(string $outletId, string $folderPath, ?string $userId): array
    {
        $folderPath = $this->normalizePath($folderPath);
        if ($folderPath === '') throw new InvalidArgumentException('Pilih folder destinasi outlet.');
        $this->existingDirectory($folderPath);
        $outlet = Outlet::query()->findOrFail($outletId);

        $duplicate = InventoryDriveFolderMapping::query()
            ->where('folder_path', $folderPath)
            ->where('outlet_id', '!=', $outletId)
            ->exists();
        if ($duplicate) throw new InvalidArgumentException('Folder tersebut sudah menjadi destinasi outlet lain.');

        $mapping = InventoryDriveFolderMapping::query()->where('outlet_id', $outletId)->first();
        $oldPath = $mapping?->folder_path;
        if ($mapping && $oldPath !== $folderPath) $this->masters->removeMasterForMapping($mapping);

        if (! $mapping) {
            $mapping = new InventoryDriveFolderMapping(['id' => (string) Str::ulid(), 'created_by_user_id' => $userId]);
            $mapping->outlet_id = (string) $outlet->id;
        }
        $mapping->forceFill([
            'outlet_code_snapshot' => $outlet->code,
            'outlet_name_snapshot' => $outlet->name,
            'folder_path' => $folderPath,
            'is_active' => true,
            'updated_by_user_id' => $userId,
        ])->save();

        $routed = $this->routeExistingPhotosForOutlet($outletId);
        $master = $this->masters->regenerateForOutlet($outletId);
        $this->audit($userId, 'map_outlet', [$oldPath ?: '(unmapped)'], $folderPath, $outletId, 'success');

        return [
            'outlet_id' => (string) $outlet->id,
            'outlet_code' => $outlet->code,
            'outlet_name' => $outlet->name,
            'folder_path' => $folderPath,
            'routed_photos' => $routed,
            'master' => $master,
        ];
    }

    public function unmapOutlet(string $outletId, ?string $userId): array
    {
        $mapping = InventoryDriveFolderMapping::query()->where('outlet_id', $outletId)->firstOrFail();
        $oldPath = (string) $mapping->folder_path;
        $this->masters->removeMasterForMapping($mapping);
        $mapping->delete();
        $this->audit($userId, 'unmap_outlet', [$oldPath], null, $outletId, 'success');
        return ['outlet_id' => $outletId, 'folder_path' => $oldPath];
    }

    public function routeModelPhoto(Model $model): bool
    {
        $outletId = trim((string) ($model->outlet_id ?? ''));
        $source = trim((string) ($model->photo_path ?? ''));
        if ($outletId === '' || $source === '') return false;
        if (! str_starts_with($source.'/', 'general-affair/profile-inventory/')) return false;

        $mapping = InventoryDriveFolderMapping::query()->where('outlet_id', $outletId)->where('is_active', true)->first();
        if (! $mapping) return false;

        $targetFolder = self::ROOT.'/'.trim((string) $mapping->folder_path, '/');
        if ($source === $targetFolder || str_starts_with($source.'/', $targetFolder.'/')) return false;

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($source)) return false;
        $disk->makeDirectory($targetFolder);
        $target = $targetFolder.'/'.basename($source);
        $contents = $disk->get($source);
        if (! $disk->put($target, $contents)) throw new RuntimeException('Gagal memindahkan foto ke folder Profile Inventory outlet.');
        if ($source !== $target) $disk->delete($source);

        $model->forceFill(['photo_disk' => self::DISK, 'photo_path' => $target]);
        if (method_exists($model, 'saveQuietly')) $model->saveQuietly();
        else $model->save();
        return true;
    }

    public function routeExistingPhotosForOutlet(string $outletId): array
    {
        $asset = 0; $inventory = 0;
        Asset::withTrashed()->where('outlet_id', $outletId)->whereNotNull('photo_path')->chunkById(200, function ($rows) use (&$asset): void {
            foreach ($rows as $row) if ($this->routeModelPhoto($row)) $asset++;
        }, 'id');
        InventoryItem::withTrashed()->where('outlet_id', $outletId)->whereNotNull('photo_path')->chunkById(200, function ($rows) use (&$inventory): void {
            foreach ($rows as $row) if ($this->routeModelPhoto($row)) $inventory++;
        }, 'id');
        return ['asset' => $asset, 'inventory' => $inventory, 'total' => $asset + $inventory];
    }

    public function folderOptions(): array
    {
        $this->ensureInitialStructure();
        $root = $this->rootAbsolute();
        $rows = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isDir()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($relative === '') continue;
            $rows[] = ['path' => $relative, 'name' => basename($relative), 'depth' => substr_count($relative, '/')];
            if (count($rows) >= 5000) break;
        }
        usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return $rows;
    }


    /** @param array<int,string> $outletIds @return array<int,string> */
    private function syncMastersFor(array $outletIds): array
    {
        $errors = [];
        foreach (array_values(array_unique(array_filter($outletIds))) as $outletId) {
            try { $this->masters->regenerateForOutlet((string) $outletId); }
            catch (\Throwable $e) { $errors[] = (string) $outletId.': '.$e->getMessage(); report($e); }
        }
        return $errors;
    }

    private function summary(string $absolute, string $path): array
    {
        $started = microtime(true);
        $files = 0; $folders = 0; $bytes = 0; $scanned = 0; $partial = false;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) continue;
                $scanned++;
                if ($scanned > self::SUMMARY_MAX_ENTRIES || (microtime(true) - $started) > self::SUMMARY_MAX_SECONDS) {
                    $partial = true; break;
                }
                if ($item->isDir()) { $folders++; continue; }
                if ($item->isFile()) { $files++; $bytes += max(0, (int) $item->getSize()); }
            }
        } catch (\Throwable) { $partial = true; }
        return ['file_count' => $files, 'folder_count' => $folders, 'total_bytes' => $bytes, 'scanned_entries' => $scanned, 'partial' => $partial, 'path' => $path];
    }

    private function recentActivity(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('ga_inventory_drive_audit_logs')) return [];
        return DB::table('ga_inventory_drive_audit_logs')->latest('created_at')->limit(12)->get([
            'id','user_id','outlet_id','action','source_paths','destination_path','status','message','created_at'
        ])->map(fn ($row) => [
            'id' => (string) $row->id,
            'user_id' => $row->user_id ? (string) $row->user_id : null,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'action' => (string) $row->action,
            'source_paths' => json_decode((string) ($row->source_paths ?: '[]'), true) ?: [],
            'destination_path' => $row->destination_path,
            'status' => (string) $row->status,
            'message' => $row->message,
            'created_at' => $row->created_at,
        ])->all();
    }

    private function audit(?string $userId, string $action, array $sourcePaths, ?string $destination, ?string $outletId, string $status, ?string $message = null): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ga_inventory_drive_audit_logs')) return;
        DB::table('ga_inventory_drive_audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $userId ?: null,
            'outlet_id' => $outletId ?: null,
            'action' => $action,
            'source_paths' => json_encode(array_values(array_filter($sourcePaths, fn ($v) => $v !== null)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'destination_path' => $destination,
            'status' => $status,
            'message' => $message ? mb_substr($message, 0, 2000) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mappingAtPath(string $path): ?array
    {
        $row = InventoryDriveFolderMapping::query()->where('folder_path', $path)->where('is_active', true)->first();
        return $row ? ['outlet_id' => (string) $row->outlet_id, 'code' => $row->outlet_code_snapshot, 'name' => $row->outlet_name_snapshot] : null;
    }

    private function assertDeletable(string $relative): void
    {
        if ($relative === '') throw new InvalidArgumentException('Root Profile Inventory dilindungi dari penghapusan.');
        if ($this->hasProtectedReference($relative)) {
            throw new InvalidArgumentException('Item terhubung ke outlet atau foto Asset/Inventory aktif. Ubah mapping/record terlebih dahulu sebelum menghapusnya.');
        }
    }

    private function hasProtectedReference(string $relative): bool
    {
        if (DB::getSchemaBuilder()->hasTable('ga_inventory_drive_folder_mappings')) {
            if (InventoryDriveFolderMapping::query()->where('folder_path', $relative)->orWhere('folder_path', 'like', $relative.'/%')->exists()) return true;
        }
        $storagePrefix = self::ROOT.'/'.$relative;
        if (DB::getSchemaBuilder()->hasTable('ga_assets')) {
            if (Asset::withTrashed()->where('photo_path', $storagePrefix)->orWhere('photo_path', 'like', $storagePrefix.'/%')->exists()) return true;
        }
        if (DB::getSchemaBuilder()->hasTable('ga_inventory_items')) {
            if (InventoryItem::withTrashed()->where('photo_path', $storagePrefix)->orWhere('photo_path', 'like', $storagePrefix.'/%')->exists()) return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private function updateReferences(string $oldRelative, string $newRelative): array
    {
        $syncOutletIds = [];
        $mappings = InventoryDriveFolderMapping::query()
            ->where('folder_path', $oldRelative)
            ->orWhere('folder_path', 'like', $oldRelative.'/%')
            ->get();
        foreach ($mappings as $mapping) {
            $syncOutletIds[(string) $mapping->outlet_id] = true;
            $mapping->folder_path = $this->replacePrefix((string) $mapping->folder_path, $oldRelative, $newRelative);
            if (filled($mapping->last_master_path)) {
                $oldFull = self::ROOT.'/'.$oldRelative;
                $newFull = self::ROOT.'/'.$newRelative;
                $mapping->last_master_path = $this->replacePrefix((string) $mapping->last_master_path, $oldFull, $newFull);
            }
            $mapping->saveQuietly();
        }

        $oldFull = self::ROOT.'/'.$oldRelative;
        $newFull = self::ROOT.'/'.$newRelative;
        foreach ([Asset::class, InventoryItem::class] as $modelClass) {
            $isInventory = $modelClass === InventoryItem::class;
            $modelClass::withTrashed()
                ->where('photo_path', $oldFull)
                ->orWhere('photo_path', 'like', $oldFull.'/%')
                ->chunkById(200, function ($rows) use ($oldFull, $newFull, $isInventory, &$syncOutletIds): void {
                    foreach ($rows as $row) {
                        $row->photo_path = $this->replacePrefix((string) $row->photo_path, $oldFull, $newFull);
                        $row->saveQuietly();
                        if ($isInventory && filled($row->outlet_id)) $syncOutletIds[(string) $row->outlet_id] = true;
                    }
                }, 'id');
        }
        return array_keys($syncOutletIds);
    }

    private function replacePrefix(string $value, string $old, string $new): string
    {
        if ($value === $old) return $new;
        if (str_starts_with($value, $old.'/')) return $new.substr($value, strlen($old));
        return $value;
    }

    private function selectedExistingPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $value = $this->normalizePath(is_string($path) ? $path : '');
            if ($value !== '') $normalized[$value] = true;
        }
        $paths = array_keys($normalized);
        usort($paths, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
        $kept = [];
        foreach ($paths as $path) {
            $covered = false;
            foreach ($kept as $parent) {
                if ($path === $parent['relative'] || str_starts_with($path.'/', $parent['relative'].'/')) { $covered = true; break; }
            }
            if ($covered) continue;
            $absolute = $this->existingPath($path);
            $kept[] = ['relative' => $path, 'absolute' => $absolute, 'is_dir' => is_dir($absolute)];
        }
        return $kept;
    }

    private function rootAbsolute(): string
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::ROOT);
        $absolute = $disk->path(self::ROOT);
        File::ensureDirectoryExists($absolute);
        $real = realpath($absolute);
        if ($real === false) throw new RuntimeException('Root Profile Inventory tidak dapat diakses.');
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function existingDirectory(string $path): string
    {
        $absolute = $path === '' ? $this->rootAbsolute() : $this->existingPath($path);
        if (! is_dir($absolute)) throw new InvalidArgumentException('Folder tidak ditemukan.');
        return $absolute;
    }

    private function existingPath(string $path): string
    {
        $root = $this->rootAbsolute();
        if ($this->hasSymlinkSegment($root, $path)) throw new InvalidArgumentException('Symbolic link tidak dapat dikelola dari Drive Inventory.');
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $real = realpath($candidate);
        if ($real === false || ! $this->withinRoot($root, $real)) throw new InvalidArgumentException('Path tidak ditemukan atau berada di luar Profile Inventory.');
        if (is_link($candidate) || is_link($real)) throw new InvalidArgumentException('Symbolic link tidak dapat dikelola dari Drive Inventory.');
        return $real;
    }

    private function hasSymlinkSegment(string $root, string $path): bool
    {
        if ($path === '') return false;
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) return true;
        }
        return false;
    }

    private function withinRoot(string $root, string $absolute): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $absolute = str_replace('\\', '/', $absolute);
        return $absolute === $root || str_starts_with($absolute.'/', $root.'/');
    }

    private function normalizePath(?string $path): string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = trim($path, '/');
        if ($path === '') return '';
        if (mb_strlen($path) > 700 || str_contains($path, "\0") || preg_match('/^[A-Za-z]:\//', $path)) throw new InvalidArgumentException('Path tidak valid.');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') throw new InvalidArgumentException('Path traversal tidak diizinkan.');
        }
        return $path;
    }

    private function assertEntryName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 180 || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Nama folder tidak valid.');
        }
        if (str_starts_with($name, '.')) throw new InvalidArgumentException('Folder tersembunyi tidak diizinkan.');
        return $name;
    }

    private function joinPath(string $parent, string $name): string
    {
        return $parent === '' ? $name : $parent.'/'.$name;
    }

    private function breadcrumbs(string $path): array
    {
        $rows = [['name' => 'Profile Inventory', 'path' => '']];
        if ($path === '') return $rows;
        $acc = [];
        foreach (explode('/', $path) as $segment) {
            $acc[] = $segment;
            $rows[] = ['name' => $segment, 'path' => implode('/', $acc)];
        }
        return $rows;
    }
}
